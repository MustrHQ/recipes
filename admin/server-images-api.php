<?php
require __DIR__ . '/../inc/import-diagnostics.php';
import_trace_start();
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/server-images.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function server_image_reply(array $data, int $code = 200): never
{
    http_response_code($code); $data['request_id'] = $GLOBALS['_import_trace']['id'];
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if (!is_admin()) server_image_reply(['error' => 'Sign in again to import server images.'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') server_image_reply(['error' => 'Not allowed.'], 405);
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) server_image_reply(['error' => 'This page expired. Reload it.'], 400);
$admin = db()->prepare('SELECT id FROM admins WHERE id = ?');
$admin->execute([(int)$_SESSION['admin_id']]);
if (!$admin->fetchColumn()) { unset($_SESSION['admin_id']); server_image_reply(['error' => 'This admin account is no longer active. Sign in again.'], 401); }
@set_time_limit(120);
try {
    $name = (string)($_POST['name'] ?? '');
    if (($_POST['action'] ?? '') === 'server_start') server_image_reply(server_image_start($name, !empty($_POST['restart'])));
    if (($_POST['action'] ?? '') === 'server_next') server_image_reply(server_image_next($name, (string)($_POST['token'] ?? ''), (int)($_POST['pos'] ?? -1)));
    server_image_reply(['error' => 'Unknown action.'], 400);
} catch (Throwable $e) {
    import_trace_write('error', ['status' => 422, 'message' => 'Server image import stopped.']);
    server_image_reply(['error' => $e->getMessage()], 422);
}
