<?php
/**
 * Recipe packs: export, import (with a preview), and the ChatGPT brief that produces a pack.
 *
 * A pack is a zip holding recipes.json plus the photos it names, anywhere in the zip.
 * A bare recipes.json (no photos) also imports. See docs/recipe-pack.md for the format.
 */
require_once __DIR__ . '/updater.php';

const PACK_FORMAT = 'mustrhq-recipes';
const PACK_VERSION = 1;

/* ---------- Export ---------- */

/** Build a pack zip of every recipe (or one brand's). Returns [path, file name]. */
function pack_export(?int $brandId): array
{
    $sql = 'SELECT r.id FROM recipes r LEFT JOIN brands b ON b.id = r.brand_id';
    $args = [];
    if ($brandId) { $sql .= ' WHERE r.brand_id = ?'; $args[] = $brandId; }
    $sql .= ' ORDER BY b.sort, b.name, r.code, r.name';
    $st = db()->prepare($sql);
    $st->execute($args);

    $files = [];
    $out = [];
    $used = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $r = load_recipe((int)$id, false);
        $key = pack_slug($r['brand_name'] ?: 'kitchen') . '__' . pack_slug($r['code'] ?: 'r' . $r['id']);
        if (isset($used[$key])) $key .= '-' . $r['id'];
        $used[$key] = true;
        $photo = function (?string $stored, string $slot) use ($key, &$files): ?string {
            if (!$stored || !is_file(UPLOAD_DIR . '/' . $stored)) return null;
            $name = $key . '__' . $slot . '.' . strtolower(pathinfo($stored, PATHINFO_EXTENSION));
            $files['images/' . $name] = (string)file_get_contents(UPLOAD_DIR . '/' . $stored);
            return $name;
        };
        $out[] = [
            'brand' => $r['brand_name'],
            'brand_colour' => $r['brand_colour'],
            'code' => $r['code'],
            'name' => $r['name'],
            'description' => $r['description'],
            'makes' => $r['yield_text'],
            'prep_minutes' => $r['prep_minutes'] !== null ? (int)$r['prep_minutes'] : null,
            'cook_minutes' => $r['cook_minutes'] !== null ? (int)$r['cook_minutes'] : null,
            'allergens' => csv_list($r['allergens']),
            'diets' => csv_list($r['diets']),
            'safety_note' => $r['notes'],
            'live' => (bool)$r['published'],
            'photo' => $photo($r['image'], 'dish'),
            'ingredients' => array_map(fn($i, $n) => [
                'name' => $i['name'], 'amount' => $i['qty'], 'unit' => $i['unit'], 'note' => $i['note'],
                'photo' => $photo($i['image'], 'ing-' . ($n + 1)),
            ], $r['ingredients'], array_keys($r['ingredients'])),
            'steps' => array_map(fn($s, $n) => [
                'title' => $s['title'], 'text' => $s['body'], 'tip' => $s['tip'],
                'timer' => $s['timer_seconds'] ? fmt_timer((int)$s['timer_seconds']) : null,
                'photo' => $photo($s['image'], 'step-' . ($n + 1)),
            ], $r['steps'], array_keys($r['steps'])),
        ];
    }
    $files['recipes.json'] = json_encode([
        'format' => PACK_FORMAT, 'version' => PACK_VERSION,
        'exported' => date('c'), 'kitchen' => kitchen_name(), 'app_version' => MUSTR_VERSION,
        'recipes' => $out,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $files['README.txt'] = "MustrHQ Recipes export from " . kitchen_name() . ", " . date('j M Y H:i') . ".\n"
        . "Import it in Admin > Import and export. recipes.json holds the recipes; images/ holds the photos it names.\n";

    $name = 'recipes-' . pack_slug(kitchen_name()) . ($brandId ? '-' . pack_slug((string)($out[0]['brand'] ?? 'brand')) : '') . '-' . date('Y-m-d') . '.zip';
    $path = upd_dir() . '/export-' . bin2hex(random_bytes(6)) . '.zip';
    upd_write_zip($path, $files);
    return [$path, $name];
}

function pack_slug(string $s): string
{
    $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s) ?? '', '-'));
    return $s === '' ? 'x' : substr($s, 0, 40);
}

/* ---------- Reading a pack ---------- */

/**
 * Read a pack from a zip or a json file. Photos are not loaded here: the result lists them
 * (lower-case file name => entry in the zip) and pack_image() reads one when it is needed,
 * so a zip with hundreds of photos never has to fit in memory.
 *
 * Understands MustrHQ Recipes packs, MustrHQ Station packs ("mustrhq-recipe-pack", including
 * its ready items, utensils and picture file names), a bare list of recipes, and several JSON
 * files in one zip (recipes-part1.json, recipes-part2.json ...).
 *
 * Returns ['path', 'zip', 'recipes' => [...normalised...], 'images' => [...], 'problems', 'notes', 'source'].
 */
function pack_read(string $path, string $originalName, bool $imagesOnly = false): array
{
    $images = [];
    $jsons = [];
    $isZip = strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'json';
    if ($imagesOnly && !$isZip) throw new RuntimeException('For image-only import, upload a ZIP of pictures. No recipes.json is needed.');
    if (!$isZip) {
        $jsons[basename($originalName)] = (string)file_get_contents($path);
    } else {
        foreach (pack_zip_list($path) as $name) {
            $base = basename($name);
            if (str_starts_with($name, '__MACOSX/') || $base === '' || $base[0] === '.') continue;
            if (preg_match('/\.json$/i', $base)) { $jsons[$name] = $name; continue; }
            if (preg_match('/\.(jpe?g|png|webp|gif)$/i', $base)) {
                $key = strtolower($base);
                if (isset($images[$key])) throw new RuntimeException('Two pictures use the same filename: ' . $base . '. Rename or remove the duplicate before importing.');
                $images[$key] = $name;
            }
        }
        if ($imagesOnly && !$images) throw new RuntimeException('That ZIP has no supported pictures.');
        if (($imagesOnly || !$jsons) && $images) return pack_read_images($path, $images);
        if (!$jsons) throw new RuntimeException('This zip contains neither recipes.json nor supported pictures.');
        // Prefer recipes*.json; ignore other JSON files (schemas, notes) when recipes files exist.
        $rec = array_filter($jsons, fn($n) => preg_match('/(^|\/)(recipes|pack)[^\/]*\.json$/i', $n));
        if ($rec) $jsons = $rec;
        foreach ($jsons as $name => $_) $jsons[$name] = pack_zip_get($path, $name) ?? '';
    }
    uksort($jsons, 'strnatcasecmp');

    $notes = [];
    $merged = ['brands' => [], 'components' => [], 'utensils' => [], 'recipes' => []];
    $format = null;
    foreach ($jsons as $name => $raw) {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', trim((string)$raw)) ?? '';
        if (str_starts_with($raw, '```')) $raw = trim(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $raw) ?? '');
        $d = json_decode($raw, true);
        if (!is_array($d)) {
            if (count($jsons) === 1) throw new RuntimeException(basename($name) . ' is not valid JSON (' . json_last_error_msg() . '). Ask ChatGPT to check the file and send it again.');
            $notes[] = basename($name) . ' is not valid JSON and was skipped.';
            continue;
        }
        if (array_is_list($d)) $d = ['recipes' => $d];
        if (isset($d['format']) && !in_array($d['format'], [PACK_FORMAT, 'mustrhq-recipe-pack'], true)) {
            throw new RuntimeException(basename($name) . ' says it is "' . $d['format'] . '", which is not a recipe pack this app understands.');
        }
        $format ??= $d['format'] ?? null;
        foreach (array_keys($merged) as $k) if (!empty($d[$k]) && is_array($d[$k])) $merged[$k] = array_merge($merged[$k], array_values($d[$k]));
    }
    if (count($jsons) > 1) $notes[] = 'Merged ' . count($jsons) . ' JSON files: ' . implode(', ', array_map('basename', array_keys($jsons))) . '.';
    if (!$merged['recipes']) throw new RuntimeException('The pack has no "recipes" list.');

    // Station extras: brand colours, ready items (for ingredient photos) and utensils.
    $brandColours = [];
    foreach ($merged['brands'] as $b) {
        if (is_array($b) && !empty($b['name']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)($b['colour'] ?? ''))) $brandColours[strtolower(trim($b['name']))] = $b['colour'];
    }
    $components = [];
    foreach ($merged['components'] as $c) {
        if (is_array($c) && !empty($c['code'])) $components[strtoupper(trim((string)$c['code']))] = ['name' => (string)($c['name'] ?? ''), 'image' => (string)($c['image'] ?? '')];
    }
    $station = $format === 'mustrhq-recipe-pack' || $merged['components'] || $merged['utensils'] || isset($merged['recipes'][0]['preview_image']) || isset($merged['recipes'][0]['qc_note']);

    $recipes = [];
    $problems = [];
    foreach ($merged['recipes'] as $n => $r) {
        if (!is_array($r)) { $problems[] = 'Entry ' . ($n + 1) . ' is not a recipe and was skipped.'; continue; }
        $name = pack_str($r['name'] ?? '', 140);
        if ($name === '') { $problems[] = 'Entry ' . ($n + 1) . ' has no name and was skipped.'; continue; }
        $recipes[] = pack_normalise($r, $name, $brandColours, $components, $images);
    }
    if (!$recipes) throw new RuntimeException('No usable recipes were found in the pack.');
    if ($station) $notes[] = 'This is a MustrHQ Station pack: ready items become ingredients with their photos, and packing, quality and chef notes go into each recipe\'s note.';
    return ['path' => $path, 'zip' => $isZip, 'recipes' => $recipes, 'images' => $images, 'problems' => $problems, 'notes' => $notes, 'source' => $station ? 'station' : 'recipes'];
}

/** Turn one recipe from either format into the shape this app stores. */
function pack_normalise(array $r, string $name, array $brandColours, array $components, array $images): array
{
    $warn = [];
    [$allergens, $badA] = pack_keys($r['allergens'] ?? [], ALLERGENS, [
        'cereals containing gluten' => 'gluten', 'wheat' => 'gluten', 'tree nuts' => 'nuts', 'nut' => 'nuts',
        'sulphur dioxide' => 'sulphites', 'sulphur dioxide / sulphites' => 'sulphites', 'sulfites' => 'sulphites', 'sulphite' => 'sulphites',
        'dairy' => 'milk', 'egg' => 'eggs', 'shellfish' => 'crustaceans', 'soy' => 'soya', 'peanut' => 'peanuts', 'mollusc' => 'molluscs',
    ]);
    [$diets, $badD] = pack_keys($r['diets'] ?? [], DIETS, ['gluten-free' => 'gluten_free', 'gluten free' => 'gluten_free', 'veg' => 'vegetarian']);
    foreach (['halal', 'vegetarian', 'vegan'] as $flag) if (!empty($r[$flag]) && !in_array($flag, $diets, true)) $diets[] = $flag;
    if (!empty($r['gluten_free']) && !in_array('gluten_free', $diets, true)) $diets[] = 'gluten_free';
    if ($badA) $warn[] = 'Unknown allergen ' . implode(', ', $badA) . ' left out. Check the allergens by hand.';
    if ($badD) $warn[] = 'Unknown diet ' . implode(', ', $badD) . ' left out.';

    $brand = pack_str($r['brand'] ?? '', 80);
    $code = pack_str($r['code'] ?? '', 20);
    $slugs = array_unique([pack_station_slug($brand), pack_slug($brand ?: 'kitchen')]);
    $codeSlug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $code) ?? '');
    $conv = fn(array $suffixes) => $code === '' ? [] : array_merge(...array_map(fn($sl) => array_map(fn($x) => $sl . '__' . $codeSlug . '__' . $x, $suffixes), $slugs));

    $ings = [];
    foreach (array_values((array)($r['ingredients'] ?? [])) as $k => $i) {
        if (is_string($i)) $i = ['name' => $i];
        if (!is_array($i)) continue;
        $comp = strtoupper(trim((string)($i['component'] ?? '')));
        $iname = pack_str($i['name'] ?? ($components[$comp]['name'] ?? ''), 120);
        if ($iname === '') continue;
        $qty = pack_str($i['amount'] ?? '', 20);
        $unit = pack_str($i['unit'] ?? '', 20);
        $note = pack_str($i['note'] ?? '', 160);
        if ($qty === '' && isset($i['qty']) && is_scalar($i['qty'])) {
            [$qty, $u, $extra] = pack_split_qty((string)$i['qty']);
            if ($unit === '') $unit = $u;
            if ($extra !== '') $note = trim($extra . ($note !== '' ? '; ' . $note : ''));
        }
        $ings[] = [
            'name' => $iname, 'qty' => mb_substr($qty, 0, 20), 'unit' => mb_substr($unit, 0, 20), 'note' => mb_substr($note, 0, 160),
            'photo' => pack_pick_image($images, array_merge(
                [pack_str($i['photo'] ?? $i['image'] ?? '', 200)],
                $comp !== '' ? [$components[$comp]['image'] ?? '', 'component__' . strtolower($comp)] : [],
                $conv(['ing-' . ($k + 1), 'ing' . ($k + 1)])
            )),
        ];
    }

    $steps = [];
    foreach (array_values((array)($r['steps'] ?? $r['method'] ?? [])) as $k => $st) {
        if (is_string($st)) $st = ['text' => $st];
        if (!is_array($st)) continue;
        $text = pack_str($st['text'] ?? $st['body'] ?? '', 4000);
        $title = pack_str($st['title'] ?? '', 120);
        if ($text === '' && $title === '') continue;
        $timer = pack_timer($st['timer'] ?? $st['timer_seconds'] ?? null) ?? pack_timer_from_text($text);
        $steps[] = [
            'title' => $title, 'body' => $text, 'tip' => pack_str($st['tip'] ?? '', 255), 'timer' => $timer,
            'photo' => pack_pick_image($images, array_merge([pack_str($st['photo'] ?? $st['image'] ?? '', 200)], $conv(['step' . ($k + 1), 'step-' . ($k + 1)]))),
        ];
    }
    if (!$steps) $warn[] = 'No steps.';
    if (!$allergens && !array_key_exists('allergens', $r)) $warn[] = 'No allergens listed. Check them before it goes live.';

    // Station keeps packing, quality and chef notes separately; here they share the recipe note.
    $notes = array_filter([
        pack_str($r['safety_note'] ?? $r['notes'] ?? '', 2000),
        ($x = pack_str($r['qc_note'] ?? '', 600)) !== '' ? 'Quality check: ' . $x : '',
        ($x = pack_str($r['pack_notes'] ?? '', 600)) !== '' ? 'Packing: ' . $x : '',
        ($x = pack_str($r['chef_note'] ?? '', 600)) !== '' ? "Chef's note: " . $x : '',
        !empty($r['utensils']) && is_array($r['utensils']) ? 'Utensils: ' . implode(', ', array_filter(array_map(fn($u) => is_array($u) ? (string)($u['name'] ?? '') : (string)$u, $r['utensils']))) : '',
    ]);
    $desc = array_filter([
        pack_str($r['description'] ?? '', 2000),
        ($x = pack_str($r['name_local'] ?? '', 140)) !== '' ? 'Also called ' . $x . '.' : '',
        ($x = pack_str($r['station_hint'] ?? '', 140)) !== '' ? 'Station: ' . $x . '.' : '',
    ]);

    $colour = (string)($r['brand_colour'] ?? ($brandColours[strtolower($brand)] ?? ''));
    return [
        'brand' => $brand,
        'brand_colour' => preg_match('/^#[0-9a-fA-F]{6}$/', $colour) ? $colour : null,
        'code' => $code,
        'name' => $name,
        'description' => mb_substr(implode(' ', $desc), 0, 2000),
        'makes' => pack_str($r['makes'] ?? $r['yield'] ?? (isset($r['minutes_per_portion']) ? '1 portion' : ''), 80),
        'prep' => pack_int($r['prep_minutes'] ?? null),
        'cook' => pack_int($r['cook_minutes'] ?? $r['minutes_per_portion'] ?? null),
        'allergens' => $allergens,
        'diets' => array_values(array_intersect(array_keys(DIETS), $diets)),
        'notes' => mb_substr(implode("\n", $notes), 0, 4000),
        'live' => !array_key_exists('live', $r) || (bool)$r['live'],
        'photo' => pack_pick_image($images, array_merge([pack_str($r['photo'] ?? $r['image'] ?? $r['preview_image'] ?? '', 200)], $conv(['dish', 'preview']))),
        'photo_named' => pack_str($r['photo'] ?? $r['image'] ?? $r['preview_image'] ?? '', 200),
        'ingredients' => $ings,
        'steps' => $steps,
        'warnings' => $warn,
    ];
}

/** Station's brand slug: lower case, "&" becomes "and", anything else becomes "-". */
function pack_station_slug(string $s): string
{
    $s = strtolower(str_replace('&', ' and ', $s));
    return trim(preg_replace('/[^a-z0-9]+/', '-', $s) ?? '', '-');
}

/**
 * The first candidate that exists in the zip, as a lower-case file name, or '' (none found),
 * or '?name' when a photo was named but is not in the zip (so the preview can say so).
 */
function pack_pick_image(array $images, array $candidates): string
{
    $named = '';
    foreach ($candidates as $i => $c) {
        $c = strtolower(basename(trim((string)$c)));
        if ($c === '') continue;
        if ($i === 0 && $named === '') $named = $c;
        if (isset($images[$c])) return $c;
        if (!preg_match('/\.(jpe?g|png|webp|gif)$/', $c)) {
            foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) if (isset($images[$c . '.' . $ext])) return $c . '.' . $ext;
        }
    }
    return $named !== '' && preg_match('/\.(jpe?g|png|webp|gif)$/', $named) ? '?' . $named : '';
}

/** "1 cutlet (180 g raw)" → ["1", "cutlet", "(180 g raw)"]; "250 g" → ["250", "g", ""]. */
function pack_split_qty(string $q): array
{
    $q = trim($q);
    if (preg_match('/^((?:\d+(?:[.,]\d+)?)|(?:\d+\/\d+)|[½¼¾⅓⅔])\s*([A-Za-zµ°]+\.?)?\s*(.*)$/u', $q, $m)) {
        $unit = $m[2] ?? '';
        $rest = trim($m[3] ?? '');
        if (mb_strlen($unit) > 12) { $rest = trim($unit . ' ' . $rest); $unit = ''; }
        return [str_replace(',', '.', $m[1]), $unit, $rest];
    }
    return ['', '', $q];
}

/** A cooking time written in a step ("fry for 5–6 min") as a timer, using the shorter time. */
function pack_timer_from_text(string $t): ?int
{
    if (preg_match_all('/(\d{1,3})(?:\s*[–-]\s*\d{1,3})?\s*(min|mins|minute|minutes|sec|secs|seconds)\b/i', $t, $m, PREG_SET_ORDER) !== 1) return null;
    $n = (int)$m[0][1];
    return str_starts_with(strtolower($m[0][2]), 's') ? ($n >= 10 ? $n : null) : ($n > 0 && $n <= 240 ? $n * 60 : null);
}

/* ---------- Zip access without loading everything ---------- */

function pack_zip_list(string $path): array
{
    if (class_exists('ZipArchive')) {
        $z = new ZipArchive();
        if ($z->open($path) !== true) throw new RuntimeException('That file is not a readable zip.');
        $out = [];
        for ($i = 0; $i < $z->numFiles; $i++) { $n = (string)$z->getNameIndex($i); if (!str_ends_with($n, '/')) $out[] = $n; }
        $z->close();
        return $out;
    }
    if (!class_exists('PharData')) throw new RuntimeException('Enable the PHP zip or phar extension to open packs.');
    try {
        $archive = new PharData($path);
        $prefix = 'phar://' . str_replace('\\', '/', (string)realpath($path)) . '/';
        $out = [];
        foreach (new RecursiveIteratorIterator($archive) as $file) {
            // List entry names only: never decompress the photos during preview.
            if ($file->isDir()) continue;
            $name = str_replace('\\', '/', $file->getPathname());
            if (!str_starts_with($name, $prefix)) throw new RuntimeException('Invalid zip entry.');
            $out[] = substr($name, strlen($prefix));
        }
        return $out;
    } catch (Throwable $e) {
        throw new RuntimeException('That file is not a readable zip.', 0, $e);
    }
}

function pack_zip_get(string $path, string $entry): ?string
{
    static $open = [];
    if (class_exists('ZipArchive')) {
        if (!isset($open[$path])) { $z = new ZipArchive(); if ($z->open($path) !== true) return null; $open[$path] = $z; }
        $b = $open[$path]->getFromName($entry);
        return $b === false ? null : $b;
    }
    if (class_exists('PharData')) {
        $b = @file_get_contents('phar://' . realpath($path) . '/' . $entry);
        return $b === false ? null : $b;
    }
    return null;
}

/** Bytes of one photo in the pack by its lower-case file name, or null. */
function pack_image(array $pack, string $key): ?string
{
    if ($key === '' || $key[0] === '?' || !isset($pack['images'][$key]) || !$pack['zip']) return null;
    return pack_zip_get($pack['path'], $pack['images'][$key]);
}

function pack_str(mixed $v, int $max): string { return is_scalar($v) ? mb_substr(trim((string)$v), 0, $max) : ''; }
function pack_int(mixed $v): ?int { return is_numeric($v) && (int)$v > 0 ? min((int)$v, 9999) : null; }
function pack_timer(mixed $v): ?int
{
    if (is_int($v) || (is_string($v) && ctype_digit($v))) return (int)$v > 0 ? min((int)$v, 86400) : null; // seconds
    if (is_string($v) && preg_match('/^(\d{1,3}):([0-5]?\d)$/', trim($v), $m)) return ((int)$m[1] * 60 + (int)$m[2]) ?: null;
    return null;
}
/** Map names or keys onto our keys. Returns [keys, unknown]. */
function pack_keys(mixed $list, array $known, array $aliases): array
{
    if (is_string($list)) $list = explode(',', $list);
    $keys = []; $bad = [];
    $byLabel = [];
    foreach ($known as $k => $label) { $byLabel[strtolower($label)] = $k; $byLabel[$k] = $k; }
    foreach ((array)$list as $v) {
        if (!is_scalar($v)) continue;
        $t = strtolower(trim((string)$v));
        if ($t === '' || $t === 'none') continue;
        $k = $byLabel[$t] ?? $aliases[$t] ?? $byLabel[str_replace([' ', '-'], '_', $t)] ?? null;
        if ($k) $keys[$k] = true; else $bad[] = '"' . $v . '"';
    }
    return [array_keys($keys), $bad];
}

/* ---------- Planning and applying ---------- */

/** Work out what an import would do, without changing anything. */
function pack_plan(array $pack): array
{
    if (!empty($pack['images_only'])) {
        $rows = []; $photos = 0;
        foreach ($pack['recipes'] as $id => $r) {
            $count = count($r['bindings']); $photos += $count;
            $rows[$id] = ['action' => 'update', 'existing_id' => $id, 'recipe' => $r,
                'photos' => $count, 'photos_wanted' => $count, 'warnings' => $r['warnings']];
        }
        return ['rows' => $rows, 'new_brands' => [], 'photos' => $photos, 'photos_missing' => 0];
    }
    $brands = [];
    foreach (db()->query('SELECT id, name FROM brands') as $b) $brands[strtolower($b['name'])] = (int)$b['id'];
    $rows = [];
    $newBrands = [];
    $seen = [];
    $photosFound = $photosMissing = 0;
    foreach ($pack['recipes'] as $n => $r) {
        $bk = strtolower($r['brand']);
        $brandId = $r['brand'] !== '' ? ($brands[$bk] ?? null) : null;
        if ($r['brand'] !== '' && $brandId === null) $newBrands[$bk] = $r['brand'];
        $existing = ($brandId || $r['brand'] === '') ? pack_find($brandId, $r['code'], $r['name']) : null;
        $sig = $bk . '|' . strtolower($r['code'] !== '' ? $r['code'] : $r['name']);
        $dupe = isset($seen[$sig]);
        $seen[$sig] = true;

        $slots = array_merge([$r['photo']], array_column($r['ingredients'], 'photo'), array_column($r['steps'], 'photo'));
        $found = count(array_filter($slots, fn($p) => $p !== '' && $p[0] !== '?'));
        $missing = array_values(array_map(fn($p) => substr($p, 1), array_filter($slots, fn($p) => $p !== '' && $p[0] === '?')));
        $photosFound += $found;
        $photosMissing += count($missing);
        $warn = $r['warnings'];
        if ($missing) $warn[] = plural(count($missing), 'photo') . ' named but not in the zip' . ($existing ? '; current photos are kept' : '') . ': ' . implode(', ', array_slice($missing, 0, 3)) . (count($missing) > 3 ? '…' : '');
        if ($dupe) $warn[] = 'Appears twice in the pack; the later one wins.';
        $rows[$n] = [
            'action' => $existing ? 'update' : 'new',
            'existing_id' => $existing,
            'recipe' => $r,
            'photos' => $found,
            'photos_wanted' => $found + count($missing),
            'warnings' => $warn,
        ];
    }
    return ['rows' => $rows, 'new_brands' => array_values($newBrands), 'photos' => $photosFound, 'photos_missing' => $photosMissing];
}

/** Find a recipe by brand + code, or brand + name when there is no code. */
function pack_find(?int $brandId, string $code, string $name): ?int
{
    $brandSql = $brandId ? 'brand_id = ?' : 'brand_id IS NULL';
    $args = $brandId ? [$brandId] : [];
    if ($code !== '') { $sql = "SELECT id FROM recipes WHERE $brandSql AND code = ? ORDER BY id LIMIT 1"; $args[] = $code; }
    else { $sql = "SELECT id FROM recipes WHERE $brandSql AND name = ? ORDER BY id LIMIT 1"; $args[] = $name; }
    $st = db()->prepare($sql);
    $st->execute($args);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}

/**
 * Apply recipes from a pack (by their row numbers). Safe to call in batches: each call is one
 * transaction. Returns [created, updated, photos].
 */
function pack_apply(array $pack, array $selected, array &$shared = []): array
{
    if (!empty($pack['images_only'])) return pack_apply_images($pack, $selected, $shared);
    $pdo = db();
    $saved = [];      // new photo files, removed again if the batch fails
    $toDelete = [];   // replaced photo files, removed once the batch succeeds
    $created = $updated = 0;
    $cache = $shared; // one stored copy per pack photo, even when several recipes (or batches) share it
    $img = function (string $key, ?string $current) use ($pack, &$saved, &$cache): ?string {
        if ($key === '' || $key[0] === '?') return $current;                    // not supplied: keep what is there
        if (isset($cache[$key]) && is_file(UPLOAD_DIR . '/' . $cache[$key])) return $cache[$key];
        $bytes = pack_image($pack, $key);
        if ($bytes === null) return $current;
        $name = save_image_bytes($bytes, $key);
        $saved[] = $name;
        return $cache[$key] = $name;
    };

    $pdo->beginTransaction();
    try {
        $brands = [];
        foreach ($pdo->query('SELECT id, name FROM brands') as $b) $brands[strtolower($b['name'])] = (int)$b['id'];
        $maxSort = (int)$pdo->query('SELECT COALESCE(MAX(sort), 0) FROM brands')->fetchColumn();

        foreach ($selected as $n) {
            if (!isset($pack['recipes'][$n])) continue;
            $r = $pack['recipes'][$n];
            $brandId = null;
            if ($r['brand'] !== '') {
                $bk = strtolower($r['brand']);
                if (!isset($brands[$bk])) {
                    $pdo->prepare('INSERT INTO brands (name, colour, sort) VALUES (?,?,?)')->execute([$r['brand'], $r['brand_colour'] ?? '#01216C', ++$maxSort]);
                    $brands[$bk] = (int)$pdo->lastInsertId();
                }
                $brandId = $brands[$bk];
            }
            $id = pack_find($brandId, $r['code'], $r['name']);
            $old = $id ? load_recipe($id, false) : null;

            $dish = $img($r['photo'], $old['image'] ?? null);
            $fields = [$brandId, $r['code'], $r['name'], $r['description'], $r['makes'], $r['prep'], $r['cook'],
                implode(',', $r['allergens']), implode(',', $r['diets']), $dish, $r['notes'], $r['live'] ? 1 : 0];
            if ($old) {
                $pdo->prepare('UPDATE recipes SET brand_id=?, code=?, name=?, description=?, yield_text=?, prep_minutes=?, cook_minutes=?, allergens=?, diets=?, image=?, notes=?, published=? WHERE id=?')
                    ->execute([...$fields, $id]);
                $pdo->prepare('DELETE FROM ingredients WHERE recipe_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM steps WHERE recipe_id = ?')->execute([$id]);
                $updated++;
            } else {
                $pdo->prepare('INSERT INTO recipes (brand_id, code, name, description, yield_text, prep_minutes, cook_minutes, allergens, diets, image, notes, published) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute($fields);
                $id = (int)$pdo->lastInsertId();
                $created++;
            }
            $keep = [$dish];
            $ii = $pdo->prepare('INSERT INTO ingredients (recipe_id, sort, name, qty, unit, note, image) VALUES (?,?,?,?,?,?,?)');
            foreach ($r['ingredients'] as $k => $i) {
                $p = $img($i['photo'], $old['ingredients'][$k]['image'] ?? null);
                $keep[] = $p;
                $ii->execute([$id, $k, $i['name'], $i['qty'], $i['unit'], $i['note'], $p]);
            }
            $si = $pdo->prepare('INSERT INTO steps (recipe_id, sort, title, body, tip, timer_seconds, image) VALUES (?,?,?,?,?,?,?)');
            foreach ($r['steps'] as $k => $st) {
                $p = $img($st['photo'], $old['steps'][$k]['image'] ?? null);
                $keep[] = $p;
                $si->execute([$id, $k, $st['title'], $st['body'], $st['tip'], $st['timer'], $p]);
            }
            if ($old) foreach (array_diff(recipe_images($old), array_filter($keep)) as $gone) $toDelete[] = $gone;
        }
        $pdo->commit();
        $shared = $cache;
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($saved as $f) delete_upload($f);
        throw new RuntimeException('Nothing in this batch was imported: ' . $t->getMessage());
    }
    // A photo still used by another recipe (shared ready-item pictures) is never deleted.
    foreach (array_unique($toDelete) as $f) delete_upload_if_unused($f);
    return [$created, $updated, count($saved)];
}

/* ---------- ChatGPT brief ---------- */

function chatgpt_brief(string $brand, string $menu, bool $photos): string
{
    $brands = db()->query('SELECT name FROM brands ORDER BY sort, name')->fetchAll(PDO::FETCH_COLUMN);
    $allergens = implode(', ', array_map(fn($k, $v) => "`$k` ($v)", array_keys(ALLERGENS), ALLERGENS));
    $diets = implode(', ', array_map(fn($k) => "`$k`", array_keys(DIETS)));
    $brandLine = $brand !== '' ? "All recipes belong to the brand **$brand**." : 'Use the brand name each dish belongs to.' . ($brands ? ' Existing brands: ' . implode(', ', $brands) . '.' : '');
    $menuBlock = $menu !== '' ? "## The menu\n\n" . $menu . "\n" : "## The menu\n\nI will paste the menu, or attach an exported recipes.json to improve, in my next message.\n";
    $photoBlock = $photos ? <<<MD
## Photos

Make one photo for each finished dish, and one for each step where seeing it helps (assembly, plating, what "done" looks like). Ingredient photos are optional; make them for anything a new starter could mistake for something else.

House photo style, the same for every picture:
- Real commercial kitchen, stainless steel bench, soft daylight from the left, shot from 45 degrees (finished dishes from directly above).
- The food fills most of the frame. Plain background. No people's faces, no text, no logos, no brand packaging.
- Square-ish landscape, 4:3. Realistic, not illustrated. Portions match the recipe amounts.

File names are lower case with no spaces. Use the recipe code:
- finished dish: `<code>-dish.jpg`, for example `k01-dish.jpg`
- step 3: `<code>-step-3.jpg`
- ingredient 2: `<code>-ing-2.jpg`

Put each file name in the matching `photo` field in recipes.json. Leave `photo` empty where there is no picture.

MD : "## Photos\n\nNo photos this time. Leave every `photo` field empty.\n";

    $example = json_encode([
        'format' => PACK_FORMAT, 'version' => PACK_VERSION,
        'recipes' => [[
            'brand' => $brand !== '' ? $brand : 'Katsu & Co', 'code' => 'K01', 'name' => 'Chicken katsu rice bowl',
            'description' => 'Crisp panko chicken on sticky rice with katsu sauce.',
            'makes' => '1 bowl', 'prep_minutes' => 6, 'cook_minutes' => 8,
            'allergens' => ['gluten', 'eggs', 'soya', 'celery'], 'diets' => ['halal'],
            'safety_note' => 'Chicken must reach 75 °C core.',
            'live' => true, 'photo' => 'k01-dish.jpg',
            'ingredients' => [['name' => 'Breaded chicken thigh', 'amount' => '150', 'unit' => 'g', 'note' => 'from the prep fridge', 'photo' => '']],
            'steps' => [['title' => 'Fry the chicken', 'text' => 'Fry at 175 °C until deep golden.', 'tip' => 'Lay it away from you.', 'timer' => '6:00', 'photo' => 'k01-step-1.jpg']],
        ]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $kitchen = kitchen_name();
    return <<<MD
# Recipe pack brief for {$kitchen}

You are writing kitchen build cards for a professional delivery kitchen. Staff read them on a tablet above the bench, often new starters. Every recipe must be followed the same way by everyone, so dishes look and taste the same every time.

$brandLine

$menuBlock
## How to write each recipe

- **Ingredients** are what goes into one portion or batch, as prepped items from the fridge (for example "Cooked sushi rice", not "rice, water, vinegar"). `amount` is a plain number so the screen can scale it; put the measure in `unit` (g, ml, pcs, slices, tbsp, scoop, ladle). Use `note` for where it lives or how it is held.
- **Steps** are one clear action each, 1 to 3 short sentences, in the order they happen at the bench. Say what it should look like when it is right. Aim for 4 to 8 steps.
- Give a step a `timer` (minutes:seconds, like "6:00") when the cook has to wait for something.
- Put food-safety and quality warnings in `tip` (for example "Use the red board only") or, for the whole dish, in `safety_note` (core temperatures, hot-holding above 63 °C, chilled below 5 °C).
- `code` is the menu or ticket number. Keep it the same when you improve an existing recipe, so the import updates it instead of making a copy.
- `allergens` use only these keys: $allergens. List every allergen the finished dish contains. If you are not sure, include it and say so in `safety_note`.
- `diets` use only these keys: $diets.
- British English. No marketing language.

$photoBlock
## What to send back

One zip called `recipes-pack.zip` holding:
- `recipes.json`, exactly in the format below (valid JSON, no comments), and
- the photos named in it, in an `images` folder.

If you cannot make a zip, send recipes.json as a file and the photos separately with the exact file names.

Check before you send: every recipe has a code, ingredients, steps and allergens; every `photo` name matches a file; the JSON is valid.

## recipes.json format

```json
$example
```

If the menu is long, do up to 10 recipes per reply, then wait for me to say "continue".
MD;
}

require_once __DIR__ . "/image-transfer.php";

/** Cache the normalized preview, never the image bytes. Uploaded source files are immutable. */
function pack_preview_read(string $path, string $name, bool $imagesOnly = false, bool $refresh = false): array
{
    $cache = $path . '.cache.json';
    $size = filesize($path); $mtime = filemtime($path);
    if (!$refresh && is_file($cache)) {
        $data = json_decode((string)file_get_contents($cache), true);
        if (is_array($data) && ($data['size'] ?? null) === $size && ($data['mtime'] ?? null) === $mtime
            && ($data['images_only'] ?? null) === $imagesOnly && isset($data['pack'])) return $data['pack'];
    }
    $pack = pack_read($path, $name, $imagesOnly);
    $data = json_encode(['size' => $size, 'mtime' => $mtime, 'images_only' => $imagesOnly, 'pack' => $pack], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    // A failed cache write must not prevent importing a valid pack.
    $tmp = $cache . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $data, LOCK_EX) !== false) @rename($tmp, $cache);
    @unlink($tmp);
    return $pack;
}
