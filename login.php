<?php require 'inc/bootstrap.php'; require 'inc/webauthn.php';
$err = ''; $max = (int)setting('max_login_attempts', 5); $mins = max(1, (int)setting('lockout_minutes', 15)); $ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $uname = trim($_POST['username']); $locked = false;
  if (!$db_outdated && $max > 0) {
    q("DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)");
    $byUser = (int)q("SELECT COUNT(*) FROM login_attempts WHERE created_at > (NOW() - INTERVAL $mins MINUTE) AND username=?", [$uname])->fetchColumn();
    $byIp = (int)q("SELECT COUNT(*) FROM login_attempts WHERE created_at > (NOW() - INTERVAL $mins MINUTE) AND ip=?", [$ip])->fetchColumn();
    $locked = $byUser >= $max || $byIp >= $max * 4;
  }
  if ($locked) { $err = "Too many failed attempts. Try again in $mins minutes, or ask an administrator."; }
  else {
    $u = q("SELECT * FROM users WHERE username=? AND active=1", [$uname])->fetch();
    if ($u && password_verify($_POST['password'], $u['pass_hash'])) {
      session_regenerate_id(true); unset($u['pass_hash']); $_SESSION['user'] = $u; $_SESSION['last'] = time();
      if (!$db_outdated) { q("DELETE FROM login_attempts WHERE username=?", [$uname]); q("UPDATE users SET last_login=NOW() WHERE id=?", [$u['id']]); }
      audit('login'); redirect(($u['role'] ?? '')==='vendor' ? 'supply.php' : 'index.php');
    }
    if (!$db_outdated) q("INSERT INTO login_attempts(username,ip) VALUES(?,?)", [$uname, $ip]);
    $err = 'Invalid username or password.';
  }
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sign in</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link rel="manifest" href="manifest.php"><meta name="theme-color" content="#0d6efd"><link rel="apple-touch-icon" href="assets/icon-180.png"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="<?=h($cfg['app_name'])?>"></head><body class="bg-light">
<div class="container py-5" style="max-width:380px"><h1 class="h3 text-center mb-4"><?=h($cfg['app_name'])?></h1>
<?php foreach (flash() as [$t,$m]): ?><div class="alert alert-<?=h($t)?>"><?=h($m)?></div><?php endforeach; ?>
<?php if($err): ?><div class="alert alert-danger"><?=h($err)?></div><?php endif; ?>
<form method="post" class="card card-body gap-3"><?=csrf_field()?>
<input class="form-control" name="username" placeholder="Username" autocomplete="username webauthn" autofocus required>
<input class="form-control" type="password" name="password" placeholder="Password" required>
<button class="btn btn-primary">Sign in</button><a class="small text-center" href="forgot.php">Forgot your password?</a></form>
<?php if (passkeys_ok()): ?><div class="text-center text-muted small my-3">or</div><button type="button" id="pkBtn" class="btn btn-outline-primary w-100"><i class="bi bi-fingerprint"></i> Sign in with a passkey</button><div id="pkMsg" class="small text-danger mt-2 text-center"></div>
<script>window.GP_CSRF=<?=json_encode(csrf())?>;</script><script src="assets/passkey.js?v=<?=APP_VERSION?>"></script>
<script>document.getElementById('pkBtn').onclick=function(){var m=document.getElementById('pkMsg');m.textContent='';
if(!GPPasskey.supported()){m.textContent='This browser does not support passkeys.';return;}
GPPasskey.login().then(function(r){if(r.ok)location.href=r.redirect;else m.textContent=r.error;}).catch(function(e){m.textContent=e&&e.name==='NotAllowedError'?'Cancelled, or no passkey for this site was found on this device.':(e&&e.message)||'Passkey sign-in failed.';});};</script>
<script>if(window.GPPasskey)GPPasskey.autofill().then(function(r){if(r&&r.ok)location.href=r.redirect;}).catch(function(){});</script><?php endif; ?>
</div></body></html>
