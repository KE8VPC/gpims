<?php // Called by the GitHub deploy workflow after the files are uploaded. Backs up the database, applies pending
// database updates, removes install.php and reports the result as JSON. Needs the deploy key from Settings.
define('GP_API', 1);
require __DIR__.'/inc/bootstrap.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
function dh_out($http, $d){ http_response_code($http); echo json_encode($d, JSON_UNESCAPED_SLASHES); exit; }
$ip = $_SERVER['REMOTE_ADDR'] ?? ''; $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
if (!$https && !in_array($ip, ['127.0.0.1', '::1'], true)) dh_out(400, ['ok' => false, 'error' => 'https_required']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') dh_out(405, ['ok' => false, 'error' => 'post_only']);
$hash = (string)setting('deploy_key_hash', '');
$bad = (int)q("SELECT COUNT(*) FROM login_attempts WHERE username='deploy' AND ip=? AND created_at > (NOW() - INTERVAL 15 MINUTE)", [$ip])->fetchColumn();
if ($bad >= 10) dh_out(429, ['ok' => false, 'error' => 'too_many_attempts']);
$key = trim((string)($_SERVER['HTTP_X_DEPLOY_KEY'] ?? ''));
if ($hash === '' || $key === '' || !hash_equals($hash, hash('sha256', $key))) { q("INSERT INTO login_attempts(username,ip) VALUES('deploy',?)", [$ip]); dh_out(403, ['ok' => false, 'error' => 'forbidden']); }
@set_time_limit(300);
$res = ['ok' => true, 'app_version' => APP_VERSION, 'db_before' => db_version(), 'backup' => null, 'applied' => []];
try {
  $pending = []; foreach (migrations() as $v => $m) if ($v > db_version()) $pending[] = $v;
  if ($pending) {
    $b = backup_make_file('gpims-predeploy');
    if ($b === '') dh_out(500, array_merge($res, ['ok' => false, 'error' => 'backup_failed', 'message' => 'Could not write the database backup, so nothing was upgraded.']));
    $res['backup'] = $b;
    $res['applied'] = run_upgrade();
    foreach (array_slice(array_values(array_filter(backup_files(), fn($f) => strpos($f['name'], 'gpims-predeploy-') === 0)), 5) as $f) @unlink(backup_dir().'/'.$f['name']);
  }
  $res['db_after'] = db_version();
  $res['install_php_removed'] = false; if (is_file(__DIR__.'/install.php')) $res['install_php_removed'] = @unlink(__DIR__.'/install.php');
  set_setting('deploy_last', date('Y-m-d H:i').' v'.APP_VERSION.' db '.db_version().($pending ? ' (upgraded, backup '.$res['backup'].')' : ''));
  q("INSERT INTO audit_log(user_id,action,details) VALUES(NULL,?,?)", ['auto_deploy', mb_substr('v'.APP_VERSION.' db '.$res['db_before'].'->'.$res['db_after'].($res['backup'] ? ' backup '.$res['backup'] : ''), 0, 250)]);
  dh_out(200, $res);
} catch (Throwable $e) {
  error_log('GPIMS deploy_hook: '.$e->getMessage()); set_setting('deploy_last', date('Y-m-d H:i').' FAILED: '.mb_substr($e->getMessage(), 0, 120));
  dh_out(500, array_merge($res, ['ok' => false, 'error' => 'upgrade_failed', 'message' => $e->getMessage(), 'note' => 'Restore the predeploy backup from Admin > Backup if the site misbehaves.']));
}
