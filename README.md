# Pure Blog Importers

A collection of import tools for [Pure Blog](https://github.com/kevquirk/pureblog).

| File | Source | Interface |
|---|---|---|
| [`import_wordpress.php`](#wordpress) | WordPress | Web UI |
| [`cli_import_wordpress.php`](#wordpress) | WordPress | Command line |
| [`import_ghost.php`](#ghost) | Ghost | Web UI |
| [`import_bearblog.php`](#bearblog) | BearBlog | Web UI |
| [`import_pagecord.php`](#pagecord) | Pagecord | Web UI |
| [`import_writefreely.php`](#writefreely) | WriteFreely | Web UI |

Full usage instructions for each importer are on [pureblog.org](https://pureblog.org/pure-blog-importers).

---

## WordPress

Two versions are available. Both require a WordPress WXR export file (`.xml`), obtained from **Tools → Export → All content** in your WordPress admin.

**Web UI** (`import_wordpress.php`) — drop into your Pure Blog root, visit the URL while logged in, and follow the three-step wizard. Handles posts, pages, tags, images, and SEO descriptions from Yoast/Rank Math/SEOPress/AIOSEO.

**Command line** (`cli_import_wordpress.php`) — useful for fresh installs before Pure Blog is running, or if you prefer the terminal. Supports `--drafts`, `--no-pages`, `--no-images`, `--uploads-dir`, and `--dry-run`.

[Full WordPress importer docs →](https://pureblog.org/wordpress-importer)

---

## Ghost

Web UI importer for Ghost JSON exports. Obtained from **Settings → Labs → Export your content** in your Ghost admin.

**Web UI** (`import_ghost.php`) — drop into your Pure Blog root, visit the URL while logged in, and follow the three-step wizard. Handles posts, pages, tags, feature/inline images, and SEO descriptions. Supports relative path resolution, copying local assets, or downloading from the live site.

[Full Ghost importer docs →](https://pureblog.org/ghost-importer)

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

## WriteFreely

Web UI importer for WriteFreely CSV exports. Export your posts from WriteFreely (**Customize → Export data → Posts (.csv)**), drop `import_writefreely.php` into your Pure Blog root, upload or place the CSV in `content/posts/import/`, and run the importer. Preserves titles, slugs, publish dates, markdown formatting, extracts hashtags into tags, and flags posts containing images as drafts (with an option to force-publish).

[Full WriteFreely importer docs →](https://pureblog.org/writefreely-importer)

---

## Contributing

Pull requests for new importers are welcome. If you've migrated from a platform that isn't listed here and want to help others do the same, please open a PR.
