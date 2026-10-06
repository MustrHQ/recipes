<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/ui.php';
require_viewer();

$r = load_recipe((int)($_GET['id'] ?? 0), !is_admin());
if (!$r) not_found('This recipe is not available', 'It may have been removed or hidden. Pick another one from the list.');

$allergens = array_intersect_key(ALLERGENS, array_flip(csv_list($r['allergens'])));
$diets = array_intersect_key(DIETS, array_flip(csv_list($r['diets'])));
$total = (int)$r['prep_minutes'] + (int)$r['cook_minutes'];
$st = db()->prepare('SELECT id FROM label_items WHERE recipe_id = ? AND active = 1 ORDER BY id LIMIT 1');
$st->execute([(int)$r['id']]);
$labelId = (int)$st->fetchColumn();

page_head($r['name']);
kitchen_top();
?>
<main class="wrap" id="main" style="--c: <?= e($r['brand_colour'] ?: '#01216C') ?>">
  <a class="back" href="<?= e(base_url('recipes.php' . ($r['brand_id'] ? '?brand=' . (int)$r['brand_id'] : ''))) ?>"><?= icon('back') ?>All recipes</a>
  <?php if (!$r['published']): ?><div class="alert bad">Hidden from the kitchen. Only admins can see this recipe.</div><?php endif; ?>

  <section class="rhead">
    <button class="hero-img" type="button" data-lb="<?= e(img_url($r['image'])) ?>" data-cap="<?= e($r['name']) ?> (finished)" <?= $r['image'] ? '' : 'disabled' ?> aria-label="Enlarge photo of the finished dish">
      <?= pic($r['image'], $r['name'] . ', finished', 'Finished dish photo to come') ?>
    </button>

    <div class="rtitle">
      <span class="code"><?= e($r['code']) ?><?php if ($r['brand_name']): ?><span class="br"><?= e($r['brand_name']) ?></span><?php endif; ?></span>
      <h1><?= e($r['name']) ?></h1>
      <?php if ($r['description']): ?><p class="desc"><?= e($r['description']) ?></p><?php endif; ?>

      <div class="facts">
        <?php if ($r['yield_text']): ?><div><span>Makes</span><strong><?= e($r['yield_text']) ?></strong></div><?php endif; ?>
        <?php if ($r['prep_minutes']): ?><div><span>Prep</span><strong><?= e(fmt_minutes((int)$r['prep_minutes'])) ?></strong></div><?php endif; ?>
        <?php if ($r['cook_minutes']): ?><div><span>Cook</span><strong><?= e(fmt_minutes((int)$r['cook_minutes'])) ?></strong></div><?php endif; ?>
        <?php if ($total): ?><div><span>Total</span><strong><?= e(fmt_minutes($total)) ?></strong></div><?php endif; ?>
        <div><span>Steps</span><strong><?= count($r['steps']) ?></strong></div>
      </div>

      <?php if ($allergens): ?>
        <div class="tags-label">Contains</div>
        <div class="tags"><?php foreach ($allergens as $a): ?><span class="tag al"><?= e($a) ?></span><?php endforeach; ?></div>
      <?php else: ?>
        <div class="tags-label">No allergens listed. Check with your manager before serving anyone with an allergy.</div>
      <?php endif; ?>
      <?php if ($diets): ?>
        <div class="tags"><?php foreach ($diets as $d): ?><span class="tag di"><?= e($d) ?></span><?php endforeach; ?></div>
      <?php endif; ?>
      <?php if ($r['notes']): ?><div class="note"><?= nl2br(e($r['notes'])) ?></div><?php endif; ?>

      <div class="start-row">
        <?php if ($r['steps']): ?>
          <a class="btn btn-go btn-big" href="cook.php?id=<?= (int)$r['id'] ?>"><?= icon('play') ?>Start step by step</a>
        <?php endif; ?>
        <?php if ($labelId): ?><a class="btn btn-big" href="labels.php?item=<?= $labelId ?>"><?= icon('printer') ?>Print label</a><?php endif; ?>
        <button class="btn btn-big btn-ghost" type="button" data-print><?= icon('print') ?>Print recipe</button>
        <?php if (is_admin()): ?><a class="btn btn-big btn-ghost" href="<?= e(base_url('admin/recipe-edit.php?id=' . (int)$r['id'])) ?>">Edit</a><?php endif; ?>
      </div>
    </div>
  </section>

  <div class="cols">
    <section class="panel" aria-labelledby="needH">
      <div class="panel-h">
        <h2 id="needH">What you need</h2>
        <?php if ($r['ingredients']): ?>
        <div class="scale" role="group" aria-label="Batch size" data-scale>
          <button type="button" data-x="0.5" aria-pressed="false">½×</button>
          <button type="button" data-x="1" aria-pressed="true">1×</button>
          <button type="button" data-x="2" aria-pressed="false">2×</button>
          <button type="button" data-x="3" aria-pressed="false">3×</button>
          <button type="button" data-x="4" aria-pressed="false">4×</button>
        </div>
        <?php endif; ?>
      </div>
      <?php if ($r['ingredients']): ?>
      <ul class="ings" data-ings>
        <?php foreach ($r['ingredients'] as $i): ?>
          <li class="ing">
            <button class="thumb" type="button" data-lb="<?= e(img_url($i['image'])) ?>" data-cap="<?= e($i['name']) ?>" <?= $i['image'] ? '' : 'disabled' ?> aria-label="Enlarge photo of <?= e($i['name']) ?>">
              <?= pic($i['image'], $i['name'], '', true) ?>
            </button>
            <label>
              <input type="checkbox" class="sr" data-got>
              <div class="nm"><?= e($i['name']) ?></div>
              <?php if ($i['note']): ?><div class="nt"><?= e($i['note']) ?></div><?php endif; ?>
            </label>
            <div class="q"><span data-qty="<?= e($i['qty']) ?>"><?= e($i['qty']) ?></span><small><?= e($i['unit']) ?></small></div>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
        <p style="padding: 16px 18px; margin: 0; color: var(--muted)">No ingredients listed.</p>
      <?php endif; ?>
    </section>

    <section class="panel" aria-labelledby="methodH">
      <div class="panel-h"><h2 id="methodH">Method</h2></div>
      <?php if ($r['steps']): ?>
      <ol class="method">
        <?php foreach ($r['steps'] as $n => $s): ?>
          <li class="mstep">
            <div class="mnum" aria-hidden="true"><?= $n + 1 ?></div>
            <div>
              <?php if ($s['title']): ?><h3><span class="sr">Step <?= $n + 1 ?>: </span><?= e($s['title']) ?></h3><?php endif; ?>
              <p><?= nl2br(e($s['body'])) ?></p>
              <?php if ($s['timer_seconds']): ?><div class="tm"><?= icon('clock') ?><?= e(fmt_timer((int)$s['timer_seconds'])) ?> timer</div><?php endif; ?>
              <?php if ($s['tip']): ?><div class="tip"><?= icon('tip') ?><span><?= e($s['tip']) ?></span></div><?php endif; ?>
            </div>
            <?php if ($s['image']): ?>
              <button class="simg" type="button" data-lb="<?= e(img_url($s['image'])) ?>" data-cap="Step <?= $n + 1 ?><?= $s['title'] ? ': ' . e($s['title']) : '' ?>" aria-label="Enlarge photo for step <?= $n + 1 ?>">
                <?= pic($s['image'], 'Step ' . ($n + 1), 'Photo to come', true) ?>
              </button>
            <?php else: ?><div></div><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ol>
      <?php else: ?>
        <p style="padding: 16px 18px; margin: 0; color: var(--muted)">No steps written yet.</p>
      <?php endif; ?>
    </section>
  </div>
</main>
<?php lightbox_markup(); page_foot();
