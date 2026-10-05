<?php
/**
 * Serves the small version of a recipe photo, making it the first time it is asked for.
 * Photos from before thumbnails existed (or added by hand) get one on first view; after that
 * the browser loads uploads/thumbs/ directly.
 */
require __DIR__ . '/inc/bootstrap.php';
require_viewer();

$f = (string)($_GET['f'] ?? '');
if (!preg_match('/^[\w.-]+\.(jpe?g|png|webp|gif)$/i', $f) || !is_file(UPLOAD_DIR . '/' . $f)) {
    http_response_code(404);
    exit;
}
$path = make_thumb($f) ? UPLOAD_DIR . '/thumbs/' . $f . '.jpg' : UPLOAD_DIR . '/' . $f;
$type = str_ends_with($path, ".jpg") && str_contains($path, "/thumbs/") ? 'image/jpeg' : (getimagesize($path)['mime'] ?? 'application/octet-stream');
header('Content-Type: ' . $type);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=31536000, immutable');
readfile($path);
