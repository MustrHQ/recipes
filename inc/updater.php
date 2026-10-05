<?php
/**
 * Updater engine — used by admin/update.php (and the zip helpers by import/export).
 *
 * A release zip is read into memory, checked, and written file by file. Nothing in the zip is
 * run before it is installed: its version is read from version.php as text.
 * Works with PHP's zip extension, or falls back to PharData on hosts without it.
 */

const UPD_KEEP     = ['config.php', 'install.php'];                     // never overwritten
const UPD_KEEP_DIR = ['backups/', 'uploads/', '.git/', 'docs/', '.github/'];
const UPD_EXT      = ['php', 'css', 'js', 'json', 'md', 'txt', 'html', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico',
                      'woff', 'woff2', 'webmanifest', 'sql', 'yml'];
const UPD_NAMED    = ['.htaccess', '.gitignore', '.gitattributes', '.editorconfig', 'LICENSE'];

function upd_dir(): string
{
    $d = ROOT . '/backups';
    if (!is_dir($d)) @mkdir($d, 0750, true);
    if (!file_exists($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\n");
    if (!file_exists($d . '/index.php')) @file_put_contents($d . '/index.php', "<?php http_response_code(404);\n");
    return $d;
}

function upd_can_zip(): bool { return class_exists('ZipArchive') || class_exists('PharData'); }

/** name => bytes for every file in a zip. */
function upd_read_zip(string $path): array
{
    $out = [];
    if (class_exists('ZipArchive')) {
        $z = new ZipArchive();
        if ($z->open($path) !== true) throw new RuntimeException('That file is not a readable zip.');
        for ($i = 0; $i < $z->numFiles; $i++) {
            $name = (string)$z->getNameIndex($i);
            if (str_ends_with($name, '/')) continue;
            $out[$name] = (string)$z->getFromIndex($i);
        }
        $z->close();
        return $out;
    }
    if (class_exists('PharData')) {
        try { $p = new PharData($path); }
        catch (Throwable $e) { throw new RuntimeException('That file is not a readable zip.'); }
        $prefix = 'phar://' . realpath($path) . '/';
        foreach (new RecursiveIteratorIterator($p) as $f) {
            $name = substr(str_replace('\\', '/', $f->getPathname()), strlen($prefix));
            $out[$name] = (string)file_get_contents($f->getPathname());
        }
        return $out;
    }
    throw new RuntimeException('This server cannot open zip files. Ask your host to turn on the "zip" PHP extension.');
}

/** Write a zip from name => bytes. */
function upd_write_zip(string $path, array $files): void
{
    if (class_exists('ZipArchive')) {
        $z = new ZipArchive();
        if ($z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not create the zip file.');
        foreach ($files as $name => $bytes) $z->addFromString($name, $bytes);
        $z->close();
        return;
    }
    if (!class_exists('PharData')) throw new RuntimeException('This server cannot write zip files. Ask your host to turn on the "zip" PHP extension.');
    $p = new PharData($path, 0, null, Phar::ZIP);
    foreach ($files as $name => $bytes) $p->addFromString($name, $bytes);
}

/** Is this relative path safe to write, and is it ours to write? Returns a reason when not. */
function upd_path_problem(string $rel): ?string
{
    if ($rel === '' || str_contains($rel, "\0")) return 'empty name';
    if (str_contains($rel, '\\') || $rel[0] === '/' || preg_match('#^[A-Za-z]:#', $rel)) return 'absolute path';
    foreach (explode('/', $rel) as $seg) if ($seg === '..' || $seg === '.') return 'path climbs out of the app folder';
    $base = basename($rel);
    $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
    if (!in_array($base, UPD_NAMED, true) && !in_array($ext, UPD_EXT, true)) return 'file type not allowed (.' . $ext . ')';
    return null;
}
function upd_is_kept(string $rel): bool
{
    if (in_array($rel, UPD_KEEP, true)) return true;
    foreach (UPD_KEEP_DIR as $d) if (str_starts_with($rel, $d)) return true;
    return false;
}

/**
 * Check an uploaded release. Returns [app, version, files (rel => bytes), skipped (rel => reason),
 * new, changed, same, missing] or throws with a plain explanation.
 */
function upd_inspect(string $zipPath): array
{
    $raw = upd_read_zip($zipPath);
    if (!$raw) throw new RuntimeException('The zip is empty.');

    // Find the app folder inside the zip: the one holding version.php next to inc/bootstrap.php.
    $prefix = null;
    foreach (array_keys($raw) as $n) {
        if (preg_match('#^(.*?)version\.php$#', $n, $m) && isset($raw[$m[1] . 'inc/bootstrap.php'])) {
            if ($prefix === null || strlen($m[1]) < strlen($prefix)) $prefix = $m[1];
        }
    }
    if ($prefix === null) throw new RuntimeException('This is not a MustrHQ Recipes release: there is no version.php next to inc/bootstrap.php.');
    if (substr_count(trim($prefix, '/'), '/') > 0) throw new RuntimeException('The app folder is buried too deep inside this zip.');

    $vsrc = $raw[$prefix . 'version.php'];
    preg_match("#define\\('MUSTR_APP',\\s*'([^']+)'\\)#", $vsrc, $am);
    preg_match("#define\\('MUSTR_VERSION',\\s*'([0-9][0-9A-Za-z.\\-]*)'\\)#", $vsrc, $vm);
    if (($am[1] ?? '') !== MUSTR_APP) throw new RuntimeException('This zip is for a different app (' . ($am[1] ?? 'unknown') . '), not MustrHQ Recipes.');
    if (empty($vm[1])) throw new RuntimeException('The release has no readable version number.');

    $files = []; $skipped = []; $new = $changed = $same = 0;
    foreach ($raw as $name => $bytes) {
        if ($prefix !== '' && !str_starts_with($name, $prefix)) { $skipped[$name] = 'outside the app folder'; continue; }
        $rel = substr($name, strlen($prefix));
        if ($rel === '' || str_starts_with($rel, '__MACOSX/') || basename($rel) === '.DS_Store') continue;
        if ($why = upd_path_problem($rel)) {
            if (!str_starts_with($why, 'file type')) {
                throw new RuntimeException('Refused: the zip contains an unsafe path (' . $rel . ': ' . $why . '). It may have been tampered with.');
            }
            $skipped[$rel] = $why;
            continue;
        }
        if (upd_is_kept($rel)) { $skipped[$rel] = 'kept: your copy is never replaced'; continue; }
        $files[$rel] = $bytes;
        $cur = ROOT . '/' . $rel;
        if (!file_exists($cur)) $new++;
        elseif (hash_file('sha1', $cur) !== sha1($bytes)) $changed++;
        else $same++;
    }
    $missing = [];
    foreach (upd_current_files() as $rel => $_) if (!isset($files[$rel]) && !upd_is_kept($rel)) $missing[] = $rel;

    return ['app' => $am[1], 'version' => $vm[1], 'files' => $files, 'skipped' => $skipped,
            'new' => $new, 'changed' => $changed, 'same' => $same, 'missing' => $missing];
}

/** rel => absolute path for every file that belongs to the running app. */
function upd_current_files(): array
{
    $out = [];
    $root = realpath(ROOT);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        foreach (UPD_KEEP_DIR as $d) if (str_starts_with($rel, $d)) continue 2;
        if ($rel === 'config.php' || str_contains($rel, '.upd-')) continue;
        $out[$rel] = $f->getPathname();
    }
    return $out;
}

function upd_stamp(): string { return date('Ymd-His'); }

/** Zip up the app's code as it is now. Returns the backup file name. */
function upd_backup_files(string $label): string
{
    $files = [];
    foreach (upd_current_files() as $rel => $abs) $files['mustrhq-recipes/' . $rel] = (string)file_get_contents($abs);
    $name = 'files-' . preg_replace('/[^0-9A-Za-z.\-]/', '', MUSTR_VERSION) . '-' . upd_stamp() . ($label ? '-' . preg_replace('/[^0-9A-Za-z.\-]/', '', $label) : '') . '.zip';
    upd_write_zip(upd_dir() . '/' . $name, $files);
    return $name;
}

/** Zip of every photo in uploads/. Returns the file name, or null if there are none. */
function upd_backup_photos(): ?string
{
    $files = [];
    foreach (glob(UPLOAD_DIR . '/*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE) ?: [] as $f) $files['uploads/' . basename($f)] = (string)file_get_contents($f);
    if (!$files) return null;
    $name = 'photos-' . upd_stamp() . '.zip';
    upd_write_zip(upd_dir() . '/' . $name, $files);
    return $name;
}

/** Plain SQL dump of the database, gzipped. Returns the file name. */
function upd_backup_db(): string
{
    $name = 'db-' . upd_stamp() . '.sql.gz';
    $gz = gzopen(upd_dir() . '/' . $name, 'w6');
    if (!$gz) throw new RuntimeException('Could not write the database backup. Check the backups folder is writable.');
    gzwrite($gz, '-- MustrHQ Recipes ' . MUSTR_VERSION . ' database backup, ' . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
    foreach (db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $create = db()->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
        gzwrite($gz, "DROP TABLE IF EXISTS `$t`;\n$create;\n");
        $st = db()->query("SELECT * FROM `$t`");
        $batch = [];
        while ($row = $st->fetch(PDO::FETCH_NUM)) {
            $batch[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : db()->quote((string)$v), $row)) . ')';
            if (count($batch) === 200) { gzwrite($gz, "INSERT INTO `$t` VALUES " . implode(",\n", $batch) . ";\n"); $batch = []; }
        }
        if ($batch) gzwrite($gz, "INSERT INTO `$t` VALUES " . implode(",\n", $batch) . ";\n");
        gzwrite($gz, "\n");
    }
    gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
    gzclose($gz);
    return $name;
}

/**
 * Write the files. Each is written beside its target and renamed into place, so a request that
 * lands mid-update never reads half a file. On any failure the backup is put back.
 */
function upd_apply(array $files, ?string $backupName): int
{
    $written = 0;
    try {
        foreach ($files as $rel => $bytes) {
            $dest = ROOT . '/' . $rel;
            $dir = dirname($dest);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('Could not create the folder for ' . $rel . '.');
            $tmp = $dest . '.upd-' . bin2hex(random_bytes(3));
            if (@file_put_contents($tmp, $bytes) === false) throw new RuntimeException('Could not write ' . $rel . '. Check folder permissions.');
            if (!@rename($tmp, $dest)) { @unlink($tmp); throw new RuntimeException('Could not replace ' . $rel . '.'); }
            $written++;
        }
    } catch (Throwable $e) {
        if ($backupName) upd_restore_quiet($backupName);
        throw new RuntimeException($e->getMessage() . ' Nothing was changed: the previous version has been put back.');
    }
    if (function_exists('opcache_reset')) @opcache_reset();
    return $written;
}

function upd_restore_quiet(string $backupName): void
{
    try {
        $info = upd_inspect(upd_dir() . '/' . basename($backupName));
        foreach ($info['files'] as $rel => $bytes) @file_put_contents(ROOT . '/' . $rel, $bytes);
        if (function_exists('opcache_reset')) @opcache_reset();
    } catch (Throwable $e) { /* best effort */ }
}

function upd_backups(): array
{
    $out = [];
    foreach (glob(upd_dir() . '/{files-*.zip,db-*.sql.gz,photos-*.zip}', GLOB_BRACE) ?: [] as $f) {
        $b = basename($f);
        $out[] = ['name' => $b, 'size' => filesize($f), 'time' => filemtime($f),
                  'kind' => str_starts_with($b, 'db-') ? 'Database' : (str_starts_with($b, 'photos-') ? 'Photos' : 'App files')];
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

/** Tidy away abandoned uploads (updates and imports) older than a day. */
function upd_tidy(): void
{
    foreach (glob(upd_dir() . '/incoming-*.zip') ?: [] as $f) if (filemtime($f) < time() - 86400) @unlink($f);
    foreach (glob(upd_dir() . '/import-*.zip') ?: [] as $f) if (filemtime($f) < time() - 86400) @unlink($f);
}
