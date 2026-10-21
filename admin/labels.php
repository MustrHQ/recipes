<?php
/** Label items (what can be labelled, and for how long) and the label size and printer settings. */
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/ui.php';
require __DIR__ . '/../inc/admin.php';
require_admin();

function label_image(int $id): ?string
{
    $st = db()->prepare('SELECT image FROM label_items WHERE id = ?');
    $st->execute([$id]);
    return ($v = $st->fetchColumn()) ? (string)$v : null;
}

$self = base_url('admin/labels.php');
$brands = db()->query('SELECT id, name FROM brands ORDER BY sort, name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) { flash('The photo is bigger than your server accepts.', 'bad'); redirect($self); }
        $id = (int)($_POST['id'] ?? 0);
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
        $recipeId = (int)($_POST['recipe_id'] ?? 0) ?: null;
        if ($name === '' && $recipeId) {
            $st = db()->prepare('SELECT name FROM recipes WHERE id = ?');
            $st->execute([$recipeId]);
            $name = (string)$st->fetchColumn();
        }
        if ($name === '') { flash('Give the item a name, or link it to a recipe.', 'bad'); redirect($self . ($id ? '?id=' . $id : '?new=1')); }
        $value = max(1, min(999, (int)($_POST['shelf_value'] ?? 1)));
        // Photo: only for finding the item on the tablet. It is never printed.
        $old = $id ? label_image($id) : null;
        $image = !empty($_POST['image_rm']) ? null : $old;
        $photoNote = '';
        try { if ($new = save_upload($_FILES['image'] ?? ['error' => UPLOAD_ERR_NO_FILE])) $image = $new; }
        catch (RuntimeException $ex) { $photoNote = ' The photo was not saved: ' . $ex->getMessage(); }
        $fields = [
            (int)($_POST['brand_id'] ?? 0) ?: null, $recipeId, $name,
            array_key_exists($_POST['kind'] ?? '', LABEL_KINDS) ? $_POST['kind'] : 'made',
            $value, ($_POST['shelf_unit'] ?? '') === 'hours' ? 'hours' : 'days',
            mb_substr(trim((string)($_POST['storage'] ?? '')), 0, 60),
            implode(',', array_intersect(array_keys(ALLERGENS), (array)($_POST['allergens'] ?? []))), $image,
            isset($_POST['ask_supplier']) ? 1 : 0, isset($_POST['active']) ? 1 : 0, (int)($_POST['sort'] ?? 0),
        ];
        if ($id) {
            db()->prepare('UPDATE label_items SET brand_id=?, recipe_id=?, name=?, kind=?, shelf_value=?, shelf_unit=?, storage=?, allergens=?, image=?, ask_supplier=?, active=?, sort=? WHERE id=?')->execute([...$fields, $id]);
        } else {
            db()->prepare('INSERT INTO label_items (brand_id, recipe_id, name, kind, shelf_value, shelf_unit, storage, allergens, image, ask_supplier, active, sort) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute($fields);
        }
        if ($old && $old !== $image) delete_upload_if_unused($old);
        flash('Saved ' . $name . '.' . $photoNote, $photoNote ? 'bad' : 'ok');
        redirect($self);
    }

    if ($action === 'delete') {
        $old = label_image((int)($_POST['id'] ?? 0));
        db()->prepare('DELETE FROM label_items WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        delete_upload_if_unused($old);
        flash('Label item deleted.');
        redirect($self);
    }

    if ($action === 'from_recipes') {
        $value = max(1, min(999, (int)($_POST['shelf_value'] ?? 2)));
        $unit = ($_POST['shelf_unit'] ?? '') === 'hours' ? 'hours' : 'days';
        $storage = mb_substr(trim((string)($_POST['storage'] ?? '')), 0, 60);
        $n = db()->prepare("INSERT INTO label_items (brand_id, recipe_id, name, kind, shelf_value, shelf_unit, storage, allergens, sort)
            SELECT r.brand_id, r.id, r.name, 'made', ?, ?, ?, '', 0 FROM recipes r
            WHERE r.published = 1 AND NOT EXISTS (SELECT 1 FROM label_items l WHERE l.recipe_id = r.id)");
        $n->execute([$value, $unit, $storage]);
        flash($n->rowCount() ? plural($n->rowCount(), 'label item') . ' added, one for each live recipe that had none. Check the shelf lives before the team uses them.' : 'Every live recipe already has a label item.');
        redirect($self);
    }

    if ($action === 'settings') {
        $w = (float)($_POST['label_w'] ?? 50); $h = (float)($_POST['label_h'] ?? 30); $pw = (float)($_POST['label_print_w'] ?? 48);
        if ($w < 25 || $w > 80 || $h < 20 || $h > 80) { flash('Labels must be 25 to 80 mm wide and 20 to 80 mm tall.', 'bad'); redirect($self . '#settings'); }
        if ($pw < 20 || $pw > 72 || $pw > $w) { flash('The print width must be 20 to 72 mm and no wider than the label.', 'bad'); redirect($self . '#settings'); }
        set_setting('label_w', (string)$w);
        set_setting('label_h', (string)$h);
        set_setting('label_print_w', (string)$pw);
        set_setting('label_gap', (string)max(0, min(10, (float)($_POST['label_gap'] ?? 2))));
        set_setting('label_dpi', (int)($_POST['label_dpi'] ?? 203) === 300 ? '300' : '203');
        set_setting('label_lang', in_array($_POST['label_lang'] ?? '', ['tspl', 'escpos', 'browser'], true) ? $_POST['label_lang'] : 'tspl');
        set_setting('label_allergens', isset($_POST['label_allergens']) ? '1' : '0');
        set_setting('label_initials', isset($_POST['label_initials']) ? '1' : '0');
        flash('Label settings saved. Tablets use them the next time the labels screen opens.');
        redirect($self . '#settings');
    }
}

$items = db()->query('SELECT l.*, b.name AS brand_name, b.colour, r.name AS recipe_name, r.image AS recipe_image FROM label_items l LEFT JOIN brands b ON b.id = l.brand_id LEFT JOIN recipes r ON r.id = l.recipe_id ORDER BY b.sort, b.name, l.sort, l.name')->fetchAll();
$recipes = db()->query('SELECT r.id, r.name, r.code, r.brand_id, r.allergens, b.name AS brand_name FROM recipes r LEFT JOIN brands b ON b.id = r.brand_id ORDER BY b.sort, b.name, r.code, r.name')->fetchAll();
$missing = (int)db()->query('SELECT COUNT(*) FROM recipes r WHERE r.published = 1 AND NOT EXISTS (SELECT 1 FROM label_items l WHERE l.recipe_id = r.id)')->fetchColumn();

$edit = null;
if (isset($_GET['id'])) {
    $st = db()->prepare('SELECT * FROM label_items WHERE id = ?');
    $st->execute([(int)$_GET['id']]);
    $edit = $st->fetch() ?: null;
} elseif (isset($_GET['new'])) {
    $edit = ['id' => 0, 'brand_id' => null, 'recipe_id' => (int)($_GET['recipe'] ?? 0) ?: null, 'name' => '', 'kind' => 'made', 'shelf_value' => 2, 'shelf_unit' => 'days',
             'storage' => 'Chilled 0–5 °C', 'allergens' => '', 'image' => null, 'ask_supplier' => 0, 'active' => 1, 'sort' => 0];
    if ($edit['recipe_id']) {
        foreach ($recipes as $r) if ((int)$r['id'] === $edit['recipe_id']) { $edit['name'] = $r['name']; $edit['brand_id'] = $r['brand_id']; }
    }
}
$cfg = label_config();

admin_head('Labels', 'labels');
?>
<div class="ahead">
  <h1>Labels</h1>
  <a class="btn" href="<?= e(base_url('labels.php')) ?>" target="_blank" rel="noopener"><?= icon('eye') ?>Open the labels screen</a>
  <a class="btn btn-primary" href="?new=1"><?= icon('plus') ?>Add label item</a>
</div>
<p class="lede">Each item has a shelf life. On the tablet, staff pick the item and say when it was made or opened; the screen works out the use-by date and prints the label. For days, the day it was made counts as day 1.</p>

<?php if ($edit): $al = csv_list($edit['allergens']); ?>
<form method="post" enctype="multipart/form-data" class="card form" id="editor" style="margin-bottom: 20px" data-label-editor>
  <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
  <div class="card-h"><h2><?= $edit['id'] ? 'Edit ' . e($edit['name']) : 'New label item' ?></h2></div>
  <div class="fgrid">
    <div class="f s3"><label for="lr">Recipe</label>
      <select id="lr" name="recipe_id">
        <option value="">Not linked to a recipe</option>
        <?php foreach ($recipes as $r): ?><option value="<?= (int)$r['id'] ?>" data-brand="<?= (int)$r['brand_id'] ?>" data-name="<?= e($r['name']) ?>" <?= (int)$edit['recipe_id'] === (int)$r['id'] ? 'selected' : '' ?>><?= e(trim(($r['brand_name'] ? $r['brand_name'] . ': ' : '') . ($r['code'] ? $r['code'] . ' ' : '') . $r['name'])) ?></option><?php endforeach; ?>
      </select>
      <small>Linked items show a Print label button on the recipe, and use its allergens unless you tick others below.</small>
    </div>
    <div class="f s3"><label for="ln">Name on the label</label><input type="text" id="ln" name="name" value="<?= e($edit['name']) ?>" maxlength="120" placeholder="e.g. Golden curry sauce"></div>
    <div class="f s2"><label for="lb">Brand</label>
      <select id="lb" name="brand_id"><option value="">Kitchen name</option><?php foreach ($brands as $b): ?><option value="<?= (int)$b['id'] ?>" <?= (int)$edit['brand_id'] === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select>
      <small>Printed in the black bar at the top.</small>
    </div>
    <div class="f s2"><label for="lk">The first date is when it was</label>
      <select id="lk" name="kind"><?php foreach (LABEL_KINDS as $k => $l): ?><option value="<?= $k ?>" <?= $edit['kind'] === $k ? 'selected' : '' ?>><?= strtolower($l) ?></option><?php endforeach; ?></select>
    </div>
    <div class="f s2"><span class="lbl">Use within</span>
      <div class="pair"><input class="in" type="number" name="shelf_value" min="1" max="999" value="<?= (int)$edit['shelf_value'] ?>" aria-label="Shelf life">
        <select class="in" name="shelf_unit" aria-label="Unit"><option value="days" <?= $edit['shelf_unit'] === 'days' ? 'selected' : '' ?>>days</option><option value="hours" <?= $edit['shelf_unit'] === 'hours' ? 'selected' : '' ?>>hours</option></select></div>
    </div>
    <div class="f s3"><label for="ls">Storage</label><input type="text" id="ls" name="storage" value="<?= e($edit['storage']) ?>" maxlength="60" list="storages" placeholder="e.g. Chilled 0–5 °C">
      <datalist id="storages"><option value="Chilled 0–5 °C"><option value="Frozen −18 °C"><option value="Hot-held above 63 °C"><option value="Ambient, dry store"><option value="Chilled once opened"></datalist></div>
    <div class="f s3"><span class="lbl">On the tablet</span>
      <div class="checks"><label><input type="checkbox" name="active" value="1" <?= $edit['active'] ? 'checked' : '' ?>> Show</label><label><input type="checkbox" name="ask_supplier" value="1" <?= $edit['ask_supplier'] ? 'checked' : '' ?>> Ask for the supplier's date</label></div>
      <small>With the supplier's date, the label uses whichever date is sooner.</small>
    </div>
    <div class="f"><span class="lbl">Photo on the tablet</span>
      <?= image_field('image', 'image_keep', 'image_rm', $edit['image'], 'Photo of the item') ?>
      <small>Helps the team find the item at a glance. It is not printed on the label. Linked items use the recipe's photo when this is empty.</small>
    </div>
    <div class="f"><span class="lbl">Allergens</span>
      <div class="checks"><?php foreach (ALLERGENS as $k => $l): ?><label><input type="checkbox" name="allergens[]" value="<?= $k ?>" <?= in_array($k, $al, true) ? 'checked' : '' ?>> <?= e($l) ?></label><?php endforeach; ?></div>
      <small>Leave all unticked on a linked item to use the recipe's allergens.</small>
    </div>
    <input type="hidden" name="sort" value="<?= (int)$edit['sort'] ?>">
  </div>
  <div class="btnrow">
    <button class="btn btn-primary btn-big" type="submit">Save label item</button>
    <a class="btn btn-ghost" href="labels.php">Cancel</a>
  </div>
</form>
<?php endif; ?>

<?php if ($missing): ?>
<form method="post" class="card notice-card" style="margin-bottom: 20px">
  <?= csrf_field() ?><input type="hidden" name="action" value="from_recipes">
  <div>
    <h2><?= plural($missing, 'live recipe') ?> without a label</h2>
    <p class="help">Add a label item for each of them in one go, with this shelf life. You can change any of them afterwards.</p>
  </div>
  <div class="inline-fields">
    <label class="sr" for="fv">Use within</label><input class="in" id="fv" type="number" name="shelf_value" value="2" min="1" max="999">
    <label class="sr" for="fu">Unit</label><select class="in" id="fu" name="shelf_unit"><option value="days">days</option><option value="hours">hours</option></select>
    <label class="sr" for="fs">Storage</label><input class="in" id="fs" type="text" name="storage" value="Chilled 0–5 °C" list="storages2"><datalist id="storages2"><option value="Chilled 0–5 °C"><option value="Hot-held above 63 °C"></datalist>
    <button class="btn btn-primary" type="submit"><?= icon('plus') ?>Add <?= $missing ?></button>
  </div>
</form>
<?php endif; ?>

<?php if (!$items): ?>
  <div class="empty"><h2>No label items yet</h2><p>Add the things your team labels: dishes, sauces, prepped veg, anything opened or defrosted.</p><a class="btn btn-primary" href="?new=1"><?= icon('plus') ?>Add label item</a></div>
<?php else: ?>
<div class="scroll-x">
<table class="list">
  <thead><tr><th class="th"><span class="sr">Photo</span></th><th>Item</th><th>Brand</th><th>Use within</th><th>Storage</th><th>On tablet</th><th><span class="sr">Actions</span></th></tr></thead>
  <tbody>
  <?php foreach ($items as $i): ?>
    <tr>
      <td class="th"><?= pic($i['image'] ?: $i['recipe_image'], '', '', true) ?></td>
      <td><strong><?= e($i['name']) ?></strong><br><span class="kind kind-<?= e($i['kind']) ?>"><?= e(LABEL_KINDS[$i['kind']] ?? 'Made') ?></span><?php if ($i['recipe_name']): ?> <span class="small muted">linked to recipe</span><?php endif; ?></td>
      <td><?= e($i['brand_name'] ?? 'Kitchen name') ?></td>
      <td class="nowrap"><strong><?= e(fmt_shelf((int)$i['shelf_value'], $i['shelf_unit'])) ?></strong></td>
      <td class="small"><?= e($i['storage']) ?></td>
      <td><span class="pill <?= $i['active'] ? 'ok' : 'off' ?>"><?= $i['active'] ? 'Shown' : 'Hidden' ?></span></td>
      <td class="acts">
        <a class="btn" href="?id=<?= (int)$i['id'] ?>#editor">Edit</a>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$i['id'] ?>"><button class="btn btn-ghost btn-danger" name="action" value="delete" data-confirm="Delete the label item <?= e($i['name']) ?>?">Delete</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<form method="post" class="card" id="settings" style="margin-top: 24px" data-label-settings>
  <?= csrf_field() ?><input type="hidden" name="action" value="settings">
  <div class="settings-split">
    <div>
      <h2>Label size and printer</h2>
      <p class="help">Most small Bluetooth label printers take labels 30 to 50 mm and print up to 48 mm wide. Each tablet pairs its own printer from the printer button on the labels screen.</p>
      <div class="fgrid">
        <div class="f s2"><label for="sw">Label width (mm)</label><input type="number" step="0.5" min="25" max="80" id="sw" name="label_w" value="<?= e((string)$cfg['w']) ?>"></div>
        <div class="f s2"><label for="sh">Label height (mm)</label><input type="number" step="0.5" min="20" max="80" id="sh" name="label_h" value="<?= e((string)$cfg['h']) ?>"></div>
        <div class="f s2"><label for="sp">Print width (mm)</label><input type="number" step="0.5" min="20" max="72" id="sp" name="label_print_w" value="<?= e((string)$cfg['print_w']) ?>"></div>
        <div class="f s2"><label for="sg">Gap between labels (mm)</label><input type="number" step="0.5" min="0" max="10" id="sg" name="label_gap" value="<?= e((string)$cfg['gap']) ?>"></div>
        <div class="f s2"><label for="sd">Resolution</label><select id="sd" name="label_dpi"><option value="203" <?= $cfg['dpi'] === 203 ? 'selected' : '' ?>>203 dpi (most)</option><option value="300" <?= $cfg['dpi'] === 300 ? 'selected' : '' ?>>300 dpi</option></select></div>
        <div class="f s2"><label for="sl">Default language</label><select id="sl" name="label_lang">
          <option value="tspl" <?= $cfg['lang'] === 'tspl' ? 'selected' : '' ?>>TSPL (label printers)</option>
          <option value="escpos" <?= $cfg['lang'] === 'escpos' ? 'selected' : '' ?>>ESC/POS (receipt-style)</option>
          <option value="browser" <?= $cfg['lang'] === 'browser' ? 'selected' : '' ?>>Print dialog</option></select></div>
        <div class="f"><div class="checks">
          <label><input type="checkbox" name="label_allergens" value="1" <?= $cfg['show_allergens'] ? 'checked' : '' ?>> Print allergens on the label</label>
          <label><input type="checkbox" name="label_initials" value="1" <?= $cfg['ask_initials'] ? 'checked' : '' ?>> Ask for the cook's initials</label>
        </div></div>
      </div>
      <button class="btn btn-primary" type="submit" style="margin-top: 14px">Save label settings</button>
    </div>
    <div class="label-sample">
      <span class="lbl">Sample at this size</span>
      <div class="paper" data-label-preview><img alt="Sample label"></div>
      <small class="muted">The tablet shows the exact label before printing.</small>
    </div>
  </div>
</form>
<script src="<?= e(asset('printer.js')) ?>" defer></script>
<?php admin_foot();
