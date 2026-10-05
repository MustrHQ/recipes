<?php
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/ui.php';
require __DIR__ . '/../inc/admin.php';
require_admin();

$id = (int)($_GET['id'] ?? 0);
$r = $id ? load_recipe($id, false) : null;
if ($id && !$r) { flash('That recipe no longer exists.', 'bad'); redirect(base_url('admin/')); }
$brands = db()->query('SELECT * FROM brands ORDER BY sort, name')->fetchAll();

/** "6:30" → 390, "6" → 360 (minutes), "" → null */
function parse_timer(string $s): ?int
{
    $s = trim($s);
    if ($s === '') return null;
    if (preg_match('/^(\d{1,3}):([0-5]?\d)$/', $s, $m)) return (int)$m[1] * 60 + (int)$m[2] ?: null;
    if (is_numeric($s)) return (int)round((float)$s * 60) ?: null;
    return null;
}

/** Work out the picture for one image field: keep, replace, or remove. */
function resolve_image(?string $keep, bool $remove, array $file, array &$warnings): ?string
{
    try {
        $new = save_upload($file);
        if ($new) return $new;
    } catch (RuntimeException $ex) {
        $warnings[] = $ex->getMessage();
    }
    return $remove ? null : ($keep !== '' && $keep !== null && preg_match('/^[\w.-]+$/', $keep) ? $keep : null);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Uploads bigger than post_max_size arrive as an empty POST.
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('Nothing was saved: the photos together were bigger than your server allows (' . ini_get('post_max_size') . '). Upload fewer photos at once.', 'bad');
        redirect(base_url('admin/recipe-edit.php' . ($id ? '?id=' . $id : '')));
    }
    check_csrf();
    $warnings = [];
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        flash('Give the recipe a name before saving.', 'bad');
        redirect(base_url('admin/recipe-edit.php' . ($id ? '?id=' . $id : '')));
    }
    $brandId = (int)($_POST['brand_id'] ?? 0) ?: null;
    $allergens = implode(',', array_intersect(array_keys(ALLERGENS), (array)($_POST['allergens'] ?? [])));
    $diets = implode(',', array_intersect(array_keys(DIETS), (array)($_POST['diets'] ?? [])));
    $num = fn($k) => ($v = (int)($_POST[$k] ?? 0)) > 0 ? min($v, 9999) : null;
    $mainImage = resolve_image($_POST['image_keep'] ?? '', !empty($_POST['image_rm']), $_FILES['image'] ?? ['error' => UPLOAD_ERR_NO_FILE], $warnings);

    $fields = [
        $brandId, mb_substr(trim((string)($_POST['code'] ?? '')), 0, 20), mb_substr($name, 0, 140),
        trim((string)($_POST['description'] ?? '')), mb_substr(trim((string)($_POST['yield_text'] ?? '')), 0, 80),
        $num('prep_minutes'), $num('cook_minutes'), $allergens, $diets, $mainImage,
        trim((string)($_POST['notes'] ?? '')), isset($_POST['published']) ? 1 : 0,
    ];

    $ings = [];
    foreach ((array)($_POST['ing'] ?? []) as $key => $row) {
        $n = trim((string)($row['name'] ?? ''));
        if ($n === '') continue;
        $ings[] = [
            mb_substr($n, 0, 120), mb_substr(trim((string)($row['qty'] ?? '')), 0, 20), mb_substr(trim((string)($row['unit'] ?? '')), 0, 20),
            mb_substr(trim((string)($row['note'] ?? '')), 0, 160),
            resolve_image($row['keep'] ?? '', !empty($row['rm']), file_at('ing_img', $key), $warnings),
        ];
    }
    $steps = [];
    foreach ((array)($_POST['step'] ?? []) as $key => $row) {
        $body = trim((string)($row['body'] ?? ''));
        $title = trim((string)($row['title'] ?? ''));
        if ($body === '' && $title === '') continue;
        $steps[] = [
            mb_substr($title, 0, 120), $body, mb_substr(trim((string)($row['tip'] ?? '')), 0, 255),
            parse_timer((string)($row['timer'] ?? '')),
            resolve_image($row['keep'] ?? '', !empty($row['rm']), file_at('step_img', $key), $warnings),
        ];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($r) {
            $pdo->prepare('UPDATE recipes SET brand_id=?, code=?, name=?, description=?, yield_text=?, prep_minutes=?, cook_minutes=?, allergens=?, diets=?, image=?, notes=?, published=? WHERE id=?')
                ->execute([...$fields, $id]);
            $pdo->prepare('DELETE FROM ingredients WHERE recipe_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM steps WHERE recipe_id = ?')->execute([$id]);
        } else {
            $pdo->prepare('INSERT INTO recipes (brand_id, code, name, description, yield_text, prep_minutes, cook_minutes, allergens, diets, image, notes, published) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute($fields);
            $id = (int)$pdo->lastInsertId();
        }
        $ii = $pdo->prepare('INSERT INTO ingredients (recipe_id, sort, name, qty, unit, note, image) VALUES (?,?,?,?,?,?,?)');
        foreach ($ings as $n => $i) $ii->execute([$id, $n, ...$i]);
        $si = $pdo->prepare('INSERT INTO steps (recipe_id, sort, title, body, tip, timer_seconds, image) VALUES (?,?,?,?,?,?,?)');
        foreach ($steps as $n => $s) $si->execute([$id, $n, ...$s]);
        $pdo->commit();
    } catch (Throwable $t) {
        $pdo->rollBack();
        flash('Nothing was saved: ' . $t->getMessage(), 'bad');
        redirect(base_url('admin/recipe-edit.php' . ($r ? '?id=' . $id : '')));
    }

    // Tidy up pictures that were replaced or removed.
    if ($r) {
        $old = array_merge([$r['image']], array_column($r['ingredients'], 'image'), array_column($r['steps'], 'image'));
        $now = array_merge([$mainImage], array_column($ings, 4), array_column($steps, 4));
        foreach (array_diff(array_filter($old), array_filter($now)) as $gone) delete_upload_if_unused($gone);
    }

    flash($warnings ? 'Saved, but some photos were skipped. ' . implode(' ', $warnings) : 'Saved "' . $name . '".', $warnings ? 'bad' : 'ok');
    redirect(base_url('admin/recipe-edit.php?id=' . $id));
}

$r ??= [
    'id' => 0, 'brand_id' => (int)($_GET['brand'] ?? 0), 'code' => '', 'name' => '', 'description' => '', 'yield_text' => '',
    'prep_minutes' => null, 'cook_minutes' => null, 'allergens' => '', 'diets' => '', 'image' => null, 'notes' => '', 'published' => 1,
    'ingredients' => [], 'steps' => [],
];
$al = csv_list($r['allergens']);
$di = csv_list($r['diets']);

function ing_row(string $key, array $i = []): string
{
    ob_start(); ?>
  <div class="row ingrow">
    <div class="num c-num"></div>
    <div class="f c-name"><label class="lbl" for="in-<?= $key ?>">Ingredient</label><input id="in-<?= $key ?>" type="text" name="ing[<?= $key ?>][name]" value="<?= e($i['name'] ?? '') ?>" placeholder="e.g. Cooked sushi rice"></div>
    <div class="f c-qty"><label class="lbl" for="iq-<?= $key ?>">Amount</label><input id="iq-<?= $key ?>" type="text" name="ing[<?= $key ?>][qty]" value="<?= e($i['qty'] ?? '') ?>" placeholder="220" inputmode="decimal"></div>
    <div class="f c-unit"><label class="lbl" for="iu-<?= $key ?>">Unit</label><input id="iu-<?= $key ?>" type="text" name="ing[<?= $key ?>][unit]" value="<?= e($i['unit'] ?? '') ?>" placeholder="g" list="units"></div>
    <div class="f c-note"><label class="lbl" for="it-<?= $key ?>">Note</label><input id="it-<?= $key ?>" type="text" name="ing[<?= $key ?>][note]" value="<?= e($i['note'] ?? '') ?>" placeholder="e.g. hot-held above 63 °C"></div>
    <div class="c-img"><?= image_field("ing_img[$key]", "ing[$key][keep]", "ing[$key][rm]", $i['image'] ?? null, 'Ingredient photo') ?></div>
    <div class="tools c-tools">
      <button type="button" data-act="up" aria-label="Move up"><?= icon('up') ?></button>
      <button type="button" data-act="down" aria-label="Move down"><?= icon('down') ?></button>
      <button type="button" data-act="del" class="del" aria-label="Remove ingredient"><?= icon('trash') ?></button>
    </div>
  </div>
<?php return (string)ob_get_clean();
}

function step_row(string $key, array $s = []): string
{
    $t = !empty($s['timer_seconds']) ? fmt_timer((int)$s['timer_seconds']) : '';
    ob_start(); ?>
  <div class="row steprow">
    <div class="num"></div>
    <div class="stepfields">
      <div class="f"><label class="lbl" for="st-<?= $key ?>">Step title</label><input id="st-<?= $key ?>" type="text" name="step[<?= $key ?>][title]" value="<?= e($s['title'] ?? '') ?>" placeholder="e.g. Fry the chicken"></div>
      <div class="f"><label class="lbl" for="sm-<?= $key ?>">Timer</label><input id="sm-<?= $key ?>" type="text" name="step[<?= $key ?>][timer]" value="<?= e($t) ?>" placeholder="6:00"><small>Minutes:seconds. Optional.</small></div>
      <div class="f full"><label class="lbl" for="sb-<?= $key ?>">What to do</label><textarea id="sb-<?= $key ?>" name="step[<?= $key ?>][body]" rows="3" placeholder="One clear action per step. Say what it should look like when it's right."><?= e($s['body'] ?? '') ?></textarea></div>
      <div class="f full"><label class="lbl" for="sp-<?= $key ?>">Tip or warning</label><input id="sp-<?= $key ?>" type="text" name="step[<?= $key ?>][tip]" value="<?= e($s['tip'] ?? '') ?>" placeholder="Optional, e.g. Use the red board only"></div>
      <div class="full"><?= image_field("step_img[$key]", "step[$key][keep]", "step[$key][rm]", $s['image'] ?? null, 'Step photo') ?></div>
    </div>
    <div class="tools">
      <button type="button" data-act="up" aria-label="Move step up"><?= icon('up') ?></button>
      <button type="button" data-act="down" aria-label="Move step down"><?= icon('down') ?></button>
      <button type="button" data-act="del" class="del" aria-label="Remove step"><?= icon('trash') ?></button>
    </div>
  </div>
<?php return (string)ob_get_clean();
}

admin_head($r['id'] ? 'Edit ' . $r['name'] : 'Add recipe', 'recipes');
?>
<div class="ahead">
  <a class="btn btn-ghost" href="./" aria-label="Back to recipes"><?= icon('back') ?></a>
  <h1><?= $r['id'] ? e($r['name']) : 'Add recipe' ?></h1>
  <?php if ($r['id']): ?><a class="btn" href="<?= e(base_url('recipe.php?id=' . (int)$r['id'])) ?>" target="_blank" rel="noopener"><?= icon('eye') ?>View in kitchen</a><?php endif; ?>
</div>

<form method="post" enctype="multipart/form-data" class="form" data-dirty data-max-files="<?= (int)ini_get('max_file_uploads') ?: 20 ?>" data-max-post="<?= ini_bytes('post_max_size') ?>" data-max-file="<?= ini_bytes('upload_max_filesize') ?>">
  <?= csrf_field() ?>
  <datalist id="units"><option value="g"><option value="kg"><option value="ml"><option value="l"><option value="pcs"><option value="slices"><option value="tbsp"><option value="tsp"><option value="pinch"><option value="scoop"><option value="ladle"></datalist>

  <section class="card">
    <h2>The dish</h2>
    <p class="help">What the kitchen sees first: the name, the finished photo and the key facts.</p>
    <div class="fgrid">
      <div class="f s4"><label for="name">Recipe name</label><input type="text" id="name" name="name" value="<?= e($r['name']) ?>" required maxlength="140"></div>
      <div class="f s2"><label for="code">Code</label><input type="text" id="code" name="code" value="<?= e($r['code']) ?>" maxlength="20" placeholder="e.g. K01"><small>Matches the ticket or menu number.</small></div>
      <div class="f s3"><label for="brand_id">Brand</label>
        <select id="brand_id" name="brand_id">
          <option value="">No brand</option>
          <?php foreach ($brands as $b): ?><option value="<?= (int)$b['id'] ?>" <?= (int)$r['brand_id'] === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
        </select>
        <?php if (!$brands): ?><small><a href="brands.php">Add brands</a> to group recipes.</small><?php endif; ?>
      </div>
      <div class="f s3"><label for="yield_text">Makes</label><input type="text" id="yield_text" name="yield_text" value="<?= e($r['yield_text']) ?>" placeholder="e.g. 1 bowl, 4 portions, 2 kg"></div>
      <div class="f"><label for="description">Short description</label><textarea id="description" name="description" rows="2" placeholder="One or two lines about the dish."><?= e($r['description']) ?></textarea></div>
      <div class="f s2"><label for="prep_minutes">Prep time (minutes)</label><input type="number" id="prep_minutes" name="prep_minutes" min="0" value="<?= e((string)$r['prep_minutes']) ?>"></div>
      <div class="f s2"><label for="cook_minutes">Cook time (minutes)</label><input type="number" id="cook_minutes" name="cook_minutes" min="0" value="<?= e((string)$r['cook_minutes']) ?>"></div>
      <div class="f s2"><span class="lbl">On kitchen screens</span><div class="checks"><label><input type="checkbox" name="published" value="1" <?= $r['published'] ? 'checked' : '' ?>> Live</label></div></div>
      <div class="f"><span class="lbl">Finished dish photo</span><?= image_field('image', 'image_keep', 'image_rm', $r['image'], 'Finished dish photo', true) ?><small>Shown on the recipe and at the end of step by step, so the team can check the dish matches.</small></div>
    </div>
  </section>

  <section class="card">
    <h2>Allergens and diet</h2>
    <p class="help">Tick every one of the 14 allergens the finished dish contains.</p>
    <div class="checks" style="margin-bottom: 14px">
      <?php foreach (ALLERGENS as $k => $label): ?><label><input type="checkbox" name="allergens[]" value="<?= $k ?>" <?= in_array($k, $al, true) ? 'checked' : '' ?>> <?= e($label) ?></label><?php endforeach; ?>
    </div>
    <div class="checks">
      <?php foreach (DIETS as $k => $label): ?><label><input type="checkbox" name="diets[]" value="<?= $k ?>" <?= in_array($k, $di, true) ? 'checked' : '' ?>> <?= e($label) ?></label><?php endforeach; ?>
    </div>
    <div class="f" style="margin-top: 16px"><label for="notes">Safety or quality note</label><textarea id="notes" name="notes" rows="2" placeholder="e.g. Chicken must reach 75 °C core."><?= e($r['notes']) ?></textarea><small>Shown in amber on the recipe page.</small></div>
  </section>

  <section class="card">
    <h2>What you need</h2>
    <p class="help">Ingredients with amounts for one batch. Use plain numbers in Amount so the kitchen can scale the batch up or down.</p>
    <div class="rows" id="ingRows" data-rows="tplIng" data-start-empty>
      <?php foreach ($r['ingredients'] as $i) echo ing_row('e' . (int)$i['id'], $i); ?>
    </div>
    <button class="btn" type="button" data-add="ingRows" style="margin-top: 12px"><?= icon('plus') ?>Add ingredient</button>
  </section>

  <section class="card">
    <h2>Step by step</h2>
    <p class="help">Each step becomes one full screen in step-by-step mode. Add a photo to show what it should look like.</p>
    <div class="rows" id="stepRows" data-rows="tplStep" data-start-empty>
      <?php foreach ($r['steps'] as $s) echo step_row('e' . (int)$s['id'], $s); ?>
    </div>
    <button class="btn" type="button" data-add="stepRows" style="margin-top: 12px"><?= icon('plus') ?>Add step</button>
  </section>

  <div class="savebar">
    <span class="grow" data-upload-note>Photos: JPG, PNG or WebP. Big photos are shrunk in your browser before upload.</span>
    <a class="btn btn-ghost" href="./">Cancel</a>
    <button class="btn btn-primary btn-big" type="submit">Save recipe</button>
  </div>
</form>

<template id="tplIng"><?= ing_row('__KEY__') ?></template>
<template id="tplStep"><?= step_row('__KEY__') ?></template>
<?php admin_foot();
