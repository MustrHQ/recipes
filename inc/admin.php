<?php
function admin_head(string $title, string $active): void
{
    page_head($title . ' | Admin');
    $nav = [
        'Kitchen' => [
            ['recipes', 'index.php', 'book', 'Recipes'],
            ['labels', 'labels.php', 'printer', 'Labels'],
            ['brands', 'brands.php', 'tag', 'Brands'],
        ],
        'Content' => [
            ['transfer', 'transfer.php', 'swap', 'Import and export'],
        ],
        'Manage' => [
            ['settings', 'settings.php', 'cog', 'Settings'],
            ['update', 'update.php', 'refresh', 'Updates and backups'],
        ],
    ];
    ?>
<div class="admin">
  <aside class="side">
    <a class="mark" href="<?= e(base_url('admin/')) ?>"><span class="mark-box"><?= mark_svg() ?></span><span><?= e(kitchen_name()) ?><small>Recipe admin</small></span></a>
    <?php foreach ($nav as $grp => $items): ?>
      <div class="grp"><?= e($grp) ?></div>
      <?php foreach ($items as [$key, $href, $ic, $label]): ?>
        <a class="nav" href="<?= e(base_url('admin/' . $href)) ?>" <?= $key === $active ? 'aria-current="page"' : '' ?>><?= icon($ic) ?><?= e($label) ?></a>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <div class="grp">Kitchen screens</div>
    <a class="nav" href="<?= e(base_url()) ?>" target="_blank" rel="noopener"><?= icon('eye') ?>Open kitchen screens</a>
    <a class="nav" href="<?= e(base_url('admin/logout.php')) ?>"><?= icon('out') ?>Sign out</a>
    <div class="foot">MustrHQ Recipes <?= e(APP_VERSION) ?><br><a href="https://github.com/MustrHQ/recipes" target="_blank" rel="noopener">Source code</a> (AGPL-3.0)</div>
  </aside>
  <main class="main" id="main">
  <?php if (is_file(ROOT . '/install.php')): ?><div class="alert bad" role="alert"><?= icon('alert') ?>install.php is still on the server. Delete it in File Manager so nobody can run the installer again.</div><?php endif; ?>
  <?php if ($f = flash()): ?><div class="alert <?= e($f[0]) ?>" role="status"><?= e($f[1]) ?></div><?php endif; ?>
<?php
}

function admin_foot(): void
{
    echo "  </main>\n</div>\n";
    lightbox_markup();
    page_foot();
}

/** Image chooser: current picture, file input, and a remove tick box. */
function image_field(string $fileName, string $keepName, string $removeName, ?string $current, string $label, bool $big = false): string
{
    $id = 'f-' . preg_replace('/\W+/', '-', $fileName);
    ob_start(); ?>
    <div class="imgpick<?= $big ? ' big' : '' ?>">
      <div class="pv"><?= $current ? '<img src="' . e(thumb_url($current)) . '" alt="" loading="lazy">' : '<div class="noimg">' . icon('image') . '</div>' ?></div>
      <div class="opts">
        <input type="hidden" name="<?= e($keepName) ?>" value="<?= e((string)$current) ?>">
        <label class="btn filebtn" for="<?= $id ?>">
          <?= icon('image') ?><span data-file-label><?= $current ? 'Replace photo' : 'Add photo' ?></span><span class="sr">: <?= e($label) ?></span>
        </label>
        <input id="<?= $id ?>" class="sr" type="file" name="<?= e($fileName) ?>" accept="image/jpeg,image/png,image/webp,image/gif" data-preview>
        <?php if ($current): ?><label><input type="checkbox" name="<?= e($removeName) ?>" value="1"> Remove picture</label><?php endif; ?>
      </div>
    </div>
    <?php return (string)ob_get_clean();
}
