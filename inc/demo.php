<?php
/**
 * Two example recipes, with illustrated photos, so a fresh install has something to look at.
 * Safe to delete from the admin once you have your own recipes.
 */
function install_demo(PDO $pdo): void
{
    $pdo->prepare('INSERT INTO brands (name, colour, sort) VALUES (?, ?, ?)')->execute(['Demo Kitchen', '#01216C', 1]);
    $brand = (int)$pdo->lastInsertId();

    $recipes = [
        [
            'code' => 'D01', 'name' => 'Chicken katsu rice bowl',
            'description' => 'Crisp panko chicken on sticky rice with katsu sauce, pickled red cabbage and spring onion.',
            'yield' => '1 bowl', 'prep' => 6, 'cook' => 8,
            'allergens' => 'gluten,eggs,soya,celery', 'diets' => 'halal',
            'notes' => 'Chicken must reach 75 °C core. Probe the thickest piece every batch.',
            'ing' => [
                ['Breaded chicken thigh', '150', 'g', 'from the prep fridge, shelf 2'],
                ['Cooked sushi rice', '220', 'g', 'hot-held above 63 °C'],
                ['Katsu sauce', '90', 'ml', 'warm, stir before use'],
                ['Pickled red cabbage', '30', 'g', ''],
                ['Spring onion, sliced', '5', 'g', ''],
                ['Toasted sesame seeds', '1', 'pinch', ''],
            ],
            'steps' => [
                ['Fry the chicken', 'Lower the breaded thigh into the fryer at 175 °C. Fry until deep golden.', 'Lay it away from you to avoid splashes.', 360],
                ['Check and rest', 'Probe the thickest part. It must read 75 °C or above. Rest on the rack for one minute.', 'Log the temperature if it is the first batch of the shift.', 60],
                ['Build the base', 'Press the rice into the bowl with the rice paddle so it sits level, slightly higher in the middle.', '', null],
                ['Slice and place', 'Slice the chicken into 6 strips on the red board. Fan the strips across the right half of the rice.', 'Use the red board only. Raw and cooked chicken boards stay separate.', null],
                ['Sauce and finish', 'Ladle the katsu sauce across the chicken in one line. Add the cabbage on the left, then spring onion and sesame on top.', 'Keep sauce off the cabbage so it stays bright.', null],
                ['Pack', 'Lid on, brand sticker across the seam, katsu bag. Out to the pass within 2 minutes.', '', null],
            ],
        ],
        [
            'code' => 'D02', 'name' => 'Garlic chilli edamame',
            'description' => 'Hot edamame tossed in garlic chilli oil and flaky salt.',
            'yield' => '1 tub', 'prep' => 2, 'cook' => 4,
            'allergens' => 'soya,sesame', 'diets' => 'vegan,vegetarian,halal,gluten_free',
            'notes' => '',
            'ing' => [
                ['Frozen edamame in pods', '180', 'g', ''],
                ['Garlic chilli oil', '15', 'ml', ''],
                ['Flaky sea salt', '2', 'g', ''],
            ],
            'steps' => [
                ['Blanch', 'Drop the edamame into the boiling basket. Cook until bright green and hot through.', '', 180],
                ['Drain and toss', 'Shake well, tip into the steel mixing bowl, add oil and salt. Toss 10 times.', 'Use the vegan-only bowl.', null],
                ['Pack', 'Tip into the tub, lid on, sticker on.', '', null],
            ],
        ],
    ];

    // Copy an illustrated demo photo into uploads/, if it ships with this release.
    $photo = function (string $name): ?string {
        $src = __DIR__ . '/demo-images/' . $name . '.jpg';
        if (!is_file($src)) return null;
        try { return save_image_bytes((string)file_get_contents($src), $name . '.jpg'); }
        catch (Throwable $t) { return null; }
    };

    $ri = $pdo->prepare('INSERT INTO recipes (brand_id, code, name, description, yield_text, prep_minutes, cook_minutes, allergens, diets, notes, image) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $ii = $pdo->prepare('INSERT INTO ingredients (recipe_id, sort, name, qty, unit, note, image) VALUES (?,?,?,?,?,?,?)');
    $si = $pdo->prepare('INSERT INTO steps (recipe_id, sort, title, body, tip, timer_seconds, image) VALUES (?,?,?,?,?,?,?)');
    $ids = [];
    foreach ($recipes as $r) {
        $key = strtolower($r['code']);
        $ri->execute([$brand, $r['code'], $r['name'], $r['description'], $r['yield'], $r['prep'], $r['cook'], $r['allergens'], $r['diets'], $r['notes'], $photo("$key-dish")]);
        $rid = (int)$pdo->lastInsertId();
        $ids[$r['code']] = $rid;
        foreach ($r['ing'] as $n => $i) $ii->execute([$rid, $n, $i[0], $i[1], $i[2], $i[3], $photo("$key-ing-" . ($n + 1))]);
        foreach ($r['steps'] as $n => $s) $si->execute([$rid, $n, $s[0], $s[1], $s[2], $s[3], $photo("$key-step-" . ($n + 1))]);
    }

    // Label items: the bowl itself, and the prepped things the team labels during the day.
    // Each has a photo so the team can find it on the tablet; the recipe-linked one uses the recipe's.
    $li = $pdo->prepare('INSERT INTO label_items (brand_id, recipe_id, name, kind, shelf_value, shelf_unit, storage, allergens, image, ask_supplier, sort) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $li->execute([$brand, $ids['D01'] ?? null, 'Chicken katsu rice bowl', 'made', 1, 'days', 'Chilled 0–5 °C', '', null, 0, 1]);
    $li->execute([$brand, null, 'Cooked sushi rice', 'made', 2, 'hours', 'Hot-held above 63 °C', '', $photo('d01-ing-2'), 0, 2]);
    $li->execute([$brand, null, 'Katsu sauce', 'made', 3, 'days', 'Chilled 0–5 °C', 'gluten,celery,soya', $photo('d01-ing-3'), 0, 3]);
    $li->execute([$brand, null, 'Pickled red cabbage', 'opened', 5, 'days', 'Chilled once opened', 'sulphites', $photo('d01-ing-4'), 1, 4]);
    $li->execute([$brand, null, 'Breaded chicken thigh', 'defrosted', 2, 'days', 'Chilled 0–5 °C', 'gluten,eggs', $photo('d01-ing-1'), 0, 5]);
}
