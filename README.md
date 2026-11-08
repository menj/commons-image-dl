# commons-image-dl

Developed and coded by **[MENJ](https://github.com/menj)**.

Automatically search and download images from **Wikimedia Commons** using Python. A command selects matching files, downloads them, and saves attribution and source metadata without interactive selection. No API key is needed.

## Install

Requires Python 3.10 or later.

```bash
git clone https://github.com/menj/commons-image-dl.git
cd commons-image-dl
python -m venv .venv
# Linux/macOS:
source .venv/bin/activate
# Windows PowerShell instead:
# .venv\Scripts\Activate.ps1
python -m pip install .
```

Use `commons-image-dl` after installation, or run `python commons_image_dl.py` from this directory. For development use `python -m pip install -e .`.

## WordPress theme support

The Python CLI is retained in full. An additional native PHP adapter can be
bundled with a WordPress theme to search Commons and import full-resolution
originals into the Media Library, preserving descriptions, attribution and
Commons category tags. No Python installation is needed for the PHP adapter.

See [WordPress integration](docs/wordpress-integration.md) for theme setup,
search/import functions, secure admin form wiring and original-file handling.

## Automatically download images

```bash
python commons_image_dl.py --query "Penang heritage buildings" --count 10
python commons_image_dl.py --category "Mosques in Malaysia" --count 20
python commons_image_dl.py --file "Sa-warthog.jpg" --count 1
python commons_image_dl.py --near 3.139 101.687 --radius 2000 --count 5
python commons_image_dl.py --uploader "FlickrLickr" --count 10
python commons_image_dl.py --random --count 5
```

Downloads always use the full-resolution original file supplied by Commons: the largest available size of the current file version. No thumbnails, resizing or format conversion are used. The former `--width` option has been removed; remove it from existing commands.

Search results retain API relevance order. Category mode uses direct members only. Uploader mode means the uploader of the **latest file version**, not necessarily the photographer. Geographic discovery depends on Commons coordinates and may return fewer results than requested.

## Batch searches and filtering

```bash
python commons_image_dl.py --query "Penang mosque" --query "Malacca mosque" --count 20
python commons_image_dl.py --queries-file topics.txt --count 30 --output downloads/topics
python commons_image_dl.py --query "Islamic architecture" --min-width 1600 --min-height 900 --count 10
python commons_image_dl.py --query "Penang" --license "CC BY-SA 4.0" --count 5
python commons_image_dl.py --query "Malaysia" --formats jpeg png webp gif tiff bmp --count 10
python commons_image_dl.py --query "Malaysia" --count 10 --dry-run
```

`topics.txt` contains one query per line; blank lines and lines beginning with `#` are ignored. `--count` is the **total across all queries**, processed in their supplied order. Duplicated file page IDs count once per run. Earlier queries can fill the whole requested count.

Filters use original dimensions and the downloaded file's MIME type. JPEG, PNG and WebP are enabled by default. GIF, TIFF and BMP are optional. SVG files are excluded because this tool validates supported raster originals. Videos, audio and PDFs are excluded. License matching uses the exact `LicenseShortName`, case-insensitively; repeat `--license` to allow multiple values. Missing license metadata is preserved rather than guessed; it will not pass a license filter.

`--max-candidates` defaults to 1,000 and bounds discovery, including rejected and duplicate candidates. There is no default download byte limit, so large originals are not excluded by the former 50 MiB cap. Set `--max-bytes` explicitly if needed; exceeding it fails the download without substituting a thumbnail. Increase the candidate limit when restrictive filters produce too few images. There is no guarantee that Commons has enough matching images.

## Output and repeat runs

The output directory contains:

- Image files, named with a page ID, sanitized title and source variant hash.
- A `.json` sidecar per image containing original/download URLs, author, credit, license, dimensions, raw file-page metadata and a SHA-256 digest.
- A `.txt` sidecar per image containing readable attribution and links.
- A uniquely named `manifest-*.json` per run, recording selected files, failures and whether the requested count was reached.

Repeat runs verify existing image checksums and reuse matching files. Verified existing files count toward `--count`; the flag does not mean that many *new* images on every invocation. Existing files with absent/invalid sidecars or changed contents are reported and left untouched. Remove or move such a file and its sidecars to explicitly redownload it. Run only one process per output directory.

`--dry-run` writes a discovery manifest but no images. Progress goes to stderr; stdout contains a compact JSON summary. Exit codes: `0` requested count reached, `1` incomplete or failed run, `2` invalid CLI arguments, `130` interrupted. Partial results are retained. Interrupted transfers are cleaned up, but a force-killed process can leave `.part` files; these are never treated as complete downloads.

## Metadata and Magnus API

The main implementation uses `https://commons.wikimedia.org/w/api.php` with `imageinfo` and `extmetadata`. Add `--magnus` to save the optional Magnus Commons API JSON response, including requested structured data, in each sidecar. Its failure is recorded without blocking ordinary downloads. `--language en` selects the metadata description language. HTML-bearing raw metadata is preserved as data; do not insert it unescaped into a webpage.

The historic category/license table is reference material, not a complete license database. The tool preserves each file's actual metadata; it does not infer authorship from uploader names, assume all files are public domain, or claim that downloading fulfills every reuse condition. Attribution sidecars are a starting point; consult the linked file page and license for applicable terms, including ShareAlike or full-license-text requirements.

The HTTP client identifies itself, paces requests, sends `maxlag=5`, and retries transient connection failures, HTTP 429/5xx and API maxlag errors. It honors Retry-After; a delay above five minutes aborts the request so it can be rerun later. An interrupted response body is reported as a failed file rather than silently accepted.

## Use in automation

Commands run unattended and can be invoked by a CMS process, cron, or Windows Task Scheduler. Each invocation performs one bounded run; the program does not install a schedule or upload files to a website. Use a distinct output directory for concurrent jobs and an operator-specific `--user-agent` containing a contact URL or email for regular deployments.

## Development

```bash
python -m unittest discover -s tests -v
commons-image-dl --help
```

Tests use mocked HTTP responses and small in-memory images. CI runs them on Python 3.10, 3.13 and 3.14. Live API availability is independent of the offline suite.

The reusable AI build specification is in [docs/build-instructions.yaml](docs/build-instructions.yaml). It describes this Python tool and the acceptance criteria for further work.

## API references

- [Commons MediaWiki API examples](https://commons.wikimedia.org/wiki/Commons:API/MediaWiki)
- [Imageinfo](https://www.mediawiki.org/wiki/API:Imageinfo)
- [Search](https://www.mediawiki.org/wiki/API:Search)
- [API etiquette](https://www.mediawiki.org/wiki/API:Etiquette)
- [Magnus Commons API](https://magnustools.toolforge.org/commonsapi/)
- [Historic Commons API license data](https://commons.wikimedia.org/wiki/Commons:Commons_API)

## License and credits

Copyright (C) 2026 MENJ.

Developed and coded by **MENJ**. This software is licensed under the
**GNU General Public License, version 3 only** (`GPL-3.0-only`).
See [LICENSE](LICENSE) for the full terms.

Downloaded Wikimedia Commons media retain their own licenses and attribution
requirements, as recorded in the accompanying metadata.
