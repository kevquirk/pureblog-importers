<?php
/**
 * WriteFreely → Pure Blog Importer (Web UI) v1.0.0
 *
 * Place this file in your Pure Blog root directory, then visit:
 *   https://yourblog.com/import_writefreely.php
 *
 * You must be logged in to the Pure Blog admin to use it.
 */

declare(strict_types=1);

@ini_set('max_execution_time', '300');
@ini_set('memory_limit', '256M');

define('WRITEFREELY_IMPORTER_VERSION', '1.0.0');

require __DIR__ . '/functions.php';
require_setup_redirect();
start_admin_session();
require_admin_login();

$config     = load_config();
$fontStack  = font_stack_css($config['theme']['admin_font_stack'] ?? 'sans');
$adminTitle = 'Import from WriteFreely';

$action  = $_POST['action'] ?? '';
$error   = '';
$preview = null;
$results = null;

// ─── Action: Preview ──────────────────────────────────────────────────────────

if ($action === 'preview') {
    verify_csrf();

    try {
        $csv_path = resolve_wf_path();
    } catch (\RuntimeException $e) {
        $error    = $e->getMessage();
        $csv_path = null;
    }

    if ($csv_path === null && $error === '') {
        $error = 'No WriteFreely CSV file provided, or the file could not be found.';
    } elseif ($csv_path !== null) {
        $preview = parse_wf_preview($csv_path);
        if ($preview === null) {
            $error = 'Could not parse the WriteFreely CSV file. Please check that it is a valid WriteFreely CSV export.';
            @unlink_temp($csv_path);
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

    $csv_path = validate_temp_path($_POST['csv_path'] ?? '');

    if ($csv_path === null || !file_exists($csv_path)) {
        $error = 'WriteFreely CSV file not found. Please start again.';
    } else {
        $opts = [
            'force_images' => !empty($_POST['force_images']),
            'extract_tags' => !empty($_POST['extract_tags']),
            'all_drafts'   => !empty($_POST['all_drafts']),
            'delete_csv'   => !empty($_POST['delete_csv']),
        ];

        $results = do_wf_import($csv_path, $opts);
        if ($opts['delete_csv'] || is_temp_file($csv_path)) {
            @unlink_temp($csv_path);
        }
    }
}

// ─── File Handling Helpers ────────────────────────────────────────────────────

function resolve_wf_path(): ?string
{
    // 1. Prefer uploaded file
    $upload_error = $_FILES['wf_file']['error'] ?? UPLOAD_ERR_NO_FILE;

    if ($upload_error === UPLOAD_ERR_INI_SIZE || $upload_error === UPLOAD_ERR_FORM_SIZE) {
        $limit = ini_get('upload_max_filesize');
        throw new \RuntimeException("The file exceeds the server's upload limit ({$limit}). Enter the file path directly in the field below instead.");
    }

    if ($upload_error === UPLOAD_ERR_OK && !empty($_FILES['wf_file']['tmp_name'])) {
        $dest = sys_get_temp_dir() . '/pb_wf_' . bin2hex(random_bytes(8)) . '.csv';
        if (move_uploaded_file($_FILES['wf_file']['tmp_name'], $dest)) {
            return $dest;
        }
    }

    // 2. Check path provided in text field
    $path = trim($_POST['wf_path'] ?? '');
    if ($path !== '') {
        if (file_exists($path) && is_readable($path)) {
            return $path;
        }
        $relative = __DIR__ . '/' . ltrim($path, '/');
        if (file_exists($relative) && is_readable($relative)) {
            return $relative;
        }
        if (defined('PUREBLOG_BASE_PATH')) {
            $base_rel = rtrim(PUREBLOG_BASE_PATH, '/') . '/' . ltrim($path, '/');
            if (file_exists($base_rel) && is_readable($base_rel)) {
                return $base_rel;
            }
        }
    }

    // 3. Auto-detect CSV in content/posts/import/
    if (defined('PUREBLOG_POSTS_PATH')) {
        $import_dir = PUREBLOG_POSTS_PATH . '/import';
        if (is_dir($import_dir)) {
            $csv_files = glob($import_dir . '/*.csv') ?: [];
            if (!empty($csv_files)) {
                return $csv_files[0];
            }
        }
    }

    return null;
}

function validate_temp_path(string $path): ?string
{
    if ($path === '') return null;

    $real = realpath($path);
    if ($real !== false && file_exists($real)) {
        return $real;
    }

    $relative = __DIR__ . '/' . ltrim($path, '/');
    $real = realpath($relative);
    if ($real !== false && file_exists($real)) {
        return $real;
    }

    if (defined('PUREBLOG_BASE_PATH')) {
        $base_rel = rtrim(PUREBLOG_BASE_PATH, '/') . '/' . ltrim($path, '/');
        $real = realpath($base_rel);
        if ($real !== false && file_exists($real)) {
            return $real;
        }
    }

    return null;
}

function is_temp_file(string $path): bool
{
    return str_starts_with(realpath($path) ?: $path, sys_get_temp_dir());
}

function unlink_temp(string $path): void
{
    if (is_temp_file($path) && file_exists($path)) {
        @unlink($path);
    }
}

// ─── CSV Parsing & Preview ───────────────────────────────────────────────────

function parse_wf_preview(string $csv_path): ?array
{
    $posts = wf_read_csv($csv_path);
    if ($posts === null) {
        return null;
    }

    $config = load_config();

    foreach ($posts as &$post) {
        $date_string = normalize_date_value($post['created'] ?? '') ?? '';
        $dt          = $date_string ? parse_post_datetime_with_timezone($date_string, $config) : null;
        $post['timestamp'] = $dt ? $dt->getTimestamp() : 0;
        $post['date']      = $date_string;
    }
    unset($post);

    usort($posts, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);

    return [
        'posts'    => $posts,
        'csv_path' => $csv_path,
    ];
}

function wf_read_csv(string $csv_path): ?array
{
    if (!file_exists($csv_path) || !is_readable($csv_path)) {
        return null;
    }

    $handle = fopen($csv_path, 'r');
    if ($handle === false) {
        return null;
    }

    // Skip UTF-8 BOM if present
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle);
    }

    $header = fgetcsv($handle);
    if ($header === false || empty($header)) {
        fclose($handle);
        return null;
    }

    $header = array_map(fn($col) => strtolower(trim((string) $col)), $header);

    // Require essential columns
    if (!in_array('body', $header, true)) {
        fclose($handle);
        return null;
    }

    $posts = [];
    $title_idx   = array_search('title', $header, true);
    $slug_idx    = array_search('slug', $header, true);
    $created_idx = array_search('created', $header, true);
    $body_idx    = array_search('body', $header, true);
    $id_idx      = array_search('id', $header, true);

    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < count($header)) {
            continue;
        }

        $body = ($body_idx !== false && isset($row[$body_idx])) ? (string) $row[$body_idx] : '';
        $raw_title = ($title_idx !== false && isset($row[$title_idx])) ? trim((string) $row[$title_idx]) : '';
        $raw_slug  = ($slug_idx !== false && isset($row[$slug_idx])) ? trim((string) $row[$slug_idx]) : '';
        $created   = ($created_idx !== false && isset($row[$created_idx])) ? trim((string) $row[$created_idx]) : '';
        $id        = ($id_idx !== false && isset($row[$id_idx])) ? trim((string) $row[$id_idx]) : '';

        // Unescape literal newlines encoded by WriteFreely in CSV exports
        $body      = str_replace(["\\r\\n", "\\r", "\\n"], "\n", $body);
        $raw_title = str_replace(["\\r\\n", "\\r", "\\n"], " ", $raw_title);

        // If title is empty, check if first line of body is a markdown heading
        $title = $raw_title;
        if ($title === '') {
            if (preg_match('/^#\s+(.+)$/m', $body, $heading_match)) {
                $title = trim($heading_match[1]);
            } elseif ($raw_slug !== '') {
                $title = ucwords(str_replace('-', ' ', $raw_slug));
            } else {
                $title = 'Untitled (' . ($id ?: date('Y-m-d')) . ')';
            }
        }

        // Clean redundant leading # Title heading from body if it matches post title
        $clean_body = $body;
        if ($raw_title !== '') {
            $clean_body = preg_replace('/^#\s+' . preg_quote($raw_title, '/') . '(?:\r?\n)+/u', '', $clean_body);
        }

        // Extract hashtags
        $tags = [];
        if (preg_match_all('/(?:^|\s)#([a-zA-Z0-9_\-]+)/u', $clean_body, $matches)) {
            foreach ($matches[1] as $tag) {
                if (!is_numeric($tag) && !in_array($tag, $tags, true)) {
                    $tags[] = $tag;
                }
            }
        }

        $has_images = (bool) preg_match('/!\[.*?\]|<img\s/i', $clean_body);

        $slug = $raw_slug !== '' ? $raw_slug : ($id !== '' ? $id : wf_slugify($title));

        $posts[] = [
            'id'         => $id,
            'title'      => $title,
            'slug'       => $slug,
            'created'    => $created,
            'body'       => $clean_body,
            'tags'       => $tags,
            'has_images' => $has_images,
        ];
    }

    fclose($handle);
    return $posts;
}

function wf_slugify(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^a-z0-9\s\-]/u', '', $text);
    $text = preg_replace('/[\s\-]+/', '-', trim($text));
    return trim($text, '-') ?: 'untitled';
}

// ─── Import Logic ─────────────────────────────────────────────────────────────

function do_wf_import(string $csv_path, array $opts): array
{
    $parsed = parse_wf_preview($csv_path);
    if ($parsed === null) {
        return ['error' => 'Could not parse WriteFreely CSV file.'];
    }

    $posts = $parsed['posts'];
    $imported = [];

    foreach ($posts as $post) {
        $status = 'published';

        if ($opts['all_drafts']) {
            $status = 'draft';
        } elseif ($post['has_images'] && !$opts['force_images']) {
            $status = 'draft';
        }

        $tags = $opts['extract_tags'] ? $post['tags'] : [];

        $save_data = [
            'title'       => $post['title'],
            'slug'        => $post['slug'],
            'date'        => $post['date'],
            'status'      => $status,
            'tags'        => $tags,
            'description' => '',
            'content'     => $post['body'],
        ];

        $save_error = null;
        $ok = save_post($save_data, null, null, null, $save_error);

        $note = '';
        if ($status === 'draft') {
            if ($opts['all_drafts']) {
                $note = 'Saved as draft (all drafts selected)';
            } elseif ($post['has_images'] && !$opts['force_images']) {
                $note = 'Saved as draft (contains images)';
            }
        }

        $imported[] = [
            'title'  => $post['title'],
            'slug'   => $save_data['slug'],
            'status' => $status,
            'ok'     => $ok,
            'error'  => $save_error,
            'note'   => $note,
        ];
    }

    return [
        'imported' => $imported,
    ];
}

// ─── Output ───────────────────────────────────────────────────────────────────

require __DIR__ . '/includes/admin-head.php';
?>

<main class="admin-main">
    <div class="admin-content">

    <h1>Import from WriteFreely</h1>

    <?php if ($error !== ''): ?>
        <p class="notice delete"><?= e($error) ?></p>
    <?php endif; ?>


    <?php // ── Step 3: Results ───────────────────────────────────────────────
    if ($results !== null && !isset($results['error'])): ?>

        <?php
        $ok_count   = count(array_filter($results['imported'], fn($r) => $r['ok']));
        $fail_count = count(array_filter($results['imported'], fn($r) => !$r['ok']));
        ?>

        <p class="notice">
            Import complete — <?= $ok_count ?> post<?= $ok_count !== 1 ? 's' : '' ?> imported.
        </p>

        <?php if ($fail_count > 0): ?>
            <p class="notice delete"><?= $fail_count ?> post<?= $fail_count !== 1 ? 's' : '' ?> failed to save:</p>
            <ul>
            <?php foreach (array_filter($results['imported'], fn($r) => !$r['ok']) as $r): ?>
                <li><?= e($r['title']) ?><?= $r['error'] ? ' — ' . e($r['error']) : '' ?></li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <details>
            <summary>Imported posts (<?= count($results['imported']) ?>)</summary>
            <ul>
            <?php foreach ($results['imported'] as $r): ?>
                <li>
                    <?= $r['ok'] ? '✓' : '✗' ?>
                    <?= e($r['title']) ?>
                    <small>(<?= e($r['status']) ?><?= $r['note'] !== '' ? ', ' . e($r['note']) : '' ?>)</small>
                </li>
            <?php endforeach; ?>
            </ul>
        </details>

        <p><a class="button" href="<?= base_path() ?>/admin/dashboard.php">← Back to dashboard</a></p>

        <form method="post" onsubmit="return confirm('Delete this importer file from the server?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cleanup">
            <button type="submit" class="delete">Delete importer file and go to dashboard</button>
        </form>


    <?php // ── Step 2: Preview ───────────────────────────────────────────────
    elseif ($preview !== null && count($preview['posts']) > 0): ?>

        <?php
        $posts       = $preview['posts'];
        $total       = count($posts);
        $with_imgs   = count(array_filter($posts, fn($p) => $p['has_images']));
        $with_tags   = count(array_filter($posts, fn($p) => !empty($p['tags'])));
        ?>

        <p>Found <strong><?= $total ?> post<?= $total !== 1 ? 's' : '' ?></strong> in WriteFreely CSV export.</p>

        <?php if ($with_imgs > 0): ?>
            <p class="notice"><?= $with_imgs ?> post<?= $with_imgs !== 1 ? 's contain' : ' contains' ?> images and will be saved as drafts so you can verify image URLs. You can choose to publish them immediately using the option below.</p>
        <?php endif; ?>

        <?php if ($with_tags > 0): ?>
            <p class="notice"><?= $with_tags ?> post<?= $with_tags !== 1 ? 's contain' : ' contains' ?> hashtags that can be converted to Pure Blog tags.</p>
        <?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="go">
            <input type="hidden" name="csv_path" value="<?= e($preview['csv_path']) ?>">

            <fieldset>
                <legend>Options</legend>

                <?php if ($with_imgs > 0): ?>
                <label>
                    <input type="checkbox" name="force_images" value="1">
                    Publish posts containing images immediately (do not save as drafts)
                </label>
                <?php endif; ?>

                <label>
                    <input type="checkbox" name="extract_tags" value="1" checked>
                    Extract hashtags into Pure Blog post tags
                </label>

                <label>
                    <input type="checkbox" name="all_drafts" value="1">
                    Import all posts as drafts
                </label>

                <label>
                    <input type="checkbox" name="delete_csv" value="1" checked>
                    Delete CSV file after a successful import
                </label>
            </fieldset>

            <p><button type="submit" onclick="this.disabled=true; this.textContent='Importing…'; this.form.submit();">
                Import <?= $total ?> post<?= $total !== 1 ? 's' : '' ?>
            </button>
            <a class="button delete" href="<?= base_path() ?>/import_writefreely.php">Start over</a></p>
        </form>

        <details>
            <summary>Posts to import (<?= $total ?>)</summary>
            <table>
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Date</th>
                        <th>Slug</th>
                        <th>Tags</th>
                        <th>Images</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($posts as $p): ?>
                    <tr>
                        <td><?= e($p['title']) ?></td>
                        <td><?= e($p['date'] !== '' ? substr($p['date'], 0, 10) : '—') ?></td>
                        <td><code><?= e($p['slug']) ?></code></td>
                        <td><?= !empty($p['tags']) ? e(implode(', ', $p['tags'])) : '—' ?></td>
                        <td><?= $p['has_images'] ? 'Yes' : 'No' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </details>


    <?php // ── Step 1: Upload / Instructions ─────────────────────────────────
    else: ?>

        <ol>
            <li>Export your posts from WriteFreely as a <strong>CSV</strong> file (via <strong>Customize → Export data → Posts (.csv)</strong>).</li>
            <li>Upload the exported CSV file below, or copy it to <code>content/posts/import/</code> on your server.</li>
            <li>Click <strong>Preview Import</strong> to review posts before importing.</li>
        </ol>

        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="preview">

            <fieldset>
                <legend>Select WriteFreely CSV File</legend>

                <label for="wf_file">Upload CSV file</label>
                <input type="file" id="wf_file" name="wf_file" accept=".csv,text/csv">

                <label for="wf_path">Or enter server path to CSV file</label>
                <input type="text" id="wf_path" name="wf_path" placeholder="content/posts/import/blog-posts.csv">
            </fieldset>

            <p><button type="submit">Preview Import</button></p>
        </form>

    <?php endif; ?>

    </div>
</main>

<?php require __DIR__ . '/includes/admin-footer.php'; ?>
