<?php
/** Resumable upload receipts. A repeated chunk is checked, never appended twice. */
class PackUploadException extends RuntimeException {}

function pack_upload_paths(string $dir, string $id): array
{
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) throw new PackUploadException('Invalid upload reference. Reload the import page.', 400);
    $base = $dir . '/import-' . $id;
    return ['base' => $base, 'state' => $base . '.upload.json', 'lock' => $base . '.upload.lock', 'part' => $base . '.part'];
}

function pack_upload_save(array $paths, array $state): void
{
    $tmp = $paths['state'] . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($tmp, $json, LOCK_EX) !== strlen($json) || !@rename($tmp, $paths['state'])) {
        @unlink($tmp);
        throw new PackUploadException('Could not save upload progress. Check the server has writable space.', 500);
    }
}

function pack_upload_load(array $paths, string $owner): array
{
    $state = is_file($paths['state']) ? json_decode((string)file_get_contents($paths['state']), true) : null;
    if (!is_array($state) || !isset($state['owner']) || !hash_equals($state['owner'], $owner)) {
        throw new PackUploadException('This saved upload is no longer available. Start the upload again.', 410);
    }
    return $state;
}

function pack_upload_finish(array $paths, array &$state): void
{
    if ($state['next_index'] < $state['total']) return;
    $target = $paths['base'] . '.' . $state['ext'];
    clearstatcache();
    if (!is_file($target)) {
        if ($state['done']) throw new PackUploadException('The saved upload was already imported or removed.', 410);
        if (!is_file($paths['part']) || filesize($paths['part']) !== $state['file_size'] || !@rename($paths['part'], $target)) {
            throw new PackUploadException('Could not finish the upload. Resume to retry; completed chunks are kept.', 500);
        }
    }
    if (filesize($target) !== $state['file_size']) throw new PackUploadException('The saved upload size is incorrect. Start it again.', 409);
    $state['done'] = true;
    pack_upload_save($paths, $state);
}

function pack_upload_reply(array $state): array
{
    return ['ok' => true, 'done' => $state['done'], 'next_index' => $state['next_index'],
        'received_bytes' => $state['received_bytes'], 'total' => $state['total'],
        'file_size' => $state['file_size'], 'chunk_size' => $state['chunk_size'],
        'name' => $state['name'], 'images_only' => $state['images_only']];
}

function pack_upload_status(string $dir, string $id, string $owner): array
{
    $paths = pack_upload_paths($dir, $id);
    if (!is_file($paths['state'])) throw new PackUploadException('No saved upload found.', 410);
    $lock = @fopen($paths['lock'], 'c+b');
    if (!$lock || !flock($lock, LOCK_EX)) throw new PackUploadException('Could not read upload progress.', 500);
    try {
        $state = pack_upload_load($paths, $owner);
        pack_upload_finish($paths, $state);
        if (!$state['done'] && !is_file($paths['part'])) throw new PackUploadException('The partial upload expired. Start it again.', 410);
        @touch($paths['state']); @touch($paths['lock']);
        return pack_upload_reply($state);
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function pack_upload_chunk(string $dir, string $id, string $owner, array $meta, int $index, string $bytes): array
{
    $paths = pack_upload_paths($dir, $id);
    $name = basename((string)($meta['name'] ?? ''));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $size = (int)($meta['file_size'] ?? 0); $chunkSize = (int)($meta['chunk_size'] ?? 0);
    $total = (int)($meta['total'] ?? 0); $imagesOnly = !empty($meta['images_only']);
    if (!in_array($ext, ['zip', 'json'], true) || ($imagesOnly && $ext !== 'zip')) throw new PackUploadException('Choose a ZIP of images or recipes, or a recipes.json file.', 400);
    if ($size < 1 || $chunkSize < 1 || $chunkSize > 2 * 1048576 || $total < 1 || $total > 20000
        || $total !== (int)ceil($size / $chunkSize) || $index < 0 || $index >= $total) {
        throw new PackUploadException('Upload details are incomplete. Reload Import and export and select the file again.', 400);
    }
    $offset = $index * $chunkSize;
    $length = min($chunkSize, $size - $offset);
    if (strlen($bytes) !== $length) throw new PackUploadException('That chunk arrived incomplete. Retry it.', 408);
    $lock = @fopen($paths['lock'], 'c+b');
    if (!$lock || !flock($lock, LOCK_EX)) throw new PackUploadException('Could not lock upload progress.', 500);
    try {
        if (is_file($paths['state'])) $state = pack_upload_load($paths, $owner);
        else {
            if ($index !== 0) throw new PackUploadException('Start the upload from the first chunk.', 410);
            if (is_file($paths['part']) || is_file($paths['base'] . '.' . $ext)) throw new PackUploadException('That upload reference is already used. Start again.', 409);
            $state = ['owner' => $owner, 'name' => $name, 'ext' => $ext, 'file_size' => $size,
                'chunk_size' => $chunkSize, 'total' => $total, 'images_only' => $imagesOnly,
                'next_index' => 0, 'received_bytes' => 0, 'done' => false];
            pack_upload_save($paths, $state);
        }
        foreach (['name' => $name, 'file_size' => $size, 'chunk_size' => $chunkSize, 'total' => $total, 'images_only' => $imagesOnly] as $key => $value) {
            if ($state[$key] !== $value) throw new PackUploadException('The file differs from the saved upload. Start a new upload.', 409);
        }
        pack_upload_finish($paths, $state);
        if ($index < $state['next_index']) {
            // The response may have been lost after committing this chunk. Verify its receipt.
            $file = $state['done'] ? $paths['base'] . '.' . $ext : $paths['part'];
            $handle = @fopen($file, 'rb');
            if (!$handle) throw new PackUploadException('The saved upload expired or was already imported.', 410);
            try {
                fseek($handle, $offset); $saved = fread($handle, $length);
                if (!is_string($saved) || !hash_equals(hash('sha256', $saved), hash('sha256', $bytes))) throw new PackUploadException('A repeated chunk has different contents. Start a new upload.', 409);
            } finally { fclose($handle); }
        } elseif ($index !== $state['next_index']) throw new PackUploadException('Upload parts arrived out of order. Resume from saved progress.', 409);
        else {
            $handle = @fopen($paths['part'], 'c+b');
            if (!$handle) throw new PackUploadException('Could not save this chunk. Check writable disk space.', 500);
            try {
                $currentSize = fstat($handle)['size'];
                if ($currentSize < $offset) throw new PackUploadException('The saved upload is incomplete. Start again.', 409);
                // Discard any uncommitted partial write left by an interrupted request.
                if (!ftruncate($handle, $offset) || fseek($handle, $offset) !== 0) throw new PackUploadException('Could not resume the upload file.', 500);
                $written = 0;
                while ($written < $length) {
                    $n = fwrite($handle, substr($bytes, $written));
                    if ($n === false || $n === 0) throw new PackUploadException('The server could not finish saving this chunk. Check disk space.', 500);
                    $written += $n;
                }
                fflush($handle);
            } finally { fclose($handle); }
            $state['next_index']++; $state['received_bytes'] += $length;
            pack_upload_save($paths, $state);
        }
        pack_upload_finish($paths, $state);
        @touch($paths['lock']);
        return pack_upload_reply($state);
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
