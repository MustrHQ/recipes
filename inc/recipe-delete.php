<?php
/** Admin bulk deletion. Each batch is atomic; retries use the same snapshot and position. */
function recipe_delete_start(string $confirmation, int $expectedCount): array
{
    if (trim($confirmation) !== 'DELETE ALL RECIPES') throw new RuntimeException('Type DELETE ALL RECIPES to confirm.');
    $ids = array_map('intval', db()->query('SELECT id FROM recipes ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    if (count($ids) !== $expectedCount) throw new RuntimeException('The recipe count changed. Reload this page and check the total again.');
    if (!$ids) throw new RuntimeException('There are no recipes to delete.');
    return ['token' => bin2hex(random_bytes(16)), 'ids' => $ids, 'pos' => 0, 'deleted' => 0];
}

function recipe_delete_state(array $job): array
{
    return ['ok' => true, 'token' => $job['token'], 'pos' => $job['pos'],
        'total' => count($job['ids']), 'deleted' => $job['deleted'], 'done' => $job['pos'] >= count($job['ids'])];
}

function recipe_delete_next(array &$job, string $token, int $position): array
{
    if (!hash_equals($job['token'], $token)) throw new RuntimeException('That deletion session expired. Reload the page.');
    // A repeated or late response must never advance an extra batch.
    if ($position !== $job['pos'] || $job['pos'] >= count($job['ids'])) return recipe_delete_state($job);
    $ids = array_slice($job['ids'], $job['pos'], 10);
    $slots = implode(',', array_fill(0, count($ids), '?'));
    $pdo = db(); $images = [];
    $pdo->beginTransaction();
    try {
        foreach (['recipes' => 'id', 'ingredients' => 'recipe_id', 'steps' => 'recipe_id'] as $table => $column) {
            $st = $pdo->prepare("SELECT image FROM $table WHERE $column IN ($slots) AND image IS NOT NULL");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $image) $images[$image] = true;
        }
        // Preserve label records and their own images when their linked recipe disappears.
        $pdo->prepare("UPDATE label_items SET recipe_id = NULL WHERE recipe_id IN ($slots)")->execute($ids);
        $pdo->prepare("DELETE FROM ingredients WHERE recipe_id IN ($slots)")->execute($ids);
        $pdo->prepare("DELETE FROM steps WHERE recipe_id IN ($slots)")->execute($ids);
        $st = $pdo->prepare("DELETE FROM recipes WHERE id IN ($slots)");
        $st->execute($ids); $deleted = $st->rowCount();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw new RuntimeException('This batch was not deleted. You can resume the deletion.', 0, $e);
    }
    $job['pos'] += count($ids); $job['deleted'] += $deleted;
    foreach (array_keys($images) as $image) delete_upload_if_unused($image);
    return recipe_delete_state($job);
}
