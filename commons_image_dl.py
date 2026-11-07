#!/usr/bin/env python3
# Copyright (C) 2026 MENJ
# Developed and coded by MENJ.
# SPDX-License-Identifier: GPL-3.0-only
"""Automatically discover and download Wikimedia Commons images."""
from __future__ import annotations

import argparse
import hashlib
import json
import logging
import math
import os
import re
import sys
import tempfile
import time
from datetime import datetime, timezone
from email.utils import parsedate_to_datetime
from html.parser import HTMLParser
from pathlib import Path
from uuid import uuid4

import requests
from PIL import Image, UnidentifiedImageError

API = "https://commons.wikimedia.org/w/api.php"
MAGNUS = "https://magnustools.toolforge.org/commonsapi/"
USER_AGENT = "commons-image-dl/0.1 (https://github.com/menj/commons-image-dl)"
MIMES = {"jpeg": "image/jpeg", "png": "image/png", "webp": "image/webp",
         "gif": "image/gif", "tiff": "image/tiff", "bmp": "image/bmp"}
EXTENSIONS = {v: ("jpg" if k == "jpeg" else k) for k, v in MIMES.items()}
LOG = logging.getLogger("commons-image-dl")


class DownloadError(Exception):
    """An actionable network, API, or file-validation failure."""


class PlainText(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.parts = []

    def handle_data(self, data):
        self.parts.append(data)


def plain(value):
    parser = PlainText()
    parser.feed(str(value or ""))
    return " ".join(" ".join(parser.parts).split())


def atomic_text(path, value):
    fd, name = tempfile.mkstemp(dir=path.parent, prefix=".commons-", suffix=".tmp")
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            handle.write(value)
        os.replace(name, path)
    finally:
        Path(name).unlink(missing_ok=True)


def digest(path):
    result = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(65536), b""):
            result.update(chunk)
    return result.hexdigest()


def retry_delay(value, attempt):
    try:
        delay = float(value)
    except (ValueError, TypeError):
        try:
            delay = (parsedate_to_datetime(value) - datetime.now(timezone.utc)).total_seconds()
        except (ValueError, TypeError, OverflowError):
            delay = 2 ** attempt
    return max(1.0, delay) if math.isfinite(delay) else 2 ** attempt


class Client:
    def __init__(self, user_agent=USER_AGENT, retries=3, delay=0.5, timeout=30):
        self.session = requests.Session()
        self.session.headers["User-Agent"] = user_agent
        self.retries, self.delay, self.timeout = retries, delay, timeout
        self.last_request = 0.0

    def close(self):
        self.session.close()

    def get(self, url, **kwargs):
        for attempt in range(self.retries + 1):
            time.sleep(max(0, self.delay - (time.monotonic() - self.last_request)))
            self.last_request = time.monotonic()
            try:
                response = self.session.get(url, timeout=(10, self.timeout), **kwargs)
            except requests.RequestException as exc:
                if attempt == self.retries:
                    raise DownloadError(str(exc)) from exc
                time.sleep(2 ** attempt)
                continue
            if response.status_code == 429 or 500 <= response.status_code < 600:
                wait = retry_delay(response.headers.get("Retry-After"), attempt)
                response.close()
                if attempt < self.retries:
                    # Do not hammer a service asking for a long pause.
                    if wait > 300:
                        raise DownloadError(f"Server requests retry after {wait:.0f}s; rerun later")
                    time.sleep(wait)
                    continue
            try:
                response.raise_for_status()
            except requests.RequestException as exc:
                response.close()
                raise DownloadError(str(exc)) from exc
            return response
        raise DownloadError("Retry budget exhausted")

    def api(self, **params):
        for attempt in range(self.retries + 1):
            with self.get(API, params={"action": "query", "format": "json",
                                     "formatversion": 2, "maxlag": 5, **params}) as response:
                try:
                    data = response.json()
                except ValueError as exc:
                    raise DownloadError("API returned invalid JSON") from exc
                header = response.headers.get("Retry-After")
            if not isinstance(data, dict):
                raise DownloadError("API returned an unexpected response")
            error = data.get("error")
            if not error:
                return data
            if error.get("code") in {"maxlag", "ratelimited"} and attempt < self.retries:
                wait = retry_delay(header, attempt)
                if wait > 300:
                    raise DownloadError("API requests a long pause; rerun later")
                time.sleep(wait)
                continue
            raise DownloadError(f"API {error.get('code')}: {error.get('info')}")
        raise DownloadError("API retry budget exhausted")

    def pages(self, params, args):
        continuation = {}
        seen_tokens = set()
        while True:
            data = self.api(**params, **continuation)
            pages = data.get("query", {}).get("pages", [])
            yield from sorted(pages, key=lambda p: p.get("index", p.get("pageid", 0)))
            continuation = data.get("continue", {})
            if not continuation:
                break
            token = json.dumps(continuation, sort_keys=True)
            if token in seen_tokens:
                raise DownloadError("API repeated a continuation token")
            seen_tokens.add(token)

    def discover(self, args):
        info = {"prop": "imageinfo", "iiprop": "url|size|mime|sha1|extmetadata|timestamp|user",
                "iiextmetadatalanguage": args.language}
        if args.width:
            info.update(iiurlwidth=args.width, iiprop=info["iiprop"] + "|thumbmime")
        if args.query or args.queries_file:
            queries = args.query or []
            if args.queries_file:
                queries = [line.strip() for line in args.queries_file.read_text(encoding="utf-8").splitlines()
                           if line.strip() and not line.lstrip().startswith("#")]
                if not queries:
                    raise DownloadError("Queries file contains no queries")
            for query in queries:
                for page in self.pages({**info, "generator": "search", "gsrnamespace": 6,
                                        "gsrsearch": query, "gsrlimit": 20}, args):
                    yield page, query
        elif args.category:
            title = args.category if args.category.startswith("Category:") else "Category:" + args.category
            for page in self.pages({**info, "generator": "categorymembers", "gcmtitle": title,
                                    "gcmtype": "file", "gcmlimit": 20}, args):
                yield page, title
        elif args.file:
            for title in args.file:
                title = re.sub(r"^(?:File|Image):", "", title)
                for page in self.pages({**info, "titles": "File:" + title, "redirects": 1}, args):
                    yield page, title
        elif args.uploader:
            for page in self.pages({**info, "generator": "allimages", "gaiuser": args.uploader,
                                    "gaisort": "timestamp", "gailimit": 20}, args):
                yield page, args.uploader
        elif args.near:
            for page in self.pages({**info, "generator": "geosearch", "ggsnamespace": 6,
                                    "ggsprimary": "all", "ggscoord": "|".join(map(str, args.near)),
                                    "ggsradius": args.radius, "ggslimit": 50}, args):
                yield page, str(args.near)
        else:
            # Random discovery has no exhaustion signal; the caller caps candidates.
            while True:
                data = self.api(**info, generator="random", grnnamespace=6, grnlimit=20)
                pages = data.get("query", {}).get("pages", [])
                if not pages:
                    break
                for page in pages:
                    yield page, "random"

    def enrich(self, title, args):
        with self.get(MAGNUS, params={"image": title.removeprefix("File:"), "format": "json",
                                      "languages": args.language + "|default", "sdc": ""}) as response:
            try:
                return response.json()
            except ValueError as exc:
                raise DownloadError("Magnus API returned invalid JSON") from exc


def record_for(page, source, args):
    if page.get("missing") or not page.get("imageinfo"):
        return None
    info = page["imageinfo"][0]
    if not info.get("mime", "").startswith("image/"):
        return None
    if info.get("width", 0) < args.min_width or info.get("height", 0) < args.min_height:
        return None
    thumb = bool(args.width and info.get("thumburl"))
    if args.width and not thumb:
        return None  # Never silently download a huge original instead of a thumbnail.
    mime = info.get("thumbmime") if thumb else info.get("mime")
    if mime not in {MIMES[fmt] for fmt in args.formats}:
        return None
    raw = info.get("extmetadata", {})
    metadata = {key: plain(value.get("value")) for key, value in raw.items() if isinstance(value, dict)}
    license_name = metadata.get("LicenseShortName", "")
    if args.license and license_name.casefold() not in {v.casefold() for v in args.license}:
        return None
    return {"page_id": page["pageid"], "title": page["title"], "discovery_source": source,
            "description_url": info.get("descriptionurl"), "original_url": info.get("url"),
            "download_url": info.get("thumburl") if thumb else info.get("url"),
            "mime": mime, "original_mime": info.get("mime"),
            "original_width": info.get("width"), "original_height": info.get("height"),
            "width": info.get("thumbwidth") if thumb else info.get("width"),
            "height": info.get("thumbheight") if thumb else info.get("height"),
            "source_sha1": info.get("sha1"), "upload_timestamp": info.get("timestamp"),
            "uploader": info.get("user"), "author": metadata.get("Artist", ""),
            "credit": metadata.get("Credit", ""), "license": license_name,
            "license_url": metadata.get("LicenseUrl", ""),
            "description": metadata.get("ImageDescription", ""),
            "metadata": metadata, "raw_extmetadata": raw,
            "retrieved_at": datetime.now(timezone.utc).isoformat()}


def filename(record):
    title = record["title"].removeprefix("File:")
    stem = re.sub(r"[^A-Za-z0-9_-]+", "-", Path(title).stem).strip("-")[:90] or "image"
    variant = hashlib.sha256((record["download_url"] + str(record["source_sha1"])).encode()).hexdigest()[:12]
    return f"{record['page_id']}-{stem}-{variant}.{EXTENSIONS[record['mime']]}"


def attribution(record):
    return (f"{record['title']}\nAuthor: {record['author'] or 'Not supplied'}\n"
            f"Credit: {record['credit'] or 'Not supplied'}\n"
            f"Source: {record['description_url']}\n"
            f"License: {record['license'] or 'Not supplied'}\n"
            f"License URL: {record['license_url'] or 'Not supplied'}\n"
            f"Usage terms: {record['metadata'].get('UsageTerms', 'See source file page')}\n")


def save_image(client, record, output, max_bytes):
    target = output / filename(record)
    sidecar = target.with_name(target.name + ".json")
    credit = target.with_name(target.name + ".txt")
    if any(p.is_symlink() for p in (target, sidecar, credit)):
        raise DownloadError("Refusing to follow an output symlink")
    if target.exists():
        try:
            old = json.loads(sidecar.read_text(encoding="utf-8"))
        except (OSError, ValueError) as exc:
            raise DownloadError(f"Existing file has no valid sidecar: {target.name}") from exc
        if (old.get("download_url") != record["download_url"] or
                old.get("source_sha1") != record["source_sha1"] or
                old.get("sha256") != digest(target)):
            raise DownloadError(f"Existing file failed integrity check: {target.name}")
        # Refresh metadata and regenerate credits on a verified rerun.
        record.update(local_file=target.name, sha256=old["sha256"], bytes=target.stat().st_size,
                      status="existing")
    else:
        fd, name = tempfile.mkstemp(dir=output, prefix=".commons-", suffix=".part")
        os.close(fd)
        temp = Path(name)
        try:
            with client.get(record["download_url"], stream=True) as response:
                content_type = response.headers.get("Content-Type", "").split(";")[0].strip()
                if content_type not in {record["mime"], "application/octet-stream"}:
                    raise DownloadError(f"Unexpected download Content-Type: {content_type}")
                total = 0
                with temp.open("wb") as handle:
                    for chunk in response.iter_content(65536):
                        total += len(chunk)
                        if total > max_bytes:
                            raise DownloadError("Image exceeds --max-bytes")
                        handle.write(chunk)
            with Image.open(temp) as image:
                actual_mime = Image.MIME.get(image.format)
                if actual_mime != record["mime"]:
                    raise DownloadError("Downloaded format does not match API metadata")
                image.verify()
            record.update(local_file=target.name, bytes=total, sha256=digest(temp), status="downloaded")
            # Atomic, exclusive publication: never expose a partially written image.
            os.link(temp, target)
        except (requests.RequestException, UnidentifiedImageError, Image.DecompressionBombError) as exc:
            raise DownloadError(str(exc)) from exc
        finally:
            temp.unlink(missing_ok=True)
    atomic_text(sidecar, json.dumps(record, ensure_ascii=False, indent=2) + "\n")
    atomic_text(credit, attribution(record))
    return record


def positive(value):
    number = int(value)
    if number <= 0:
        raise argparse.ArgumentTypeError("must be positive")
    return number


def parser():
    p = argparse.ArgumentParser(description=__doc__)
    source = p.add_mutually_exclusive_group(required=True)
    source.add_argument("--query", action="append", help="Search phrase; repeat for multiple queries")
    source.add_argument("--queries-file", type=Path, help="UTF-8 file, one search phrase per line")
    source.add_argument("--category", help="Direct members only; optional Category: prefix")
    source.add_argument("--file", action="append", help="Exact filename; repeat for multiple files")
    source.add_argument("--uploader", help="Files whose latest version was uploaded by this user")
    source.add_argument("--near", nargs=2, type=float, metavar=("LAT", "LON"))
    source.add_argument("--random", action="store_true")
    p.add_argument("--count", type=positive, default=10, help="Total images across all sources (default: 10)")
    p.add_argument("--output", type=Path, default=Path("downloads"))
    p.add_argument("--width", type=positive, help="Download thumbnails at this requested width")
    p.add_argument("--min-width", type=positive, default=1, help="Minimum original width")
    p.add_argument("--min-height", type=positive, default=1, help="Minimum original height")
    p.add_argument("--formats", nargs="+", choices=sorted(MIMES), default=["jpeg", "png", "webp"])
    p.add_argument("--license", action="append", help="Exact LicenseShortName match, e.g. CC BY-SA 4.0")
    p.add_argument("--language", default="en")
    p.add_argument("--radius", type=positive, default=1000, help="Geo radius in metres, 10 to 10000")
    p.add_argument("--max-candidates", type=positive, default=1000)
    p.add_argument("--max-bytes", type=positive, default=52428800)
    p.add_argument("--user-agent", default=USER_AGENT)
    p.add_argument("--magnus", action="store_true", help="Include optional Magnus JSON and structured data")
    p.add_argument("--dry-run", action="store_true", help="Discover and save a manifest without downloading")
    p.add_argument("--verbose", action="store_true")
    return p


def run(args, client):
    args.output.mkdir(parents=True, exist_ok=True)
    results, failures, seen = [], [], set()
    examined = 0
    error = None
    try:
        for page, source in client.discover(args):
            examined += 1
            if page.get("pageid") not in seen:
                seen.add(page.get("pageid"))
                record = record_for(page, source, args)
                if record:
                    try:
                        if args.magnus:
                            try:
                                record["magnus"] = client.enrich(record["title"], args)
                            except DownloadError as exc:
                                record["magnus_error"] = str(exc)
                                LOG.warning("Optional enrichment failed: %s", exc)
                        if args.dry_run:
                            record["status"] = "planned"
                        else:
                            save_image(client, record, args.output, args.max_bytes)
                        results.append(record)
                        LOG.info("%s: %s", record["status"], record["title"])
                    except (DownloadError, OSError, ValueError) as exc:
                        failures.append({"title": record["title"], "error": str(exc)})
                        LOG.warning("Skipping %s: %s", record["title"], exc)
            if len(results) >= args.count or examined >= args.max_candidates:
                break
    except (DownloadError, OSError, ValueError) as exc:
        error = str(exc)
        LOG.error("Discovery stopped: %s", exc)
    report = {"requested": args.count, "matched": len(results), "examined": examined,
              "complete": len(results) >= args.count, "dry_run": args.dry_run,
              "error": error, "failures": failures, "images": results}
    report_path = args.output / ("manifest-" + uuid4().hex + ".json")
    atomic_text(report_path, json.dumps(report, ensure_ascii=False, indent=2) + "\n")
    print(json.dumps({"matched": len(results), "requested": args.count,
                      "manifest": str(report_path), "complete": report["complete"]}))
    return 0 if report["complete"] else 1


def main(argv=None):
    p = parser()
    args = p.parse_args(argv)
    if args.near and not (-90 <= args.near[0] <= 90 and -180 <= args.near[1] <= 180):
        p.error("Latitude/longitude must be finite and in range")
    if not 10 <= args.radius <= 10000:
        p.error("--radius must be between 10 and 10000")
    if args.query and any(not query.strip() for query in args.query):
        p.error("Search queries must not be empty")
    logging.basicConfig(level=logging.DEBUG if args.verbose else logging.INFO, format="%(message)s")
    client = Client(args.user_agent)
    try:
        return run(args, client)
    except (OSError, DownloadError) as exc:
        LOG.error("%s", exc)
        return 1
    except KeyboardInterrupt:
        LOG.error("Interrupted")
        return 130
    finally:
        client.close()


if __name__ == "__main__":
    sys.exit(main())
