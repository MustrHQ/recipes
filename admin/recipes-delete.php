<?php
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/ui.php';
require __DIR__ . '/../inc/admin.php';
require __DIR__ . '/../inc/recipe-delete.php';
require_admin();
$count = (int)db()->query('SELECT COUNT(*) FROM recipes')->fetchColumn();
$job = $_SESSION['recipe_delete'] ?? null;
$active = $job && !recipe_delete_state($job)['done'];
admin_head('Delete all recipes', 'recipes');
?>
<div class="ahead"><h1>Delete all recipes</h1><a class="btn" href="./">Back to recipes</a></div>
<section class="card">
  <?php if ($active): ?>
    <h2>Resume deletion</h2>
    <p><?= (int)$job['pos'] ?> of <?= count($job['ids']) ?> recipes processed. Previously completed batches stay deleted.</p>
  <?php else: ?>
    <h2><?= plural($count, 'recipe') ?> across all brands</h2>
    <p>This permanently deletes every current recipe, its ingredients and its steps, including hidden recipes. Pictures are removed when no remaining recipe or label uses them.</p>
    <p>Brands and label records are kept. Labels are unlinked from deleted recipes. Recipe-list filters do not limit this action.</p>
    <p><a href="update.php">Download a database and photo backup</a> before deleting if you may need to restore the recipes.</p>
  <?php endif; ?>
  <?php if ($count || $active): ?>
  <form method="post" class="form tight" data-delete-recipes data-api="<?= e(base_url('admin/recipes-api.php')) ?>" data-next="<?= e(base_url('admin/')) ?>" data-token="<?= $active ? e($job['token']) : '' ?>" data-pos="<?= $active ? (int)$job['pos'] : 0 ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="expected_count" value="<?= $count ?>">
    <?php if (!$active): ?>
    <div class="f"><label for="delete-confirm">Type DELETE ALL RECIPES to confirm</label><input id="delete-confirm" name="confirmation" required pattern="DELETE ALL RECIPES" autocomplete="off" spellcheck="false"></div>
    <?php endif; ?>
    <div class="progress" data-progress hidden><div class="bar"><i></i></div><p class="small" aria-live="polite"></p></div>
    <div class="alert bad" data-delete-error role="alert" hidden></div>
    <div><button type="submit" class="btn btn-danger"><?= $active ? 'Resume deletion' : 'Permanently delete all ' . $count . ' recipes' ?></button></div>
    <p class="help small">Keep this page open until deletion finishes. Recipes added after deletion starts are kept.</p>
    <noscript><p class="alert bad">Enable JavaScript to run this operation with progress and resume support.</p></noscript>
  </form>
  <?php else: ?><p>There are no recipes to delete.</p><?php endif; ?>
</section>
<?php admin_foot();
