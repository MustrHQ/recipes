<?php
/**
 * Import and export, the parts that run in steps from the browser:
 *  - "chunk": a big recipe pack arrives in slices, so it can be larger than the server's upload limit;
 *  - "apply_start" / "apply_next": the import runs a few recipes at a time, so hundreds of recipes
 *    and photos never hit the hosting's time limit.
 * Every call needs an admin session and the form token.
 */
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/transfer.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!is_admin()) out(['error' => 'You are signed out. Sign in again and repeat the import.'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error' => 'Not allowed.'], 405);
if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) out(['error' => 'This page expired. Reload it and try again.'], 400);

$dir = upd_dir();
$action = (string)($_POST['action'] ?? '');
@set_time_limit(120);

/* ---------- Upload in slices ---------- */
if ($action === 'chunk') {
    $id = (string)($_POST['upload_id'] ?? '');
    $index = (int)($_POST['index'] ?? -1);
    $total = (int)($_POST['total'] ?? 0);
    $name = basename((string)($_POST['name'] ?? 'pack.zip'));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!preg_match('/^[a-f0-9]{16}$/', $id) || $index < 0 || $total < 1 || $index >= $total || $total > 20000) out(['error' => 'The upload was garbled. Try again.'], 400);
    if (!in_array($ext, ['zip', 'json'], true)) out(['error' => 'Upload a .zip recipe pack or a recipes.json file.'], 400);
    $f = $_FILES['chunk'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) out(['error' => 'A part of the file did not arrive. Check the connection and try again.'], 400);

    $part = $dir . '/import-' . $id . '.part';
    if ($index === 0) @unlink($part);
    elseif (!is_file($part)) out(['error' => 'The upload was interrupted. Start it again.'], 409);
    // Parts must arrive in order; the browser sends them one after another.
    if ($index > 0 && (int)($_SESSION['chunk'][$id] ?? -1) !== $index - 1) out(['error' => 'Parts arrived out of order. Start the upload again.'], 409);
    if (@file_put_contents($part, (string)file_get_contents($f['tmp_name']), FILE_APPEND) === false) out(['error' => 'Could not save the upload. Check the backups folder is writable (755).'], 500);
    $_SESSION['chunk'][$id] = $index;

    if ($index < $total - 1) out(['ok' => true]);

    unset($_SESSION['chunk'][$id]);
    $file = 'import-' . $id . '.' . $ext;
    if (!@rename($part, $dir . '/' . $file)) out(['error' => 'Could not finish the upload.'], 500);
    try {
        pack_read($dir . '/' . $file, $file);   // fail early on a broken pack
    } catch (Throwable $e) {
        @unlink($dir . '/' . $file);
        out(['error' => $e->getMessage()], 422);
    }
    $old = $_SESSION['import']['file'] ?? '';
    if ($old && $old !== $file && preg_match('/^import-[a-f0-9]{16}\.(zip|json)$/', $old)) @unlink($dir . '/' . $old);
    $_SESSION['import'] = ['file' => $file, 'name' => $name];
    out(['ok' => true, 'done' => true, 'next' => base_url('admin/transfer.php?checked=' . time()) . '#preview']);
}

/* ---------- Import a few recipes at a time ---------- */
$p = $_SESSION['import'] ?? null;
if (!$p || !preg_match('/^import-[a-f0-9]{16}\.(zip|json)$/', $p['file']) || !is_file($dir . '/' . $p['file'])) {
    out(['error' => 'That import has expired. Upload the pack again.'], 410);
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
    $batch = array_slice($rows, $pos, 6);
    try {
        $shared = $p['photos'] ?? [];
        [$c, $u, $ph] = pack_apply(pack_read($dir . '/' . $p['file'], $p['file']), $batch, $shared);
        $_SESSION['import']['photos'] = $shared;
    } catch (Throwable $e) {
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
        unset($_SESSION['import']);
        flash('Imported: ' . plural($t[0], 'new recipe') . ', ' . plural($t[1], 'recipe') . ' updated, ' . plural($t[2], 'photo') . ' added.');
    }
    out(['ok' => true, 'done' => $done, 'pos' => $pos, 'total' => count($rows), 'created' => $t[0], 'updated' => $t[1], 'photos' => $t[2], 'next' => base_url('admin/')]);
}

out(['error' => 'Unknown action.'], 400);
