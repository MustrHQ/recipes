<?php
/** Import and export: recipe packs in and out, and the ChatGPT brief that makes them. */
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/ui.php';
require __DIR__ . '/../inc/admin.php';
require __DIR__ . '/../inc/transfer.php';
require_admin();

$self = base_url('admin/transfer.php');
$dir = upd_dir();
$brands = db()->query('SELECT b.id, b.name, (SELECT COUNT(*) FROM recipes r WHERE r.brand_id = b.id) AS n FROM brands b ORDER BY b.sort, b.name')->fetchAll();
$total = (int)db()->query('SELECT COUNT(*) FROM recipes')->fetchColumn();

/** The pack file waiting for the current admin to apply, or null. */
function pending_pack(string $dir): ?array
{
    $p = $_SESSION['import'] ?? null;
    if (!$p || !preg_match('/^import-[a-f0-9]{16}\.(zip|json)$/', $p['file']) || !is_file($dir . '/' . $p['file'])) return null;
    return $p;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('That file is bigger than your server accepts (' . ini_get('post_max_size') . '). Split the pack into smaller zips, or raise the limit in cPanel → Select PHP Version → Options.', 'bad');
        redirect($self);
    }
    check_csrf();
    $action = $_POST['action'] ?? '';
    @set_time_limit(300);

    if ($action === 'brief') {
        $brand = trim((string)($_POST['brand'] ?? ''));
        $md = chatgpt_brief(mb_substr($brand, 0, 80), trim((string)($_POST['menu'] ?? '')), !empty($_POST['photos']));
        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="recipe-brief' . ($brand !== '' ? '-' . pack_slug($brand) : '') . '.md"');
        echo $md;
        exit;
    }

    if ($action === 'export') {
        try {
            [$path, $name] = pack_export((int)($_POST['brand_id'] ?? 0) ?: null);
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            @unlink($path);
            exit;
        } catch (Throwable $e) {
            flash($e->getMessage(), 'bad');
            redirect($self);
        }
    }

    if ($action === 'upload') {
        $f = $_FILES['pack'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            flash(($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE
                ? 'That file is bigger than your server accepts (' . ini_get('upload_max_filesize') . '). Split the pack into smaller zips.'
                : 'Choose a recipe pack (.zip) or recipes.json first.', 'bad');
            redirect($self);
        }
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['zip', 'json'], true)) { flash('Upload a .zip recipe pack or a recipes.json file.', 'bad'); redirect($self); }
        if (pending_pack($dir)) @unlink($dir . '/' . $_SESSION['import']['file']);
        $file = 'import-' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $file)) { flash('Could not save the upload. Check the backups folder is writable (755).', 'bad'); redirect($self); }
        try {
            pack_read($dir . '/' . $file, $file);    // fail early on a broken pack
            $_SESSION['import'] = ['file' => $file, 'name' => (string)$f['name']];
        } catch (Throwable $e) {
            @unlink($dir . '/' . $file);
            flash($e->getMessage(), 'bad');
        }
        redirect($self . '#preview');
    }

    if ($action === 'apply' || $action === 'cancel') {
        $p = pending_pack($dir);
        if (!$p) { flash('That import has expired. Upload the pack again.', 'bad'); redirect($self); }
        if ($action === 'cancel') {
            @unlink($dir . '/' . $p['file']);
            unset($_SESSION['import']);
            flash('Import cancelled. Nothing was changed.');
            redirect($self);
        }
        $selected = array_map('intval', (array)($_POST['rows'] ?? []));
        if (!$selected) { flash('Tick at least one recipe to import.', 'bad'); redirect($self . '#preview'); }
        try {
            [$c, $u, $ph] = pack_apply(pack_read($dir . '/' . $p['file'], $p['file']), $selected);
            @unlink($dir . '/' . $p['file']);
            unset($_SESSION['import']);
            flash('Imported: ' . plural($c, 'new recipe') . ', ' . plural($u, 'recipe') . ' updated, ' . plural($ph, 'photo') . ' added.');
            redirect(base_url('admin/'));
        } catch (Throwable $e) {
            flash($e->getMessage(), 'bad');
            redirect($self . '#preview');
        }
    }
}

upd_tidy();
$pending = pending_pack($dir);
$plan = null;
$readError = null;
if ($pending) {
    try {
        $pack = pack_read($dir . '/' . $pending['file'], $pending['file']);
        $plan = pack_plan($pack);
        $plan['problems'] = $pack['problems'];
        $plan['notes'] = $pack['notes'];
        $plan['image_count'] = count($pack['images']);
    } catch (Throwable $e) {
        $readError = $e->getMessage();
        @unlink($dir . '/' . $pending['file']);
        unset($_SESSION['import']);
    }
}
$canZip = upd_can_zip();
$chunk = max(512 * 1024, min(8 * 1048576, min(ini_bytes('upload_max_filesize') ?: PHP_INT_MAX, ini_bytes('post_max_size') ?: PHP_INT_MAX) - 256 * 1024));

admin_head('Import and export', 'transfer');
?>
<div class="ahead"><h1>Import and export</h1></div>
<?php if ($readError): ?><div class="alert bad"><?= e($readError) ?></div><?php endif; ?>

<?php if ($plan): ?>
<form method="post" class="card" id="preview" aria-labelledby="pvH" data-batch-apply data-api="<?= e(base_url('admin/transfer-api.php')) ?>">
  <?= csrf_field() ?>
  <div class="card-h">
    <div>
      <h2 id="pvH">Check the import</h2>
      <p class="help">From <?= e($pending['name']) ?>: <?= plural(count($plan['rows']), 'recipe') ?>, <?= plural($plan['image_count'], 'photo') ?>. Nothing changes until you press Import.</p>
    </div>
  </div>
  <?php $nNew = count(array_filter($plan['rows'], fn($r) => $r['action'] === 'new')); $nWarn = count(array_filter($plan['rows'], fn($r) => $r['warnings'])); ?>
  <div class="facts" style="margin-bottom: 14px">
    <div><span>New recipes</span><strong><?= $nNew ?></strong></div>
    <div><span>Replace existing</span><strong><?= count($plan['rows']) - $nNew ?></strong></div>
    <div><span>Pictures matched</span><strong><?= (int)$plan['photos'] ?></strong></div>
    <?php if ($plan['photos_missing']): ?><div><span>Pictures missing</span><strong><?= (int)$plan['photos_missing'] ?></strong></div><?php endif; ?>
    <div><span>To check</span><strong><?= $nWarn ?></strong></div>
  </div>
  <?php foreach ($plan['notes'] as $nt): ?><div class="alert warn"><?= e($nt) ?></div><?php endforeach; ?>
  <?php if ($plan['new_brands']): ?><div class="alert ok">New brands will be added: <?= e(implode(', ', $plan['new_brands'])) ?>.</div><?php endif; ?>
  <?php foreach ($plan['problems'] as $p): ?><div class="alert bad"><?= e($p) ?></div><?php endforeach; ?>
  <div class="scroll-x">
  <table class="list">
    <thead><tr><th class="ck"><input type="checkbox" checked data-check-all="rows[]" aria-label="Select all"></th><th>Recipe</th><th>Brand</th><th>What happens</th><th>Contents</th><th>Check</th></tr></thead>
    <tbody>
    <?php foreach ($plan['rows'] as $n => $row): $r = $row['recipe']; ?>
      <tr>
        <td class="ck"><input type="checkbox" name="rows[]" value="<?= (int)$n ?>" checked aria-label="Import <?= e($r['name']) ?>"></td>
        <td><strong><?= e($r['name']) ?></strong><?php if ($r['code'] !== ''): ?><br><span class="code"><?= e($r['code']) ?></span><?php endif; ?></td>
        <td><?= e($r['brand'] ?: 'No brand') ?></td>
        <td><span class="pill <?= $row['action'] === 'update' ? 'warn' : '' ?>"><?= $row['action'] === 'update' ? 'Replaces existing' : 'New' ?></span><?php if (!$r['live']): ?> <span class="pill off">Hidden</span><?php endif; ?></td>
        <td class="small muted"><?= plural(count($r['ingredients']), 'ingredient') ?>, <?= plural(count($r['steps']), 'step') ?>,<br><?= (int)$row['photos'] ?> of <?= (int)$row['photos_wanted'] ?> photos found</td>
        <td class="small"><?php if ($row['warnings']): ?><ul class="warns"><?php foreach ($row['warnings'] as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul><?php else: ?><span class="ok-text"><?= icon('check') ?>Looks good</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="help small" style="margin-top: 12px">"Replaces existing" matches on brand and code (or name, when there is no code). Its ingredients and steps are replaced by the ones in the pack; photos not in the pack stay as they are.</p>
  <div class="progress" data-progress hidden><div class="bar"><i></i></div><p class="small" aria-live="polite"></p></div>
  <div class="btnrow">
    <button class="btn btn-go btn-big" name="action" value="apply"><?= icon('upload') ?>Import ticked recipes</button>
    <button class="btn btn-ghost" name="action" value="cancel" formnovalidate>Cancel</button>
  </div>
</form>
<?php endif; ?>

<div class="steps3">
  <section class="card" aria-labelledby="g1">
    <span class="stepno" aria-hidden="true">1</span>
    <div class="intro">
      <h2 id="g1">Get recipes from ChatGPT</h2>
      <p class="help">Download a brief with your brands, the allergen list, the photo style and the exact file format. Attach it to a new ChatGPT chat and say "Follow the attached brief". It sends back a recipe pack to import in step 2.</p>
      <p class="help small">To improve recipes you already have, export them in step 3, attach the brief and the export to ChatGPT, and ask it to improve them. Codes stay the same, so the import updates them instead of making copies.</p>
    </div>
    <form method="post" class="form tight">
      <?= csrf_field() ?><input type="hidden" name="action" value="brief">
      <div class="f"><label for="bb">Brand</label>
        <input type="text" id="bb" name="brand" list="brandlist" placeholder="e.g. Katsu &amp; Co (optional)">
        <datalist id="brandlist"><?php foreach ($brands as $b): ?><option value="<?= e($b['name']) ?>"><?php endforeach; ?></datalist>
      </div>
      <div class="f"><label for="menu">Menu</label><textarea id="menu" name="menu" rows="4" placeholder="Paste the dishes, one per line, with codes if you have them. Optional: you can paste it into ChatGPT instead."></textarea></div>
      <div class="checks"><label><input type="checkbox" name="photos" value="1" checked> Ask for photos too</label></div>
      <div><button class="btn btn-primary" type="submit"><?= icon('download') ?>Download the brief</button></div>
    </form>
  </section>

  <section class="card" aria-labelledby="g2">
    <span class="stepno" aria-hidden="true">2</span>
    <div class="intro">
      <h2 id="g2">Import a recipe pack</h2>
      <p class="help">A zip with <code>recipes.json</code> and its photos, or just <code>recipes.json</code>. You see every recipe and any problems before anything is saved.</p>
      <p class="help small"><strong>Moving from MustrHQ Station?</strong> Its export and its ChatGPT <code>mustrhq-pack.zip</code> import here as they are, with every recipe and picture. Big packs are fine: the file is sent in parts and imported a few recipes at a time.</p>
    </div>
    <?php if (!$canZip): ?><div class="alert bad">This server cannot open zip files, so only recipes.json can be imported. Ask your host to turn on the "zip" PHP extension.</div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="form tight" data-chunked data-api="<?= e(base_url('admin/transfer-api.php')) ?>" data-chunk="<?= (int)$chunk ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="upload">
      <div class="f"><label for="pack">Recipe pack</label><input class="in" type="file" id="pack" name="pack" accept=".zip,.json,application/zip,application/json" required></div>
      <div class="progress" data-progress hidden><div class="bar"><i></i></div><p class="small" aria-live="polite"></p></div>
      <div><button class="btn btn-primary" type="submit"><?= icon('upload') ?>Check the pack</button></div>
    </form>
  </section>

  <section class="card" aria-labelledby="g3">
    <span class="stepno" aria-hidden="true">3</span>
    <div class="intro">
      <h2 id="g3">Export recipes</h2>
      <p class="help">A recipe pack of your recipes and photos. Use it as a backup, to copy recipes to another kitchen, or to have ChatGPT improve them.</p>
    </div>
    <?php if (!$total): ?>
      <p class="muted">No recipes to export yet.</p>
    <?php elseif (!$canZip): ?>
      <div class="alert bad">This server cannot write zip files. Ask your host to turn on the "zip" PHP extension.</div>
    <?php else: ?>
    <form method="post" class="form tight">
      <?= csrf_field() ?><input type="hidden" name="action" value="export">
      <div class="f"><label for="eb">Which recipes</label>
        <select id="eb" name="brand_id">
          <option value="">All recipes (<?= $total ?>)</option>
          <?php foreach ($brands as $b): if (!$b['n']) continue; ?><option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?> (<?= (int)$b['n'] ?>)</option><?php endforeach; ?>
        </select>
      </div>
      <div><button class="btn btn-primary" type="submit"><?= icon('download') ?>Download recipe pack</button></div>
    </form>
    <?php endif; ?>
  </section>
</div>
<?php admin_foot();
