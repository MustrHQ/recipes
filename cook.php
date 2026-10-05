<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/ui.php';
require_viewer();

$r = load_recipe((int)($_GET['id'] ?? 0), !is_admin());
if (!$r || !$r['steps']) redirect(base_url($r ? 'recipe.php?id=' . (int)$r['id'] : ''));
$n = count($r['steps']);
$allergens = array_intersect_key(ALLERGENS, array_flip(csv_list($r['allergens'])));
$st = db()->prepare('SELECT id FROM label_items WHERE recipe_id = ? AND active = 1 ORDER BY id LIMIT 1');
$st->execute([(int)$r['id']]);
$labelId = (int)$st->fetchColumn();

page_head($r['name'] . ' step by step', 'cook');
?>
<div class="cookapp" id="main" data-cook data-total="<?= $n ?>">
  <header class="cbar">
    <a class="btn" href="recipe.php?id=<?= (int)$r['id'] ?>" aria-label="Leave step by step"><?= icon('close') ?></a>
    <div class="ttl">
      <strong><?= e($r['name']) ?></strong>
      <span class="sub"><?php foreach (array_filter([$r['code'], $r['brand_name'], $allergens ? 'Contains ' . implode(', ', $allergens) : '']) as $bit): ?><span><?= e($bit) ?></span><?php endforeach; ?></span>
    </div>
    <button class="btn" type="button" data-open-drawer><?= icon('list') ?><span>What you need</span></button>
  </header>

  <nav class="segs" aria-label="Steps">
    <?php for ($i = 0; $i < $n; $i++): ?>
      <button type="button" data-go="<?= $i ?>" aria-label="Go to step <?= $i + 1 ?>"></button>
    <?php endfor; ?>
  </nav>

  <main class="stage" id="stage">
    <?php foreach ($r['steps'] as $i => $s): ?>
      <section class="slide" data-slide="<?= $i ?>" aria-label="Step <?= $i + 1 ?> of <?= $n ?>">
        <div class="rail" aria-hidden="true"><span class="n"><?= $i + 1 ?></span><span class="of">of <?= $n ?></span></div>
        <button class="cimg" type="button" <?= $s['image'] ? 'data-lb="' . e(img_url($s['image'])) . '"' : 'disabled' ?> data-cap="Step <?= $i + 1 ?><?= $s['title'] ? ': ' . e($s['title']) : '' ?>" aria-label="Enlarge photo for step <?= $i + 1 ?>">
          <?= pic($s['image'], 'Step ' . ($i + 1), 'No photo for this step') ?>
        </button>
        <div class="ctext">
          <h2><?= e($s['title'] ?: 'Step ' . ($i + 1)) ?></h2>
          <p class="body"><?= nl2br(e($s['body'])) ?></p>
          <?php if ($s['timer_seconds']): ?>
            <div class="timer" data-timer="<?= (int)$s['timer_seconds'] ?>">
              <span class="clock" aria-live="off"><?= e(fmt_timer((int)$s['timer_seconds'])) ?></span>
              <button class="btn" type="button" data-t-start><?= icon('play') ?><span>Start timer</span></button>
              <button class="btn" type="button" data-t-reset><?= icon('restart') ?><span class="sr">Reset timer</span></button>
            </div>
          <?php endif; ?>
          <?php if ($s['tip']): ?><div class="tip"><?= e($s['tip']) ?></div><?php endif; ?>
        </div>
      </section>
    <?php endforeach; ?>

    <section class="finish" data-finish aria-label="Finished">
      <div>
        <?php if ($r['image']): ?>
          <button class="fimg" type="button" data-lb="<?= e(img_url($r['image'])) ?>" data-cap="<?= e($r['name']) ?> (finished)" aria-label="Enlarge photo of the finished dish">
            <?= pic($r['image'], $r['name'] . ', finished') ?>
          </button>
        <?php endif; ?>
        <h2>Check it matches the photo</h2>
        <p style="font-size: 19px; opacity: .8; margin: 0 0 22px">That's every step for <?= e($r['name']) ?>.</p>
        <div class="frow">
          <button class="btn btn-big" type="button" data-go="0"><?= icon('restart') ?>Start again</button>
          <?php if ($labelId): ?><a class="btn btn-big" href="labels.php?item=<?= $labelId ?>"><?= icon('printer') ?>Print label</a><?php endif; ?>
          <a class="btn btn-big btn-go" href="<?= e(base_url($r['brand_id'] ? '?brand=' . (int)$r['brand_id'] : '')) ?>"><?= icon('check') ?>Done, back to recipes</a>
        </div>
      </div>
    </section>
  </main>

  <footer class="cnav">
    <button class="btn btn-big prev" type="button" data-prev><?= icon('back') ?>Back</button>
    <button class="btn btn-big btn-go" type="button" data-next><span data-next-label>Next step</span><?= icon('next') ?></button>
  </footer>
</div>

<div class="drawer" id="drawer" aria-hidden="true">
  <div class="scrim" data-close-drawer></div>
  <aside class="sheet" role="dialog" aria-modal="true" aria-labelledby="drawerH">
    <div class="panel-h">
      <h2 id="drawerH">What you need</h2>
      <button class="btn" type="button" data-close-drawer><?= icon('close') ?>Close</button>
    </div>
    <ul class="ings">
      <?php foreach ($r['ingredients'] as $i): ?>
        <li class="ing">
          <button class="thumb" type="button" <?= $i['image'] ? 'data-lb="' . e(img_url($i['image'])) . '"' : 'disabled' ?> data-cap="<?= e($i['name']) ?>" aria-label="Enlarge photo of <?= e($i['name']) ?>">
            <?= pic($i['image'], $i['name'], '', true) ?>
          </button>
          <label><input type="checkbox" class="sr" data-got><div class="nm"><?= e($i['name']) ?></div><?php if ($i['note']): ?><div class="nt"><?= e($i['note']) ?></div><?php endif; ?></label>
          <div class="q"><?= e($i['qty']) ?><small><?= e($i['unit']) ?></small></div>
        </li>
      <?php endforeach; ?>
      <?php if (!$r['ingredients']): ?><li style="padding: 16px 18px; color: var(--muted)">No ingredients listed.</li><?php endif; ?>
    </ul>
  </aside>
</div>
<?php lightbox_markup(); page_foot();
