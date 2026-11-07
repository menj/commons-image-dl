# Copyright (C) 2026 MENJ
# Developed and coded by MENJ.
# SPDX-License-Identifier: GPL-3.0-only
import io
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock, patch

import requests
from PIL import Image

import commons_image_dl as dl


def page(index=1, mime="image/png"):
    return {"pageid": index, "title": f"File:Example {index}.png", "imageinfo": [{
        "url": f"https://upload.wikimedia.org/example{index}.png", "mime": mime,
        "width": 800, "height": 600, "sha1": "test", "size": 200,
        "descriptionurl": f"https://commons.wikimedia.org/wiki/File:Example_{index}.png",
        "extmetadata": {"Artist": {"value": "<b>Example &amp; Author</b>"},
                        "LicenseShortName": {"value": "CC BY-SA 4.0"}}}]}


def response(data=None, status=200, headers=None, payload=b""):
    r = Mock()
    r.status_code = status
    r.headers = headers or {}
    r.json.return_value = data
    r.iter_content.return_value = [payload]
    r.__enter__ = Mock(return_value=r)
    r.__exit__ = Mock(return_value=False)
    if status >= 400:
        r.raise_for_status.side_effect = requests.HTTPError(str(status))
    return r


class DownloaderTests(unittest.TestCase):
    def args(self, *extra):
        return dl.parser().parse_args(["--query", "test", *extra])

    def test_metadata_and_exact_license_filter(self):
        record = dl.record_for(page(), "test", self.args("--license", "CC BY-SA 4.0"))
        self.assertEqual(record["author"], "Example & Author")
        self.assertIsNone(dl.record_for(page(), "test", self.args("--license", "CC BY")))
        self.assertEqual(record["raw_extmetadata"]["Artist"]["value"], "<b>Example &amp; Author</b>")

    def test_filter_nonimages_and_dimensions(self):
        self.assertIsNone(dl.record_for(page(mime="video/webm"), "test", self.args()))
        self.assertIsNone(dl.record_for(page(), "test", self.args("--min-width", "900")))
        self.assertIsNone(dl.record_for({"missing": True}, "test", self.args()))

    def test_original_selected_even_when_thumbnail_available(self):
        p = page()
        info = p["imageinfo"][0]
        info.update(thumburl="https://upload.wikimedia.org/thumb.jpg",
                    thumbmime="image/jpeg", thumbwidth=300, thumbheight=225)
        record = dl.record_for(p, "test", self.args())
        self.assertEqual(record["download_url"], info["url"])
        self.assertEqual((record["width"], record["height"]), (800, 600))
        self.assertEqual(record["mime"], "image/png")
        del info["url"]
        self.assertIsNone(dl.record_for(p, "test", self.args()))
        info["url"] = "https://upload.wikimedia.org/original.svg"
        info["mime"] = "image/svg+xml"
        self.assertIsNone(dl.record_for(p, "test", self.args()))

    def test_discovery_never_requests_thumbnails(self):
        client = dl.Client()
        try:
            client.api = Mock(return_value={"query": {"pages": [page()]}})
            list(client.discover(self.args()))
            params = client.api.call_args.kwargs
            self.assertNotIn("iiurlwidth", params)
            self.assertNotIn("thumbmime", params["iiprop"])
        finally:
            client.close()

    def test_default_download_has_no_byte_cap(self):
        args = self.args()
        self.assertIsNone(args.max_bytes)
        with tempfile.TemporaryDirectory() as directory:
            payload = self.png()
            client = Mock()
            client.get.return_value = response(headers={"Content-Type": "image/png"}, payload=payload)
            record = dl.record_for(page(), "test", args)
            dl.save_image(client, record, Path(directory), args.max_bytes)
            self.assertEqual((Path(directory) / record["local_file"]).read_bytes(), payload)
            client.get.assert_called_once_with(record["original_url"], stream=True)

    def test_safe_filename(self):
        record = dl.record_for(page(), "test", self.args())
        record["title"] = "File:../../CON\\name: bad.png"
        name = dl.filename(record)
        self.assertNotIn("/", name)
        self.assertNotIn("\\", name)
        self.assertTrue(name.startswith("1-"))

    def test_continuation_and_search_order(self):
        client = dl.Client()
        client.api = Mock(side_effect=[
            {"query": {"pages": [{"pageid": 2, "index": 2}, {"pageid": 1, "index": 1}]},
             "continue": {"continue": "-||", "gsroffset": 20}},
            {"query": {"pages": [{"pageid": 3}]}}])
        pages = list(client.pages({"generator": "search"}, self.args()))
        self.assertEqual([p["pageid"] for p in pages], [1, 2, 3])
        self.assertEqual(client.api.call_args.kwargs["gsroffset"], 20)
        self.assertEqual(client.api.call_args.kwargs["continue"], "-||")
        client.close()

    def test_repeated_continuation_is_rejected(self):
        client = dl.Client()
        client.api = Mock(return_value={"continue": {"x": 1}})
        with self.assertRaises(dl.DownloadError):
            list(client.pages({}, self.args()))
        client.close()

    @patch("commons_image_dl.time.sleep")
    def test_retry_429_and_maxlag(self, sleep):
        client = dl.Client(delay=0)
        client.session.get = Mock(side_effect=[response(status=429, headers={"Retry-After": "2"}),
                                               response(data={"error": {"code": "maxlag"}}),
                                               response(data={"query": {}})])
        self.assertEqual(client.api(), {"query": {}})
        self.assertEqual(client.session.get.call_count, 3)
        self.assertIn(((2.0,), {}), sleep.call_args_list)
        client.close()

    def test_invalid_json_is_actionable(self):
        client = dl.Client(delay=0)
        r = response()
        r.json.side_effect = ValueError("bad json")
        client.get = Mock(return_value=r)
        with self.assertRaisesRegex(dl.DownloadError, "invalid JSON"):
            client.api()
        client.close()

    def png(self):
        buffer = io.BytesIO()
        Image.new("RGB", (4, 4), "red").save(buffer, format="PNG")
        return buffer.getvalue()

    def test_download_sidecar_credit_and_verified_rerun(self):
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory)
            client = Mock()
            client.get.return_value = response(headers={"Content-Type": "image/png"}, payload=self.png())
            record = dl.record_for(page(), "test", self.args())
            dl.save_image(client, record, output, 10000)
            target = output / record["local_file"]
            self.assertTrue(target.exists())
            self.assertIn("Example & Author", target.with_name(target.name + ".txt").read_text())
            dl.save_image(client, record, output, 10000)
            self.assertEqual(record["status"], "existing")
            self.assertEqual(client.get.call_count, 1)
            target.write_bytes(b"corrupt")
            with self.assertRaisesRegex(dl.DownloadError, "integrity"):
                dl.save_image(client, record, output, 10000)

    def test_bad_download_and_oversize_leave_no_files(self):
        for payload, limit in [(b"not an image", 1000), (self.png(), 1)]:
            with self.subTest(limit=limit), tempfile.TemporaryDirectory() as directory:
                output = Path(directory)
                client = Mock()
                client.get.return_value = response(headers={"Content-Type": "image/png"}, payload=payload)
                with self.assertRaises(dl.DownloadError):
                    dl.save_image(client, dl.record_for(page(), "test", self.args()), output, limit)
                self.assertEqual(list(output.iterdir()), [])

    def test_symlink_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory)
            record = dl.record_for(page(), "test", self.args())
            (output / dl.filename(record)).symlink_to(output / "other")
            with self.assertRaisesRegex(dl.DownloadError, "symlink"):
                dl.save_image(Mock(), record, output, 1000)

    def test_dry_run_counts_unique_filtered_results(self):
        with tempfile.TemporaryDirectory() as directory:
            args = self.args("--dry-run", "--count", "2", "--output", directory)
            client = Mock()
            client.discover.return_value = iter([(page(), "a"), (page(), "b"),
                                                 (page(2, "video/webm"), "b"), (page(3), "b")])
            self.assertEqual(dl.run(args, client), 0)
            report = json.loads(next(Path(directory).glob("manifest-*.json")).read_text())
            self.assertEqual(report["examined"], 4)
            self.assertEqual(report["matched"], 2)
            client.get.assert_not_called()

    def test_candidate_cap_partial_exit_and_manifest(self):
        with tempfile.TemporaryDirectory() as directory:
            args = self.args("--dry-run", "--count", "2", "--max-candidates", "1", "--output", directory)
            client = Mock()
            client.discover.return_value = iter([(page(), "a"), (page(2), "a")])
            self.assertEqual(dl.run(args, client), 1)
            self.assertEqual(len(list(Path(directory).glob("manifest-*.json"))), 1)

    def test_magnus_failure_does_not_block(self):
        with tempfile.TemporaryDirectory() as directory:
            args = self.args("--dry-run", "--count", "1", "--magnus", "--output", directory)
            client = Mock()
            client.discover.return_value = iter([(page(), "a")])
            client.enrich.side_effect = dl.DownloadError("offline")
            self.assertEqual(dl.run(args, client), 0)

    def test_sources_use_expected_parameters(self):
        examples = [(["--category", "Cats"], "generator", "categorymembers"),
                    (["--uploader", "Example"], "gaiuser", "Example"),
                    (["--near", "3.1", "101.7"], "ggscoord", "3.1|101.7"),
                    (["--file", "Example.png"], "titles", "File:Example.png")]
        for argv, key, value in examples:
            with self.subTest(argv=argv):
                client = dl.Client()
                client.pages = Mock(return_value=iter([page()]))
                list(client.discover(dl.parser().parse_args(argv)))
                self.assertEqual(client.pages.call_args.args[0][key], value)
                client.close()


if __name__ == "__main__":
    unittest.main()
