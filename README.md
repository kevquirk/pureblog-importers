# Pure Blog Importers

A collection of import tools for [Pure Blog](https://github.com/kevquirk/pureblog).

| File | Source | Interface | Requires running Pure Blog |
|---|---|---|---|
| [`import_wordpress.php`](#wordpress--web-ui-import_wordpressphp) | WordPress | Web UI | Yes |
| [`cli_import_wordpress.php`](#wordpress--command-line-cli_import_wordpressphp) | WordPress | Command line | No |
| [`import_bearblog.php`](#bearblog--web-ui-import_bearblogphp) | BearBlog | Web UI | Yes |

---

## WordPress — Web UI (`import_wordpress.php`)

Copy `import_wordpress.php` to your Pure Blog root, then visit:

```
https://yourblog.com/import_wordpress.php
```

You must be logged in to the Pure Blog admin. The importer walks you through three steps:

1. **Upload** — upload the WXR export file, or enter the path to a file already on your server (useful for large exports that exceed PHP's upload limit)
2. **Preview** — review what will be imported, with a count of posts, pages, drafts, and items containing images
3. **Results** — summary of what was imported, with any image failures logged to `content/wp-import-errors.log`

The search and tag indexes are rebuilt automatically.

### Getting your WordPress export

In WordPress admin: **Tools → Export → All content → Download Export File**. This gives you a `.xml` file (WXR format).

### What gets imported

- Posts and pages
- Categories and tags → merged into Pure Blog tags
- Images → downloaded or copied locally, URLs rewritten in content
- SEO descriptions → from Yoast, Rank Math, SEOPress, or AIOSEO if present
- Post content → Gutenberg markup stripped, HTML converted to Markdown

Drafts are skipped by default.

### Images

The web importer can handle images in two ways:

1. **Path to `wp-content/uploads`** (recommended) — enter the server path to your WP uploads folder and files will be copied directly. Fastest and most reliable.
2. **HTTP download** — if no path is provided, the importer will try to download images from the URLs in the export. Requires those URLs to be publicly accessible.

> **Note:** If your WP site was restored from a backup or migrated to a new domain, the image URLs in the export may still point to the old domain. Use the uploads path option in that case.

---

## WordPress — Command Line (`cli_import_wordpress.php`)

Useful when setting up a fresh Pure Blog install before it's running, or if you prefer the terminal. The search and tag indexes will need to be rebuilt manually afterwards by opening and saving a post in the admin.

### Usage

```
php cli_import_wordpress.php <export.xml> <path-to-pureblog> [options]
```

### Options

| Option | Description |
|---|---|
| `--uploads-dir` | Path to WP's `wp-content/uploads` folder — copies images locally instead of downloading |
| `--drafts` | Also import draft posts (default: published only) |
| `--no-pages` | Skip importing pages |
| `--no-images` | Skip importing images entirely |
| `--dry-run` | Preview what would be imported without writing any files |

### Examples

```
# Basic import (downloads images from the live WP site)
php cli_import_wordpress.php export.xml /var/www/pureblog

# Import using a local uploads folder (recommended)
php cli_import_wordpress.php export.xml /var/www/pureblog --uploads-dir /var/www/wordpress/wp-content/uploads

# Preview without writing anything
php cli_import_wordpress.php export.xml /var/www/pureblog --dry-run

# Include drafts, skip images
php cli_import_wordpress.php export.xml /var/www/pureblog --drafts --no-images
```

---

## BearBlog — Web UI (`import_bearblog.php`)

*Original concept by [David (justdaj)](https://github.com/justdaj).*

Copy `import_bearblog.php` to your Pure Blog root, then visit:

```
https://yourblog.com/import_bearblog.php
```

You must be logged in to the Pure Blog admin.

### Before you start

1. Export your posts from BearBlog in **Markdown** format
2. Create the folder `content/posts/import/` in your Pure Blog install
3. Copy your exported `.md` files into that folder

The importer walks you through three steps:

1. **Instructions** — confirms your files are in the right place
2. **Preview** — shows what will be imported, with warnings for drafts and posts containing images
3. **Results** — summary of what was imported

The search and tag indexes are rebuilt automatically.

### What gets imported

- Posts only (pages are skipped)
- Title, slug, published date, tags, meta description, content
- Draft/published status

### Images

Posts containing images are saved as **draft** by default so you can verify the image URLs are correct before publishing. There is an option on the preview screen to publish them immediately if you're confident the URLs are already right.
