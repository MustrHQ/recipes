<?php
/** Use-by labels: pick an item, say when it was made or opened, check the label, print. */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/ui.php';
require_viewer();

$items = db()->query(
    "SELECT l.*, b.name AS brand_name, b.colour, r.allergens AS recipe_allergens, r.image AS recipe_image
     FROM label_items l
     LEFT JOIN brands b ON b.id = l.brand_id
     LEFT JOIN recipes r ON r.id = l.recipe_id
     WHERE l.active = 1
     ORDER BY b.sort, b.name, l.sort, l.name"
)->fetchAll();
$brands = [];
foreach ($items as $i) if ($i['brand_id']) $brands[(int)$i['brand_id']] = ['name' => $i['brand_name'], 'colour' => $i['colour']];
$cfg = label_config();

// Everything the label screen needs, handed to labels.js as data (not as a script: the CSP forbids inline scripts).
$data = [
    'kitchen' => kitchen_name(),
    'cfg' => $cfg,
    'open' => (int)($_GET['item'] ?? 0),
    'items' => array_map(fn($i) => [
        'id' => (int)$i['id'],
        'name' => $i['name'],
        'brand' => $i['brand_name'] ?: kitchen_name(),
        'colour' => $i['colour'] ?: '#01216C',
        'kind' => $i['kind'],
        'kindLabel' => LABEL_KINDS[$i['kind']] ?? 'Made',
        'value' => (int)$i['shelf_value'],
        'unit' => $i['shelf_unit'],
        'storage' => $i['storage'],
        'supplier' => (bool)$i['ask_supplier'],
        'photo' => ($i['image'] ?: $i['recipe_image']) ? thumb_url($i['image'] ?: $i['recipe_image']) : '',
        'allergens' => array_values(array_map(fn($k) => ALLERGENS[$k] ?? $k, csv_list($i['allergens'] !== '' ? $i['allergens'] : (string)$i['recipe_allergens']))),
    ], $items),
];

$printerChip = '<button class="btn printer-chip" type="button" data-printer-open><span class="dot" aria-hidden="true"></span>' . icon('printer') . '<span data-printer-name>Printer</span></button>';

page_head('Labels', 'labels-page');
kitchen_top('labels', $printerChip);
?>
<main class="wrap" id="main">
  <?php if (!$items): ?>
    <div class="empty">
      <h2>No label items yet</h2>
      <p>A manager adds the things your team labels, each with its shelf life. After that, anyone can print labels here without signing in.</p>
      <?php if (is_admin()): ?><a class="btn btn-primary" href="<?= e(base_url('admin/labels.php')) ?>"><?= icon('plus') ?>Set up labels</a>
      <?php else: ?><a class="btn" href="<?= e(base_url('admin/login.php')) ?>"><?= icon('lock') ?>Manager? Sign in to set them up</a><?php endif; ?>
    </div>
  <?php else: ?>
  <div class="find">
    <div class="search">
      <?= icon('search') ?>
      <label class="sr" for="lq">Search label items</label>
      <input id="lq" type="search" placeholder="Search items" autocomplete="off" data-label-search>
    </div>
  </div>
  <?php if (count($brands) > 1): ?>
  <nav class="brands" aria-label="Filter by brand" data-brand-filter>
    <button class="chip" type="button" data-brand="" aria-current="true">All</button>
    <?php foreach ($brands as $id => $b): ?>
      <button class="chip" type="button" style="--c: <?= e($b['colour']) ?>" data-brand="<?= $id ?>" aria-current="false"><i></i><?= e($b['name']) ?></button>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
  <p class="count" id="count" aria-live="polite"><?= plural(count($items), 'item') ?></p>

  <div id="grid" class="lgrid">
    <?php foreach ($items as $i): ?>
      <?php $photo = $i['image'] ?: $i['recipe_image']; ?>
      <article class="lcard" style="--c: <?= e($i['colour'] ?: '#01216C') ?>" data-search="<?= e(mb_strtolower($i['name'] . ' ' . $i['brand_name'])) ?>" data-brand-id="<?= (int)$i['brand_id'] ?>">
        <button class="lphoto" type="button" data-label="<?= (int)$i['id'] ?>" tabindex="-1" aria-hidden="true">
          <?php if ($photo): ?><img src="<?= e(thumb_url($photo)) ?>" alt="" loading="lazy" decoding="async">
          <?php else: ?><span class="lphoto-none"><?= e(label_initials($i['name'])) ?></span><?php endif; ?>
        </button>
        <div class="lcard-top">
          <span class="kind kind-<?= e($i['kind']) ?>"><?= e(LABEL_KINDS[$i['kind']] ?? 'Made') ?></span>
          <?php if ($i['brand_name']): ?><span class="lbrand"><?= e($i['brand_name']) ?></span><?php endif; ?>
        </div>
        <h3><?= e($i['name']) ?></h3>
        <p class="shelf"><strong><?= e(fmt_shelf((int)$i['shelf_value'], $i['shelf_unit'])) ?></strong><?php if ($i['storage'] !== ''): ?><span><?= e($i['storage']) ?></span><?php endif; ?></p>
        <button class="btn btn-go lprint" type="button" data-label="<?= (int)$i['id'] ?>"><?= icon('printer') ?>Print label</button>
      </article>
    <?php endforeach; ?>
  </div>
  <div class="empty" id="noMatch" hidden><h2>Nothing matches</h2><p>Try fewer letters, or pick "All".</p></div>
  <?php endif; ?>
</main>

<!-- Print one label -->
<div class="modal" id="labelModal" role="dialog" aria-modal="true" aria-labelledby="lmTitle" hidden>
  <div class="modal-scrim" data-close></div>
  <div class="modal-box lmodal">
    <header class="modal-h">
      <span class="lm-photo" id="lmPhoto" aria-hidden="true"></span>
      <div class="lm-head">
        <span class="kind" id="lmKind"></span>
        <h2 id="lmTitle"></h2>
        <p class="muted" id="lmShelf"></p>
      </div>
      <button class="ibtn" type="button" data-close aria-label="Close"><?= icon('close') ?></button>
    </header>
    <div class="lm-body">
      <div class="lm-form">
        <fieldset class="lm-when">
          <legend id="lmWhenLegend">When was it made?</legend>
          <div class="seg" role="group" aria-label="Quick dates">
            <button type="button" data-when="now" aria-pressed="true">Now</button>
            <button type="button" data-when="today">Earlier today</button>
            <button type="button" data-when="yesterday">Yesterday</button>
          </div>
          <div class="lm-dt">
            <label><span>Date</span><input type="date" id="lmDate" class="in"></label>
            <label><span>Time</span><input type="time" id="lmTime" class="in"></label>
          </div>
        </fieldset>
        <label class="lm-field" id="lmSupplierRow" hidden><span>Supplier's use-by date <small>(optional, from the pack)</small></span><input type="date" id="lmSupplier" class="in"></label>
        <label class="lm-field" id="lmInitialsRow"><span>Your initials</span><input type="text" id="lmInitials" class="in initials" maxlength="4" autocomplete="off" autocapitalize="characters" placeholder="e.g. SH"></label>
        <div class="useby">
          <span>Use by</span>
          <strong id="lmUseBy"></strong>
          <small id="lmUseByNote"></small>
        </div>
      </div>
      <div class="lm-preview">
        <div class="paper" id="lmPaper"><img id="lmImg" alt="Label preview"></div>
        <p class="muted small" id="lmSize"></p>
        <div class="lm-qty">
          <span>Labels</span>
          <div class="stepper">
            <button type="button" class="ibtn" data-q="-1" aria-label="One fewer"><?= icon('minus') ?></button>
            <output id="lmQty" aria-live="polite">1</output>
            <button type="button" class="ibtn" data-q="1" aria-label="One more"><?= icon('plus') ?></button>
          </div>
        </div>
        <button class="btn btn-go btn-big wide" type="button" id="lmPrint"><?= icon('printer') ?><span>Print label</span></button>
        <p class="lm-via small" id="lmVia"></p>
      </div>
    </div>
  </div>
</div>

<!-- Printer panel -->
<div class="modal" id="printerModal" role="dialog" aria-modal="true" aria-labelledby="pmTitle" hidden>
  <div class="modal-scrim" data-close></div>
  <div class="modal-box pm">
    <header class="modal-h">
      <h2 id="pmTitle">Label printer</h2>
      <button class="ibtn" type="button" data-close aria-label="Close"><?= icon('close') ?></button>
    </header>
    <div class="pm-state" id="pmState"></div>
    <div class="alert warn" id="pmNote" hidden></div>
    <div class="pm-actions">
      <button class="btn btn-go btn-big" type="button" id="pmConnect"><?= icon('bluetooth') ?><span>Connect Bluetooth printer</span></button>
      <button class="btn btn-big" type="button" id="pmReconnect" hidden><?= icon('refresh') ?>Reconnect</button>
      <button class="btn btn-big" type="button" id="pmTest"><?= icon('tag') ?>Print a test label</button>
      <button class="btn btn-ghost btn-danger" type="button" id="pmForget" hidden><?= icon('close') ?>Forget this printer</button>
    </div>
    <div class="pm-row">
      <span>Printer language</span>
      <div class="seg" role="group" aria-label="Printer language" id="pmLang">
        <button type="button" data-lang="tspl">TSPL</button>
        <button type="button" data-lang="escpos">ESC/POS</button>
        <button type="button" data-lang="browser">Print dialog</button>
      </div>
    </div>
    <label class="pm-row"><span>Labels come out black or blank? Invert the image</span><input type="checkbox" id="pmInvert"></label>
    <p class="muted small">Most Bluetooth label printers (Xprinter, Munbyn, HPRT, iDPRT and similar) use TSPL. Receipt-style printers use ESC/POS. Printers that only work with their own app, such as Niimbot, Phomemo or Brother P-touch, can't be driven from a browser: use Print dialog.</p>
  </div>
</div>

<div class="toast" id="toast" role="status" aria-live="polite" hidden></div>
<script type="application/json" id="labelData"><?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset('printer.js')) ?>" defer></script>
<script src="<?= e(asset('labels.js')) ?>" defer></script>
<?php page_foot();
