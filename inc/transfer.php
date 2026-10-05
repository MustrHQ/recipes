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
 * Read a pack from a zip or json file. Returns ['recipes' => [...normalised...], 'images' => [lower basename => bytes], 'problems' => [...]].
 */
function pack_read(string $path, string $originalName): array
{
    $images = [];
    $json = null;
    if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) === 'json') {
        $json = (string)file_get_contents($path);
    } else {
        foreach (upd_read_zip($path) as $name => $bytes) {
            $base = basename($name);
            if (str_starts_with($name, '__MACOSX/') || $base === '' || $base[0] === '.') continue;
            if (strtolower($base) === 'recipes.json') { if ($json === null || substr_count($name, '/') === 0) $json = $bytes; continue; }
            if (preg_match('/\.(jpe?g|png|webp|gif)$/i', $base)) $images[strtolower($base)] = $bytes;
        }
        if ($json === null) throw new RuntimeException('There is no recipes.json in that zip. A recipe pack needs recipes.json plus the photos it names.');
    }
    $json = preg_replace('/^\xEF\xBB\xBF/', '', trim($json)) ?? '';
    // ChatGPT sometimes wraps the file in a ```json fence.
    if (str_starts_with($json, '```')) $json = trim(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $json) ?? '');
    $data = json_decode($json, true);
    if (!is_array($data)) throw new RuntimeException('recipes.json is not valid JSON (' . json_last_error_msg() . '). Ask ChatGPT to check the file and send it again.');
    $list = array_is_list($data) ? $data : ($data['recipes'] ?? null);
    if (!is_array($list) || !$list) throw new RuntimeException('recipes.json has no "recipes" list.');
    if (!array_is_list($data) && isset($data['format']) && $data['format'] !== PACK_FORMAT) {
        throw new RuntimeException('recipes.json says it is "' . $data['format'] . '", not a MustrHQ Recipes pack.');
    }

    $recipes = [];
    $problems = [];
    foreach (array_values($list) as $n => $r) {
        if (!is_array($r)) { $problems[] = 'Entry ' . ($n + 1) . ' is not a recipe and was skipped.'; continue; }
        $name = pack_str($r['name'] ?? '', 140);
        if ($name === '') { $problems[] = 'Entry ' . ($n + 1) . ' has no name and was skipped.'; continue; }
        $warn = [];
        [$allergens, $badA] = pack_keys($r['allergens'] ?? [], ALLERGENS, ['cereals containing gluten' => 'gluten', 'wheat' => 'gluten', 'tree nuts' => 'nuts', 'sulphur dioxide' => 'sulphites', 'sulphur dioxide / sulphites' => 'sulphites', 'sulfites' => 'sulphites', 'dairy' => 'milk', 'egg' => 'eggs', 'shellfish' => 'crustaceans', 'soy' => 'soya', 'peanut' => 'peanuts']);
        [$diets, $badD] = pack_keys($r['diets'] ?? [], DIETS, ['gluten-free' => 'gluten_free', 'gluten free' => 'gluten_free', 'veg' => 'vegetarian']);
        if ($badA) $warn[] = 'Unknown allergen ' . implode(', ', $badA) . ' left out. Check the allergens by hand.';
        if ($badD) $warn[] = 'Unknown diet ' . implode(', ', $badD) . ' left out.';

        $ings = [];
        foreach ((array)($r['ingredients'] ?? []) as $i) {
            if (is_string($i)) $i = ['name' => $i];
            if (!is_array($i) || pack_str($i['name'] ?? '', 120) === '') continue;
            $ings[] = [
                'name' => pack_str($i['name'], 120),
                'qty' => pack_str($i['amount'] ?? $i['qty'] ?? '', 20),
                'unit' => pack_str($i['unit'] ?? '', 20),
                'note' => pack_str($i['note'] ?? '', 160),
                'photo' => pack_str($i['photo'] ?? $i['image'] ?? '', 200),
            ];
        }
        $steps = [];
        foreach ((array)($r['steps'] ?? $r['method'] ?? []) as $s) {
            if (is_string($s)) $s = ['text' => $s];
            if (!is_array($s)) continue;
            $text = pack_str($s['text'] ?? $s['body'] ?? '', 4000);
            $title = pack_str($s['title'] ?? '', 120);
            if ($text === '' && $title === '') continue;
            $steps[] = [
                'title' => $title, 'body' => $text, 'tip' => pack_str($s['tip'] ?? '', 255),
                'timer' => pack_timer($s['timer'] ?? $s['timer_seconds'] ?? null),
                'photo' => pack_str($s['photo'] ?? $s['image'] ?? '', 200),
            ];
        }
        if (!$steps) $warn[] = 'No steps.';
        if (!$allergens && !array_key_exists('allergens', $r)) $warn[] = 'No allergens listed. Check them before it goes live.';

        $colour = (string)($r['brand_colour'] ?? '');
        $recipes[] = [
            'brand' => pack_str($r['brand'] ?? '', 80),
            'brand_colour' => preg_match('/^#[0-9a-fA-F]{6}$/', $colour) ? $colour : null,
            'code' => pack_str($r['code'] ?? '', 20),
            'name' => $name,
            'description' => pack_str($r['description'] ?? '', 2000),
            'makes' => pack_str($r['makes'] ?? $r['yield'] ?? '', 80),
            'prep' => pack_int($r['prep_minutes'] ?? null),
            'cook' => pack_int($r['cook_minutes'] ?? null),
            'allergens' => $allergens,
            'diets' => $diets,
            'notes' => pack_str($r['safety_note'] ?? $r['notes'] ?? '', 2000),
            'live' => !array_key_exists('live', $r) || (bool)$r['live'],
            'photo' => pack_str($r['photo'] ?? $r['image'] ?? '', 200),
            'ingredients' => $ings,
            'steps' => $steps,
            'warnings' => $warn,
        ];
    }
    if (!$recipes) throw new RuntimeException('No usable recipes were found in recipes.json.');
    return ['recipes' => $recipes, 'images' => $images, 'problems' => $problems];
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
    $brands = [];
    foreach (db()->query('SELECT id, name FROM brands') as $b) $brands[strtolower($b['name'])] = (int)$b['id'];
    $rows = [];
    $newBrands = [];
    $seen = [];
    foreach ($pack['recipes'] as $n => $r) {
        $bk = strtolower($r['brand']);
        $brandId = $r['brand'] !== '' ? ($brands[$bk] ?? null) : null;
        if ($r['brand'] !== '' && $brandId === null) $newBrands[$bk] = $r['brand'];
        $existing = $brandId || $r['brand'] === '' ? pack_find($brandId, $r['code'], $r['name']) : null;
        $sig = $bk . '|' . strtolower($r['code'] !== '' ? $r['code'] : $r['name']);
        $dupe = isset($seen[$sig]);
        $seen[$sig] = true;

        $want = array_filter(array_merge([$r['photo']], array_column($r['ingredients'], 'photo'), array_column($r['steps'], 'photo')));
        $found = array_filter($want, fn($p) => isset($pack['images'][strtolower(basename($p))]));
        $warn = $r['warnings'];
        $missing = array_diff($want, $found);
        if ($missing) $warn[] = plural(count($missing), 'photo') . ' named but not in the zip' . ($existing ? '; current photos are kept' : '') . ': ' . implode(', ', array_slice(array_map('basename', $missing), 0, 3)) . (count($missing) > 3 ? '…' : '');
        if ($dupe) $warn[] = 'Appears twice in the pack; the later one wins.';
        $rows[$n] = [
            'action' => $existing ? 'update' : 'new',
            'existing_id' => $existing,
            'recipe' => $r,
            'photos' => count($found),
            'photos_wanted' => count($want),
            'warnings' => $warn,
        ];
    }
    return ['rows' => $rows, 'new_brands' => array_values($newBrands)];
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

/** Apply a planned import. Selected = row numbers to apply. Returns [created, updated, photos]. */
function pack_apply(array $pack, array $selected): array
{
    $pdo = db();
    $saved = [];      // new photo files, removed again if the import fails
    $toDelete = [];   // replaced photo files, removed once the import succeeds
    $created = $updated = 0;
    $img = function (string $wanted, ?string $current) use ($pack, &$saved): ?string {
        $key = strtolower(basename($wanted));
        if ($wanted === '' || !isset($pack['images'][$key])) return $current;   // not supplied: keep what is there
        $name = save_image_bytes($pack['images'][$key], basename($wanted));
        $saved[] = $name;
        return $name;
    };

    $pdo->beginTransaction();
    try {
        $brands = [];
        foreach ($pdo->query('SELECT id, name FROM brands') as $b) $brands[strtolower($b['name'])] = (int)$b['id'];
        $maxSort = (int)$pdo->query('SELECT COALESCE(MAX(sort), 0) FROM brands')->fetchColumn();
        $plan = pack_plan($pack)['rows'];

        foreach ($selected as $n) {
            if (!isset($plan[$n])) continue;
            $r = $plan[$n]['recipe'];
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
            $ii = $pdo->prepare('INSERT INTO ingredients (recipe_id, sort, name, qty, unit, note, image) VALUES (?,?,?,?,?,?,?)');
            $keep = [];
            foreach ($r['ingredients'] as $k => $i) {
                $p = $img($i['photo'], $old['ingredients'][$k]['image'] ?? null);
                $keep[] = $p;
                $ii->execute([$id, $k, $i['name'], $i['qty'], $i['unit'], $i['note'], $p]);
            }
            $si = $pdo->prepare('INSERT INTO steps (recipe_id, sort, title, body, tip, timer_seconds, image) VALUES (?,?,?,?,?,?,?)');
            foreach ($r['steps'] as $k => $s) {
                $p = $img($s['photo'], $old['steps'][$k]['image'] ?? null);
                $keep[] = $p;
                $si->execute([$id, $k, $s['title'], $s['body'], $s['tip'], $s['timer'], $p]);
            }
            if ($old) {
                $keep[] = $dish;
                foreach (array_diff(recipe_images($old), array_filter($keep)) as $gone) $toDelete[] = $gone;
            }
        }
        $pdo->commit();
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($saved as $f) delete_upload($f);
        throw new RuntimeException('Nothing was imported: ' . $t->getMessage());
    }
    foreach (array_unique($toDelete) as $f) delete_upload($f);
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
