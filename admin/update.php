<?php
/** Updates and backups: install a new release from a zip, roll back, and keep backups. */
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/ui.php';
require __DIR__ . '/../inc/admin.php';
require __DIR__ . '/../inc/updater.php';
require_admin();

$dir = upd_dir();
$self = base_url('admin/update.php');
const BACKUP_NAME = '/^(files-.+\.zip|db-.+\.sql\.gz|photos-.+\.zip)$/';

/* ---------- Download a backup ---------- */
if (isset($_GET['download'])) {
    $name = basename((string)$_GET['download']);
    $f = $dir . '/' . $name;
    if (!preg_match(BACKUP_NAME, $name) || !is_file($f)) not_found('Backup not found', 'It may have been deleted.');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($f));
    readfile($f);
    exit;
}

/* ---------- Second half of an update: the new code is live, so run its database upgrade ---------- */
if (isset($_GET['migrate'])) {
    try {
        $ran = run_migrations();
        flash('Now running version ' . MUSTR_VERSION . '. ' . ($ran ? 'The database was upgraded.' : 'The database was already up to date.'));
    } catch (Throwable $e) {
        flash('The files were updated, but the database upgrade stopped: ' . $e->getMessage() . ' Your recipes are untouched. Restore the app files backup below if something is not working.', 'bad');
    }
    redirect($self);
}

$preview = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('The zip is bigger than your server accepts (' . ini_get('post_max_size') . '). Raise post_max_size and upload_max_filesize in cPanel → Select PHP Version → Options.', 'bad');
        redirect($self);
    }
    check_csrf();
    $action = $_POST['action'] ?? '';
    @set_time_limit(300);

    if ($action === 'inspect') {
        $f = $_FILES['release'] ?? null;
        $errs = [
            UPLOAD_ERR_INI_SIZE => 'The zip is bigger than your server accepts (' . ini_get('upload_max_filesize') . '). Raise upload_max_filesize in cPanel → Select PHP Version → Options.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Try again.',
            UPLOAD_ERR_NO_FILE => 'Choose the release zip first.',
        ];
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) { flash($errs[$f['error'] ?? UPLOAD_ERR_NO_FILE] ?? 'The upload failed. Try again.', 'bad'); redirect($self); }
        if (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'zip') { flash('That is not a .zip file. Download the release zip from GitHub.', 'bad'); redirect($self); }
        $token = bin2hex(random_bytes(8));
        $dest = $dir . '/incoming-' . $token . '.zip';
        if (!move_uploaded_file($f['tmp_name'], $dest)) { flash('Could not save the upload. Check the backups folder is writable (755).', 'bad'); redirect($self); }
        try {
            $preview = upd_inspect($dest);
            $preview['token'] = $token;
            $preview['file'] = $f['name'];
            $_SESSION['upd_token'] = $token;
        } catch (Throwable $e) {
            @unlink($dest);
            flash($e->getMessage(), 'bad');
            redirect($self);
        }
    }

    if ($action === 'apply') {
        $token = (string)($_POST['token'] ?? '');
        if (!preg_match('/^[a-f0-9]{16}$/', $token) || $token !== ($_SESSION['upd_token'] ?? '')) {
            flash('That update has expired. Upload the zip again.', 'bad');
            redirect($self);
        }
        $zip = $dir . '/incoming-' . $token . '.zip';
        try {
            $info = upd_inspect($zip);
            $fb = upd_backup_files('before-' . $info['version']);
            $db = upd_backup_db();
            $n = upd_apply($info['files'], $fb);
            @unlink($zip);
            unset($_SESSION['upd_token']);
            flash(plural($n, 'file') . ' installed. Backups saved first: ' . $fb . ' and ' . $db . '.');
            redirect($self . '?migrate=1');
        } catch (Throwable $e) {
            flash($e->getMessage(), 'bad');
            redirect($self);
        }
    }

    if ($action === 'cancel') {
        $token = (string)($_POST['token'] ?? '');
        if (preg_match('/^[a-f0-9]{16}$/', $token)) @unlink($dir . '/incoming-' . $token . '.zip');
        unset($_SESSION['upd_token']);
        flash('Update cancelled. Nothing was changed.');
        redirect($self);
    }

    if ($action === 'restore') {
        $name = basename((string)($_POST['name'] ?? ''));
        if (!preg_match('/^files-.+\.zip$/', $name) || !is_file($dir . '/' . $name)) { flash('Backup not found.', 'bad'); redirect($self); }
        try {
            $info = upd_inspect($dir . '/' . $name);
            $fb = upd_backup_files('before-restore');
            upd_apply($info['files'], $fb);
            flash('Restored version ' . $info['version'] . ' from ' . $name . '. What was running before is saved as ' . $fb . '.');
            redirect($self . '?migrate=1');
        } catch (Throwable $e) {
            flash($e->getMessage(), 'bad');
            redirect($self);
        }
    }

    if ($action === 'backup_now') {
        try {
            $made = array_filter([upd_backup_db(), upd_backup_photos(), upd_backup_files('manual')]);
            flash('Backups saved: ' . implode(', ', $made) . '. Download them to keep a copy off the server.');
        } catch (Throwable $e) {
            flash($e->getMessage(), 'bad');
        }
        redirect($self);
    }

    if ($action === 'delete') {
        $name = basename((string)($_POST['name'] ?? ''));
        if (preg_match(BACKUP_NAME, $name)) @unlink($dir . '/' . $name);
        flash('Backup deleted.');
        redirect($self);
    }
}

upd_tidy();
$backups = upd_backups();
$canZip = upd_can_zip();
$writable = is_writable(ROOT) && is_writable($dir);

admin_head('Updates and backups', 'update');
?>
<div class="ahead"><h1>Updates and backups</h1></div>

<?php if ($preview): ?>
  <section class="card" aria-labelledby="pvH">
    <h2 id="pvH">Ready to install version <?= e($preview['version']) ?></h2>
    <p class="help">From <?= e($preview['file']) ?>. You are running <?= e(MUSTR_VERSION) ?>.
      <?php if (version_compare($preview['version'], MUSTR_VERSION, '<')): ?><strong>This is an older version than the one you have.</strong><?php endif; ?></p>
    <div class="facts" style="margin-bottom: 14px">
      <div><span>New files</span><strong><?= (int)$preview['new'] ?></strong></div>
      <div><span>Changed</span><strong><?= (int)$preview['changed'] ?></strong></div>
      <div><span>Unchanged</span><strong><?= (int)$preview['same'] ?></strong></div>
      <div><span>Kept as they are</span><strong><?= count($preview['skipped']) ?></strong></div>
    </div>
    <p class="help">Before anything changes, the app files and the database are backed up. Your recipes, photos and <code>config.php</code> are never touched.
      <?php if ($preview['missing']): ?><?= plural(count($preview['missing']), 'file') ?> from older versions are left in place; they do no harm.<?php endif; ?></p>
    <form method="post" class="btnrow">
      <?= csrf_field() ?><input type="hidden" name="token" value="<?= e($preview['token']) ?>">
      <button class="btn btn-go btn-big" name="action" value="apply"><?= icon('download') ?>Back up and install <?= e($preview['version']) ?></button>
      <button class="btn btn-ghost" name="action" value="cancel">Cancel</button>
    </form>
  </section>
<?php else: ?>
  <section class="card" aria-labelledby="upH">
    <h2 id="upH">Install an update</h2>
    <p class="help">You are running <strong>version <?= e(MUSTR_VERSION) ?></strong>. Download the newest
      <code>mustrhq-recipes-x.y.z.zip</code> from <a href="https://github.com/MustrHQ/recipes/releases/latest" target="_blank" rel="noopener">GitHub releases</a> and upload it here.
      You see what will change before anything is installed.</p>
    <?php if (!$canZip): ?>
      <div class="alert bad">This server cannot open zip files. Ask your host to turn on the "zip" PHP extension, or update by uploading the files in File Manager.</div>
    <?php elseif (!$writable): ?>
      <div class="alert bad">The app folder or the backups folder is not writable, so updates can't be installed from here. Set both to permission 755.</div>
    <?php else: ?>
      <form method="post" enctype="multipart/form-data" class="btnrow">
        <?= csrf_field() ?><input type="hidden" name="action" value="inspect">
        <label class="sr" for="release">Release zip</label>
        <input id="release" type="file" name="release" accept=".zip,application/zip" required class="in">
        <button class="btn btn-primary" type="submit"><?= icon('upload') ?>Check this update</button>
      </form>
      <p class="help small">Largest upload your server accepts: <?= e(human_bytes(min(ini_bytes('upload_max_filesize'), ini_bytes('post_max_size')))) ?>.</p>
    <?php endif; ?>
  </section>
<?php endif; ?>

<section class="card" aria-labelledby="bkH" style="margin-top: 20px">
  <div class="card-h">
    <div>
      <h2 id="bkH">Backups</h2>
      <p class="help">Backups are kept in the protected <code>backups</code> folder. Download the database and photos now and then, and keep them somewhere else.</p>
    </div>
    <?php if ($canZip): ?>
    <form method="post"><?= csrf_field() ?><button class="btn" name="action" value="backup_now"><?= icon('download') ?>Back up now</button></form>
    <?php endif; ?>
  </div>
  <?php if (!$backups): ?>
    <p class="muted">No backups yet. One is made automatically before every update.</p>
  <?php else: ?>
    <div class="scroll-x">
    <table class="list">
      <thead><tr><th>Backup</th><th>What</th><th>Size</th><th>Made</th><th><span class="sr">Actions</span></th></tr></thead>
      <tbody>
      <?php foreach ($backups as $b): ?>
        <tr>
          <td><code class="small"><?= e($b['name']) ?></code></td>
          <td><?= e($b['kind']) ?></td>
          <td class="nowrap"><?= e(human_bytes($b['size'])) ?></td>
          <td class="nowrap"><?= e(date('j M Y, H:i', $b['time'])) ?></td>
          <td class="acts">
            <a class="btn" href="?download=<?= rawurlencode($b['name']) ?>"><?= icon('download') ?>Download<span class="sr"> <?= e($b['name']) ?></span></a>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="name" value="<?= e($b['name']) ?>">
              <?php if ($b['kind'] === 'App files'): ?><button class="btn btn-ghost" name="action" value="restore" data-confirm="Put back the app files from <?= e($b['name']) ?>? Your recipes and photos are not affected.">Restore</button><?php endif; ?>
              <button class="btn btn-ghost btn-danger" name="action" value="delete" data-confirm="Delete this backup?">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="help small" style="margin-top: 12px">To restore a database backup, import the <code>.sql.gz</code> file in cPanel → phpMyAdmin → Import. To restore photos, unzip the photos backup into the app folder.</p>
  <?php endif; ?>
</section>
<?php admin_foot();
