<?php
/** The start screen: staff choose Recipes or Labels. Managers sign in to the admin from here. */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/ui.php';
require_viewer();

// Old links and bookmarks to the library (/?brand=3, /?q=katsu) still work.
if (isset($_GET['brand']) || isset($_GET['q'])) {
    redirect(base_url('recipes.php?' . http_build_query(array_intersect_key($_GET, ['brand' => 1, 'q' => 1]))));
}

$nRecipes = (int)db()->query('SELECT COUNT(*) FROM recipes WHERE published = 1')->fetchColumn();
$nBrands = (int)db()->query('SELECT COUNT(DISTINCT brand_id) FROM recipes WHERE published = 1 AND brand_id IS NOT NULL')->fetchColumn();
$photos = db()->query('SELECT image, name FROM recipes WHERE published = 1 AND image IS NOT NULL ORDER BY updated_at DESC LIMIT 3')->fetchAll();
$nLabels = (int)db()->query('SELECT COUNT(*) FROM label_items WHERE active = 1')->fetchColumn();
$kinds = db()->query('SELECT kind, COUNT(*) AS n FROM label_items WHERE active = 1 GROUP BY kind')->fetchAll(PDO::FETCH_KEY_PAIR);

page_head('Start', 'start-page');
?>
<main class="start" id="main">
  <header class="start-h">
    <span class="mark-box big"><?= mark_svg() ?></span>
    <div>
      <h1><?= e(kitchen_name()) ?></h1>
      <p class="today"><time data-clock><?= e(date('l j F, H:i')) ?></time></p>
    </div>
  </header>

  <h2 class="start-q">What do you need?</h2>

  <div class="choices">
    <a class="choice choice-recipes" href="<?= e(base_url('recipes.php')) ?>">
      <div class="choice-art" aria-hidden="true">
        <?php if ($photos): ?>
          <?php foreach ($photos as $p): ?><img src="<?= e(thumb_url($p['image'])) ?>" alt="" loading="lazy" decoding="async"><?php endforeach; ?>
        <?php else: ?>
          <span class="choice-icon"><?= icon('book') ?></span>
        <?php endif; ?>
      </div>
      <div class="choice-body">
        <h3>Recipes</h3>
        <p>What you need, photos and step by step for every dish.</p>
        <span class="choice-meta"><?= e(plural($nRecipes, 'recipe')) ?><?= $nBrands > 1 ? ' · ' . e(plural($nBrands, 'brand')) : '' ?></span>
      </div>
      <span class="choice-go"><?= icon('arrow') ?></span>
    </a>

    <a class="choice choice-labels" href="<?= e(base_url('labels.php')) ?>">
      <div class="choice-art" aria-hidden="true">
        <div class="mini-label">
          <b><?= e(mb_substr(kitchen_name(), 0, 22)) ?></b>
          <span>Made <?= e(date('D d/m H:i')) ?></span>
          <strong>USE BY <em><?= e(date('D d/m', strtotime('+2 days'))) ?></em></strong>
        </div>
      </div>
      <div class="choice-body">
        <h3>Labels</h3>
        <p>Print use-by labels for anything made, opened or defrosted.</p>
        <span class="choice-meta">
          <?php if ($nLabels): ?>
            <?= e(plural($nLabels, 'item')) ?><?php foreach (LABEL_KINDS as $k => $l) if (!empty($kinds[$k]) && $k !== 'made') echo ' · ' . (int)$kinds[$k] . ' ' . e(strtolower($l)); ?>
          <?php else: ?>
            No label items yet: a manager adds them in the admin
          <?php endif; ?>
        </span>
      </div>
      <span class="choice-go"><?= icon('arrow') ?></span>
    </a>
  </div>

  <footer class="start-f">
    <button class="btn install-btn" type="button" data-install hidden><?= icon('download') ?><span>Install app on this tablet</span></button>
    <?= admin_link('btn btn-ghost admin-link') ?>
  </footer>
</main>
<?php page_foot();
