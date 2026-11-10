<?php require 'inc/bootstrap.php'; need('admin'); $title='Backup and restore';
$me = me(); $err = null;
if (isset($_GET['dl'])) { // download a copy stored on the server
  $n = (string)$_GET['dl']; if (!backup_name_ok($n)) { http_response_code(404); exit('Not found.'); }
  audit('backup_download', $n); while (ob_get_level()) ob_end_clean(); header('Content-Type: application/octet-stream'); header('Content-Disposition: attachment; filename="'.$n.'"'); header('Content-Length: '.filesize(backup_dir().'/'.$n)); readfile(backup_dir().'/'.$n); exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $a = $_POST['action'] ?? 'download'; $stamp = date('Ymd-His');
  if ($a === 'settings') {
    set_setting('auto_backup', !empty($_POST['auto_backup']) ? '1' : '0'); set_setting('backup_keep', (string)max(1, min(60, (int)($_POST['backup_keep'] ?? 7)))); flash('Backup settings saved.'); redirect('backup.php');
  }
  if ($a === 'make') { $n = backup_make_file('gpims-manual'); if ($n) { set_setting('last_backup', date('Y-m-d H:i')); audit('backup', 'saved on server '.$n); flash('Backup saved on the server: '.$n); } else flash('Could not write to the backups folder. Check its permissions.', 'danger'); redirect('backup.php'); }
  if ($a === 'delete') { $n = (string)($_POST['name'] ?? ''); if (backup_name_ok($n)) { @unlink(backup_dir().'/'.$n); audit('backup_delete', $n); flash('Deleted '.$n.'.'); } redirect('backup.php'); }
  if ($a === 'restore') {
    $pw = (string)($_POST['password'] ?? ''); $row = q("SELECT pass_hash FROM users WHERE id=?", [$me['id']])->fetch();
    if (trim($_POST['confirm'] ?? '') !== 'RESTORE') $err = 'Type RESTORE in capital letters to confirm.';
    elseif (!$row || !password_verify($pw, $row['pass_hash'])) $err = 'Your password was not correct.';
    else {
      $path = null; $isZip = false; $srv = (string)($_POST['server_file'] ?? '');
      if ($srv !== '') { if (!backup_name_ok($srv)) $err = 'That backup file was not found.'; else $path = backup_dir().'/'.$srv; }
      elseif (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) { $path = $_FILES['file']['tmp_name']; $isZip = (bool)preg_match('/\.zip$/i', $_FILES['file']['name']); }
      elseif (!empty($_FILES['file']) && in_array($_FILES['file']['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) $err = 'That file is larger than this server allows ('.ini_get('upload_max_filesize').'). Use "Database only", or put the .sql file in the backups folder with FTP and pick it from the list.';
      else $err = 'Choose a backup file first.';
      if ($path && !$err) {
        $uname = $me['username'];
        [$ok, $msg] = restore_run($path, $isZip);
        if ($ok) { try { audit('restore', $msg); } catch (Throwable $e) {} $_SESSION = []; @session_regenerate_id(true); flash('Restore finished. '.$msg.' Please sign in again.'); redirect('login.php'); }
        else $err = $msg;
      }
    }
  }
  if ($a === 'download' || $a === 'full' || $a === 'sql') {
    @set_time_limit(300); @ini_set('memory_limit', '256M'); $what = $_POST['what'] ?? 'sql';
    set_setting('last_backup', date('Y-m-d H:i')); audit('backup', $what); while (ob_get_level()) ob_end_clean();
    if ($what === 'full' && class_exists('ZipArchive')) {
      $tmp = tempnam(sys_get_temp_dir(), 'gpb'); $sqlf = $tmp.'.sql'; $o = fopen($sqlf, 'w'); sql_dump($o); fclose($o);
      $z = new ZipArchive(); $z->open($tmp.'.zip', ZipArchive::CREATE|ZipArchive::OVERWRITE); $z->addFile($sqlf, 'database.sql');
      $up = __DIR__.'/uploads'; foreach (glob($up.'/*') as $f) if (is_file($f) && basename($f) !== 'index.html' && basename($f) !== '.htaccess') $z->addFile($f, 'uploads/'.basename($f));
      $z->close(); header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="gpims-full-backup-'.$stamp.'.zip"'); header('Content-Length: '.filesize($tmp.'.zip')); readfile($tmp.'.zip'); @unlink($sqlf); @unlink($tmp.'.zip'); @unlink($tmp); exit;
    }
    header('Content-Type: application/sql; charset=utf-8'); header('Content-Disposition: attachment; filename="gpims-database-'.$stamp.'.sql"'); $o = fopen('php://output', 'w'); sql_dump($o); exit;
  }
}
$last = setting('last_backup', ''); $nph = (int)q("SELECT COUNT(*) FROM item_photos")->fetchColumn(); $nd = (int)q("SELECT COUNT(*) FROM documents")->fetchColumn();
$files = backup_files(); $auto = setting('auto_backup', '0') === '1'; $keep = (int)setting('backup_keep', 7) ?: 7; $aerr = setting('auto_backup_error', '');
$kb = fn($b) => $b >= 1048576 ? round($b / 1048576, 1).' MB' : max(1, round($b / 1024)).' KB';
include 'inc/header.php'; ?>
<h1 class="h4 mb-2">Backup and restore</h1>
<?php if($err): ?><div class="alert alert-danger"><?=h($err)?></div><?php endif; ?>
<p class="text-muted">Keep a copy of your data somewhere safe (your computer, a cloud drive). Last backup: <strong><?=$last?h($last):'never'?></strong>.</p>
<div class="row g-3 mb-3">
<div class="col-md-6"><div class="card h-100"><div class="card-body"><h2 class="h6">Everything (recommended)</h2><p class="small text-muted mb-2">A zip with the full database plus all photos and documents (<?=$nph?> photo<?=$nph==1?'':'s'?>, <?=$nd?> document<?=$nd==1?'':'s'?>).</p>
<form method="post"><?=csrf_field()?><input type="hidden" name="what" value="full"><button class="btn btn-primary" <?=class_exists('ZipArchive')?'':'disabled'?>><i class="bi bi-download"></i> Download full backup</button><?php if(!class_exists('ZipArchive')) echo ' <span class="small text-danger">Zip is not available on this server.</span>'; ?></form></div></div></div>
<div class="col-md-6"><div class="card h-100"><div class="card-body"><h2 class="h6">Database only</h2><p class="small text-muted mb-2">A single .sql file. Smaller and faster. Photos and documents are not included.</p>
<form method="post"><?=csrf_field()?><input type="hidden" name="what" value="sql"><button class="btn btn-outline-primary"><i class="bi bi-download"></i> Download database</button></form></div></div></div></div>

<div class="card mb-3"><div class="card-header fw-bold">Copies kept on the server</div><div class="card-body">
<form method="post" class="row g-2 align-items-end mb-3"><?=csrf_field()?><input type="hidden" name="action" value="settings">
<div class="col-auto"><div class="form-check"><input class="form-check-input" type="checkbox" name="auto_backup" id="ab" value="1" <?=$auto?'checked':''?>><label class="form-check-label" for="ab">Make an automatic database backup once a day</label></div></div>
<div class="col-auto"><label class="form-label small mb-0">Keep the newest</label><input class="form-control form-control-sm d-inline-block" style="width:70px" type="number" min="1" max="60" name="backup_keep" value="<?=$keep?>"> <span class="small">automatic copies</span></div>
<div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Save</button></div></form>
<?php if($aerr): ?><div class="alert alert-warning py-2 small"><?=h($aerr)?></div><?php endif; ?>
<form method="post" class="mb-3"><?=csrf_field()?><input type="hidden" name="action" value="make"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-save"></i> Save a copy on the server now</button></form>
<div class="table-responsive"><table class="table table-sm align-middle mb-0"><tbody>
<?php foreach($files as $f): ?><tr><td><?=h($f['name'])?></td><td class="small text-muted"><?=h(date('Y-m-d H:i',$f['time']))?> · <?=$kb($f['size'])?></td><td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="backup.php?dl=<?=urlencode($f['name'])?>"><i class="bi bi-download"></i></a>
<form method="post" class="d-inline"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="name" value="<?=h($f['name'])?>"><button class="btn btn-sm btn-outline-danger" data-confirm="Delete this backup file?"><i class="bi bi-trash"></i></button></form></td></tr>
<?php endforeach; if(!$files) echo '<tr><td class="text-muted">No copies yet.</td></tr>'; ?></tbody></table></div>
<div class="form-text mt-2">These live on the same hosting account as the site, so they protect against mistakes, not against losing the account. Download a copy now and then.</div></div></div>

<div class="card border-danger"><div class="card-header fw-bold text-danger"><i class="bi bi-exclamation-triangle"></i> Restore from a backup</div><div class="card-body">
<p class="small">Restoring <strong>replaces all current data</strong> (items, people, history, users and settings) with what is in the backup. A safety copy of the current data is saved on the server first. You will be signed out afterwards. Use a backup made by GPIMS (.sql, .sql.gz, or the full .zip).</p>
<form method="post" enctype="multipart/form-data" class="row g-2"><?=csrf_field()?><input type="hidden" name="action" value="restore">
<div class="col-md-6"><label class="form-label small mb-0">Upload a backup file</label><input class="form-control" type="file" name="file" accept=".sql,.gz,.zip"><div class="form-text">This server accepts up to <?=h(ini_get('upload_max_filesize'))?> per file.</div></div>
<div class="col-md-6"><label class="form-label small mb-0">...or pick one stored on the server</label><select class="form-select" name="server_file"><option value="">(none)</option><?php foreach($files as $f): ?><option value="<?=h($f['name'])?>"><?=h($f['name'])?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><label class="form-label small mb-0">Your password</label><input class="form-control" type="password" name="password" autocomplete="current-password" required></div>
<div class="col-md-4"><label class="form-label small mb-0">Type RESTORE to confirm</label><input class="form-control" name="confirm" autocomplete="off" required></div>
<div class="col-md-4 d-flex align-items-end"><button class="btn btn-danger w-100" data-confirm="Replace ALL current data with this backup?">Restore now</button></div></form></div></div>
<?php include 'inc/footer.php'; ?>
