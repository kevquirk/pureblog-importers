<?php
/**
 * Ghost → Pure Blog Importer (Web UI) v1.0.0
 *
 * Place this file in your Pure Blog root directory, then visit:
 *   https://yourblog.com/import_ghost.php
 *
 * You must be logged in to the Pure Blog admin to use it.
 */

declare(strict_types=1);

@ini_set('max_execution_time', '300');
@ini_set('memory_limit', '256M');

define('GHOST_IMPORTER_VERSION', '1.0.0');

require __DIR__ . '/functions.php';
require_setup_redirect();
start_admin_session();
require_admin_login();

$config    = load_config();
$fontStack = font_stack_css($config['theme']['admin_font_stack'] ?? 'sans');
$adminTitle = 'Import from Ghost';

$action = $_POST['action'] ?? '';
$error  = '';
$preview = null;
$results = null;

// ─── Action: Preview ──────────────────────────────────────────────────────────

if ($action === 'preview') {
    verify_csrf();

    try {
        $ghost_path = resolve_ghost_path();
    } catch (\RuntimeException $e) {
        $error      = $e->getMessage();
        $ghost_path = null;
    }

    if ($ghost_path === null && $error === '') {
        $error = 'No Ghost JSON file provided, or the file could not be found.';
    } elseif ($ghost_path !== null) {
        $preview = parse_ghost_preview($ghost_path);
        if ($preview === null) {
            $error = 'Could not parse the Ghost JSON file. Please check it is a valid Ghost export.';
            @unlink_temp($ghost_path);
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

    $ghost_path     = validate_temp_path($_POST['ghost_path'] ?? '');
    $uploads_dir    = rtrim(trim($_POST['uploads_dir'] ?? ''), '/');
    $ghost_site_url = rtrim(trim($_POST['ghost_site_url'] ?? ''), '/');

    if ($ghost_path === null || !file_exists($ghost_path)) {
        $error = 'Ghost JSON file not found. Please start again.';
    } else {
        $opts = [
            'drafts'         => !empty($_POST['drafts']),
            'skip_pages'     => !empty($_POST['skip_pages']),
            'skip_images'    => !empty($_POST['skip_images']),
            'uploads_dir'    => ($uploads_dir !== '' && is_dir($uploads_dir)) ? $uploads_dir : null,
            'ghost_site_url' => ($ghost_site_url !== '') ? $ghost_site_url : null,
        ];

        $results = do_import($ghost_path, $opts);
        unlink_temp($ghost_path);
    }
}

// ─── Helpers for file handling ────────────────────────────────────────────────

function resolve_ghost_path(): ?string
{
    // Prefer uploaded file
    $upload_error = $_FILES['ghost_file']['error'] ?? UPLOAD_ERR_NO_FILE;

    if ($upload_error === UPLOAD_ERR_INI_SIZE || $upload_error === UPLOAD_ERR_FORM_SIZE) {
        $limit = ini_get('upload_max_filesize');
        throw new \RuntimeException("The file exceeds the server's upload limit ({$limit}). Enter the file path directly in the field below instead.");
    }

    if ($upload_error === UPLOAD_ERR_OK && !empty($_FILES['ghost_file']['tmp_name'])) {
        $dest = sys_get_temp_dir() . '/pb_ghost_' . bin2hex(random_bytes(8)) . '.json';
        if (move_uploaded_file($_FILES['ghost_file']['tmp_name'], $dest)) {
            return $dest;
        }
    }

    // Fall back to server path
    $path = trim($_POST['ghost_path'] ?? '');
    if ($path !== '') {
        // Try literal path
        if (file_exists($path) && is_readable($path)) {
            return $path;
        }
        // Try relative to current script directory (__DIR__)
        $relative = __DIR__ . '/' . ltrim($path, '/');
        if (file_exists($relative) && is_readable($relative)) {
            return $relative;
        }
        // Try relative to PUREBLOG_BASE_PATH if defined
        if (defined('PUREBLOG_BASE_PATH')) {
            $base_rel = rtrim(PUREBLOG_BASE_PATH, '/') . '/' . ltrim($path, '/');
            if (file_exists($base_rel) && is_readable($base_rel)) {
                return $base_rel;
            }
        }
    }

    return null;
}

function validate_temp_path(string $path): ?string
{
    if ($path === '') return null;
    
    // Try literal path
    $real = realpath($path);
    if ($real !== false && file_exists($real)) {
        return $real;
    }
    
    // Try relative to current script directory (__DIR__)
    $relative = __DIR__ . '/' . ltrim($path, '/');
    $real = realpath($relative);
    if ($real !== false && file_exists($real)) {
        return $real;
    }

    // Try relative to PUREBLOG_BASE_PATH if defined
    if (defined('PUREBLOG_BASE_PATH')) {
        $base_rel = rtrim(PUREBLOG_BASE_PATH, '/') . '/' . ltrim($path, '/');
        $real = realpath($base_rel);
        if ($real !== false && file_exists($real)) {
            return $real;
        }
    }

    return null;
}

function unlink_temp(string $path): void
{
    if (str_starts_with($path, sys_get_temp_dir())) {
        @unlink($path);
    }
}

// ─── Ghost Parsing (Preview) ──────────────────────────────────────────────────

function parse_ghost_preview(string $ghost_path): ?array
{
    $raw = @file_get_contents($ghost_path);
    if (!$raw) return null;
    $data = @json_decode($raw, true);
    if (!isset($data['db'][0]['data']['posts'])) return null;

    $posts = [];
    $pages = [];

    foreach ($data['db'][0]['data']['posts'] as $p) {
        $type   = $p['type'] ?? 'post';
        if (!in_array($type, ['post', 'page'])) continue;

        $title       = $p['title'] ?? 'Untitled';
        $slug        = $p['slug'] ?? '';
        $date        = $p['published_at'] ?? $p['created_at'] ?? '';
        $pb_status   = (($p['status'] ?? '') === 'published') ? 'published' : 'draft';
        $has_images  = (bool) (preg_match('/<img\b/i', $p['html'] ?? '') || !empty($p['feature_image']));

        $entry = [
            'title'      => $title,
            'slug'       => $slug,
            'date'       => $date ? date('Y-m-d', strtotime($date)) : '',
            'status'     => $pb_status,
            'has_images' => $has_images,
        ];

        if ($type === 'post') {
            $posts[] = $entry;
        } else {
            $pages[] = $entry;
        }
    }

    return ['posts' => $posts, 'pages' => $pages, 'ghost_path' => $ghost_path];
}

// ─── Import ───────────────────────────────────────────────────────────────────

function do_import(string $ghost_path, array $opts): array
{
    $raw = @file_get_contents($ghost_path);
    if (!$raw) {
        return ['error' => 'Could not read Ghost file.'];
    }
    $data = @json_decode($raw, true);
    if (!isset($data['db'][0]['data']['posts'])) {
        return ['error' => 'Could not parse Ghost file or posts are missing.'];
    }

    $db_data = $data['db'][0]['data'];
    $images_dir  = PUREBLOG_CONTENT_IMAGES_PATH;
    $imported    = [];
    $skipped     = 0;
    $image_errors = [];

    // Map tags
    $tag_map = [];
    if (isset($db_data['tags'])) {
        foreach ($db_data['tags'] as $tag) {
            $tag_map[$tag['id']] = $tag['slug'] ?: $tag['name'];
        }
    }

    $post_tags_map = [];
    if (isset($db_data['posts_tags'])) {
        foreach ($db_data['posts_tags'] as $pt) {
            if (isset($tag_map[$pt['tag_id']])) {
                $post_tags_map[$pt['post_id']][] = $tag_map[$pt['tag_id']];
            }
        }
    }

    // Map posts_meta
    $meta_map = [];
    if (isset($db_data['posts_meta'])) {
        foreach ($db_data['posts_meta'] as $meta) {
            if (!empty($meta['meta_description'])) {
                $meta_map[$meta['post_id']] = trim($meta['meta_description']);
            }
        }
    }

    foreach ($db_data['posts'] as $p) {
        $type = $p['type'] ?? 'post';
        if (!in_array($type, ['post', 'page'])) continue;
        if ($type === 'page' && $opts['skip_pages']) continue;

        $status    = $p['status'] ?? 'draft';
        $pb_status = ($status === 'published') ? 'published' : 'draft';

        if ($status !== 'published' && !$opts['drafts']) {
            $skipped++;
            continue;
        }

        $title = $p['title'] ?? 'Untitled';
        $slug  = $p['slug'] ?? '';
        if ($slug === '') {
            $slug = ghost_slugify($title);
        }

        $date = $p['published_at'] ?? $p['created_at'] ?? '';
        try {
            $datetime = (new DateTimeImmutable($date))->format('Y-m-d H:i');
        } catch (\Exception $e) {
            $datetime = date('Y-m-d H:i');
        }

        $raw_html = $p['html'] ?? '';

        $description = $meta_map[$p['id']] ?? trim($p['custom_excerpt'] ?? '');

        $tags     = $post_tags_map[$p['id']] ?? [];
        $markdown = ghost_process_content($raw_html, $slug, $images_dir, $opts, $image_errors);

        $feature_image_url = $p['feature_image'] ?? '';
        $local_feature_image = null;
        if ($feature_image_url !== '' && !$opts['skip_images']) {
            $local_feature_image = ghost_handle_feature_image(
                $feature_image_url,
                $slug,
                $images_dir,
                $opts['uploads_dir'],
                $opts['ghost_site_url'],
                $image_errors
            );
        }

        $post = [
            'title'       => $title,
            'slug'        => $slug,
            'date'        => $datetime,
            'status'      => $pb_status,
            'tags'        => $tags,
            'description' => $description,
            'content'     => $markdown,
        ];

        if ($local_feature_image !== null) {
            $post['feature_image'] = $local_feature_image;
        }

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
        $log_path = PUREBLOG_BASE_PATH . '/content/ghost-import-errors.log';
        $lines    = ['Ghost Importer — image errors (' . date('Y-m-d H:i:s') . ')', str_repeat('-', 60)];
        foreach ($image_errors as $err) {
            $lines[] = "Post/Page: {$err['slug']}";
            $lines[] = "  URL:       {$err['url']}";
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

function ghost_process_content(string $html, string $slug, string $images_dir, array $opts, array &$image_errors): string
{
    // Clean Ghost cards comments
    $html = preg_replace('/<!--kg-card-begin:\s*[^>]*-->/s', '', $html);
    $html = preg_replace('/<!--kg-card-end:\s*[^>]*-->/s', '', $html);
    $html = trim($html);

    if (empty($html)) return '';

    if (!$opts['skip_images']) {
        $html = ghost_handle_images($html, $slug, $images_dir, $opts['uploads_dir'], $opts['ghost_site_url'], $image_errors);
    }

    return ghost_html_to_markdown($html);
}

function ghost_handle_feature_image(string $url, string $slug, string $images_dir, ?string $uploads_dir, ?string $ghost_site_url, array &$image_errors): ?string
{
    $filename = '';
    $source = null;
    $download_url = null;

    if (str_starts_with($url, '__GHOST_URL__/content/images/')) {
        $relative = substr($url, strlen('__GHOST_URL__/content/images/'));
        $filename = basename($relative);
        
        if ($uploads_dir !== null) {
            $source = rtrim($uploads_dir, '/') . '/' . $relative;
        }
        if ($ghost_site_url !== null) {
            $download_url = rtrim($ghost_site_url, '/') . '/content/images/' . $relative;
        }
    } elseif (str_starts_with($url, '/content/images/')) {
        $relative = substr($url, strlen('/content/images/'));
        $filename = basename($relative);
        
        if ($uploads_dir !== null) {
            $source = rtrim($uploads_dir, '/') . '/' . $relative;
        }
        if ($ghost_site_url !== null) {
            $download_url = rtrim($ghost_site_url, '/') . '/content/images/' . $relative;
        }
    } else {
        if (empty($url) || str_starts_with($url, 'data:')) {
            return null;
        }
        $url_path = parse_url($url, PHP_URL_PATH) ?? '';
        $filename = basename($url_path);
        $download_url = $url;
    }

    if (empty($filename)) {
        return null;
    }

    $local_dir  = rtrim($images_dir, '/') . '/' . $slug;
    $local_path = $local_dir . '/' . $filename;
    $web_path   = '/content/images/' . $slug . '/' . $filename;

    if (!is_dir($local_dir)) {
        mkdir($local_dir, 0755, true);
    }

    if (!file_exists($local_path)) {
        $ok = false;

        if ($source !== null && file_exists($source)) {
            $ok = copy($source, $local_path);
        } elseif ($download_url !== null) {
            $ctx  = stream_context_create(['http' => ['user_agent' => 'PureBlog-Ghost-Importer/' . GHOST_IMPORTER_VERSION]]);
            $data = @file_get_contents($download_url, false, $ctx);
            if ($data !== false) {
                file_put_contents($local_path, $data);
                $ok = true;
            }
        }

        if (!$ok) {
            $image_errors[] = ['slug' => $slug, 'url' => $url];
            return null;
        }
    }

    return $web_path;
}

function ghost_handle_images(string $html, string $slug, string $images_dir, ?string $uploads_dir, ?string $ghost_site_url, array &$image_errors): string
{
    return preg_replace_callback(
        '/(<img\b[^>]*?\bsrc=)["\']([^"\']+)["\']([^>]*>)/i',
        function ($m) use ($slug, $images_dir, $uploads_dir, $ghost_site_url, &$image_errors) {
            $url = $m[2];
            $filename = '';
            $source = null;
            $download_url = null;

            if (str_starts_with($url, '__GHOST_URL__/content/images/')) {
                $relative = substr($url, strlen('__GHOST_URL__/content/images/'));
                $filename = basename($relative);
                
                if ($uploads_dir !== null) {
                    $source = rtrim($uploads_dir, '/') . '/' . $relative;
                }
                if ($ghost_site_url !== null) {
                    $download_url = rtrim($ghost_site_url, '/') . '/content/images/' . $relative;
                }
            } elseif (str_starts_with($url, '/content/images/')) {
                $relative = substr($url, strlen('/content/images/'));
                $filename = basename($relative);
                
                if ($uploads_dir !== null) {
                    $source = rtrim($uploads_dir, '/') . '/' . $relative;
                }
                if ($ghost_site_url !== null) {
                    $download_url = rtrim($ghost_site_url, '/') . '/content/images/' . $relative;
                }
            } else {
                if (empty($url) || str_starts_with($url, 'data:')) {
                    return $m[0];
                }
                $url_path = parse_url($url, PHP_URL_PATH) ?? '';
                $filename = basename($url_path);
                $download_url = $url;
            }

            if (empty($filename)) {
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

                if ($source !== null && file_exists($source)) {
                    $ok = copy($source, $local_path);
                } elseif ($download_url !== null) {
                    $ctx  = stream_context_create(['http' => ['user_agent' => 'PureBlog-Ghost-Importer/' . GHOST_IMPORTER_VERSION]]);
                    $data = @file_get_contents($download_url, false, $ctx);
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

function ghost_html_to_markdown(string $html): string
{
    if (empty(trim($html))) return '';

    $dom = new DOMDocument('1.0', 'UTF-8');
    @$dom->loadHTML(
        '<?xml encoding="UTF-8"><div id="__pb_root__">' . $html . '</div>',
        LIBXML_NOERROR | LIBXML_NOWARNING
    );

    $root = $dom->getElementById('__pb_root__');
    if (!$root) return $html;

    $md = ghost_convert_children($root);
    $md = preg_replace('/\n{3,}/', "\n\n", $md);
    return trim($md) . "\n";
}

function ghost_convert_children(DOMNode $node): string
{
    $out = '';
    foreach ($node->childNodes as $child) {
        $out .= ghost_convert_node($child);
    }
    return $out;
}

function ghost_convert_node(DOMNode $node): string
{
    if ($node->nodeType === XML_TEXT_NODE) {
        return str_replace("\u{00A0}", ' ', $node->textContent);
    }
    if ($node->nodeType !== XML_ELEMENT_NODE) return '';

    $tag = strtolower($node->nodeName);

    switch ($tag) {
        case 'div': case 'section': case 'article': case 'main': case 'aside':
            return ghost_convert_children($node) . "\n";
        case 'p':
            $inner = trim(ghost_convert_children($node));
            return $inner === '' ? '' : $inner . "\n\n";
        case 'h1': return '# '      . trim(ghost_convert_children($node)) . "\n\n";
        case 'h2': return '## '     . trim(ghost_convert_children($node)) . "\n\n";
        case 'h3': return '### '    . trim(ghost_convert_children($node)) . "\n\n";
        case 'h4': return '#### '   . trim(ghost_convert_children($node)) . "\n\n";
        case 'h5': return '##### '  . trim(ghost_convert_children($node)) . "\n\n";
        case 'h6': return '###### ' . trim(ghost_convert_children($node)) . "\n\n";
        case 'strong': case 'b':
            $inner = trim(ghost_convert_children($node));
            return $inner === '' ? '' : "**{$inner}**";
        case 'em': case 'i':
            $inner = trim(ghost_convert_children($node));
            return $inner === '' ? '' : "*{$inner}*";
        case 's': case 'del': case 'strike':
            $inner = trim(ghost_convert_children($node));
            return $inner === '' ? '' : "~~{$inner}~~";
        case 'span':
            return ghost_convert_children($node);
        case 'a':
            $href  = $node->getAttribute('href');
            $inner = trim(ghost_convert_children($node));
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
            $inner = trim(ghost_convert_children($node));
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
            return ghost_convert_list($node, false) . "\n";
        case 'ol':
            return ghost_convert_list($node, true) . "\n";
        case 'li':
            return trim(ghost_convert_children($node));
        case 'figure':
            return ghost_convert_figure($node) . "\n\n";
        case 'figcaption':
            return '';
        case 'mark':
            return '<mark>' . ghost_convert_children($node) . '</mark>';
        case 'kbd':
            return '<kbd>' . htmlspecialchars($node->textContent, ENT_QUOTES, 'UTF-8') . '</kbd>';
        case 'sup':  return '<sup>' . $node->textContent . '</sup>';
        case 'sub':  return '<sub>' . $node->textContent . '</sub>';
        case 'abbr':
            $title = $node->getAttribute('title');
            $inner = ghost_convert_children($node);
            return $title ? "<abbr title=\"{$title}\">{$inner}</abbr>" : $inner;
        case 'table': case 'thead': case 'tbody': case 'tfoot':
        case 'tr': case 'th': case 'td': case 'caption':
        case 'details': case 'summary':
            return $node->ownerDocument->saveHTML($node) . "\n\n";
        default:
            return $node->ownerDocument->saveHTML($node) . "\n\n";
    }
}

function ghost_convert_list(DOMNode $node, bool $ordered, int $depth = 0): string
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
                $nested .= "\n" . ghost_convert_list($li_child, $ct === 'ol', $depth + 1);
            } else {
                $inline .= ghost_convert_node($li_child);
            }
        }

        $prefix  = $ordered ? "{$indent}{$counter}. " : "{$indent}- ";
        $result .= $prefix . trim($inline) . $nested . "\n";
        $counter++;
    }

    return rtrim($result);
}

function ghost_convert_figure(DOMNode $node): string
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

function ghost_slugify(string $text): string
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

    <h1>Import from Ghost</h1>

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
                A log has been saved to <code>content/ghost-import-errors.log</code>.
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

        <p><a class="button" href="<?= base_path() ?>/admin/dashboard.php">← Back to dashboard</a></p>

        <form method="post" onsubmit="return confirm('Delete this importer file from the server?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cleanup">
            <p><button type="submit" class="delete">Delete importer file and go to dashboard</button></p>
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
            <input type="hidden" name="action"     value="go">
            <input type="hidden" name="ghost_path" value="<?= e($preview['ghost_path']) ?>">

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

                <label>
                    Ghost blog Site URL <small>(optional — e.g. https://myblog.com, used to download images starting with <code>__GHOST_URL__</code>)</small>
                    <input type="text" name="ghost_site_url" placeholder="https://myblog.com">
                </label>

                <label>
                    Path to Ghost <code>content/images</code> directory on this server <small>(optional — copies images locally instead of downloading)</small>
                    <input type="text" name="uploads_dir" placeholder="/var/www/ghost/content/images">
                </label>
            </fieldset>

            <p><button type="submit" onclick="this.disabled=true; this.textContent='Importing…'; this.form.submit();">
                Import
            </button>
            <a class="button delete" href="<?= base_path() ?>/import_ghost.php">Start over</a></p>
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

        <p>Export your Ghost content from your Ghost admin dashboard (Settings → Labs → Export your content), then upload the <code>.json</code> file below.</p>

        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="preview">

            <label>
                Ghost export file <small>(.json)</small>
                <input type="file" name="ghost_file" accept=".json">
            </label>

            <label>
                Or enter the path to the file on this server <small>(useful for large exports)</small>
                <input type="text" name="ghost_path" placeholder="/path/to/ghost.json">
            </label>

            <p><button type="submit">Preview Import</button></p>
        </form>

    <?php endif; ?>

    </div>
</main>

<?php require __DIR__ . '/includes/admin-footer.php'; ?>
