<?php
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/ui.php';
require __DIR__ . '/../inc/admin.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $act = $_POST['action'] ?? '';
    $colour = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_POST['colour'] ?? '')) ? $_POST['colour'] : '#01216C';
    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80);
    $sort = (int)($_POST['sort'] ?? 0);
    if ($act === 'add' && $name !== '') {
        db()->prepare('INSERT INTO brands (name, colour, sort) VALUES (?,?,?)')->execute([$name, $colour, $sort]);
        flash('Added ' . $name . '.');
    } elseif ($act === 'save' && $name !== '') {
        db()->prepare('UPDATE brands SET name=?, colour=?, sort=? WHERE id=?')->execute([$name, $colour, $sort, (int)$_POST['id']]);
        flash('Saved ' . $name . '.');
    } elseif ($act === 'delete') {
        db()->prepare('DELETE FROM brands WHERE id=?')->execute([(int)$_POST['id']]);
        flash('Brand deleted. Its recipes are kept with no brand.');
    } else {
        flash('Enter a brand name.', 'bad');
    }
    redirect(base_url('admin/brands.php'));
}

$brands = db()->query('SELECT b.*, (SELECT COUNT(*) FROM recipes r WHERE r.brand_id = b.id) AS n FROM brands b ORDER BY b.sort, b.name')->fetchAll();
admin_head('Brands', 'brands');
?>
<div class="ahead"><h1>Brands</h1></div>
<p style="margin: -10px 0 18px; color: var(--muted); max-width: 64ch">Brands group recipes on the kitchen screens. The colour marks each brand's recipes so the team can spot them at a glance. Lower order numbers show first.</p>

<div class="form">
  <?php foreach ($brands as $b): ?>
  <form method="post" class="card" style="padding: 14px 16px">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
    <div class="fgrid" style="align-items: end">
      <div class="f s3"><label for="n<?= (int)$b['id'] ?>">Name</label><input type="text" id="n<?= (int)$b['id'] ?>" name="name" value="<?= e($b['name']) ?>" required></div>
      <div class="f s1"><label for="c<?= (int)$b['id'] ?>">Colour</label><input type="color" id="c<?= (int)$b['id'] ?>" name="colour" value="<?= e($b['colour']) ?>" style="height: 44px; width: 100%; border: 1.5px solid var(--line); border-radius: 6px; padding: 2px"></div>
      <div class="f s1"><label for="s<?= (int)$b['id'] ?>">Order</label><input type="number" id="s<?= (int)$b['id'] ?>" name="sort" value="<?= (int)$b['sort'] ?>"></div>
      <div class="f s1" style="flex-direction: row; gap: 6px">
        <button class="btn btn-primary" name="action" value="save">Save</button>
        <button class="btn btn-ghost btn-danger" name="action" value="delete" data-confirm="Delete this brand? Its <?= (int)$b['n'] ?> recipes are kept, with no brand." aria-label="Delete <?= e($b['name']) ?>"><?= icon('trash') ?></button>
      </div>
    </div>
    <small style="color: var(--muted)"><?= (int)$b['n'] ?> <?= (int)$b['n'] === 1 ? 'recipe' : 'recipes' ?></small>
  </form>
  <?php endforeach; ?>

  <form method="post" class="card">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <h2>Add a brand</h2>
    <div class="fgrid" style="align-items: end; margin-top: 10px">
      <div class="f s3"><label for="nn">Name</label><input type="text" id="nn" name="name" required placeholder="e.g. Katsu & Co"></div>
      <div class="f s1"><label for="nc">Colour</label><input type="color" id="nc" name="colour" value="#01216C" style="height: 44px; width: 100%; border: 1.5px solid var(--line); border-radius: 6px; padding: 2px"></div>
      <div class="f s1"><label for="ns">Order</label><input type="number" id="ns" name="sort" value="<?= count($brands) + 1 ?>"></div>
      <div class="f s1"><button class="btn btn-primary" type="submit"><?= icon('plus') ?>Add</button></div>
    </div>
  </form>
</div>
<?php admin_foot();
