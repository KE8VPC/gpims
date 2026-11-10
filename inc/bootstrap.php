<?php
require_once __DIR__.'/version.php';
$root = dirname(__DIR__);
if (!file_exists("$root/config.php")) { header('Location: install.php'); exit; }
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
if (session_status() === PHP_SESSION_NONE) {
  session_name('gpims_sid');
  session_set_cookie_params(['lifetime'=>0, 'path'=>rtrim(dirname($_SERVER['SCRIPT_NAME']), '/').'/', 'secure'=>$https, 'httponly'=>true, 'samesite'=>'Lax']);
  session_start();
}
if (!headers_sent()) {
  header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: SAMEORIGIN'); header('Referrer-Policy: same-origin');
  header('Permissions-Policy: camera=(self), microphone=(), geolocation=(), payment=(), publickey-credentials-get=(self), publickey-credentials-create=(self)');
  header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; font-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com data:; img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self'; worker-src 'self' blob:; manifest-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
  if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') header('Strict-Transport-Security: max-age=15552000');
}
$cfg = require "$root/config.php";
$pdo = new PDO("mysql:host={$cfg['host']};dbname={$cfg['name']};charset=utf8mb4", $cfg['user'], $cfg['pass'],
  [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
set_exception_handler(function($e){
  $prev = ($e instanceof PDOException && (string)$e->getCode() === '22001');
  if ($prev) { http_response_code(400); exit('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><div class="container py-5 text-center" style="max-width:520px"><h1 class="h4">That value is too long</h1><p class="text-muted">One of the fields has more text than it can hold. Go back, shorten it and try again.</p><a class="btn btn-primary" href="javascript:history.back()">Go back</a></div>'); }
  error_log('GPIMS: '.get_class($e).': '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
  http_response_code(500); exit('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><div class="container py-5 text-center" style="max-width:520px"><h1 class="h4">Something went wrong</h1><p class="text-muted">The error was logged. Try again, and if it keeps happening ask an administrator to check System health.</p><a href="index.php">Home</a></div>');
});
const ROLES = ['vendor'=>0,'viewer'=>1,'user'=>2,'manager'=>3,'admin'=>4];
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function q($sql, $p = []){ global $pdo; $s = $pdo->prepare($sql); $s->execute($p); return $s; }
function me(){ return $_SESSION['user'] ?? null; }
function level(){ return me() ? ROLES[me()['role']] : 0; }
function need($role){
  if (!me()) { header('Location: login.php'); exit; }
  if (level() < ROLES[$role]) { http_response_code(403); exit('Access denied.'); }
}
function csrf(){ if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function csrf_field(){ return '<input type="hidden" name="csrf" value="'.csrf().'">'; }
function csrf_check(){
  if ($_SERVER['REQUEST_METHOD']==='POST' && !$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 1024) { http_response_code(413); exit('That upload was larger than this server allows (post_max_size). Go back and try a smaller photo.'); }
  if ($_SERVER['REQUEST_METHOD']==='POST' && !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) { http_response_code(400); exit('Bad request (CSRF). Reload the page and try again.'); }
}
function flash($msg = null, $type = 'success'){
  if ($msg !== null) { $_SESSION['flash'][] = [$type, $msg]; return; }
  $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f;
}
function audit($action, $details = ''){ q("INSERT INTO audit_log(user_id,action,details) VALUES(?,?,?)", [me()['id'] ?? null, $action, mb_substr($details,0,250)]); }
function redirect($url){ header("Location: $url"); exit; }
function status_badge($s){
  $c = ['available'=>'success','checked_out'=>'warning','maintenance'=>'info','retired'=>'secondary'][$s] ?? 'light';
  return '<span class="badge text-bg-'.$c.'">'.h(str_replace('_',' ',$s)).'</span>';
}
$SETTINGS = [];
function setting($k, $d = ''){ global $SETTINGS; return (isset($SETTINGS[$k]) && $SETTINGS[$k] !== '') ? $SETTINGS[$k] : $d; }
function set_setting($k, $v){ global $SETTINGS; q("INSERT INTO settings(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [$k,$v]); $SETTINGS[$k] = $v; }
require __DIR__.'/migrations.php';
require __DIR__.'/photos.php';
require __DIR__.'/history.php';
require __DIR__.'/access.php';
require __DIR__.'/codes.php';
require __DIR__.'/alerts.php';
require __DIR__.'/issues.php';
require __DIR__.'/vehicles.php';
require __DIR__.'/documents.php';
require __DIR__.'/import.php';
require __DIR__.'/planning.php';
require __DIR__.'/mail.php';
require __DIR__.'/notify.php';
require __DIR__.'/digest.php';
require __DIR__.'/reports.php';
require __DIR__.'/trash.php';
require __DIR__.'/backup.php';
require __DIR__.'/lookup.php';
try { foreach (q("SELECT k,v FROM settings")->fetchAll() as $r) $SETTINGS[$r['k']] = $r['v']; } catch (Exception $e) { $SETTINGS = []; }
$db_outdated = db_version() < APP_DB_VERSION;
$page = basename($_SERVER['SCRIPT_NAME']);
if ($db_outdated && !in_array($page, ['login.php','logout.php','upgrade.php','health.php'])) {
  if (me() && me()['role'] === 'admin') redirect('upgrade.php');
  http_response_code(503);
  exit('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><div class="container py-5 text-center" style="max-width:520px"><h1 class="h3">System update in progress</h1><p class="text-muted">An administrator needs to finish a quick update. Please check back shortly.</p><a href="login.php">Back to sign in</a></div>');
}
if (!$db_outdated) $cfg['app_name'] = setting('org_name', $cfg['app_name']);
$tzs = setting('timezone', '');
if ($tzs !== '' && in_array($tzs, DateTimeZone::listIdentifiers(), true)) date_default_timezone_set($tzs);
$tzoff = (new DateTime('now'))->format('P');
if (preg_match('/^[+-]\d\d:\d\d$/', $tzoff)) { try { $pdo->exec("SET time_zone = '$tzoff'"); } catch (Exception $e) {} }
if (me() && !$db_outdated) {
  $fresh = q("SELECT * FROM users WHERE id=? AND active=1", [me()['id']])->fetch();
  if (!$fresh) { session_unset(); session_destroy(); session_start(); flash('Your account is no longer active.', 'danger'); }
  else { unset($fresh['pass_hash']); $_SESSION['user'] = $fresh; }
}
$timeout = (int)setting('session_timeout_min', 120);
if (me()) {
  if ($timeout > 0 && isset($_SESSION['last']) && time() - $_SESSION['last'] > $timeout * 60) {
    session_unset(); session_destroy(); session_start(); flash('You were signed out after being idle.', 'warning');
  } else $_SESSION['last'] = time();
}
csrf_check();
if (me() && !$db_outdated) mail_maybe_flush();
