<?php
/**
 * WordPress → Pure Blog Importer v1.0.0
 *
 * Converts a WordPress WXR export file to Pure Blog Markdown files.
 * No external dependencies required — drop this file anywhere and run it.
 *
 * Usage:
 *   php cli_import_wordpress.php <export.xml> <path-to-pureblog> [options]
 *
 * Options:
 *   --uploads-dir  Path to WP's wp-content/uploads folder (copies files instead of downloading)
 *   --drafts       Include draft posts (default: published only)
 *   --no-pages     Skip importing pages
 *   --no-images    Skip importing images entirely
 *   --dry-run      Preview without writing any files
 *
 * Examples:
 *   php cli_import_wordpress.php export.xml /var/www/pureblog --uploads-dir /var/www/wordpress/wp-content/uploads
 *   php cli_import_wordpress.php export.xml /var/www/pureblog --drafts --dry-run
 */

define('IMPORTER_VERSION', '1.0.0');
define('WP_NS',      'http://wordpress.org/export/1.2/');
define('CONTENT_NS', 'http://purl.org/rss/1.0/modules/content/');
define('EXCERPT_NS', 'http://wordpress.org/export/1.2/excerpt/');

// ─── Entry ────────────────────────────────────────────────────────────────────

if (PHP_SAPI !== 'cli') {
    die("This script must be run from the command line.\n");
}

$args       = array_slice($argv, 1);
$flags      = array_filter($args, fn($a) => str_starts_with($a, '--'));
$positional = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));

if (in_array('--help', $flags) || count($positional) < 2) {
    print_usage();
    exit(0);
}

[$wxr_file, $pb_path] = [$positional[0], rtrim($positional[1], '/')];

// Parse --uploads-dir=/path or --uploads-dir /path
$uploads_dir = null;
foreach ($flags as $flag) {
    if (str_starts_with($flag, '--uploads-dir=')) {
        $uploads_dir = rtrim(substr($flag, strlen('--uploads-dir=')), '/');
    }
}
// Also handle --uploads-dir as a separate positional-style argument
if ($uploads_dir === null && in_array('--uploads-dir', $flags)) {
    $idx = array_search('--uploads-dir', $args);
    if ($idx !== false && isset($args[$idx + 1]) && !str_starts_with($args[$idx + 1], '--')) {
        $uploads_dir = rtrim($args[$idx + 1], '/');
    }
}

if ($uploads_dir !== null && !is_dir($uploads_dir)) {
    die("Error: uploads directory not found: $uploads_dir\n");
}

$opts = [
    'drafts'       => in_array('--drafts',    $flags),
    'skip_pages'   => in_array('--no-pages',  $flags),
    'skip_images'  => in_array('--no-images', $flags),
    'dry_run'      => in_array('--dry-run',   $flags),
    'uploads_dir'  => $uploads_dir,
];

if (!file_exists($wxr_file)) {
    die("Error: WXR file not found: $wxr_file\n");
}

if (!$opts['dry_run'] && !is_dir("$pb_path/content/posts")) {
    die("Error: Pure Blog directory not found at: $pb_path\n(Expected: $pb_path/content/posts/)\n");
}

run($wxr_file, $pb_path, $opts);


// ─── Main ─────────────────────────────────────────────────────────────────────

function run(string $wxr_file, string $pb_path, array $opts): void
{
    echo "WordPress → Pure Blog Importer v" . IMPORTER_VERSION . "\n";
    echo str_repeat('─', 50) . "\n\n";

    if ($opts['dry_run']) {
        echo "[DRY RUN — no files will be written]\n\n";
    }

    echo "Parsing: $wxr_file\n\n";

    $xml = simplexml_load_file($wxr_file);
    if (!$xml) {
        die("Error: Could not parse WXR file. Is it a valid WordPress export?\n");
    }

    $posts_dir  = "$pb_path/content/posts";
    $pages_dir  = "$pb_path/content/pages";
    $images_dir = "$pb_path/content/images";

    $stats = [
        'posts'             => 0,
        'pages'             => 0,
        'images_downloaded' => 0,
        'images_failed'     => 0,
        'skipped'           => 0,
        'image_errors'      => [],  // ['slug' => ..., 'url' => ...]
    ];

    foreach ($xml->channel->item as $item) {
        $wp   = $item->children(WP_NS);
        $type = (string) $wp->post_type;

        if (!in_array($type, ['post', 'page'])) {
            continue;
        }

        if ($type === 'page' && $opts['skip_pages']) {
            continue;
        }

        $status    = (string) $wp->status;
        $pb_status = ($status === 'publish') ? 'published' : 'draft';

        if ($status !== 'publish' && !$opts['drafts']) {
            $stats['skipped']++;
            continue;
        }

        // Core fields
        $title = html_entity_decode((string) $item->title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $slug  = trim((string) $wp->post_name);
        $date  = (string) $wp->post_date;

        if (empty($slug)) {
            $slug = slugify($title);
        }

        try {
            $date_obj    = new DateTimeImmutable($date);
            $datetime    = $date_obj->format('Y-m-d H:i');
            $date_prefix = $date_obj->format('Y-m-d');
        } catch (\Exception $e) {
            $datetime    = date('Y-m-d H:i');
            $date_prefix = date('Y-m-d');
        }

        // Raw HTML content from WordPress
        $content_xml = $item->children(CONTENT_NS);
        $raw_html    = (string) $content_xml->encoded;

        // Description: excerpt → SEO meta → empty
        $excerpt_xml = $item->children(EXCERPT_NS);
        $description = trim((string) $excerpt_xml->encoded);
        if (empty($description)) {
            $description = get_seo_description($item);
        }

        // Tags: WP categories + WP tags → Pure Blog tags
        $tags = get_tags($item);

        // Convert content: download images, strip Gutenberg markup, HTML → Markdown
        $markdown = process_content($raw_html, $slug, $images_dir, $opts, $stats);

        // Build file
        $frontmatter  = build_frontmatter($title, $slug, $datetime, $pb_status, $tags, $description);
        $file_content = "---\n{$frontmatter}---\n\n{$markdown}";

        if ($type === 'post') {
            $filename    = "{$date_prefix}-{$slug}.md";
            $output_path = "{$posts_dir}/{$filename}";
            $stats['posts']++;
        } else {
            $filename    = "{$slug}.md";
            $output_path = "{$pages_dir}/{$filename}";
            $stats['pages']++;
        }

        if (!$opts['dry_run']) {
            file_put_contents($output_path, $file_content);
        }

        $label = $opts['dry_run'] ? '[dry-run] ' : '✓ ';
        echo "  {$label}{$type}: {$filename}\n";
    }

    // Summary
    echo "\n" . str_repeat('─', 50) . "\n";
    echo "Import complete!\n\n";
    printf("  Posts imported:    %d\n",  $stats['posts']);
    printf("  Pages imported:    %d\n",  $stats['pages']);
    printf("  Images downloaded: %d\n",  $stats['images_downloaded']);

    if ($stats['images_failed'] > 0) {
        printf("  Images failed:     %d\n", $stats['images_failed']);
    }
    if ($stats['skipped'] > 0) {
        printf("  Skipped (drafts):  %d\n", $stats['skipped']);
    }

    // Write image error log if anything failed
    if (!$opts['dry_run'] && !empty($stats['image_errors'])) {
        $log_path = $pb_path . '/content/import-errors.log';
        $lines    = ['WordPress Importer — image errors (' . date('Y-m-d H:i:s') . ')', str_repeat('-', 60)];
        foreach ($stats['image_errors'] as $err) {
            $lines[] = "Post: {$err['slug']}";
            $lines[] = "  URL: {$err['url']}";
        }
        file_put_contents($log_path, implode("\n", $lines) . "\n");
        printf("  Error log:         %s\n", $log_path);
    }

    if (!$opts['dry_run'] && ($stats['posts'] + $stats['pages']) > 0) {
        echo "\nNext step: log in to Pure Blog admin to rebuild the search and tag indexes.\n";
    }
}


// ─── Content Processing ───────────────────────────────────────────────────────

function process_content(string $html, string $slug, string $images_dir, array $opts, array &$stats): string
{
    // Strip Gutenberg block comments: <!-- wp:image {...} --> and <!-- /wp:image -->
    $html = preg_replace('/<!--\s*wp:[^>]*-->/s', '', $html);
    $html = preg_replace('/<!--\s*\/wp:[^>]*-->/s', '', $html);
    $html = trim($html);

    if (empty($html)) {
        return '';
    }

    // Download/copy images and rewrite their URLs before converting to Markdown
    if (!$opts['skip_images']) {
        $html = download_and_rewrite_images($html, $slug, $images_dir, $opts['dry_run'], $opts['uploads_dir'], $stats);
    }

    return html_to_markdown($html);
}


function download_and_rewrite_images(string $html, string $slug, string $images_dir, bool $dry_run, ?string $uploads_dir, array &$stats): string
{
    return preg_replace_callback(
        '/(<img\b[^>]*?\bsrc=)["\']([^"\']+)["\']([^>]*>)/i',
        function ($m) use ($slug, $images_dir, $dry_run, $uploads_dir, &$stats) {
            $url = $m[2];

            // Skip data URIs and empty srcs
            if (empty($url) || str_starts_with($url, 'data:')) {
                return $m[0];
            }

            $url_path = parse_url($url, PHP_URL_PATH) ?? '';
            $filename = basename($url_path);

            if (empty($filename)) {
                return $m[0];
            }

            $local_dir  = "{$images_dir}/{$slug}";
            $local_path = "{$local_dir}/{$filename}";
            $web_path   = "/content/images/{$slug}/{$filename}";

            if (!$dry_run) {
                if (!is_dir($local_dir)) {
                    mkdir($local_dir, 0755, true);
                }

                if (!file_exists($local_path)) {
                    $ok = false;

                    if ($uploads_dir !== null) {
                        // Local copy: strip /wp-content/uploads/ prefix from URL path
                        $relative = preg_replace('#^.*?/wp-content/uploads/#', '', $url_path);
                        $source   = "{$uploads_dir}/{$relative}";
                        $ok       = file_exists($source) && copy($source, $local_path);
                    } else {
                        // HTTP download
                        $ctx  = stream_context_create(['http' => ['user_agent' => 'PureBlog-WP-Importer/' . IMPORTER_VERSION]]);
                        $data = @file_get_contents($url, false, $ctx);
                        if ($data !== false) {
                            file_put_contents($local_path, $data);
                            $ok = true;
                        }
                    }

                    if (!$ok) {
                        $stats['images_failed']++;
                        $stats['image_errors'][] = ['slug' => $slug, 'url' => $url];
                        return $m[0]; // keep original URL on failure
                    }
                }
            }

            $stats['images_downloaded']++;
            return $m[1] . '"' . $web_path . '"' . $m[3];
        },
        $html
    );
}


function html_to_markdown(string $html): string
{
    if (empty(trim($html))) {
        return '';
    }

    $dom = new DOMDocument('1.0', 'UTF-8');
    @$dom->loadHTML(
        '<?xml encoding="UTF-8"><div id="__pb_root__">' . $html . '</div>',
        LIBXML_NOERROR | LIBXML_NOWARNING
    );

    $root = $dom->getElementById('__pb_root__');
    if (!$root) {
        return $html; // fallback: return raw HTML (Pure Blog supports it)
    }

    $md = convert_children($root);

    // Clean up excessive blank lines
    $md = preg_replace('/\n{3,}/', "\n\n", $md);

    return trim($md) . "\n";
}


function convert_children(DOMNode $node): string
{
    $out = '';
    foreach ($node->childNodes as $child) {
        $out .= convert_node($child);
    }
    return $out;
}


function convert_node(DOMNode $node): string
{
    // Text node
    if ($node->nodeType === XML_TEXT_NODE) {
        // Normalise non-breaking spaces
        return str_replace("\u{00A0}", ' ', $node->textContent);
    }

    if ($node->nodeType !== XML_ELEMENT_NODE) {
        return '';
    }

    $tag = strtolower($node->nodeName);

    switch ($tag) {

        // ── Block containers ──────────────────────────────────────────────
        case 'div': case 'section': case 'article': case 'main': case 'aside':
            return convert_children($node) . "\n";

        // ── Paragraphs ────────────────────────────────────────────────────
        case 'p':
            $inner = trim(convert_children($node));
            return $inner === '' ? '' : $inner . "\n\n";

        // ── Headings ──────────────────────────────────────────────────────
        case 'h1': return '# '      . trim(convert_children($node)) . "\n\n";
        case 'h2': return '## '     . trim(convert_children($node)) . "\n\n";
        case 'h3': return '### '    . trim(convert_children($node)) . "\n\n";
        case 'h4': return '#### '   . trim(convert_children($node)) . "\n\n";
        case 'h5': return '##### '  . trim(convert_children($node)) . "\n\n";
        case 'h6': return '###### ' . trim(convert_children($node)) . "\n\n";

        // ── Inline formatting ─────────────────────────────────────────────
        case 'strong': case 'b':
            $inner = trim(convert_children($node));
            return $inner === '' ? '' : "**{$inner}**";

        case 'em': case 'i':
            $inner = trim(convert_children($node));
            return $inner === '' ? '' : "*{$inner}*";

        case 's': case 'del': case 'strike':
            $inner = trim(convert_children($node));
            return $inner === '' ? '' : "~~{$inner}~~";

        case 'span':
            return convert_children($node);

        // ── Links ─────────────────────────────────────────────────────────
        case 'a':
            $href  = $node->getAttribute('href');
            $inner = trim(convert_children($node));
            if (empty($href))  return $inner;
            if (empty($inner)) return "<{$href}>";
            return "[{$inner}]({$href})";

        // ── Images ───────────────────────────────────────────────────────
        case 'img':
            $src = $node->getAttribute('src');
            $alt = $node->getAttribute('alt') ?? '';
            return "![{$alt}]({$src})";

        // ── Line breaks ───────────────────────────────────────────────────
        case 'br':
            return "  \n";

        case 'hr':
            return "\n---\n\n";

        // ── Blockquote ────────────────────────────────────────────────────
        case 'blockquote':
            $inner = trim(convert_children($node));
            if ($inner === '') return '';
            $lines = explode("\n", $inner);
            return implode("\n", array_map(fn($l) => '> ' . $l, $lines)) . "\n\n";

        // ── Code ──────────────────────────────────────────────────────────
        case 'pre':
            $code = '';
            $lang = '';
            foreach ($node->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE && strtolower($child->nodeName) === 'code') {
                    $code = $child->textContent;
                    $class = $child->getAttribute('class') ?? '';
                    if (preg_match('/language-(\S+)/', $class, $match)) {
                        $lang = $match[1];
                    }
                    break;
                }
            }
            if (empty($code)) {
                $code = $node->textContent;
            }
            return "```{$lang}\n{$code}\n```\n\n";

        case 'code':
            // Block code handled by `pre` above
            if ($node->parentNode && strtolower($node->parentNode->nodeName) === 'pre') {
                return $node->textContent;
            }
            return '`' . $node->textContent . '`';

        // ── Lists ─────────────────────────────────────────────────────────
        case 'ul':
            return convert_list($node, false) . "\n";

        case 'ol':
            return convert_list($node, true) . "\n";

        case 'li':
            return trim(convert_children($node)); // handled inside convert_list

        // ── Figures ───────────────────────────────────────────────────────
        case 'figure':
            return convert_figure($node) . "\n\n";

        case 'figcaption':
            return ''; // handled by convert_figure

        // ── HTML elements Pure Blog supports natively ─────────────────────
        case 'mark':
            return '<mark>' . convert_children($node) . '</mark>';

        case 'kbd':
            return '<kbd>' . htmlspecialchars($node->textContent, ENT_QUOTES, 'UTF-8') . '</kbd>';

        case 'sup':
            return '<sup>' . $node->textContent . '</sup>';

        case 'sub':
            return '<sub>' . $node->textContent . '</sub>';

        case 'abbr':
            $title = $node->getAttribute('title');
            $inner = convert_children($node);
            return $title ? "<abbr title=\"{$title}\">{$inner}</abbr>" : $inner;

        // ── Pass through as HTML (tables, details, etc.) ──────────────────
        case 'table': case 'thead': case 'tbody': case 'tfoot':
        case 'tr': case 'th': case 'td': case 'caption':
        case 'details': case 'summary':
            return node_outer_html($node) . "\n\n";

        default:
            // Unknown element — output as raw HTML; Pure Blog renders it fine
            return node_outer_html($node) . "\n\n";
    }
}


function convert_list(DOMNode $node, bool $ordered, int $depth = 0): string
{
    $result  = '';
    $counter = 1;
    $indent  = str_repeat('  ', $depth);

    foreach ($node->childNodes as $child) {
        if ($child->nodeType !== XML_ELEMENT_NODE || strtolower($child->nodeName) !== 'li') {
            continue;
        }

        $inline = '';
        $nested = '';

        foreach ($child->childNodes as $li_child) {
            $child_tag = strtolower($li_child->nodeName ?? '');
            if ($li_child->nodeType === XML_ELEMENT_NODE && in_array($child_tag, ['ul', 'ol'])) {
                $nested .= "\n" . convert_list($li_child, $child_tag === 'ol', $depth + 1);
            } else {
                $inline .= convert_node($li_child);
            }
        }

        $prefix  = $ordered ? "{$indent}{$counter}. " : "{$indent}- ";
        $result .= $prefix . trim($inline) . $nested . "\n";
        $counter++;
    }

    return rtrim($result);
}


function convert_figure(DOMNode $node): string
{
    $img_md  = '';
    $caption = '';

    foreach ($node->childNodes as $child) {
        if ($child->nodeType !== XML_ELEMENT_NODE) continue;

        $child_tag = strtolower($child->nodeName);

        if ($child_tag === 'img') {
            $src    = $child->getAttribute('src');
            $alt    = $child->getAttribute('alt') ?? '';
            $img_md = "![{$alt}]({$src})";

        } elseif ($child_tag === 'figcaption') {
            $caption = trim($child->textContent);

        } elseif ($child_tag === 'a') {
            // Linked image (e.g. lightbox)
            $href = $child->getAttribute('href');
            foreach ($child->childNodes as $a_child) {
                if ($a_child->nodeType === XML_ELEMENT_NODE && strtolower($a_child->nodeName) === 'img') {
                    $src    = $a_child->getAttribute('src');
                    $alt    = $a_child->getAttribute('alt') ?? '';
                    $img_md = "[![{$alt}]({$src})]({$href})";
                    break;
                }
            }
        }
    }

    if ($img_md === '') {
        return node_outer_html($node);
    }

    return $img_md . ($caption !== '' ? "\n*{$caption}*" : '');
}


function node_outer_html(DOMNode $node): string
{
    return $node->ownerDocument->saveHTML($node);
}


// ─── Field Extraction Helpers ─────────────────────────────────────────────────

/**
 * Try common SEO plugin meta keys for a post description.
 */
function get_seo_description(\SimpleXMLElement $item): string
{
    static $seo_keys = [
        '_yoast_wpseo_metadesc',
        '_rank_math_description',
        '_seopress_titles_desc',
        '_aioseo_description',
    ];

    $wp = $item->children(WP_NS);

    foreach ($wp->postmeta as $meta) {
        $meta_wp = $meta->children(WP_NS);
        $key     = (string) $meta_wp->meta_key;
        $val     = trim((string) $meta_wp->meta_value);

        if (in_array($key, $seo_keys) && !empty($val)) {
            return $val;
        }
    }

    return '';
}


/**
 * Collect WordPress categories and tags into a single flat tag list.
 */
function get_tags(\SimpleXMLElement $item): array
{
    $tags = [];

    foreach ($item->category as $cat) {
        $domain   = (string) $cat['domain'];
        $nicename = (string) $cat['nicename'];

        if (in_array($domain, ['category', 'post_tag']) && !empty($nicename)) {
            if (!in_array($nicename, $tags)) {
                $tags[] = $nicename;
            }
        }
    }

    return $tags;
}


/**
 * Build Pure Blog frontmatter string from post fields.
 */
function build_frontmatter(
    string $title,
    string $slug,
    string $date,
    string $status,
    array  $tags,
    string $description
): string {
    // Quote title if it contains YAML special characters
    $needs_quotes = preg_match('/[:#\[\]{}|>&*!,]/', $title) || str_contains($title, "'") || str_contains($title, '"');
    $title_safe   = $needs_quotes
        ? '"' . str_replace('"', '\\"', $title) . '"'
        : $title;

    $fm  = "title: {$title_safe}\n";
    $fm .= "slug: {$slug}\n";
    $fm .= "date: {$date}\n";
    $fm .= "status: {$status}\n";

    if (!empty($tags)) {
        $fm .= 'tags: [' . implode(', ', $tags) . "]\n";
    }

    if (!empty($description)) {
        $fm .= 'description: "' . str_replace('"', '\\"', $description) . "\"\n";
    }

    return $fm;
}


/**
 * Convert a string to a URL-friendly slug.
 */
function slugify(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^a-z0-9\s\-]/', '', $text);
    $text = preg_replace('/[\s\-]+/', '-', trim($text));
    return trim($text, '-') ?: 'untitled';
}


function print_usage(): void
{
    echo <<<USAGE
    WordPress → Pure Blog Importer v1.0.0

    Usage:
      php cli_import_wordpress.php <export.xml> <path-to-pureblog> [options]

    Options:
      --uploads-dir  Path to WP's wp-content/uploads folder (copies files instead of downloading)
      --drafts       Include draft posts (default: published only)
      --no-pages     Skip importing pages
      --no-images    Skip importing images entirely
      --dry-run      Preview without writing any files

    Examples:
      php cli_import_wordpress.php export.xml /var/www/pureblog --uploads-dir /var/www/wordpress/wp-content/uploads
      php cli_import_wordpress.php export.xml /var/www/pureblog --drafts --dry-run

    USAGE;
}
