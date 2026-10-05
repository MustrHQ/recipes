<?php
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/ui.php';
require __DIR__ . '/../inc/admin.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $act = $_POST['action'] ?? '';
    if ($act === 'toggle') {
        db()->prepare('UPDATE recipes SET published = 1 - published WHERE id = ?')->execute([$id]);
        flash('Recipe visibility changed.');
    } elseif ($act === 'delete') {
        $r = load_recipe($id, false);
        if ($r) {
            db()->prepare('DELETE FROM recipes WHERE id = ?')->execute([$id]);
            delete_upload($r['image']);
            foreach ($r['ingredients'] as $i) delete_upload($i['image']);
            foreach ($r['steps'] as $s) delete_upload($s['image']);
            flash('Deleted "' . $r['name'] . '".');
        }
    } elseif ($act === 'duplicate') {
        $r = load_recipe($id, false);
        if ($r) {
            $pdo = db();
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO recipes (brand_id, code, name, description, yield_text, prep_minutes, cook_minutes, allergens, diets, image, notes, published) VALUES (?,?,?,?,?,?,?,?,?,?,?,0)')
                ->execute([$r['brand_id'], $r['code'], $r['name'] . ' (copy)', $r['description'], $r['yield_text'], $r['prep_minutes'], $r['cook_minutes'], $r['allergens'], $r['diets'], copy_upload($r['image']), $r['notes']]);
            $nid = (int)$pdo->lastInsertId();
            $ii = $pdo->prepare('INSERT INTO ingredients (recipe_id, sort, name, qty, unit, note, image) VALUES (?,?,?,?,?,?,?)');
            foreach ($r['ingredients'] as $i) $ii->execute([$nid, $i['sort'], $i['name'], $i['qty'], $i['unit'], $i['note'], copy_upload($i['image'])]);
            $si = $pdo->prepare('INSERT INTO steps (recipe_id, sort, title, body, tip, timer_seconds, image) VALUES (?,?,?,?,?,?,?)');
            foreach ($r['steps'] as $s) $si->execute([$nid, $s['sort'], $s['title'], $s['body'], $s['tip'], $s['timer_seconds'], copy_upload($s['image'])]);
            $pdo->commit();
            flash('Copied. The copy is hidden until you publish it.');
            redirect(base_url('admin/recipe-edit.php?id=' . $nid));
        }
    }
    redirect(base_url('admin/') . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
}

/** Copy a picture so a duplicated recipe owns its own files. */
function copy_upload(?string $f): ?string
{
    if (!$f || !is_file(UPLOAD_DIR . '/' . $f)) return null;
    $n = date('Ym') . '-' . bin2hex(random_bytes(8)) . '.' . pathinfo($f, PATHINFO_EXTENSION);
    return @copy(UPLOAD_DIR . '/' . $f, UPLOAD_DIR . '/' . $n) ? $n : null;
}

$brands = db()->query('SELECT * FROM brands ORDER BY sort, name')->fetchAll();
$brandId = (int)($_GET['brand'] ?? 0);
$show = in_array($_GET['show'] ?? '', ['hidden', 'photos'], true) ? $_GET['show'] : '';
$q = trim((string)($_GET['q'] ?? ''));

$sql = 'SELECT r.*, b.name AS brand_name, b.colour,
          (SELECT COUNT(*) FROM steps s WHERE s.recipe_id = r.id) AS n_steps,
          (SELECT COUNT(*) FROM ingredients i WHERE i.recipe_id = r.id) AS n_ing,
          ((r.image IS NOT NULL) + (SELECT COUNT(*) FROM steps s WHERE s.recipe_id = r.id AND s.image IS NOT NULL) + (SELECT COUNT(*) FROM ingredients i WHERE i.recipe_id = r.id AND i.image IS NOT NULL)) AS n_pics
        FROM recipes r LEFT JOIN brands b ON b.id = r.brand_id WHERE 1 = 1';
$args = [];
if ($brandId) { $sql .= ' AND r.brand_id = ?'; $args[] = $brandId; }
if ($show === 'hidden') $sql .= ' AND r.published = 0';
if ($show === 'photos') $sql .= ' AND r.image IS NULL';
if ($q !== '') { $sql .= ' AND (r.name LIKE ? OR r.code LIKE ?)'; array_push($args, "%$q%", "%$q%"); }
$sql .= ' ORDER BY b.sort, b.name, r.code, r.name';
$st = db()->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

$stats = db()->query('SELECT COUNT(*) AS total, COALESCE(SUM(published = 1), 0) AS live, COALESCE(SUM(published = 0), 0) AS hidden, COALESCE(SUM(image IS NULL), 0) AS nophoto FROM recipes')->fetch();
$qs = fn(array $over) => '?' . http_build_query(array_filter(array_merge(['brand' => $brandId ?: null, 'show' => $show ?: null, 'q' => $q !== '' ? $q : null], $over), fn($v) => $v !== null && $v !== ''));

admin_head('Recipes', 'recipes');
?>
<div class="ahead">
  <h1>Recipes</h1>
  <a class="btn btn-primary" href="recipe-edit.php<?= $brandId ? '?brand=' . $brandId : '' ?>"><?= icon('plus') ?>Add recipe</a>
</div>
<div class="stat-row">
  <a class="stat" href="./"><span>Recipes</span><strong><?= (int)$stats['total'] ?></strong></a>
  <a class="stat" href="<?= e($qs(['show' => null, 'brand' => null])) ?>"><span>Live in the kitchen</span><strong><?= (int)$stats['live'] ?></strong></a>
  <a class="stat<?= $stats['hidden'] ? ' attn' : '' ?>" href="?show=hidden"><span>Hidden</span><strong><?= (int)$stats['hidden'] ?></strong></a>
  <a class="stat<?= $stats['nophoto'] ? ' attn' : '' ?>" href="?show=photos"><span>No finished photo</span><strong><?= (int)$stats['nophoto'] ?></strong></a>
</div>

<form class="find" method="get" role="search">
  <?php if ($brandId): ?><input type="hidden" name="brand" value="<?= $brandId ?>"><?php endif; ?>
  <?php if ($show): ?><input type="hidden" name="show" value="<?= e($show) ?>"><?php endif; ?>
  <div class="search">
    <?= icon('search') ?>
    <label class="sr" for="aq">Search recipes</label>
    <input id="aq" name="q" type="search" value="<?= e($q) ?>" placeholder="Search by name or code" data-live-search>
  </div>
</form>

<?php if ($brands || $show): ?>
<nav class="brands" aria-label="Filter recipes">
  <a class="chip" href="<?= e($qs(['brand' => null])) ?>" aria-current="<?= $brandId ? 'false' : 'true' ?>">All brands</a>
  <?php foreach ($brands as $b): ?>
    <a class="chip" style="--c: <?= e($b['colour']) ?>" href="<?= e($qs(['brand' => (int)$b['id']])) ?>" aria-current="<?= $brandId === (int)$b['id'] ? 'true' : 'false' ?>"><i></i><?= e($b['name']) ?></a>
  <?php endforeach; ?>
  <?php if ($show): ?><a class="chip" href="<?= e($qs(['show' => null])) ?>" aria-current="true"><?= $show === 'hidden' ? 'Hidden only' : 'No finished photo' ?> <?= icon('close') ?></a><?php endif; ?>
</nav>
<?php endif; ?>

<?php if (!$rows): ?>
  <?php if ($stats['total']): ?>
  <div class="empty"><h2>Nothing matches</h2><p>No recipe fits these filters.</p><a class="btn" href="./">Show all recipes</a></div>
  <?php else: ?>
  <div class="empty"><h2>Add your first recipe</h2><p>Write it by hand, or get a whole menu from ChatGPT and import it in one go.</p>
    <div class="btnrow" style="justify-content: center"><a class="btn btn-primary" href="recipe-edit.php"><?= icon('plus') ?>Add recipe</a><a class="btn" href="transfer.php"><?= icon('upload') ?>Import a recipe pack</a></div></div>
  <?php endif; ?>
<?php else: ?>
<div class="scroll-x">
<table class="list" id="grid">
  <thead><tr><th class="th"><span class="sr">Photo</span></th><th>Recipe</th><th>Brand</th><th>Contents</th><th>Status</th><th><span class="sr">Actions</span></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr class="tile-row" data-search="<?= e(mb_strtolower($r['name'] . ' ' . $r['code'])) ?>">
      <td class="th"><?= pic($r['image'], '', '') ?></td>
      <td><strong><?= e($r['name']) ?></strong><?php if ($r['code']): ?><br><span class="code" style="--c: <?= e($r['colour'] ?: '#01216C') ?>"><?= e($r['code']) ?></span><?php endif; ?></td>
      <td><?= e($r['brand_name'] ?? 'No brand') ?></td>
      <td class="small muted"><?= plural((int)$r['n_ing'], 'ingredient') ?>, <?= plural((int)$r['n_steps'], 'step') ?>,<br><?= plural((int)$r['n_pics'], 'photo') ?></td>
      <td><span class="pill <?= $r['published'] ? 'ok' : 'off' ?>"><?= $r['published'] ? 'Live' : 'Hidden' ?></span></td>
      <td class="acts">
        <a class="btn" href="recipe-edit.php?id=<?= (int)$r['id'] ?>">Edit</a>
        <a class="btn btn-ghost" href="<?= e(base_url('recipe.php?id=' . (int)$r['id'])) ?>" target="_blank" rel="noopener">View</a>
        <form method="post" style="display: inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn btn-ghost" name="action" value="toggle"><?= $r['published'] ? 'Hide' : 'Publish' ?></button>
          <button class="btn btn-ghost" name="action" value="duplicate">Copy</button>
          <button class="btn btn-ghost btn-danger" name="action" value="delete" data-confirm="Delete this recipe and its photos? This cannot be undone.">Delete</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php admin_foot();
