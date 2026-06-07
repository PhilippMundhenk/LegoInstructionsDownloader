# Lego Instructions Downloader

A small self-hosted web app that downloads official LEGO building
instructions and product images by set number and gives you a fast,
searchable grid to browse what you've collected.

It runs as a single container. Sets are stored as plain folders on a
volume you control, so nothing is locked into a database — you can move,
back up, or delete sets with normal file tools.

## Features

- **Download by set number** — paste one or more set IDs (comma
  separated) and the container fetches the PDF instructions, thumbnails,
  and a product image from lego.com.
- **Browse, search, scroll** — responsive card grid with live search by
  number or title. The topbar collapses when you scroll so the search
  stays visible on long pages.
- **Per-set actions menu** — three-dot menu on each card to
  - rename the set (inline edit, saved to `name.txt`)
  - jump to the set's pages on LEGO.com, BrickLink, Brickset,
    Rebrickable, and the LEGO building-instructions site
  - delete the set (with a confirmation) — removes the folder from disk
- **Six-language UI** — English, German, Spanish, French, Hindi,
  Chinese. Auto-detected from your browser's `Accept-Language` and
  overridable from a language switcher in the topbar (persisted via
  cookie).
- **Smart duplicate filtering** — when a set's `data.json` lists the
  same build step across multiple language/region versions, only the
  canonical PDF is shown so you don't see five copies of the same
  booklet.
- **Tail the log** — `View log` link in the footer shows the last 256 KB
  of fetch output so you can see what the scraper is doing.
- **Diagnostic empty state** — if the downloads folder is unreadable,
  missing, or full of unrecognized entries, the empty page tells you
  what it sees instead of just "no sets".

## Quick start

`docker-compose.yml`:

```yaml
services:
  legodownloader:
    image: ghcr.io/philippmundhenk/legoinstructionsdownloader:latest
    ports:
      - "8080:80"
    environment:
      - UID=1000          # uid that owns your downloads volume
      - GID=1000
    volumes:
      - /path/to/your/lego/downloads:/downloads
    restart: unless-stopped
```

Then open <http://localhost:8080>.

### Environment variables

| Variable          | Default            | Purpose                                          |
|-------------------|--------------------|--------------------------------------------------|
| `DOWNLOADS_DIR`   | `/downloads`       | Where sets are stored inside the container       |
| `LEGO_LOG_FILE`   | `/var/log/lego.log`| Path of the fetch log shown by `log.php`         |
| `UID` / `GID`     | —                  | User the web process runs as (must own the mount)|

### URL parameters

- `?lang=en|de|es|fr|hi|zh` — force the UI language; the choice is
  remembered in a cookie.

## On-disk layout

Each downloaded set is one folder named after its set number:

```
/downloads/
  31099/
    data.json              # raw API response (v1 sets)
    name.txt               # human-readable name; wins over data.json
    31099_Prod.jpg         # product image
    6308552.pdf            # building instructions
    6308552.png            # …with thumbnail
  30640/
    name.txt
    30640_Prod.png
    6447079.pdf
    6447079.png
```

Renaming a set just writes `name.txt`; the original `data.json` is
never modified.

## Development

Everything is plain PHP, vanilla JS, and a single CSS file served by
lighttpd — no build step.

Run the test suite:

```bash
php tests/run_tests.php
```

53 tests cover the parser, dedup, rename / delete safety (shell
injection, path traversal), external-link URL shapes, and i18n
(Accept-Language negotiation, catalog completeness, sprintf fallback).

The end-to-end harness boots a real lighttpd + php-cgi against a
fixture downloads directory and curls every endpoint — see
`tests/e2e/`.

CI runs both suites on every push to `main`; the green build also
publishes the Docker image to `ghcr.io/philippmundhenk/legoinstructionsdownloader:latest`.
