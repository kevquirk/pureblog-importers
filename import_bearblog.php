<?php
/**
 * BearBlog → Pure Blog Importer (Web UI) v1.0.0
 *
 * Original concept by David (justdaj) — https://github.com/kevquirk/pureblog/pull/24
 *
 * Place this file in your Pure Blog root directory, then visit:
 *   https://yourblog.com/import_bearblog.php
 *
 * You must be logged in to the Pure Blog admin to use it.
 *
 * Before importing:
 *   1. Export your posts from BearBlog in Markdown format
 *   2. Create the folder content/posts/import/ in your Pure Blog install
 *   3. Copy your exported .md files into that folder
 */

declare(strict_types=1);

require __DIR__ . '/functions.php';
require_setup_redirect();
start_admin_session();
require_admin_login();

$config     = load_config();
$fontStack  = font_stack_css($config['theme']['admin_font_stack'] ?? 'sans');
$adminTitle = 'Import from BearBlog';

$action  = $_POST['action'] ?? '';
$error   = '';
$preview = null;
$results = null;

// ─── Action: Preview ──────────────────────────────────────────────────────────

if ($action === 'preview') {
    verify_csrf();
    $posts = get_bear_posts();
    if (empty($posts)) {
        $error = 'Nothing found to import. Make sure your .md files are in content/posts/import/.';
    } else {
        $preview = $posts;
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
    $force_images  = !empty($_POST['force_images']);
    $delete_files  = !empty($_POST['delete_files']);
    $results = do_bear_import(get_bear_posts(), $force_images, $delete_files);
}


// ─── BearBlog Logic ───────────────────────────────────────────────────────────

function get_bear_posts(): array
{
    $import_path = PUREBLOG_POSTS_PATH . '/import';

    if (!is_dir($import_path)) {
        return [];
    }

    $files  = glob($import_path . '/*.md') ?: [];
    $posts  = [];
    $config = load_config();

    foreach ($files as $file) {
        $parsed = parse_post_file($file);
        $front  = $parsed['front_matter'];

        $date_string = normalize_date_value($front['published_date'] ?? '') ?? '';
        $dt          = $date_string ? parse_post_datetime_with_timezone($date_string, $config) : null;

        $is_page    = ($front['is_page']  ?? 'false') !== 'false';
        $publish    = ($front['publish']  ?? 'true')  === 'true';
        $has_images = (bool) preg_match('/!\[.*?\]/', $parsed['content']);

        $note = '';
        if ($is_page)      $note = 'Page — will not be imported';
        elseif (!$publish) $note = 'Will be saved as draft';
        elseif ($has_images) $note = 'Contains images — check URLs before publishing';

        $posts[] = [
            'title'       => $front['title']            ?? 'Untitled',
            'slug'        => $front['slug']              ?? '',
            'date'        => $date_string,
            'timestamp'   => $dt ? $dt->getTimestamp()  : 0,
            'status'      => $publish ? 'published'     : 'draft',
            'tags'        => $front['tags']              ?? [],
            'description' => $front['meta_description'] ?? '',
            'content'     => $parsed['content'],
            'path'        => $file,
            'is_page'     => $is_page,
            'has_images'  => $has_images,
            'note'        => $note,
        ];
    }

    usort($posts, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);
    return $posts;
}


function do_bear_import(array $posts, bool $force_images, bool $delete_files): array
{
    $imported = [];
    $skipped  = [];

    foreach ($posts as $post) {
        if ($post['is_page']) {
            $skipped[] = $post['title'];
            continue;
        }

        // Posts with images are saved as draft unless the user forces publish
        if ($post['has_images'] && !$force_images) {
            $post['status'] = 'draft';
        }

        $save_data = [
            'title'       => $post['title'],
            'slug'        => $post['slug'],
            'date'        => $post['date'],
            'status'      => $post['status'],
            'tags'        => $post['tags'],
            'description' => $post['description'],
            'content'     => $post['content'],
        ];

        $save_error = null;
        $ok = save_post($save_data, null, null, null, $save_error);

        $imported[] = [
            'title'  => $post['title'],
            'status' => $post['status'],
            'note'   => $post['note'],
            'ok'     => $ok,
            'error'  => $save_error,
        ];

        if ($delete_files) {
            @unlink($post['path']);
        }
    }

    return ['imported' => $imported, 'skipped' => $skipped];
}


// ─── Output ───────────────────────────────────────────────────────────────────

require __DIR__ . '/includes/admin-head.php';
?>

<main class="admin-main">
    <div class="admin-content">

    <h1>Import from BearBlog</h1>

    <?php if ($error !== ''): ?>
        <p class="notice delete"><?= e($error) ?></p>
    <?php endif; ?>


    <?php // ── Step 3: Results ───────────────────────────────────────────────
    if ($results !== null): ?>

        <?php
        $ok_count   = count(array_filter($results['imported'], fn($r) => $r['ok']));
        $fail_count = count(array_filter($results['imported'], fn($r) => !$r['ok']));
        $skip_count = count($results['skipped']);
        ?>

        <p class="notice">
            Import complete — <?= $ok_count ?> post<?= $ok_count !== 1 ? 's' : '' ?> imported
            <?= $skip_count > 0 ? ", {$skip_count} page" . ($skip_count !== 1 ? 's' : '') . ' skipped' : '' ?>.
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
                    <small>(<?= e($r['status']) ?><?= $r['note'] ? ', ' . e($r['note']) : '' ?>)</small>
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
        $total      = count($preview);
        $pages      = array_filter($preview, fn($p) => $p['is_page']);
        $drafts     = array_filter($preview, fn($p) => !$p['is_page'] && $p['status'] === 'draft');
        $with_imgs  = array_filter($preview, fn($p) => !$p['is_page'] && $p['has_images']);
        $clean      = array_filter($preview, fn($p) => !$p['is_page'] && $p['note'] === '');
        ?>

        <p>Found <strong><?= $total ?> file<?= $total !== 1 ? 's' : '' ?></strong> in <code>content/posts/import/</code>.</p>

        <?php if (count($pages) > 0): ?>
            <p class="notice"><?= count($pages) ?> file<?= count($pages) !== 1 ? 's are' : ' is a' ?> page<?= count($pages) !== 1 ? 's' : '' ?> and will be skipped (BearBlog pages are not supported).</p>
        <?php endif; ?>

        <?php if (count($drafts) > 0): ?>
            <p class="notice"><?= count($drafts) ?> post<?= count($drafts) !== 1 ? 's are' : ' is' ?> marked as draft and will be imported as draft.</p>
        <?php endif; ?>

        <?php if (count($with_imgs) > 0): ?>
            <p class="notice"><?= count($with_imgs) ?> post<?= count($with_imgs) !== 1 ? 's contain' : ' contains' ?> images and will be saved as draft so you can check the image URLs. Use the option below to override this.</p>
        <?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="go">

            <fieldset>
                <legend>Options</legend>

                <?php if (count($with_imgs) > 0): ?>
                <label>
                    <input type="checkbox" name="force_images">
                    Publish posts with images (without checking image URLs first)
                </label>
                <?php endif; ?>

                <label>
                    <input type="checkbox" name="delete_files">
                    Delete files from <code>import/</code> folder after importing
                </label>
            </fieldset>

            <button type="submit" onclick="this.disabled=true; this.textContent='Importing…'; this.form.submit();">
                Import <?= count($preview) - count($pages) ?> post<?= (count($preview) - count($pages)) !== 1 ? 's' : '' ?>
            </button>
            <a href="<?= base_path() ?>/import_bearblog.php">Start over</a>
        </form>

        <details>
            <summary>All files (<?= $total ?>)</summary>
            <table>
                <thead><tr><th>Title</th><th>Date</th><th>Status</th><th>Note</th></tr></thead>
                <tbody>
                <?php foreach ($preview as $p): ?>
                    <tr>
                        <td><?= e($p['title']) ?></td>
                        <td><?= e($p['date'] ? substr($p['date'], 0, 10) : '—') ?></td>
                        <td><?= $p['is_page'] ? '—' : e($p['status']) ?></td>
                        <td><small><?= e($p['note']) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </details>


    <?php // ── Step 1: Instructions ──────────────────────────────────────────
    else: ?>

        <ol>
            <li>Export your posts from BearBlog in <strong>Markdown</strong> format.</li>
            <li>Create the folder <code>content/posts/import/</code> in your Pure Blog install.</li>
            <li>Copy your exported <code>.md</code> files into that folder.</li>
            <li>Click <strong>Preview Import</strong> below.</li>
        </ol>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="preview">
            <button type="submit">Preview Import</button>
        </form>

    <?php endif; ?>

    </div>
</main>

<?php require __DIR__ . '/includes/admin-footer.php'; ?>
