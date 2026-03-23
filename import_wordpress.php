<?php
/**
 * WordPress → Pure Blog Importer (Web UI) v1.0.0
 *
 * Place this file in your Pure Blog root directory, then visit:
 *   https://yourblog.com/import_wordpress.php
 *
 * You must be logged in to the Pure Blog admin to use it.
 */

declare(strict_types=1);

@ini_set('max_execution_time', '300');
@ini_set('memory_limit', '256M');

define('WP_IMPORTER_VERSION', '1.0.0');
define('WP_NS',      'http://wordpress.org/export/1.2/');
define('CONTENT_NS', 'http://purl.org/rss/1.0/modules/content/');
define('EXCERPT_NS', 'http://wordpress.org/export/1.2/excerpt/');

require __DIR__ . '/functions.php';
require_setup_redirect();
start_admin_session();
require_admin_login();

$config    = load_config();
$fontStack = font_stack_css($config['theme']['admin_font_stack'] ?? 'sans');
$adminTitle = 'Import from WordPress';

$action = $_POST['action'] ?? '';
$error  = '';
$preview = null;
$results = null;

// ─── Action: Preview ──────────────────────────────────────────────────────────

if ($action === 'preview') {
    verify_csrf();

    try {
        $wxr_path = resolve_wxr_path();
    } catch (\RuntimeException $e) {
        $error    = $e->getMessage();
        $wxr_path = null;
    }

    if ($wxr_path === null && $error === '') {
        $error = 'No WXR file provided, or the file could not be found.';
    } elseif ($wxr_path !== null) {
        $preview = parse_wxr_preview($wxr_path);
        if ($preview === null) {
            $error = 'Could not parse the WXR file. Please check it is a valid WordPress export.';
            @unlink_temp($wxr_path);
        }
    }
}

// ─── Action: Cleanup ─────────────────────────────────────────────────────────

if ($action === 'cleanup') {
    verify_csrf();
    @unlink(__FILE__);
    header('Location: ' . base_path() . '/admin/dashboard.php');
    exit;
}

// ─── Action: Import ───────────────────────────────────────────────────────────

if ($action === 'go') {
    verify_csrf();

    $wxr_path    = validate_temp_path($_POST['wxr_path'] ?? '');
    $uploads_dir = rtrim(trim($_POST['uploads_dir'] ?? ''), '/');

    if ($wxr_path === null || !file_exists($wxr_path)) {
        $error = 'WXR file not found. Please start again.';
    } else {
        $opts = [
            'drafts'      => !empty($_POST['drafts']),
            'skip_pages'  => !empty($_POST['skip_pages']),
            'skip_images' => !empty($_POST['skip_images']),
            'uploads_dir' => ($uploads_dir !== '' && is_dir($uploads_dir)) ? $uploads_dir : null,
        ];

        $results = do_import($wxr_path, $opts);
        unlink_temp($wxr_path);
    }
}

// ─── Helpers for file handling ────────────────────────────────────────────────

function resolve_wxr_path(): ?string
{
    // Prefer uploaded file
    $upload_error = $_FILES['wxr_file']['error'] ?? UPLOAD_ERR_NO_FILE;

    if ($upload_error === UPLOAD_ERR_INI_SIZE || $upload_error === UPLOAD_ERR_FORM_SIZE) {
        $limit = ini_get('upload_max_filesize');
        throw new \RuntimeException("The file exceeds the server's upload limit ({$limit}). Enter the file path directly in the field below instead.");
    }

    if ($upload_error === UPLOAD_ERR_OK && !empty($_FILES['wxr_file']['tmp_name'])) {
        $dest = sys_get_temp_dir() . '/pb_wp_' . bin2hex(random_bytes(8)) . '.xml';
        if (move_uploaded_file($_FILES['wxr_file']['tmp_name'], $dest)) {
            return $dest;
        }
    }

    // Fall back to server path
    $path = trim($_POST['wxr_path'] ?? '');
    if ($path !== '' && file_exists($path) && is_readable($path)) {
        return $path;
    }

    return null;
}

function validate_temp_path(string $path): ?string
{
    // Only allow paths in the system temp dir (uploaded files) or absolute paths
    // that actually exist, to prevent path traversal abuse.
    if ($path === '') return null;
    $real = realpath($path);
    if ($real === false) return null;
    return $real;
}

function unlink_temp(string $path): void
{
    // Only delete files we uploaded to the temp dir — don't delete server files the user pointed us to
    if (str_starts_with($path, sys_get_temp_dir())) {
        @unlink($path);
    }
}


// ─── WXR Parsing (Preview) ────────────────────────────────────────────────────

function parse_wxr_preview(string $wxr_path): ?array
{
    $xml = @simplexml_load_file($wxr_path);
    if (!$xml) return null;

    $posts = [];
    $pages = [];

    foreach ($xml->channel->item as $item) {
        $wp     = $item->children(WP_NS);
        $type   = (string) $wp->post_type;
        $status = (string) $wp->status;

        if (!in_array($type, ['post', 'page'])) continue;

        $title       = html_entity_decode((string) $item->title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $slug        = trim((string) $wp->post_name) ?: wp_slugify($title);
        $date        = (string) $wp->post_date;
        $pb_status   = ($status === 'publish') ? 'published' : 'draft';

        $content_xml = $item->children(CONTENT_NS);
        $raw_html    = (string) $content_xml->encoded;
        $has_images  = (bool) preg_match('/<img\b/i', $raw_html);

        $entry = [
            'title'      => $title,
            'slug'       => $slug,
            'date'       => date('Y-m-d', strtotime($date)),
            'status'     => $pb_status,
            'has_images' => $has_images,
        ];

        if ($type === 'post') {
            $posts[] = $entry;
        } else {
            $pages[] = $entry;
        }
    }

    return ['posts' => $posts, 'pages' => $pages, 'wxr_path' => $wxr_path];
}


// ─── Import ───────────────────────────────────────────────────────────────────

function do_import(string $wxr_path, array $opts): array
{
    $xml = @simplexml_load_file($wxr_path);
    if (!$xml) {
        return ['error' => 'Could not parse WXR file.'];
    }

    $images_dir  = PUREBLOG_CONTENT_IMAGES_PATH;
    $imported    = [];
    $skipped     = 0;
    $image_errors = [];

    foreach ($xml->channel->item as $item) {
        $wp   = $item->children(WP_NS);
        $type = (string) $wp->post_type;

        if (!in_array($type, ['post', 'page'])) continue;
        if ($type === 'page' && $opts['skip_pages'])  continue;

        $status    = (string) $wp->status;
        $pb_status = ($status === 'publish') ? 'published' : 'draft';

        if ($status !== 'publish' && !$opts['drafts']) {
            $skipped++;
            continue;
        }

        $title = html_entity_decode((string) $item->title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $slug  = trim((string) $wp->post_name) ?: wp_slugify($title);
        $date  = (string) $wp->post_date;

        try {
            $datetime = (new DateTimeImmutable($date))->format('Y-m-d H:i');
        } catch (\Exception $e) {
            $datetime = date('Y-m-d H:i');
        }

        $content_xml = $item->children(CONTENT_NS);
        $raw_html    = (string) $content_xml->encoded;

        $excerpt_xml = $item->children(EXCERPT_NS);
        $description = trim((string) $excerpt_xml->encoded);
        if (empty($description)) {
            $description = wp_get_seo_description($item);
        }

        $tags     = wp_get_tags($item);
        $markdown = wp_process_content($raw_html, $slug, $images_dir, $opts, $image_errors);

        $post = [
            'title'       => $title,
            'slug'        => $slug,
            'date'        => $datetime,
            'status'      => $pb_status,
            'tags'        => $tags,
            'description' => $description,
            'content'     => $markdown,
        ];

        $saveError = null;
        $ok = ($type === 'page')
            ? save_page($post, null, null, $saveError)
            : save_post($post, null, null, null, $saveError);

        $imported[] = [
            'title'  => $title,
            'type'   => $type,
            'status' => $pb_status,
            'ok'     => $ok,
            'error'  => $saveError,
        ];
    }

    // Write image error log if needed
    $log_path = null;
    if (!empty($image_errors)) {
        $log_path = PUREBLOG_BASE_PATH . '/content/wp-import-errors.log';
        $lines    = ['WordPress Importer — image errors (' . date('Y-m-d H:i:s') . ')', str_repeat('-', 60)];
        foreach ($image_errors as $err) {
            $lines[] = "Post:  {$err['slug']}";
            $lines[] = "  URL: {$err['url']}";
        }
        file_put_contents($log_path, implode("\n", $lines) . "\n");
    }

    return [
        'imported'     => $imported,
        'skipped'      => $skipped,
        'image_errors' => $image_errors,
        'log_path'     => $log_path,
    ];
}


// ─── Content Processing ───────────────────────────────────────────────────────

function wp_process_content(string $html, string $slug, string $images_dir, array $opts, array &$image_errors): string
{
    $html = preg_replace('/<!--\s*wp:[^>]*-->/s', '', $html);
    $html = preg_replace('/<!--\s*\/wp:[^>]*-->/s', '', $html);
    $html = trim($html);

    if (empty($html)) return '';

    if (!$opts['skip_images']) {
        $html = wp_handle_images($html, $slug, $images_dir, $opts['uploads_dir'], $image_errors);
    }

    return wp_html_to_markdown($html);
}


function wp_handle_images(string $html, string $slug, string $images_dir, ?string $uploads_dir, array &$image_errors): string
{
    return preg_replace_callback(
        '/(<img\b[^>]*?\bsrc=)["\']([^"\']+)["\']([^>]*>)/i',
        function ($m) use ($slug, $images_dir, $uploads_dir, &$image_errors) {
            $url      = $m[2];
            $url_path = parse_url($url, PHP_URL_PATH) ?? '';
            $filename = basename($url_path);

            if (empty($url) || str_starts_with($url, 'data:') || empty($filename)) {
                return $m[0];
            }

            $local_dir  = rtrim($images_dir, '/') . '/' . $slug;
            $local_path = $local_dir . '/' . $filename;
            $web_path   = '/content/images/' . $slug . '/' . $filename;

            if (!is_dir($local_dir)) {
                mkdir($local_dir, 0755, true);
            }

            if (!file_exists($local_path)) {
                $ok = false;

                if ($uploads_dir !== null) {
                    $relative = preg_replace('#^.*?/wp-content/uploads/#', '', $url_path);
                    $source   = rtrim($uploads_dir, '/') . '/' . $relative;
                    $ok       = file_exists($source) && copy($source, $local_path);
                } else {
                    $ctx  = stream_context_create(['http' => ['user_agent' => 'PureBlog-WP-Importer/' . WP_IMPORTER_VERSION]]);
                    $data = @file_get_contents($url, false, $ctx);
                    if ($data !== false) {
                        file_put_contents($local_path, $data);
                        $ok = true;
                    }
                }

                if (!$ok) {
                    $image_errors[] = ['slug' => $slug, 'url' => $url];
                    return $m[0];
                }
            }

            return $m[1] . '"' . $web_path . '"' . $m[3];
        },
        $html
    );
}


// ─── HTML → Markdown ──────────────────────────────────────────────────────────

function wp_html_to_markdown(string $html): string
{
    if (empty(trim($html))) return '';

    $dom = new DOMDocument('1.0', 'UTF-8');
    @$dom->loadHTML(
        '<?xml encoding="UTF-8"><div id="__pb_root__">' . $html . '</div>',
        LIBXML_NOERROR | LIBXML_NOWARNING
    );

    $root = $dom->getElementById('__pb_root__');
    if (!$root) return $html;

    $md = wp_convert_children($root);
    $md = preg_replace('/\n{3,}/', "\n\n", $md);
    return trim($md) . "\n";
}

function wp_convert_children(DOMNode $node): string
{
    $out = '';
    foreach ($node->childNodes as $child) {
        $out .= wp_convert_node($child);
    }
    return $out;
}

function wp_convert_node(DOMNode $node): string
{
    if ($node->nodeType === XML_TEXT_NODE) {
        return str_replace("\u{00A0}", ' ', $node->textContent);
    }
    if ($node->nodeType !== XML_ELEMENT_NODE) return '';

    $tag = strtolower($node->nodeName);

    switch ($tag) {
        case 'div': case 'section': case 'article': case 'main': case 'aside':
            return wp_convert_children($node) . "\n";
        case 'p':
            $inner = trim(wp_convert_children($node));
            return $inner === '' ? '' : $inner . "\n\n";
        case 'h1': return '# '      . trim(wp_convert_children($node)) . "\n\n";
        case 'h2': return '## '     . trim(wp_convert_children($node)) . "\n\n";
        case 'h3': return '### '    . trim(wp_convert_children($node)) . "\n\n";
        case 'h4': return '#### '   . trim(wp_convert_children($node)) . "\n\n";
        case 'h5': return '##### '  . trim(wp_convert_children($node)) . "\n\n";
        case 'h6': return '###### ' . trim(wp_convert_children($node)) . "\n\n";
        case 'strong': case 'b':
            $inner = trim(wp_convert_children($node));
            return $inner === '' ? '' : "**{$inner}**";
        case 'em': case 'i':
            $inner = trim(wp_convert_children($node));
            return $inner === '' ? '' : "*{$inner}*";
        case 's': case 'del': case 'strike':
            $inner = trim(wp_convert_children($node));
            return $inner === '' ? '' : "~~{$inner}~~";
        case 'span':
            return wp_convert_children($node);
        case 'a':
            $href  = $node->getAttribute('href');
            $inner = trim(wp_convert_children($node));
            if (empty($href))  return $inner;
            if (empty($inner)) return "<{$href}>";
            return "[{$inner}]({$href})";
        case 'img':
            $src = $node->getAttribute('src');
            $alt = $node->getAttribute('alt') ?? '';
            return "![{$alt}]({$src})";
        case 'br':  return "  \n";
        case 'hr':  return "\n---\n\n";
        case 'blockquote':
            $inner = trim(wp_convert_children($node));
            if ($inner === '') return '';
            return implode("\n", array_map(fn($l) => '> ' . $l, explode("\n", $inner))) . "\n\n";
        case 'pre':
            $code = '';
            $lang = '';
            foreach ($node->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE && strtolower($child->nodeName) === 'code') {
                    $code = $child->textContent;
                    if (preg_match('/language-(\S+)/', $child->getAttribute('class') ?? '', $m)) {
                        $lang = $m[1];
                    }
                    break;
                }
            }
            if (empty($code)) $code = $node->textContent;
            return "```{$lang}\n{$code}\n```\n\n";
        case 'code':
            if ($node->parentNode && strtolower($node->parentNode->nodeName) === 'pre') {
                return $node->textContent;
            }
            return '`' . $node->textContent . '`';
        case 'ul':
            return wp_convert_list($node, false) . "\n";
        case 'ol':
            return wp_convert_list($node, true) . "\n";
        case 'li':
            return trim(wp_convert_children($node));
        case 'figure':
            return wp_convert_figure($node) . "\n\n";
        case 'figcaption':
            return '';
        case 'mark':
            return '<mark>' . wp_convert_children($node) . '</mark>';
        case 'kbd':
            return '<kbd>' . htmlspecialchars($node->textContent, ENT_QUOTES, 'UTF-8') . '</kbd>';
        case 'sup':  return '<sup>' . $node->textContent . '</sup>';
        case 'sub':  return '<sub>' . $node->textContent . '</sub>';
        case 'abbr':
            $title = $node->getAttribute('title');
            $inner = wp_convert_children($node);
            return $title ? "<abbr title=\"{$title}\">{$inner}</abbr>" : $inner;
        case 'table': case 'thead': case 'tbody': case 'tfoot':
        case 'tr': case 'th': case 'td': case 'caption':
        case 'details': case 'summary':
            return $node->ownerDocument->saveHTML($node) . "\n\n";
        default:
            return $node->ownerDocument->saveHTML($node) . "\n\n";
    }
}

function wp_convert_list(DOMNode $node, bool $ordered, int $depth = 0): string
{
    $result  = '';
    $counter = 1;
    $indent  = str_repeat('  ', $depth);

    foreach ($node->childNodes as $child) {
        if ($child->nodeType !== XML_ELEMENT_NODE || strtolower($child->nodeName) !== 'li') continue;

        $inline = '';
        $nested = '';
        foreach ($child->childNodes as $li_child) {
            $ct = strtolower($li_child->nodeName ?? '');
            if ($li_child->nodeType === XML_ELEMENT_NODE && in_array($ct, ['ul', 'ol'])) {
                $nested .= "\n" . wp_convert_list($li_child, $ct === 'ol', $depth + 1);
            } else {
                $inline .= wp_convert_node($li_child);
            }
        }

        $prefix  = $ordered ? "{$indent}{$counter}. " : "{$indent}- ";
        $result .= $prefix . trim($inline) . $nested . "\n";
        $counter++;
    }

    return rtrim($result);
}

function wp_convert_figure(DOMNode $node): string
{
    $img_md  = '';
    $caption = '';

    foreach ($node->childNodes as $child) {
        if ($child->nodeType !== XML_ELEMENT_NODE) continue;
        $ct = strtolower($child->nodeName);
        if ($ct === 'img') {
            $img_md = '![' . ($child->getAttribute('alt') ?? '') . '](' . $child->getAttribute('src') . ')';
        } elseif ($ct === 'figcaption') {
            $caption = trim($child->textContent);
        } elseif ($ct === 'a') {
            $href = $child->getAttribute('href');
            foreach ($child->childNodes as $ac) {
                if ($ac->nodeType === XML_ELEMENT_NODE && strtolower($ac->nodeName) === 'img') {
                    $img_md = '[![' . ($ac->getAttribute('alt') ?? '') . '](' . $ac->getAttribute('src') . ')](' . $href . ')';
                    break;
                }
            }
        }
    }

    return $img_md === '' ? $node->ownerDocument->saveHTML($node) : $img_md . ($caption !== '' ? "\n*{$caption}*" : '');
}


// ─── Field Extraction ─────────────────────────────────────────────────────────

function wp_get_seo_description(\SimpleXMLElement $item): string
{
    static $keys = ['_yoast_wpseo_metadesc', '_rank_math_description', '_seopress_titles_desc', '_aioseo_description'];
    $wp = $item->children(WP_NS);
    foreach ($wp->postmeta as $meta) {
        $mwp = $meta->children(WP_NS);
        $val = trim((string) $mwp->meta_value);
        if (in_array((string) $mwp->meta_key, $keys) && $val !== '') return $val;
    }
    return '';
}

function wp_get_tags(\SimpleXMLElement $item): array
{
    $tags = [];
    foreach ($item->category as $cat) {
        $domain   = (string) $cat['domain'];
        $nicename = (string) $cat['nicename'];
        if (in_array($domain, ['category', 'post_tag']) && $nicename !== '' && !in_array($nicename, $tags)) {
            $tags[] = $nicename;
        }
    }
    return $tags;
}

function wp_slugify(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^a-z0-9\s\-]/', '', $text);
    $text = preg_replace('/[\s\-]+/', '-', trim($text));
    return trim($text, '-') ?: 'untitled';
}


// ─── Output ───────────────────────────────────────────────────────────────────

require __DIR__ . '/includes/admin-head.php';
?>

<main class="admin-main">
    <div class="admin-content">

    <h1>Import from WordPress</h1>

    <?php if ($error !== ''): ?>
        <p class="notice delete"><?= e($error) ?></p>
    <?php endif; ?>


    <?php // ── Step 3: Results ───────────────────────────────────────────────
    if ($results !== null && !isset($results['error'])): ?>

        <?php
        $ok_count   = count(array_filter($results['imported'], fn($r) => $r['ok']));
        $fail_count = count(array_filter($results['imported'], fn($r) => !$r['ok']));
        $img_fails  = count($results['image_errors']);
        ?>

        <p class="notice">
            Import complete — <?= $ok_count ?> item<?= $ok_count !== 1 ? 's' : '' ?> imported
            <?= $results['skipped'] > 0 ? ', ' . $results['skipped'] . ' draft(s) skipped' : '' ?>.
        </p>

        <?php if ($img_fails > 0): ?>
            <p class="notice delete">
                <?= $img_fails ?> image<?= $img_fails !== 1 ? 's' : '' ?> could not be imported.
                A log has been saved to <code>content/wp-import-errors.log</code>.
            </p>
        <?php endif; ?>

        <?php if ($fail_count > 0): ?>
            <p class="notice delete"><?= $fail_count ?> post<?= $fail_count !== 1 ? 's' : '' ?> failed to save:</p>
            <ul>
            <?php foreach (array_filter($results['imported'], fn($r) => !$r['ok']) as $r): ?>
                <li><?= e($r['title']) ?><?= $r['error'] ? ' — ' . e($r['error']) : '' ?></li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <details>
            <summary>Imported items (<?= count($results['imported']) ?>)</summary>
            <ul>
            <?php foreach ($results['imported'] as $r): ?>
                <li>
                    <?= $r['ok'] ? '✓' : '✗' ?>
                    <?= e($r['title']) ?>
                    <small>(<?= e($r['type']) ?>, <?= e($r['status']) ?>)</small>
                </li>
            <?php endforeach; ?>
            </ul>
        </details>

        <p><a href="<?= base_path() ?>/admin/dashboard.php">← Back to dashboard</a></p>

        <form method="post" onsubmit="return confirm('Delete this importer file from the server?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cleanup">
            <button type="submit" class="delete">Delete importer file and go to dashboard</button>
        </form>


    <?php // ── Step 2: Preview ───────────────────────────────────────────────
    elseif ($preview !== null): ?>

        <?php
        $post_count = count($preview['posts']);
        $page_count = count($preview['pages']);
        $img_count  = count(array_filter(array_merge($preview['posts'], $preview['pages']), fn($p) => $p['has_images']));
        $draft_count = count(array_filter(array_merge($preview['posts'], $preview['pages']), fn($p) => $p['status'] === 'draft'));
        ?>

        <p>Found <strong><?= $post_count ?> post<?= $post_count !== 1 ? 's' : '' ?></strong>
        and <strong><?= $page_count ?> page<?= $page_count !== 1 ? 's' : '' ?></strong> to import.</p>

        <?php if ($draft_count > 0): ?>
            <p class="notice"><?= $draft_count ?> item<?= $draft_count !== 1 ? 's are' : ' is' ?> a draft and will be skipped unless you check "Include drafts" below.</p>
        <?php endif; ?>

        <?php if ($img_count > 0): ?>
            <p class="notice"><?= $img_count ?> item<?= $img_count !== 1 ? 's contain' : ' contains' ?> images. See the image options below.</p>
        <?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action"   value="go">
            <input type="hidden" name="wxr_path" value="<?= e($preview['wxr_path']) ?>">

            <fieldset>
                <legend>Options</legend>

                <label>
                    <input type="checkbox" name="drafts">
                    Include draft posts
                </label>

                <label>
                    <input type="checkbox" name="skip_pages">
                    Skip pages
                </label>

                <hr>

                <label>
                    <input type="checkbox" name="skip_images">
                    Skip images
                </label>

                <?php if ($img_count > 0): ?>
                <label>
                    Path to <code>wp-content/uploads</code> on this server <small>(optional — copies images locally instead of downloading)</small>
                    <input type="text" name="uploads_dir" placeholder="/var/www/wordpress/wp-content/uploads">
                </label>
                <?php endif; ?>
            </fieldset>

            <button type="submit" onclick="this.disabled=true; this.textContent='Importing…'; this.form.submit();">
                Import
            </button>
            <a href="<?= base_path() ?>/import_wordpress.php">Start over</a>
        </form>

        <?php if ($post_count > 0): ?>
        <details>
            <summary>Posts (<?= $post_count ?>)</summary>
            <table>
                <thead><tr><th>Title</th><th>Date</th><th>Status</th><th>Images</th></tr></thead>
                <tbody>
                <?php foreach ($preview['posts'] as $p): ?>
                    <tr>
                        <td><?= e($p['title']) ?></td>
                        <td><?= e($p['date']) ?></td>
                        <td><?= e($p['status']) ?></td>
                        <td><?= $p['has_images'] ? '✓' : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </details>
        <?php endif; ?>

        <?php if ($page_count > 0): ?>
        <details>
            <summary>Pages (<?= $page_count ?>)</summary>
            <table>
                <thead><tr><th>Title</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($preview['pages'] as $p): ?>
                    <tr>
                        <td><?= e($p['title']) ?></td>
                        <td><?= e($p['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </details>
        <?php endif; ?>


    <?php // ── Step 1: Upload ────────────────────────────────────────────────
    else: ?>

        <p>Export your WordPress content from <strong>Tools → Export → All content</strong>, then upload the <code>.xml</code> file below.</p>

        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="preview">

            <label>
                WordPress export file <small>(.xml)</small>
                <input type="file" name="wxr_file" accept=".xml">
            </label>

            <label>
                Or enter the path to the file on this server <small>(useful for large exports)</small>
                <input type="text" name="wxr_path" placeholder="/path/to/wordpress-export.xml">
            </label>

            <button type="submit">Preview Import</button>
        </form>

    <?php endif; ?>

    </div>
</main>

<?php require __DIR__ . '/includes/admin-footer.php'; ?>
