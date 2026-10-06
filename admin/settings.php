<?php
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/ui.php';
require __DIR__ . '/../inc/admin.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'kitchen') {
        $k = mb_substr(trim((string)($_POST['kitchen_name'] ?? '')), 0, 80);
        $code = trim((string)($_POST['viewer_code'] ?? ''));
        if ($k === '') flash('Enter a kitchen name.', 'bad');
        elseif ($code !== '' && !preg_match('/^\d{4,8}$/', $code)) flash('The kitchen code must be 4 to 8 digits, or empty.', 'bad');
        else {
            set_setting('kitchen_name', $k);
            set_setting('viewer_code', $code);
            flash($code === '' ? 'Saved. Recipes are open to anyone with the link.' : 'Saved. Devices need the new code once to see recipes.');
        }
    } elseif ($act === 'password') {
        $st = db()->prepare('SELECT password_hash FROM admins WHERE id = ?');
        $st->execute([$_SESSION['admin_id']]);
        $new = (string)($_POST['new'] ?? '');
        if (!password_verify((string)($_POST['current'] ?? ''), (string)$st->fetchColumn())) flash('Your current password is not right.', 'bad');
        elseif (strlen($new) < 8) flash('Use a new password of at least 8 characters.', 'bad');
        else {
            db()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['admin_id']]);
            flash('Password changed.');
        }
    } elseif ($act === 'add_admin') {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $name = trim((string)($_POST['name'] ?? ''));
        $pw = (string)($_POST['password'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pw) < 8) flash('Enter a name, a valid email and a password of at least 8 characters.', 'bad');
        else {
            try {
                db()->prepare('INSERT INTO admins (name, email, password_hash) VALUES (?,?,?)')->execute([$name, $email, password_hash($pw, PASSWORD_DEFAULT)]);
                flash($name . ' can now sign in.');
            } catch (PDOException $ex) { flash('An admin with that email already exists.', 'bad'); }
        }
    } elseif ($act === 'remove_admin') {
        $aid = (int)($_POST['id'] ?? 0);
        if ($aid === (int)$_SESSION['admin_id']) flash('You cannot remove yourself.', 'bad');
        else { db()->prepare('DELETE FROM admins WHERE id = ?')->execute([$aid]); flash('Admin removed.'); }
    }
    redirect(base_url('admin/settings.php'));
}

$admins = db()->query('SELECT id, name, email FROM admins ORDER BY name')->fetchAll();
admin_head('Settings', 'settings');
?>
<div class="ahead"><h1>Settings</h1></div>
<div class="form" style="max-width: 820px">
  <form method="post" class="card">
    <?= csrf_field() ?><input type="hidden" name="action" value="kitchen">
    <h2>Kitchen</h2>
    <p class="help">The kitchen code stops anyone outside the team opening recipes from the link. Each tablet asks for it once.</p>
    <div class="fgrid">
      <div class="f s4"><label for="kn">Kitchen name</label><input type="text" id="kn" name="kitchen_name" value="<?= e(setting('kitchen_name', '')) ?>" required></div>
      <div class="f s2"><label for="vc">Kitchen code</label><input type="text" id="vc" name="viewer_code" inputmode="numeric" value="<?= e(setting('viewer_code', '')) ?>" placeholder="No code"><small>4 to 8 digits. Empty means no code.</small></div>
    </div>
    <button class="btn btn-primary" type="submit" style="margin-top: 14px">Save kitchen settings</button>
  </form>

  <section class="card">
    <h2>Admins</h2>
    <p class="help">People who can add and edit recipes.</p>
    <table class="list" style="margin-bottom: 16px">
      <?php foreach ($admins as $a): ?>
        <tr><td><strong><?= e($a['name']) ?></strong><?= (int)$a['id'] === (int)$_SESSION['admin_id'] ? ' <span class="pill">You</span>' : '' ?><br><small style="color: var(--muted)"><?= e($a['email']) ?></small></td>
          <td class="acts"><?php if ((int)$a['id'] !== (int)$_SESSION['admin_id']): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="remove_admin"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
              <button class="btn btn-ghost btn-danger" data-confirm="Remove <?= e($a['name']) ?> as an admin?">Remove</button></form>
          <?php endif; ?></td></tr>
      <?php endforeach; ?>
    </table>
    <form method="post" class="fgrid" style="align-items: end">
      <?= csrf_field() ?><input type="hidden" name="action" value="add_admin">
      <div class="f s2"><label for="an">Name</label><input type="text" id="an" name="name" required></div>
      <div class="f s2"><label for="ae">Email</label><input type="email" id="ae" name="email" required></div>
      <div class="f s2"><label for="ap">Password</label><input type="password" id="ap" name="password" minlength="8" required autocomplete="new-password"></div>
      <div class="f"><div><button class="btn" type="submit"><?= icon('plus') ?>Add admin</button></div></div>
    </form>
  </section>

  <section class="card">
    <h2>Kitchen tablets</h2>
    <p class="help">Install the recipe screens as an app on each tablet. It opens full screen, starts on the recipes, has a shortcut to labels, and keeps photos for when the wifi drops.</p>
    <div class="howto-grid">
      <div><h3>Android tablet</h3><ol>
        <li>Open <code><?= e((isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '') . base_url()) ?></code> in Chrome.</li>
        <li>Tap <strong>Install app</strong> on the card on the start screen, or the ⋮ menu → <strong>Install app</strong>.</li>
        <li>For Bluetooth label printing, open <strong>Labels</strong> → printer button → <strong>Connect</strong>.</li></ol></div>
      <div><h3>iPad</h3><ol>
        <li>Open the same address in Safari.</li>
        <li>Tap Share → <strong>Add to Home Screen</strong>.</li>
        <li>Labels print through AirPrint, because Safari has no Bluetooth for websites.</li></ol></div>
    </div>
    <p class="help small">Tip: Android's <strong>App pinning</strong> and iPad's <strong>Guided Access</strong> lock a tablet to this app. The site needs <code>https://</code> for install and Bluetooth.</p>
  </section>

  <section class="card">
    <h2>This installation</h2>
    <p class="help">Useful when you ask for help or report a problem.</p>
    <div class="facts">
      <div><span>MustrHQ Recipes</span><strong><?= e(MUSTR_VERSION) ?></strong></div>
      <div><span>PHP</span><strong><?= e(PHP_VERSION) ?></strong></div>
      <div><span>Largest photo upload</span><strong><?= e(human_bytes(ini_bytes('upload_max_filesize'))) ?></strong></div>
      <div><span>Zip support</span><strong><?= class_exists('ZipArchive') || class_exists('PharData') ? 'Yes' : 'No' ?></strong></div>
      <div><span>Photo resizing (GD)</span><strong><?= extension_loaded('gd') ? 'Yes' : 'No' ?></strong></div>
    </div>
    <p class="help small" style="margin-top: 10px">New versions: <a href="update.php">Updates and backups</a>. Source code and issues: <a href="https://github.com/MustrHQ/recipes" target="_blank" rel="noopener">github.com/MustrHQ/recipes</a>.</p>
  </section>

  <form method="post" class="card">
    <?= csrf_field() ?><input type="hidden" name="action" value="password">
    <h2>Your password</h2>
    <div class="fgrid">
      <div class="f s3"><label for="cp">Current password</label><input type="password" id="cp" name="current" required autocomplete="current-password"></div>
      <div class="f s3"><label for="np">New password</label><input type="password" id="np" name="new" minlength="8" required autocomplete="new-password"></div>
    </div>
    <button class="btn btn-primary" type="submit" style="margin-top: 14px">Change password</button>
  </form>
</div>
<?php admin_foot();
