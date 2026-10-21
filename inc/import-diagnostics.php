<?php
/** Bounded, private import request log. No recipe contents, tokens or credentials. */
function import_trace_write(string $event, array $extra = []): void
{
    $dir = dirname(__DIR__) . '/backups';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    if (!is_file($dir . '/index.php')) @file_put_contents($dir . '/index.php', "<?php http_response_code(404);\n");
    $file = $dir . '/import-diagnostics.jsonl';
    clearstatcache(true, $file);
    if (is_file($file) && filesize($file) > 512 * 1024) @rename($file, $file . '.previous');
    $trace = $GLOBALS['_import_trace'];
    $row = ['at' => gmdate('c'), 'id' => $trace['id'], 'event' => $event,
        'action' => $trace['action'], 'index' => $trace['index'], 'total' => $trace['total'],
        'request_bytes' => $trace['bytes'], 'elapsed_ms' => (int)((microtime(true) - $trace['start']) * 1000),
        'peak_bytes' => memory_get_peak_usage(true)] + $extra;
    @file_put_contents($file, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX);
}

function import_trace_start(): void
{
    @ini_set('display_errors', '0');
    $GLOBALS['_import_trace_reserve'] = str_repeat('x', 256 * 1024);
    $GLOBALS['_import_trace'] = ['id' => bin2hex(random_bytes(6)), 'start' => microtime(true),
        'action' => substr(preg_replace('/[^a-z_]/', '', (string)($_POST['action'] ?? 'preview')) ?? '', 0, 32),
        'index' => isset($_POST['index']) ? (int)$_POST['index'] : null,
        'total' => isset($_POST['total']) ? (int)$_POST['total'] : null,
        'bytes' => (int)($_SERVER['CONTENT_LENGTH'] ?? 0)];
    header('X-Mustr-Import-Request: ' . $GLOBALS['_import_trace']['id']);
    import_trace_write('begin');
    set_exception_handler(function (Throwable $error): void {
        import_trace_write('exception', ['class' => get_class($error), 'code' => substr((string)$error->getCode(), 0, 24)]);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'The import request stopped unexpectedly. Download the import diagnostics from Import and export.', 'request_id' => $GLOBALS['_import_trace']['id']]);
        }
    });
    register_shutdown_function(function (): void {
        unset($GLOBALS['_import_trace_reserve']);
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            // Keep server paths and raw fatal messages private; expose a correlation ID only.
            import_trace_write('fatal', ['message' => preg_match('/Allowed memory size|Maximum execution time/i', $error['message']) ? substr(explode(' in ', $error['message'])[0], 0, 300) : 'PHP fatal error; see the hosting PHP error log.', 'line' => $error['line']]);
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'PHP stopped this import request. Download the import diagnostics from Import and export.', 'request_id' => $GLOBALS['_import_trace']['id']]);
            }
        } else import_trace_write('end', ['status' => http_response_code() ?: 200]);
    });
}
