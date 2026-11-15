<?php require 'inc/bootstrap.php'; require 'inc/webauthn.php';
$err = ''; $need2fa = false; $max = (int)setting('max_login_attempts', 5); $mins = max(1, (int)setting('lockout_minutes', 15)); $ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $uname = trim($_POST['username'] ?? ''); $locked = false;
  if (isset($_POST['code']) && !empty($_SESSION['pending2fa'])) $uname = (string)q("SELECT username FROM users WHERE id=?", [(int)$_SESSION['pending2fa']['id']])->fetchColumn();
  if (!$db_outdated && $max > 0) {
    q("DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)");
    $byUser = (int)q("SELECT COUNT(*) FROM login_attempts WHERE created_at > (NOW() - INTERVAL $mins MINUTE) AND username=?", [$uname])->fetchColumn();
    $byIp = (int)q("SELECT COUNT(*) FROM login_attempts WHERE created_at > (NOW() - INTERVAL $mins MINUTE) AND ip=?", [$ip])->fetchColumn();
    $locked = $byUser >= $max || $byIp >= $max * 4;
  }
  if ($locked) { $err = "Too many failed attempts. Try again in $mins minutes, or ask an administrator."; }
  elseif (isset($_POST['code']) && !empty($_SESSION['pending2fa']) && $_SESSION['pending2fa']['t'] > time() - 300) {   // step 2: authenticator code
    $pu = q("SELECT * FROM users WHERE id=? AND active=1", [(int)$_SESSION['pending2fa']['id']])->fetch(); $uname = $pu['username'] ?? '';
    if ($pu && $pu['totp_enabled'] && totp_user_verify($pu, $_POST['code'])) { unset($_SESSION['pending2fa']); login_finish($pu); }
    if (!$db_outdated) q("INSERT INTO login_attempts(username,ip) VALUES(?,?)", [$uname, $ip]);
    $err = 'That code was not accepted. Check your authenticator app, or use a recovery code.'; $need2fa = true;
  }
  else {
    unset($_SESSION['pending2fa']);
    $u = q("SELECT * FROM users WHERE username=? AND active=1", [$uname])->fetch();
    if ($u && password_verify($_POST['password'] ?? '', $u['pass_hash'])) {
      if (!$db_outdated && !empty($u['totp_enabled'])) { session_regenerate_id(true); $_SESSION['pending2fa'] = ['id' => (int)$u['id'], 't' => time()]; $need2fa = true; }
      else login_finish($u);
    } else {
      if (!$db_outdated) q("INSERT INTO login_attempts(username,ip) VALUES(?,?)", [$uname, $ip]);
      $err = 'Invalid username or password.';
    }
  }
}
function login_finish($u){
  global $db_outdated, $uname; session_regenerate_id(true); unset($u['pass_hash'], $u['totp_secret'], $u['totp_recovery']); $_SESSION['user'] = $u; $_SESSION['last'] = time();
  if (!$db_outdated) { q("DELETE FROM login_attempts WHERE username=?", [$u['username']]); q("UPDATE users SET last_login=NOW() WHERE id=?", [$u['id']]); }
  audit('login'); redirect(($u['role'] ?? '')==='vendor' ? 'supply.php' : 'index.php');
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sign in</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link rel="manifest" href="manifest.php"><meta name="theme-color" content="#0d6efd"><link rel="apple-touch-icon" href="assets/icon-180.png"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="<?=h($cfg['app_name'])?>"></head><body class="bg-light">
<div class="container py-5" style="max-width:380px"><h1 class="h3 text-center mb-4"><?=h($cfg['app_name'])?></h1>
<?php foreach (flash() as [$t,$m]): ?><div class="alert alert-<?=h($t)?>"><?=h($m)?></div><?php endforeach; ?>
<?php if($err): ?><div class="alert alert-danger"><?=h($err)?></div><?php endif; ?>
<?php if ($need2fa): ?><form method="post" class="card card-body gap-3"><?=csrf_field()?><div class="small text-muted">Enter the 6-digit code from your authenticator app. Lost your phone? Use one of your recovery codes instead.</div>
<input class="form-control form-control-lg text-center" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" autofocus required><button class="btn btn-primary">Verify</button><a class="small text-center" href="login.php">Start over</a></form>
<?php else: ?><form method="post" class="card card-body gap-3"><?=csrf_field()?>
<input class="form-control" name="username" placeholder="Username" autocomplete="username webauthn" autofocus required>
<input class="form-control" type="password" name="password" placeholder="Password" required>
<button class="btn btn-primary">Sign in</button><a class="small text-center" href="forgot.php">Forgot your password?</a></form><?php endif; ?>
<?php if (passkeys_ok() && !$need2fa): ?><div class="text-center text-muted small my-3">or</div><button type="button" id="pkBtn" class="btn btn-outline-primary w-100"><i class="bi bi-fingerprint"></i> Sign in with a passkey</button><div id="pkMsg" class="small text-danger mt-2 text-center"></div>
<script>window.GP_CSRF=<?=json_encode(csrf())?>;</script><script src="assets/passkey.js?v=<?=APP_VERSION?>"></script>
<script>document.getElementById('pkBtn').onclick=function(){var m=document.getElementById('pkMsg');m.textContent='';
if(!GPPasskey.supported()){m.textContent='This browser does not support passkeys.';return;}
GPPasskey.login().then(function(r){if(r.ok)location.href=r.redirect;else m.textContent=r.error;}).catch(function(e){m.textContent=e&&e.name==='NotAllowedError'?'Cancelled, or no passkey for this site was found on this device.':(e&&e.message)||'Passkey sign-in failed.';});};</script>
<script>if(window.GPPasskey)GPPasskey.autofill().then(function(r){if(r&&r.ok)location.href=r.redirect;}).catch(function(){});</script><?php endif; ?>
</div></body></html>
