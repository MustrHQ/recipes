<?php
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/ui.php';
if (is_admin()) redirect(base_url('admin/'));

$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if ($wait = throttle_wait('admin')) {
        $error = 'Too many attempts from this device. Try again in ' . max(1, (int)ceil($wait / 60)) . ' min.';
    } else {
        $st = db()->prepare('SELECT * FROM admins WHERE email = ?');
        $st->execute([$email]);
        $a = $st->fetch();
        $pw = (string)($_POST['password'] ?? '');
        if ($a && password_verify($pw, $a['password_hash'])) {
            if (password_needs_rehash($a['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $a['id']]);
            }
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int)$a['id'];
            $_SESSION['admin_name'] = $a['name'];
            throttle_clear('admin');
            redirect(base_url('admin/'));
        }
        if (!$a) password_verify($pw, '$2y$10$u1Nr9dHR4wGcFQ9ELhXBSeoKKdHJvXx2duYrLrqTXjp6eF/.nB1Ci'); // same timing either way
        throttle_fail('admin');
        $error = 'That email and password do not match an admin account.';
    }
}
page_head('Sign in');
?>
<main class="login" id="main">
  <section class="art">
    <span class="mark"><span class="mark-box"><?= mark_svg() ?></span><span><?= e(kitchen_name()) ?><small>Recipe admin</small></span></span>
    <div>
      <h2>Every dish, built the same way.</h2>
      <p>Write the recipe once, add the photos, and every screen in the kitchen shows the same steps.</p>
    </div>
    <small class="faint">MustrHQ Recipes <?= e(APP_VERSION) ?></small>
  </section>
  <section class="frm">
    <form method="post">
      <?= csrf_field() ?>
      <h1>Sign in</h1>
      <?php if (isset($_GET['installer'])): ?><div class="alert bad">Could not delete install.php. Delete it in File Manager.</div><?php endif; ?>
      <?php if ($error): ?><div class="alert bad"><?= e($error) ?></div><?php endif; ?>
      <div class="f"><label for="email">Email</label><input type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus></div>
      <div class="f"><label for="password">Password</label><input type="password" id="password" name="password" autocomplete="current-password" required></div>
      <button class="btn btn-primary btn-big" type="submit">Sign in</button>
      <a class="small" href="<?= e(base_url()) ?>">Go to the recipes</a>
    </form>
  </section>
</main>
<?php page_foot();
