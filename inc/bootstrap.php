<?php
/**
 * MustrHQ Recipes — shared bootstrap. Every page starts here.
 * Plain PHP 8.1+, PDO MySQL. No framework, no Composer.
 *
 * Copyright (C) MustrHQ contributors. Licensed under the GNU AGPL-3.0 (see LICENSE).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/version.php';

define('APP_NAME', 'MustrHQ Recipes');
define('APP_VERSION', MUSTR_VERSION);
define('ROOT', dirname(__DIR__));
define('UPLOAD_DIR', ROOT . '/uploads');
define('MAX_UPLOAD_BYTES', 10 * 1024 * 1024);
define('MAX_IMAGE_SIDE', 1800);

/** The 14 allergens UK food law requires you to declare. Keys are stored; labels are shown. */
const ALLERGENS = [
    'celery' => 'Celery', 'gluten' => 'Gluten', 'crustaceans' => 'Crustaceans',
    'eggs' => 'Eggs', 'fish' => 'Fish', 'lupin' => 'Lupin', 'milk' => 'Milk', 'molluscs' => 'Molluscs',
    'mustard' => 'Mustard', 'nuts' => 'Tree nuts', 'peanuts' => 'Peanuts', 'sesame' => 'Sesame',
    'soya' => 'Soya', 'sulphites' => 'Sulphites',
];
const DIETS = ['vegan' => 'Vegan', 'vegetarian' => 'Vegetarian', 'halal' => 'Halal', 'gluten_free' => 'Gluten free'];

/** What the first date on a label means. */
const LABEL_KINDS = ['made' => 'Made', 'opened' => 'Opened', 'defrosted' => 'Defrosted'];

// Some hosts ship without mbstring; these fallbacks keep things working.
if (!function_exists('mb_substr')) {
    function mb_substr(string $s, int $start, ?int $len = null): string
    {
        $a = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return implode('', array_slice($a, $start, $len));
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $s): int { return (int)preg_match_all('/./us', $s); }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $s): string { return strtolower($s); }
}

/** URL inside the app folder, wherever it is installed (e.g. /recipes/). */
function base_url(string $path = ''): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
    $dir = rtrim(dirname($script), '/');
    if (str_ends_with($dir, '/admin')) $dir = substr($dir, 0, -6);
    return $dir . '/' . ltrim($path, '/');
}

/* ---------- Security headers (sent before anything else) ---------- */
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'");
}

if (is_file(ROOT . '/config.php')) {
    require ROOT . '/config.php';
} elseif (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php') {
    header('Location: ' . base_url('install.php'));
    exit;
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('mhq_recipes');
session_set_cookie_params([
    'path' => base_url(),
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
    }
    return $pdo;
}

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function img_url(?string $file): string { return $file ? base_url('uploads/' . rawurlencode($file)) : ''; }
/** Asset URL stamped with the file's change time, so browsers never hold an old copy after an update. */
function asset(string $file): string
{
    $v = @filemtime(ROOT . '/assets/' . $file) ?: APP_VERSION;
    return base_url('assets/' . $file) . '?v=' . $v;
}

function setting(string $k, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        if (!defined('DB_HOST')) return $default;
        try {
            foreach (db()->query('SELECT k, v FROM settings') as $r) $cache[$r['k']] = $r['v'];
        } catch (PDOException $ex) {
            return $default;
        }
    }
    return $cache[$k] ?? $default;
}
function set_setting(string $k, string $v): void
{
    db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$k, $v]);
}
function kitchen_name(): string { return (string)setting('kitchen_name', 'Kitchen'); }

/* ---------- Database upgrades ---------- */
/**
 * Bring an existing database up to this release. Each step runs once, in order, and is
 * recorded in settings.schema_version. Steps only add — they never drop your data.
 */
function run_migrations(): array
{
    $steps = require ROOT . '/inc/migrations.php';
    $have = (int)setting('schema_version', '1');
    $ran = [];
    foreach ($steps as $n => $sqls) {
        if ($n <= $have) continue;
        foreach ($sqls as $sql) db()->exec($sql);
        set_setting('schema_version', (string)$n);
        $ran[] = $n;
    }
    return $ran;
}
function schema_target(): int { return (int)max(array_keys(require ROOT . '/inc/migrations.php')); }

// Upgrade quietly on the first request after new files are uploaded.
if (defined('DB_HOST') && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php') {
    try {
        if ((int)setting('schema_version', '1') < schema_target()) run_migrations();
    } catch (Throwable $t) {
        error_log('MustrHQ Recipes migration failed: ' . $t->getMessage());
    }
}

/* ---------- CSRF + flash ---------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf_token() . '">'; }
function check_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('This form expired. Go back, reload the page and try again.');
    }
}
function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) { $_SESSION['flash'] = [$type, $msg]; return null; }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}
function redirect(string $url): never { header('Location: ' . $url); exit; }

/* ---------- Access ---------- */
function is_admin(): bool { return !empty($_SESSION['admin_id']); }
function require_admin(): void
{
    if (!is_admin()) redirect(base_url('admin/login.php'));
    // Signed out everywhere if the account was removed.
    $st = db()->prepare('SELECT id FROM admins WHERE id = ?');
    $st->execute([$_SESSION['admin_id']]);
    if (!$st->fetchColumn()) { $_SESSION = []; redirect(base_url('admin/login.php')); }
}

/** Optional kitchen code for the recipe screens. Admins always get through. */
function require_viewer(): void
{
    $code = (string)setting('viewer_code', '');
    if ($code === '' || is_admin()) return;
    // A changed code signs every device out.
    if (($_SESSION['viewer_ok'] ?? '') === hash('sha256', $code)) return;
    redirect(base_url('unlock.php?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? base_url())));
}

/** Throttle guessing: 8 failures from one address in 15 minutes locks that address for a while. */
function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45); }
function throttle_wait(string $kind): int
{
    $st = db()->prepare('SELECT COUNT(*), MAX(at) FROM login_attempts WHERE kind = ? AND ip = ? AND at > (NOW() - INTERVAL 15 MINUTE)');
    $st->execute([$kind, client_ip()]);
    [$n, $last] = $st->fetch(PDO::FETCH_NUM);
    if ((int)$n < 8) return 0;
    $wait = 60 * min(15, (int)$n - 7) - (time() - strtotime((string)$last));
    return max(0, $wait);
}
function throttle_fail(string $kind): void
{
    db()->prepare('INSERT INTO login_attempts (kind, ip) VALUES (?, ?)')->execute([$kind, client_ip()]);
    if (random_int(1, 20) === 1) db()->exec('DELETE FROM login_attempts WHERE at < (NOW() - INTERVAL 1 DAY)');
}
function throttle_clear(string $kind): void
{
    db()->prepare('DELETE FROM login_attempts WHERE kind = ? AND ip = ?')->execute([$kind, client_ip()]);
}

/* ---------- Images ---------- */
/**
 * Store image bytes as a new file in uploads/. Large photos are shrunk when GD is available.
 * Returns the stored file name. Throws RuntimeException with a plain-English message.
 */
function save_image_bytes(string $bytes, string $label): string
{
    if (strlen($bytes) > MAX_UPLOAD_BYTES) throw new RuntimeException('"' . $label . '" is over 10 MB. Use a smaller image.');
    $info = @getimagesizefromstring($bytes);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($types[$info[2]])) throw new RuntimeException('"' . $label . '" is not a JPG, PNG, WebP or GIF image.');
    $ext = $types[$info[2]];
    if (!is_dir(UPLOAD_DIR) || !is_writable(UPLOAD_DIR)) throw new RuntimeException('The uploads folder is not writable. Set it to permission 755.');
    $name = date('Ym') . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = UPLOAD_DIR . '/' . $name;

    [$w, $h] = $info;
    $max = MAX_IMAGE_SIDE;
    if (extension_loaded('gd') && ($w > $max || $h > $max) && $ext !== 'gif' && ($src = @imagecreatefromstring($bytes))) {
        $r = min($max / $w, $max / $h);
        $nw = (int)round($w * $r);
        $nh = (int)round($h * $r);
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $ok = match ($ext) {
            'jpg' => imagejpeg($dst, $dest, 84),
            'png' => imagepng($dst, $dest, 7),
            'webp' => function_exists('imagewebp') && imagewebp($dst, $dest, 84),
        };
        imagedestroy($src);
        imagedestroy($dst);
        if ($ok) return $name;
    }
    if (@file_put_contents($dest, $bytes) === false) throw new RuntimeException('Could not save "' . $label . '". Check the uploads folder is writable (755).');
    return $name;
}

/** Save one uploaded file. Returns the stored name, or null when no file was sent. */
function save_upload(array $f): ?string
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $label = (string)($f['name'] ?? 'An image');
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('"' . $label . '" is bigger than your server accepts (' . ini_get('upload_max_filesize') . ').');
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new RuntimeException('"' . $label . '" did not upload. Try again.');
    return save_image_bytes((string)file_get_contents($f['tmp_name']), $label);
}

function delete_upload(?string $name): void
{
    if ($name && preg_match('/^[\w.-]+$/', $name) && is_file(UPLOAD_DIR . '/' . $name)) @unlink(UPLOAD_DIR . '/' . $name);
}

/** Delete a photo only when no recipe, ingredient or step uses it any more (imports can share photos). */
function delete_upload_if_unused(?string $name): void
{
    if (!$name) return;
    $st = db()->prepare('SELECT (SELECT COUNT(*) FROM recipes WHERE image = ?) + (SELECT COUNT(*) FROM ingredients WHERE image = ?) + (SELECT COUNT(*) FROM steps WHERE image = ?)');
    $st->execute([$name, $name, $name]);
    if (!(int)$st->fetchColumn()) delete_upload($name);
}

/** One file out of a PHP multi-file field like $_FILES['ing_img'], by key. */
function file_at(string $field, string|int $key): array
{
    $F = $_FILES[$field] ?? null;
    if (!$F || !is_array($F['error']) || !isset($F['error'][$key])) return ['error' => UPLOAD_ERR_NO_FILE];
    return ['name' => $F['name'][$key], 'tmp_name' => $F['tmp_name'][$key], 'error' => $F['error'][$key], 'size' => $F['size'][$key]];
}

/** A php.ini size like "8M" in bytes. */
function ini_bytes(string $key): int
{
    $v = trim((string)ini_get($key));
    $n = (int)$v;
    return match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
}
function human_bytes(int|float $n): string
{
    foreach (['B', 'KB', 'MB', 'GB'] as $u) {
        if ($n < 1024) return round($n, $u === 'B' ? 0 : 1) . ' ' . $u;
        $n /= 1024;
    }
    return round($n, 1) . ' TB';
}

/* ---------- Formatting ---------- */
function fmt_minutes(?int $m): string
{
    if (!$m) return '';
    if ($m < 60) return $m . ' min';
    return intdiv($m, 60) . ' h' . ($m % 60 ? ' ' . ($m % 60) . ' min' : '');
}
function fmt_timer(int $s): string { return sprintf('%d:%02d', intdiv($s, 60), $s % 60); }
function csv_list(?string $s): array { return array_values(array_filter(array_map('trim', explode(',', (string)$s)))); }
function plural(int $n, string $one, ?string $many = null): string { return $n . ' ' . ($n === 1 ? $one : ($many ?? $one . 's')); }

/** Recipe with brand, ingredients and steps, or null. */
function load_recipe(int $id, bool $publishedOnly = true): ?array
{
    $sql = 'SELECT r.*, b.name AS brand_name, b.colour AS brand_colour FROM recipes r LEFT JOIN brands b ON b.id = r.brand_id WHERE r.id = ?';
    if ($publishedOnly) $sql .= ' AND r.published = 1';
    $st = db()->prepare($sql);
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) return null;
    $st = db()->prepare('SELECT * FROM ingredients WHERE recipe_id = ? ORDER BY sort, id');
    $st->execute([$id]);
    $r['ingredients'] = $st->fetchAll();
    $st = db()->prepare('SELECT * FROM steps WHERE recipe_id = ? ORDER BY sort, id');
    $st->execute([$id]);
    $r['steps'] = $st->fetchAll();
    return $r;
}

/** Label size and printer defaults, clamped to what small thermal label printers handle. */
function label_config(): array
{
    $w = max(25, min(80, (float)setting('label_w', '50')));
    $h = max(20, min(80, (float)setting('label_h', '30')));
    $lang = (string)setting('label_lang', 'tspl');
    return [
        'w' => $w, 'h' => $h,
        'print_w' => max(20, min(72, (float)setting('label_print_w', '48'), $w)),
        'gap' => max(0, min(10, (float)setting('label_gap', '2'))),
        'dpi' => (int)setting('label_dpi', '203') === 300 ? 300 : 203,
        'lang' => in_array($lang, ['tspl', 'escpos', 'browser'], true) ? $lang : 'tspl',
        'show_allergens' => setting('label_allergens', '1') === '1',
        'ask_initials' => setting('label_initials', '1') === '1',
    ];
}

/** "3 days", "2 hours" */
function fmt_shelf(int $v, string $unit): string { return plural($v, $unit === 'hours' ? 'hour' : 'day'); }

/** Every picture a recipe uses (dish, ingredients, steps). */
function recipe_images(array $r): array
{
    return array_values(array_filter(array_merge([$r['image']], array_column($r['ingredients'], 'image'), array_column($r['steps'], 'image'))));
}
