# Pure Blog Importers

A collection of import tools for [Pure Blog](https://github.com/kevquirk/pureblog).

| File | Source | Interface |
|---|---|---|
| [`import_wordpress.php`](#wordpress) | WordPress | Web UI |
| [`cli_import_wordpress.php`](#wordpress) | WordPress | Command line |
| [`import_bearblog.php`](#bearblog) | BearBlog | Web UI |
| [`import_pagecord.php`](#pagecord) | Pagecord | Web UI |

Full usage instructions for each importer are on [pureblog.org](https://pureblog.org/pure-blog-importers).

---

## WordPress

Two versions are available. Both require a WordPress WXR export file (`.xml`), obtained from **Tools → Export → All content** in your WordPress admin.

**Web UI** (`import_wordpress.php`) — drop into your Pure Blog root, visit the URL while logged in, and follow the three-step wizard. Handles posts, pages, tags, images, and SEO descriptions from Yoast/Rank Math/SEOPress/AIOSEO.

**Command line** (`cli_import_wordpress.php`) — useful for fresh installs before Pure Blog is running, or if you prefer the terminal. Supports `--drafts`, `--no-pages`, `--no-images`, `--uploads-dir`, and `--dry-run`.

[Full WordPress importer docs →](https://pureblog.org/wordpress-importer)

---

## BearBlog

Web UI importer for BearBlog Markdown exports. Export your posts from BearBlog, drop the `.md` files into `content/posts/import/`, and run the importer. Posts containing images are saved as drafts by default so you can verify the URLs before publishing.

*Original concept by [David (justdaj)](https://github.com/justdaj).*

[Full BearBlog importer docs →](https://pureblog.org/bear-blog-importer)

---

## Pagecord

Web UI importer for Pagecord Markdown exports. Export your posts from Pagecord, drop the `.md` files into `content/posts/import/`, and run the importer. Shows a preview before importing, flags posts with images as drafts (with an option to force-publish), and optionally deletes source files after a successful import.

*Original concept by [David (justdaj)](https://github.com/justdaj).*

[Full Pagecord importer docs →](https://pureblog.org/pagecord-importer)

---

## Contributing

Pull requests for new importers are welcome. If you've migrated from a platform that isn't listed here and want to help others do the same, please open a PR.
