<?php
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/recipe-delete.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function recipe_reply(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (!is_admin()) recipe_reply(['error' => 'Sign in again before deleting recipes.'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') recipe_reply(['error' => 'Not allowed.'], 405);
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) recipe_reply(['error' => 'This page expired. Reload it.'], 400);
@set_time_limit(30);
try {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'delete_start') {
        if (!empty($_SESSION['recipe_delete']) && !recipe_delete_state($_SESSION['recipe_delete'])['done']) {
            recipe_reply(['error' => 'A deletion is already in progress. Reload this page to resume it.'], 409);
        }
        $_SESSION['recipe_delete'] = recipe_delete_start((string)($_POST['confirmation'] ?? ''), (int)($_POST['expected_count'] ?? -1));
        recipe_reply(recipe_delete_state($_SESSION['recipe_delete']));
    }
    if ($action === 'delete_next') {
        if (empty($_SESSION['recipe_delete'])) recipe_reply(['error' => 'No deletion is in progress. Reload this page.'], 410);
        $state = recipe_delete_next($_SESSION['recipe_delete'], (string)($_POST['token'] ?? ''), (int)($_POST['pos'] ?? -1));
        if ($state['done']) flash('Deleted ' . plural($state['deleted'], 'recipe') . '. Brands and labels were kept.');
        recipe_reply($state);
    }
    recipe_reply(['error' => 'Unknown action.'], 400);
} catch (Throwable $e) {
    recipe_reply(['error' => $e->getMessage()], 422);
}
