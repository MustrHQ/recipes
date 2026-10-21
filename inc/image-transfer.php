<?php
/** Image-only updates. Recipe text, order, allergens and publication are never written. */
function pack_image_text(string $text): string
{
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', trim($text, " \t\n\r\0\x0B.")) ?? $text));
}

function pack_read_images(string $path, array $images): array
{
    $map = json_decode((string)file_get_contents(__DIR__ . '/../data/kitchen-image-map.json'), true, 512, JSON_THROW_ON_ERROR);
    $existing = db()->query('SELECT r.*, b.name AS brand_name FROM recipes r LEFT JOIN brands b ON b.id = r.brand_id ORDER BY r.id')->fetchAll();
    $children = ['ingredients' => [], 'steps' => []];
    foreach (array_keys($children) as $table) {
        foreach (db()->query('SELECT * FROM ' . $table . ' ORDER BY recipe_id, sort, id') as $child) {
            $children[$table][(int)$child['recipe_id']][] = $child;
        }
    }
    // Accept both the Station filenames and this app's own export filenames.
    // More than one recipe with the same identity is ambiguous: never guess.
    $identities = [];
    foreach ($existing as $old) {
        $code = pack_slug($old['code'] ?: 'r' . $old['id']);
        foreach (array_unique([pack_station_slug($old['brand_name'] ?: 'kitchen'), pack_slug($old['brand_name'] ?: 'kitchen')]) as $brand) {
            $identities[$brand . '|' . $code][(int)$old['id']] = true;
        }
    }
    $dish = $steps = $positions = $ingredientPositions = $ingredients = [];
    foreach ($images as $key => $entry) {
        $ref = $map[$key] ?? $map[pathinfo($key, PATHINFO_FILENAME) . '.png'] ?? null;
        if ($ref) {
            if ($ref['kind'] === 'ingredient') {
                foreach ($ref['names'] as $name) $ingredients[pack_image_text($name)][$key] = true;
                continue;
            }
            $ids = array_keys($identities[$ref['brand_slug'] . '|' . $ref['code']] ?? []);
            if (count($ids) !== 1) continue;
            $id = $ids[0];
            if ($ref['kind'] === 'dish') $dish[$id][$key] = true;
            else $steps[$id][pack_image_text($ref['text'])][$key] = true;
            // Known kitchen steps require the reference instruction; no positional fallback.
            continue;
        }
        if (!preg_match('/^([a-z0-9-]+)__([a-z0-9-]+)__(dish|preview|(?:step|ing)-?[1-9][0-9]*)\.(?:jpe?g|png|webp|gif)$/', $key, $m)) continue;
        $ids = array_keys($identities[$m[1] . '|' . $m[2]] ?? []);
        if (count($ids) !== 1) continue;
        $id = $ids[0]; $slot = $m[3];
        if (in_array($slot, ['dish', 'preview'], true)) $dish[$id][$key] = true;
        elseif (preg_match('/^step-?(\d+)$/', $slot, $n)) $positions[$id][(int)$n[1] - 1][$key] = true;
        elseif (preg_match('/^ing-?(\d+)$/', $slot, $n)) $ingredientPositions[$id][(int)$n[1] - 1][$key] = true;
    }
    $recipes = $used = [];
    foreach ($existing as $old) {
        $id = (int)$old['id'];
        $bindings = [];
        $candidates = array_keys($dish[$id] ?? []);
        $photo = count($candidates) === 1 ? $candidates[0] : '';
        if ($photo) $bindings[] = ['table' => 'recipes', 'id' => $id, 'photo' => $photo, 'slot' => 'Finished dish'];
        $ings = $method = [];
        foreach (($children['ingredients'][$id] ?? []) as $k => $i) {
            $candidates = array_keys(($ingredients[pack_image_text($i['name'])] ?? []) + ($ingredientPositions[$id][$k] ?? []));
            $key = count($candidates) === 1 ? $candidates[0] : '';
            $ings[] = ['photo' => $key];
            if ($key) $bindings[] = ['table' => 'ingredients', 'id' => (int)$i['id'], 'photo' => $key, 'slot' => 'Ingredient ' . ($k + 1) . ': ' . $i['name']];
        }
        foreach (($children['steps'][$id] ?? []) as $k => $step) {
            $candidates = array_keys(($steps[$id][pack_image_text($step['body'])] ?? []) + ($positions[$id][$k] ?? []));
            $key = count($candidates) === 1 ? $candidates[0] : '';
            $method[] = ['photo' => $key];
            if ($key) $bindings[] = ['table' => 'steps', 'id' => (int)$step['id'], 'photo' => $key, 'slot' => 'Step ' . ($k + 1) . ': ' . mb_substr($step['body'], 0, 100)];
        }
        if (!$bindings) continue;
        foreach ($bindings as $b) $used[$b['photo']] = true;
        $recipes[$id] = [
            'brand' => $old['brand_name'] ?? '', 'code' => $old['code'], 'name' => $old['name'],
            'live' => (bool)$old['published'], 'photo' => $photo, 'ingredients' => $ings,
            'steps' => $method, 'warnings' => [], 'bindings' => $bindings,
        ];
    }
    $unmatched = array_values(array_diff(array_keys($images), array_keys($used)));
    if (!$recipes) throw new RuntimeException('No pictures match existing recipes. Create or import the recipes first. Check filenames against a recipe export: brand__code__dish.png, brand__code__step-1.png or brand__code__ing-1.png. Known kitchen step pictures also require matching instructions. Duplicate recipe identities, conflicting pictures and utensils are not attached.');
    $notes = ['IMAGE-ONLY IMPORT: updates matching picture slots only. Recipe text, quantities, allergens and publication stay unchanged. Review each picture placement before importing.'];
    if ($unmatched) $notes[] = count($unmatched) . ' pictures will not be attached. Check the unmatched list for unsupported utensils, missing or duplicate recipe identities, conflicting pictures or unmatched instructions. The original files stay in your ZIP.';
    return ['path' => $path, 'zip' => true, 'images_only' => true, 'recipes' => $recipes,
        'images' => $images, 'unmatched_images' => $unmatched, 'problems' => [], 'notes' => $notes, 'source' => 'image-only'];
}

function pack_apply_images(array $pack, array $selected, array &$shared): array
{
    $pdo = db();
    $saved = $oldFiles = [];
    $cache = $shared;
    $updated = 0;
    $pdo->beginTransaction();
    try {
        foreach ($selected as $id) {
            $r = $pack['recipes'][$id] ?? null;
            if (!$r) continue;
            foreach ($r['bindings'] as $binding) {
                $table = $binding['table'];
                if (!in_array($table, ['recipes', 'ingredients', 'steps'], true)) throw new RuntimeException('Invalid picture slot.');
                $key = $binding['photo'];
                if (!isset($cache[$key]) || !is_file(UPLOAD_DIR . '/' . $cache[$key])) {
                    $bytes = pack_image($pack, $key);
                    if ($bytes === null) throw new RuntimeException('Missing picture: ' . $key);
                    $cache[$key] = save_image_bytes($bytes, $key);
                    $saved[] = $cache[$key];
                }
                $st = $pdo->prepare("SELECT image FROM $table WHERE id = ?");
                $st->execute([$binding['id']]);
                $old = $st->fetchColumn();
                $pdo->prepare("UPDATE $table SET image = ? WHERE id = ?")->execute([$cache[$key], $binding['id']]);
                if ($old && $old !== $cache[$key]) $oldFiles[] = $old;
            }
            $updated++;
        }
        $pdo->commit();
        $shared = $cache;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($saved as $file) delete_upload($file);
        throw new RuntimeException('No pictures in this batch were imported: ' . $e->getMessage(), 0, $e);
    }
    foreach (array_unique($oldFiles) as $file) delete_upload_if_unused($file);
    return [0, $updated, count($saved)];
}

/** Detect changed recipe rows between the placement preview and the apply request. */
function pack_image_signatures(array $pack): array
{
    $signatures = [];
    foreach ($pack['recipes'] as $id => $recipe) {
        $signatures[$id] = hash('sha256', json_encode($recipe['bindings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
    return $signatures;
}

function pack_check_image_preview(array $pack, array $selected, array $signatures): void
{
    if (empty($pack['images_only'])) return;
    $current = pack_image_signatures($pack);
    foreach ($selected as $id) {
        if (!isset($current[$id], $signatures[$id]) || !hash_equals($current[$id], $signatures[$id])) {
            throw new RuntimeException('Recipe picture placements changed after the preview. Reload Import and export and review the matches again.');
        }
    }
}
