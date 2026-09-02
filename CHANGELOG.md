# Changelog

## 1.1.1

### Added
- WriteFreely importer (`import_writefreely.php`) — web UI importer for WriteFreely exported CSV files. Drop it in your Pure Blog root, upload or place the `.csv` file in `content/posts/import/`, and visit the URL while logged in. Shows a preview before importing, converts markdown content, preserves dates and slugs, extracts hashtags into tags, flags posts with images as drafts (with an option to force-publish), and optionally deletes source files after a successful import.

## Fixed
- Added classes and wrapped buttons in `p` tags to improve UI.

---

## 1.1.0

### Added
- Pagecord importer (`import_pagecord.php`) — web UI importer for Pagecord exported Markdown files. Drop it in your Pure Blog root, place exported `.md` files in `content/posts/import/`, and visit the URL while logged in. Shows a preview before importing, flags posts with images as drafts (with an option to force-publish), and optionally deletes source files after a successful import. Original script by [David (justdaj)](https://github.com/justdaj).

---

## 1.0.0

### Added
- WordPress web UI importer (`import_wordpress.php`) — three-step wizard (upload WXR → preview → import). Handles posts, pages, tags, images (download or local copy), and SEO descriptions from Yoast/Rank Math/SEOPress/AIOSEO.
- WordPress CLI importer (`cli_import_wordpress.php`) — command-line version for fresh installs or large exports. Supports `--drafts`, `--no-pages`, `--no-images`, `--uploads-dir`, and `--dry-run`.
- BearBlog web UI importer (`import_bearblog.php`) — imports Markdown exports from BearBlog. Preserves title, slug, date, tags, description, and draft/published status. Original script by [David (justdaj)](https://github.com/justdaj).
