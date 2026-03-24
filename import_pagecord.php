<?php
/**
 * Pagecord → Pure Blog Importer (Web UI) v1.0.0
 *
 * Place this file in your Pure Blog root directory, then visit:
 *   https://yourblog.com/import_pagecord.php
 *
 * Place your Pagecord exported Markdown files in content/posts/import/
 * before running. You must be logged in to the Pure Blog admin to use it.
 */

declare(strict_types=1);

@ini_set('max_execution_time', '120');

define('PC_IMPORTER_VERSION', '1.0.0');

require __DIR__ . '/functions.php';
require_setup_redirect();
start_admin_session();
require_admin_login();

$config    = load_config();
$fontStack = font_stack_css($config['theme']['admin_font_stack'] ?? 'sans');
$adminTitle = 'Import from Pagecord';

$action  = $_POST['action'] ?? '';
$error   = '';
$preview = null;
$results = null;

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
    $force_images = !empty($_POST['force_images']);
    $delete_files = !empty($_POST['delete_files']);
    $results = pc_do_import($force_images, $delete_files);
} else {
    $preview = pc_get_posts();
    if ($preview === null) {
        $error = 'The import directory content/posts/import/ does not exist or could not be read.';
    } elseif (count($preview) === 0) {
        $error = 'No Markdown files found in content/posts/import/.';
    }
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function pc_get_posts(): ?array
{
    $importDir = PUREBLOG_POSTS_PATH . '/import';
    if (!is_dir($importDir)) {
        return null;
    }

    $files  = glob($importDir . '/*.md') ?: [];
    $posts  = [];
    $config = load_config();

    foreach ($files as $file) {
        $parsed = parse_post_file($file);
        $front  = $parsed['front_matter'];
        if (empty($front)) {
            continue;
        }

        $dateString = isset($front['published_at']) ? normalize_date_value($front['published_at']) : '';
        $dt         = parse_post_datetime_with_timezone($dateString, $config);
        $timestamp  = $dt ? $dt->getTimestamp() : 0;

        $title     = trim($front['title'] ?? '', '"');
        $slug      = basename($file, '.md');
        $content   = str_replace("# {$title}", '', $parsed['content']);
        $tags      = array_map('trim', (array) ($front['tags'] ?? []));
        $tags      = array_values(array_filter(array_map(fn($t) => str_replace('"', '', $t), $tags)));
        $published = ($front['published'] ?? 'true') === 'true';
        $status    = $published ? 'published' : 'draft';
        $hasImages = (bool) preg_match('/!\[.*?\]/', $parsed['content']);

        $note = '';
        if (!$published) {
            $note = 'Not published — will be saved as draft';
        } elseif ($hasImages) {
            $note = 'Contains images — will be saved as draft unless force-published';
        }

        $posts[] = [
            'title'       => $title,
            'slug'        => $slug,
            'date'        => $dateString ?? '',
            'timestamp'   => $timestamp,
            'status'      => $status,
            'tags'        => $tags,
            'description' => $front['meta_description'] ?? '',
            'content'     => $content,
            'path'        => $file,
            'has_images'  => $hasImages,
            'published'   => $published,
            'note'        => $note,
        ];
    }

    usort($posts, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);
    return $posts;
}

function pc_do_import(bool $force_images, bool $delete_files): array
{
    $posts = pc_get_posts();
    if ($posts === null) {
        return ['error' => 'Import directory not found.'];
    }

    $imported = [];

    foreach ($posts as $post) {
        $status = $post['status'];

        if ($post['has_images'] && !$force_images) {
            $status = 'draft';
        }

        $saveData = [
            'title'       => $post['title'],
            'slug'        => $post['slug'],
            'date'        => $post['date'],
            'status'      => $status,
            'tags'        => $post['tags'],
            'description' => $post['description'],
            'content'     => $post['content'],
        ];

        $saveError = null;
        $ok = save_post($saveData, null, null, null, $saveError);

        if ($delete_files && $ok) {
            @unlink($post['path']);
        }

        $imported[] = [
            'title'  => $post['title'],
            'status' => $status,
            'ok'     => $ok,
            'error'  => $saveError,
            'note'   => $post['note'],
        ];
    }

    return ['imported' => $imported];
}


// ─── Output ───────────────────────────────────────────────────────────────────

require __DIR__ . '/includes/admin-head.php';
?>

<main class="admin-main">
    <div class="admin-content">

    <h1>Import from Pagecord</h1>

    <?php if ($error !== ''): ?>
        <p class="notice delete"><?= e($error) ?></p>
    <?php endif; ?>


    <?php // ── Step 2: Results ───────────────────────────────────────────────
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

        <p class="notice">Note: Pagecord does not distinguish posts from pages. Check your dashboard and delete anything that should not be a post.</p>

        <p><a href="<?= base_path() ?>/admin/dashboard.php">← Back to dashboard</a></p>

        <form method="post" onsubmit="return confirm('Delete this importer file from the server?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cleanup">
            <button type="submit" class="delete">Delete importer file and go to dashboard</button>
        </form>


    <?php // ── Step 1: Preview ───────────────────────────────────────────────
    elseif ($preview !== null && count($preview) > 0): ?>

        <?php
        $total       = count($preview);
        $draft_count = count(array_filter($preview, fn($p) => !$p['published']));
        $image_count = count(array_filter($preview, fn($p) => $p['has_images']));
        ?>

        <p>Found <strong><?= $total ?> post<?= $total !== 1 ? 's' : '' ?></strong> in <code>content/posts/import/</code>.</p>

        <?php if ($draft_count > 0): ?>
            <p class="notice"><?= $draft_count ?> post<?= $draft_count !== 1 ? 's are' : ' is' ?> unpublished and will be saved as a draft.</p>
        <?php endif; ?>

        <?php if ($image_count > 0): ?>
            <p class="notice"><?= $image_count ?> post<?= $image_count !== 1 ? 's contain' : ' contains' ?> images. See the image option below — you will need to copy image files manually after import.</p>
        <?php endif; ?>

        <p class="notice">Note: Pagecord does not distinguish posts from pages. After import, delete anything in the dashboard that should not be a post.</p>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="go">

            <fieldset>
                <legend>Options</legend>

                <?php if ($image_count > 0): ?>
                <label>
                    <input type="checkbox" name="force_images">
                    Publish posts with images <small>(otherwise saved as drafts so you can fix image paths manually)</small>
                </label>
                <?php endif; ?>

                <label>
                    <input type="checkbox" name="delete_files">
                    Delete import files after a successful import
                </label>
            </fieldset>

            <button type="submit" onclick="this.disabled=true; this.textContent='Importing…'; this.form.submit();">
                Import <?= $total ?> post<?= $total !== 1 ? 's' : '' ?>
            </button>
        </form>

        <details>
            <summary>Posts to import (<?= $total ?>)</summary>
            <table>
                <thead><tr><th>Title</th><th>Date</th><th>Status</th><th>Notes</th></tr></thead>
                <tbody>
                <?php foreach ($preview as $p): ?>
                    <tr>
                        <td><?= e($p['title']) ?></td>
                        <td><?= e($p['date']) ?></td>
                        <td><?= e($p['status']) ?></td>
                        <td><?= e($p['note']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </details>

    <?php endif; ?>

    </div>
</main>

<?php require __DIR__ . '/includes/admin-footer.php'; ?>
