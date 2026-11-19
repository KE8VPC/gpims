<?php require 'inc/bootstrap.php'; need('admin'); $title = 'Updates'; $err = ''; $msgs = []; $info = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $a = $_POST['action'] ?? '';
  try {
    if ($a === 'prefs') {
      $r = trim($_POST['repo'] ?? ''); if (!preg_match('#^[A-Za-z0-9_.-]{1,60}/[A-Za-z0-9_.-]{1,80}$#', $r)) throw new Exception('Repository must look like owner/name.');
      set_setting('update_repo', $r); audit('update_prefs', $r); flash('Saved.'); header('Location: update.php'); exit;
    }
    if ($a === 'check') { $info = upd_check(); }
    if ($a === 'install') {
      $info = upd_check(); if (!$info['newer']) throw new Exception('You already have the latest version.'); if ($info['zip'] === '') throw new Exception('That release has no zip file.');
      @set_time_limit(300); $tmp = tempnam(sys_get_temp_dir(), 'gpu'); try { upd_http($info['zip'], $tmp); $msgs = upd_install($tmp); } finally { @unlink($tmp); }
      $msgs = array_merge($msgs, run_upgrade() ?: []); audit('app_update', $info['tag'].' from v'.APP_VERSION); set_setting('update_installed', date('Y-m-d H:i').' '.$info['tag']);
      $msgs[] = 'Update to '.$info['tag'].' complete. Reload the page to see the new version.';
    }
  } catch (Exception $e) { $err = $e->getMessage(); }
}
$last = json_decode((string)setting('update_last_check', ''), true);
include 'inc/header.php'; ?>
<h1 class="h3 mb-3">Updates</h1>
<div class="card mb-3" style="max-width:640px"><div class="card-body">
<div class="d-flex justify-content-between"><span>Installed version</span><strong><?=h(APP_VERSION)?></strong></div>
<div class="d-flex justify-content-between"><span>Source</span><span><?=h(upd_repo())?></span></div>
<?php if ($last): ?><div class="d-flex justify-content-between"><span>Last check</span><span><?=h($last['at'])?> (latest <?=h($last['tag'])?>)</span></div><?php endif; ?>
<?php if (setting('update_installed')): ?><div class="d-flex justify-content-between"><span>Last update installed</span><span><?=h(setting('update_installed'))?></span></div><?php endif; ?>
<?php if (setting('deploy_last')): ?><div class="d-flex justify-content-between"><span>Last automatic deploy</span><span><?=h(setting('deploy_last'))?></span></div><?php endif; ?></div></div>
<?php if ($err): ?><div class="alert alert-danger" style="max-width:640px"><?=h($err)?></div><?php endif; ?>
<?php foreach ($msgs as $m): ?><div class="alert alert-success py-2" style="max-width:640px"><?=h($m)?></div><?php endforeach; ?>
<?php if ($info): ?><div class="card mb-3" style="max-width:640px"><div class="card-body">
<h2 class="h6"><?=h($info['name'])?> <?php if ($info['newer']): ?><span class="badge bg-warning text-dark">newer</span><?php else: ?><span class="badge bg-success">up to date</span><?php endif; ?></h2>
<?php if ($info['notes'] !== ''): ?><pre class="small mb-2" style="white-space:pre-wrap"><?=h(mb_substr($info['notes'], 0, 3000))?></pre><?php endif; ?>
<?php if ($info['newer'] && !$msgs): ?><form method="post" onsubmit="return confirm('Back up the database and install this update now?')"><?=csrf_field()?><input type="hidden" name="action" value="install"><button class="btn btn-primary">Back up and install <?=h($info['tag'])?></button></form><?php endif; ?></div></div><?php endif; ?>
<form method="post" class="mb-4"><?=csrf_field()?><input type="hidden" name="action" value="check"><button class="btn btn-outline-primary">Check for updates</button></form>
<p class="text-muted small" style="max-width:640px">An update backs up the database, replaces the program files with the release, and applies any database changes. Your settings file, uploaded photos and backups are never touched. If you deploy from GitHub automatically, you do not need this page.</p>
<form method="post" class="card card-body" style="max-width:640px"><?=csrf_field()?><input type="hidden" name="action" value="prefs"><label class="form-label">GitHub repository (owner/name)</label><div class="input-group"><input class="form-control" name="repo" value="<?=h(upd_repo())?>"><button class="btn btn-outline-secondary">Save</button></div></form>
<?php include 'inc/footer.php'; ?>
