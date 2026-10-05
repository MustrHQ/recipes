<?php
/**
 * MustrHQ Recipes installer. Open this file in a browser once, then delete it.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/ui.php';

$installed = false;
if (defined('DB_HOST')) {
    try { $installed = (bool)db()->query("SHOW TABLES LIKE 'admins'")->fetchColumn() && (int)db()->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0; }
    catch (Throwable $t) { $installed = false; }
}

// Final step: remove this file.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    check_csrf();
    @unlink(__FILE__);
    redirect(base_url('admin/login.php' . (is_file(__FILE__) ? '?installer=1' : '')));
}

$checks = [
    ['PHP 8.1 or newer', version_compare(PHP_VERSION, '8.1.0', '>='), 'You have PHP ' . PHP_VERSION . '. Change it in cPanel → Select PHP Version.', true],
    ['PDO MySQL extension', extension_loaded('pdo_mysql'), 'Turn on "pdo_mysql" in cPanel → Select PHP Version → Extensions.', true],
    ['Uploads folder is writable', is_writable(UPLOAD_DIR), 'Set the "uploads" folder to permission 755 in File Manager.', true],
    ['GD image extension', extension_loaded('gd'), 'Optional. Without it, big photos are stored at full size.', false],
    ['Zip support', class_exists('ZipArchive') || class_exists('PharData'), 'Optional. Needed for import, export and one-click updates. Turn on "zip" in Select PHP Version.', false],
    ['Backups folder is writable', is_writable(ROOT . '/backups'), 'Optional. Needed for updates and backups. Set the "backups" folder to 755.', false],
];
$blocked = (bool)array_filter($checks, fn($c) => $c[3] && !$c[1]);

$errors = [];
$done = false;
$configText = '';
$v = ['db_host' => 'localhost', 'db_name' => '', 'db_user' => '', 'kitchen' => '', 'name' => '', 'email' => '', 'viewer_code' => '', 'demo' => '1'];

if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'install') {
    check_csrf();
    foreach ($v as $k => $_) $v[$k] = trim((string)($_POST[$k] ?? ''));
    $v['demo'] = isset($_POST['demo']) ? '1' : '';
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $pass = (string)($_POST['password'] ?? '');

    if ($v['db_name'] === '' || $v['db_user'] === '') $errors[] = 'Enter the database name and database user from cPanel.';
    if ($v['kitchen'] === '') $errors[] = 'Enter a kitchen name. It shows at the top of every screen.';
    if ($v['name'] === '') $errors[] = 'Enter your name.';
    if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address for signing in.';
    if (strlen($pass) < 8) $errors[] = 'Use a password of at least 8 characters.';
    if ($v['viewer_code'] !== '' && !preg_match('/^\d{4,8}$/', $v['viewer_code'])) $errors[] = 'The kitchen code must be 4 to 8 digits, or left empty.';

    if (!$errors) {
        try {
            $pdo = new PDO('mysql:host=' . $v['db_host'] . ';dbname=' . $v['db_name'] . ';charset=utf8mb4', $v['db_user'], $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        } catch (Throwable $t) {
            $errors[] = 'Could not connect to the database. Check the name, user and password, and that the user is added to the database with All Privileges. (' . $t->getMessage() . ')';
        }
    }

    if (!$errors) {
        try {
            foreach (require __DIR__ . '/inc/schema.php' as $sql) $pdo->exec($sql);
            if ((int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0) {
                $errors[] = 'This database already has a MustrHQ Recipes admin. Use a new database, or sign in with the existing account.';
            } else {
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO admins (name, email, password_hash) VALUES (?,?,?)')->execute([$v['name'], strtolower($v['email']), password_hash($pass, PASSWORD_DEFAULT)]);
                $set = $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
                $set->execute(['kitchen_name', $v['kitchen']]);
                $set->execute(['viewer_code', $v['viewer_code']]);
                $set->execute(['schema_version', (string)schema_target()]);
                if ($v['demo']) { require __DIR__ . '/inc/demo.php'; install_demo($pdo); }
                $pdo->commit();

                $exp = fn($s) => var_export($s, true);
                $configText = "<?php\n// MustrHQ Recipes settings, written by the installer. Keep this file private.\n"
                    . "define('DB_HOST', " . $exp($v['db_host']) . ");\n"
                    . "define('DB_NAME', " . $exp($v['db_name']) . ");\n"
                    . "define('DB_USER', " . $exp($v['db_user']) . ");\n"
                    . "define('DB_PASS', " . $exp($dbPass) . ");\n";
                if (@file_put_contents(ROOT . '/config.php', $configText) !== false) {
                    @chmod(ROOT . '/config.php', 0640);
                    $configText = '';
                }
                $done = true;
            }
        } catch (Throwable $t) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Setup stopped: ' . $t->getMessage();
        }
    }
}

page_head('Install');
?>
<main class="wrap" style="max-width: 760px">
  <div class="ahead" style="margin-top: 10px">
    <span class="mark-box big"><?= mark_svg() ?></span>
    <h1>Set up MustrHQ Recipes</h1>
  </div>

<?php if ($installed): ?>
  <div class="card">
    <h2>Already installed</h2>
    <p>MustrHQ Recipes is set up on this server. Delete <strong>install.php</strong> so nobody can run it again.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete">
      <button class="btn btn-primary" type="submit">Delete install.php and sign in</button></form>
  </div>

<?php elseif ($done): ?>
  <div class="card form">
    <div>
      <h2>Your recipe portal is ready</h2>
      <p class="help">Sign in to add brands, recipes and photos. Kitchen screens use the main address of this folder.</p>
    </div>
    <?php if ($configText !== ''): ?>
      <div class="alert bad">Your server did not let the installer create <strong>config.php</strong>. In File Manager, create a file called <strong>config.php</strong> in this folder and paste this into it:</div>
      <textarea class="in" rows="8" readonly style="font-family: monospace; font-size: 14px"><?= e($configText) ?></textarea>
    <?php endif; ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete">
      <button class="btn btn-go btn-big" type="submit">Delete install.php and sign in</button></form>
  </div>

<?php else: ?>
  <div class="card" style="margin-bottom: 20px">
    <h2>Server check</h2>
    <ul style="list-style: none; padding: 0; margin: 10px 0 0; display: grid; gap: 8px">
      <?php foreach ($checks as [$label, $ok, $fix, $required]): ?>
        <li style="display: flex; gap: 10px; align-items: flex-start">
          <span class="pill <?= $ok ? '' : 'off' ?>" style="<?= $ok ? 'background:#DDF1E7;color:#0D4A30' : ($required ? 'background:#FBEAE9;color:#8F1F1A' : '') ?>"><?= $ok ? 'OK' : ($required ? 'Fix' : 'Optional') ?></span>
          <span><strong><?= e($label) ?></strong><?php if (!$ok): ?><br><small style="color: var(--muted)"><?= e($fix) ?></small><?php endif; ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <?php foreach ($errors as $err): ?><div class="alert bad"><?= e($err) ?></div><?php endforeach; ?>

  <?php if ($blocked): ?>
    <div class="alert bad">Fix the items marked "Fix" above, then reload this page.</div>
  <?php else: ?>
  <form method="post" class="form" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="install">
    <section class="card">
      <h2>Database</h2>
      <p class="help">Create these in cPanel → MySQL Databases, and add the user to the database with All Privileges.</p>
      <div class="fgrid">
        <div class="f s3"><label for="db_name">Database name</label><input type="text" id="db_name" name="db_name" value="<?= e($v['db_name']) ?>" required></div>
        <div class="f s3"><label for="db_host">Database host</label><input type="text" id="db_host" name="db_host" value="<?= e($v['db_host']) ?>" required><small>Almost always "localhost".</small></div>
        <div class="f s3"><label for="db_user">Database user</label><input type="text" id="db_user" name="db_user" value="<?= e($v['db_user']) ?>" required></div>
        <div class="f s3"><label for="db_pass">Database password</label><input type="password" id="db_pass" name="db_pass"></div>
      </div>
    </section>
    <section class="card">
      <h2>Kitchen and admin account</h2>
      <div class="fgrid">
        <div class="f"><label for="kitchen">Kitchen name</label><input type="text" id="kitchen" name="kitchen" value="<?= e($v['kitchen']) ?>" placeholder="e.g. Brewery Road kitchen" required></div>
        <div class="f s3"><label for="name">Your name</label><input type="text" id="name" name="name" value="<?= e($v['name']) ?>" required></div>
        <div class="f s3"><label for="email">Email (for signing in)</label><input type="email" id="email" name="email" value="<?= e($v['email']) ?>" required></div>
        <div class="f s3"><label for="password">Password</label><input type="password" id="password" name="password" minlength="8" required><small>At least 8 characters.</small></div>
        <div class="f s3"><label for="viewer_code">Kitchen code (optional)</label><input type="text" id="viewer_code" name="viewer_code" inputmode="numeric" value="<?= e($v['viewer_code']) ?>" placeholder="e.g. 4821"><small>Staff type this once per device to see recipes. Leave empty for no code.</small></div>
        <div class="f"><div class="checks"><label><input type="checkbox" name="demo" value="1" <?= $v['demo'] ? 'checked' : '' ?>> Add two example recipes to start with</label></div></div>
      </div>
    </section>
    <div><button class="btn btn-go btn-big" type="submit">Install</button></div>
  </form>
  <?php endif; ?>
<?php endif; ?>
</main>
<?php page_foot();
