<?php
/** Read image ZIPs already uploaded through cPanel. Original archives are never moved or deleted. */
require_once __DIR__ . '/transfer.php';

function server_image_storage(string $leaf): string
{
    $dir = upd_dir() . '/' . $leaf;
    if (is_link($dir)) throw new RuntimeException('The image import folder must be a regular folder.');
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) throw new RuntimeException('Could not create ' . $leaf . '. Check folder permissions in cPanel.');
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    if (!is_file($dir . '/index.php')) @file_put_contents($dir . '/index.php', "<?php http_response_code(404);\n");
    return $dir;
}

function server_image_source(string $name): array
{
    if ($name === '' || $name[0] === '.' || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")
        || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') throw new RuntimeException('Choose a ZIP from the server image folder.');
    $dir = server_image_storage('image-inbox'); $path = $dir . '/' . $name;
    clearstatcache(true, $path);
    if (is_link($path) || !is_file($path) || !is_readable($path) || realpath(dirname($path)) !== realpath($dir)) {
        throw new RuntimeException('That ZIP is unavailable. Finish uploading it in cPanel, then refresh the file list.');
    }
    $stat = stat($path);
    if (!$stat || $stat['size'] < 1) throw new RuntimeException('The ZIP is empty or still uploading.');
    $fingerprint = hash('sha256', json_encode([$name, $stat['dev'], $stat['ino'], $stat['size'], $stat['mtime'], $stat['ctime']], JSON_THROW_ON_ERROR));
    return ['name' => $name, 'path' => $path, 'size' => $stat['size'], 'modified' => $stat['mtime'], 'fingerprint' => $fingerprint];
}

function server_image_job_paths(string $name): array
{
    $base = server_image_storage('image-import-jobs') . '/' . hash('sha256', $name);
    return ['state' => $base . '.json', 'lock' => $base . '.lock'];
}

function server_image_job_save(array $paths, array $job): void
{
    $tmp = $paths['state'] . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($job, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (@file_put_contents($tmp, $json, LOCK_EX) !== strlen($json) || !@rename($tmp, $paths['state'])) {
        @unlink($tmp); throw new RuntimeException('Could not save import progress. Check writable disk space.');
    }
}

function server_image_job_load(array $paths): ?array
{
    if (!is_file($paths['state'])) return null;
    $job = json_decode((string)file_get_contents($paths['state']), true);
    return is_array($job) && isset($job['fingerprint'], $job['token'], $job['rows'], $job['pos']) ? $job : null;
}

function server_image_job_reply(array $job): array
{
    return ['ok' => true, 'token' => $job['token'], 'name' => $job['name'], 'pos' => $job['pos'],
        'total' => count($job['rows']), 'done' => $job['pos'] >= count($job['rows']),
        'recipes_updated' => $job['updated'], 'photos_saved' => $job['photos_saved'],
        'matched_files' => $job['matched_files'], 'unmatched_files' => $job['unmatched_files'],
        'last_error' => $job['last_error'] ?? ''];
}

function server_image_files(): array
{
    $dir = server_image_storage('image-inbox'); $files = [];
    foreach (new DirectoryIterator($dir) as $entry) {
        if ($entry->isDot() || $entry->isLink() || !$entry->isFile() || $entry->getFilename()[0] === '.'
            || strtolower($entry->getExtension()) !== 'zip') continue;
        try {
            $file = server_image_source($entry->getFilename());
            $job = server_image_job_load(server_image_job_paths($file['name']));
            $file['job'] = $job && hash_equals($file['fingerprint'], $job['fingerprint']) ? server_image_job_reply($job) : null;
            $files[] = $file;
        } catch (RuntimeException $e) { continue; }
    }
    usort($files, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    return $files;
}

function server_image_start(string $name, bool $restart = false): array
{
    $source = server_image_source($name); $paths = server_image_job_paths($name);
    $lock = @fopen($paths['lock'], 'c+b');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Could not lock this image import.');
    try {
        $job = server_image_job_load($paths);
        if (!$restart && $job && hash_equals($source['fingerprint'], $job['fingerprint'])) return server_image_job_reply($job);
        $pack = pack_read($source['path'], $name, true); // Image-only even if recipes.json is present.
        if (!hash_equals($source['fingerprint'], server_image_source($name)['fingerprint'])) throw new RuntimeException('The ZIP changed while it was checked. Finish the cPanel upload before importing.');
        $used = [];
        foreach ($pack['recipes'] as $recipe) foreach ($recipe['bindings'] as $binding) $used[$binding['photo']] = true;
        $job = ['name' => $name, 'fingerprint' => $source['fingerprint'], 'token' => bin2hex(random_bytes(16)),
            'rows' => array_keys($pack['recipes']), 'signatures' => pack_image_signatures($pack), 'pos' => 0,
            'updated' => 0, 'photos_saved' => 0, 'shared' => [], 'matched_files' => count($used),
            'unmatched_files' => $pack['unmatched_images'] ?? [], 'last_error' => '', 'started_at' => gmdate('c')];
        server_image_job_save($paths, $job);
        return server_image_job_reply($job);
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function server_image_next(string $name, string $token, int $position): array
{
    $source = server_image_source($name); $paths = server_image_job_paths($name);
    $lock = @fopen($paths['lock'], 'c+b');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Could not lock this image import.');
    try {
        $job = server_image_job_load($paths);
        if (!$job || !hash_equals($job['token'], $token)) throw new RuntimeException('That import was replaced. Refresh the file list.');
        if (!hash_equals($source['fingerprint'], $job['fingerprint'])) throw new RuntimeException('The server ZIP changed. Finish uploading it, then refresh and import the new file.');
        if ($position !== $job['pos'] || $job['pos'] >= count($job['rows'])) return server_image_job_reply($job);
        try {
            $pack = pack_read($source['path'], $name, true);
            if (!hash_equals($source['fingerprint'], server_image_source($name)['fingerprint'])) throw new RuntimeException('The ZIP changed during import. Finish the upload and refresh.');
            $batch = array_slice($job['rows'], $job['pos'], 1);
            pack_check_image_preview($pack, $batch, $job['signatures']);
            $shared = $job['shared'];
            [, $updated, $photos] = pack_apply_images($pack, $batch, $shared);
            $job['shared'] = $shared; $job['pos'] += count($batch);
            $job['updated'] += $updated; $job['photos_saved'] += $photos; $job['last_error'] = '';
            if ($job['pos'] >= count($job['rows'])) $job['completed_at'] = gmdate('c');
            server_image_job_save($paths, $job);
            return server_image_job_reply($job);
        } catch (Throwable $e) {
            $job['last_error'] = $e->getMessage(); server_image_job_save($paths, $job);
            throw $e;
        }
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
