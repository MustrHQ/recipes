<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/ui.php';
require_viewer();

$brands = db()->query('SELECT b.*, (SELECT COUNT(*) FROM recipes r WHERE r.brand_id = b.id AND r.published = 1) AS n FROM brands b ORDER BY b.sort, b.name')->fetchAll();
$brandId = (int)($_GET['brand'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));

$sql = 'SELECT r.id, r.code, r.name, r.description, r.image, r.prep_minutes, r.cook_minutes, r.yield_text,
               b.name AS brand_name, b.colour,
               (SELECT COUNT(*) FROM steps s WHERE s.recipe_id = r.id) AS step_count
        FROM recipes r LEFT JOIN brands b ON b.id = r.brand_id
        WHERE r.published = 1';
$args = [];
if ($brandId) { $sql .= ' AND r.brand_id = ?'; $args[] = $brandId; }
if ($q !== '') { $sql .= ' AND (r.name LIKE ? OR r.code LIKE ? OR r.description LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%"); }
$sql .= ' ORDER BY b.sort, b.name, r.code, r.name';
$st = db()->prepare($sql);
$st->execute($args);
$recipes = $st->fetchAll();
$total = (int)db()->query('SELECT COUNT(*) FROM recipes WHERE published = 1')->fetchColumn();

page_head('Recipes');
kitchen_top();
?>
<main class="wrap" id="main">
  <form class="find" method="get" role="search">
    <?php if ($brandId): ?><input type="hidden" name="brand" value="<?= $brandId ?>"><?php endif; ?>
    <div class="search">
      <?= icon('search') ?>
      <label class="sr" for="q">Search recipes</label>
      <input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Search by dish name or code" autocomplete="off" data-live-search>
    </div>
  </form>

  <?php if ($brands): ?>
  <nav class="brands" aria-label="Brands">
    <a class="chip" href="<?= e(base_url($q !== '' ? '?q=' . rawurlencode($q) : '')) ?>" aria-current="<?= $brandId ? 'false' : 'true' ?>">All brands</a>
    <?php foreach ($brands as $b): ?>
      <a class="chip" style="--c: <?= e($b['colour']) ?>" href="?brand=<?= (int)$b['id'] ?><?= $q !== '' ? '&q=' . rawurlencode($q) : '' ?>" aria-current="<?= $brandId === (int)$b['id'] ? 'true' : 'false' ?>">
        <i></i><?= e($b['name']) ?>
      </a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>

  <?php if (!$total): ?>
    <div class="empty">
      <h2>No recipes yet</h2>
      <p>Recipes added in the admin panel appear here for the whole kitchen.</p>
      <?php if (is_admin()): ?><a class="btn btn-primary" href="<?= e(base_url('admin/recipe-edit.php')) ?>"><?= icon('plus') ?>Add the first recipe</a><?php endif; ?>
    </div>
  <?php else: ?>
    <p class="count" id="count" aria-live="polite"><?= count($recipes) ?> <?= count($recipes) === 1 ? 'recipe' : 'recipes' ?></p>
    <?php
      // Group by brand when showing everything, so a long menu is easy to scan.
      $groups = [];
      foreach ($recipes as $r) $groups[$brandId ? '' : (string)$r['brand_name']][] = $r;
      $showHeads = !$brandId && count($groups) > 1;
    ?>
    <div id="grid">
      <?php foreach ($groups as $gName => $items): ?>
      <section class="group" data-group>
        <?php if ($showHeads): ?><h2 class="group-h" style="--c: <?= e($items[0]['colour'] ?: '#01216C') ?>"><?= e($gName !== '' ? $gName : 'Other recipes') ?> <span><?= count($items) ?></span></h2><?php endif; ?>
        <div class="grid">
          <?php foreach ($items as $r): ?>
            <a class="tile" style="--c: <?= e($r['colour'] ?: '#01216C') ?>" href="recipe.php?id=<?= (int)$r['id'] ?>"
               data-search="<?= e(mb_strtolower($r['name'] . ' ' . $r['code'] . ' ' . $r['brand_name'])) ?>">
              <div class="ph"><?= pic($r['image'], $r['name'], 'Photo to come', true) ?></div>
              <div class="body">
                <span class="code"><?= e($r['code']) ?><?php if (!$showHeads && !$brandId && $r['brand_name']): ?><span class="br"><?= e($r['brand_name']) ?></span><?php endif; ?></span>
                <h3><?= e($r['name']) ?></h3>
                <div class="meta">
                  <?php $mins = (int)$r['prep_minutes'] + (int)$r['cook_minutes']; if ($mins): ?><span><?= e(fmt_minutes($mins)) ?></span><?php endif; ?>
                  <span><?= e(plural((int)$r['step_count'], 'step')) ?></span>
                  <?php if ($r['yield_text']): ?><span>Makes <?= e($r['yield_text']) ?></span><?php endif; ?>
                </div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endforeach; ?>
    </div>
    <div class="empty" id="noMatch" <?= $recipes ? 'hidden' : '' ?>>
      <h2>No recipe matches that search</h2>
      <p>Try the dish code from the ticket, or pick "All brands".</p>
    </div>
  <?php endif; ?>
</main>
<?php lightbox_markup(); page_foot();
