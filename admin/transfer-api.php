<?php
/**
 * Import and export, the parts that run in steps from the browser:
 *  - "chunk": a big recipe pack arrives in slices, so it can be larger than the server's upload limit;
 *  - "apply_start" / "apply_next": the import runs a few recipes at a time, so hundreds of recipes
 *    and photos never hit the hosting's time limit.
 * Every call needs an admin session and the form token.
 */
require __DIR__ . '/../inc/import-diagnostics.php';
import_trace_start();
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/transfer.php';
require __DIR__ . '/../inc/upload.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    $data['request_id'] = $GLOBALS['_import_trace']['id'];
    if ($code >= 400) import_trace_write('error', ['status' => $code, 'message' => 'Request rejected or processing failed.']);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!is_admin()) out(['error' => 'You are signed out. Sign in again and repeat the import.'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error' => 'Not allowed.'], 405);
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) out(['error' => 'This page expired. Reload it and try again.'], 400);

$dir = upd_dir();
$action = (string)($_POST['action'] ?? '');
@set_time_limit(120);

/* ---------- Resumable upload; chunk retries never append twice ---------- */
if ($action === 'chunk' || $action === 'upload_status') {
    $id = (string)($_POST['upload_id'] ?? '');
    $owner = hash('sha256', session_id() . '|' . (int)$_SESSION['admin_id']);
    try {
        if ($action === 'upload_status') $reply = pack_upload_status($dir, $id, $owner);
        else {
            $f = $_FILES['chunk'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) out(['error' => 'A file chunk did not arrive. Retry it.'], 408);
            $meta = ['name' => (string)($_POST['name'] ?? ''), 'total' => (int)($_POST['total'] ?? 0),
                'file_size' => (int)($_POST['file_size'] ?? 0), 'chunk_size' => (int)($_POST['chunk_size'] ?? 0),
                'images_only' => ($_POST['import_mode'] ?? '') === 'images'];
            $reply = pack_upload_chunk($dir, $id, $owner, $meta, (int)($_POST['index'] ?? -1), (string)file_get_contents($f['tmp_name']));
        }
        if ($reply['done']) {
            $file = 'import-' . $id . '.' . strtolower(pathinfo($reply['name'], PATHINFO_EXTENSION));
            if (($_SESSION['import']['file'] ?? '') !== $file) {
                $old = $_SESSION['import']['file'] ?? '';
                if ($old && preg_match('/^import-[a-f0-9]{16}\.(zip|json)$/', $old)) { @unlink($dir . '/' . $old); @unlink($dir . '/' . $old . '.cache.json'); }
                $_SESSION['import'] = ['file' => $file, 'name' => $reply['name'], 'images_only' => $reply['images_only']];
            }
            $reply['needs_validation'] = true;
            $reply['next'] = base_url('admin/transfer.php?checked=' . time()) . '#preview';
        }
        out($reply);
    } catch (PackUploadException $e) {
        out(['error' => $e->getMessage()], $e->getCode() ?: 500);
    }
}

/* ---------- Import a few recipes at a time ---------- */
$p = $_SESSION['import'] ?? null;
if (!$p || !preg_match('/^import-[a-f0-9]{16}\.(zip|json)$/', $p['file']) || !is_file($dir . '/' . $p['file'])) {
    out(['error' => 'That import has expired. Upload the pack again.'], 410);
}

if ($action === 'validate') {
    $uploadId = (string)($_POST['upload_id'] ?? '');
    if ($uploadId !== '' && (!preg_match('/^[a-f0-9]{16}$/', $uploadId) || !str_starts_with($p['file'], 'import-' . $uploadId . '.'))) out(['error' => 'A different upload is active. Reload Import and export.'], 409);
    try {
        pack_preview_read($dir . '/' . $p['file'], $p['file'], !empty($p['images_only']), true);
        out(['ok' => true, 'next' => base_url('admin/transfer.php?checked=' . time()) . '#preview']);
    } catch (Throwable $e) {
        out(['error' => $e->getMessage()], 422);
    }
}

if ($action === 'apply_start') {
    $rows = array_values(array_unique(array_map('intval', (array)($_POST['rows'] ?? []))));
    sort($rows);
    if (!$rows) out(['error' => 'Tick at least one recipe to import.'], 400);
    $_SESSION['import']['rows'] = $rows;
    $_SESSION['import']['pos'] = 0;
    $_SESSION['import']['totals'] = [0, 0, 0];
    $_SESSION['import']['photos'] = [];
    out(['ok' => true, 'total' => count($rows)]);
}

if ($action === 'apply_next') {
    $rows = $p['rows'] ?? null;
    if (!$rows) out(['error' => 'Start the import again.'], 409);
    $pos = (int)$p['pos'];
    $batch = array_slice($rows, $pos, 1);
    try {
        $shared = $p['photos'] ?? [];
        $toApply = pack_preview_read($dir . '/' . $p['file'], $p['file'], !empty($p['images_only']));
        if (!empty($toApply['images_only'])) $toApply = pack_read($dir . '/' . $p['file'], $p['file'], true);
        pack_check_image_preview($toApply, $batch, $p['image_signatures'] ?? []);
        [$c, $u, $ph] = pack_apply($toApply, $batch, $shared);
        $_SESSION['import']['photos'] = $shared;
    } catch (Throwable $e) {
        @unlink($dir . '/' . $p['file'] . '.cache.json');
        out(['error' => $e->getMessage() . ($pos ? ' The ' . $pos . ' recipes before it were imported. Import the pack again to finish: recipes already done are simply updated.' : ''), 'done_so_far' => $pos], 500);
    }
    $t = $p['totals'];
    $t = [$t[0] + $c, $t[1] + $u, $t[2] + $ph];
    $pos += count($batch);
    $_SESSION['import']['pos'] = $pos;
    $_SESSION['import']['totals'] = $t;
    $done = $pos >= count($rows);
    if ($done) {
        @unlink($dir . '/' . $p['file']);
        @unlink($dir . '/' . $p['file'] . '.cache.json');
        unset($_SESSION['import']);
        flash('Imported: ' . plural($t[0], 'new recipe') . ', ' . plural($t[1], 'recipe') . ' updated, ' . plural($t[2], 'photo') . ' added.');
    }
    out(['ok' => true, 'done' => $done, 'pos' => $pos, 'total' => count($rows), 'created' => $t[0], 'updated' => $t[1], 'photos' => $t[2], 'next' => base_url('admin/')]);
}

out(['error' => 'Unknown action.'], 400);
