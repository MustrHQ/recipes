<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/ui.php';

$next = (string)($_GET['next'] ?? base_url());
if (!str_starts_with($next, '/') || str_starts_with($next, '//')) $next = base_url();
$code = (string)setting('viewer_code', '');
if ($code === '' || ($_SESSION['viewer_ok'] ?? '') === hash('sha256', $code)) redirect($next);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if ($wait = throttle_wait('code')) {
        $error = 'Too many wrong codes from this device. Try again in ' . max(1, (int)ceil($wait / 60)) . ' min.';
    } elseif (hash_equals($code, trim((string)($_POST['code'] ?? '')))) {
        session_regenerate_id(true);
        $_SESSION['viewer_ok'] = hash('sha256', $code);
        throttle_clear('code');
        redirect($next);
    } else {
        throttle_fail('code');
        $error = 'That code is not right. Ask your manager for the kitchen code.';
    }
}

page_head('Enter kitchen code');
?>
<main class="unlock" id="main">
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <span class="mark-box big"><?= mark_svg() ?></span>
    <h1><?= e(kitchen_name()) ?></h1>
    <p class="muted">Enter the kitchen code to open the recipes.</p>
    <?php if ($error): ?><div class="alert bad" role="alert"><?= e($error) ?></div><?php endif; ?>
    <label class="sr" for="code">Kitchen code</label>
    <input id="code" name="code" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="8" autofocus required>
    <button class="btn btn-primary btn-big wide" type="submit">Open recipes</button>
  </form>
</main>
<?php page_foot();
