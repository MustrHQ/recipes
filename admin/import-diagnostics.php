<?php
require __DIR__ . '/../inc/bootstrap.php';
require_admin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Content-Disposition: attachment; filename="mustr-import-diagnostics.json"');
$events = [];
$file = ROOT . '/backups/import-diagnostics.jsonl';
if (is_file($file)) {
    $handle = fopen($file, 'rb');
    if ($handle) {
        if (filesize($file) > 600 * 1024) { fseek($handle, -600 * 1024, SEEK_END); fgets($handle); }
        while (($line = fgets($handle)) !== false) {
            $row = json_decode($line, true);
            if (is_array($row)) $events[] = $row;
        }
        fclose($handle);
    }
}
echo json_encode(['app_version' => MUSTR_VERSION, 'exported_at' => gmdate('c'),
    'php_version' => PHP_VERSION, 'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'), 'post_max_size' => ini_get('post_max_size'),
    'upload_max_filesize' => ini_get('upload_max_filesize'), 'zip_extension' => class_exists('ZipArchive'),
    'phar_extension' => class_exists('PharData'), 'gd_extension' => extension_loaded('gd'),
    'events' => array_slice($events, -600)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
