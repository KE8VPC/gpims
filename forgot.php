<?php require 'inc/bootstrap.php';
$msg = ''; $ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($_SERVER['REQUEST_METHOD']==='POST' && !$db_outdated) {
  $id = trim($_POST['identifier'] ?? '');
  $n = (int)q("SELECT COUNT(*) FROM login_attempts WHERE username='reset' AND ip=? AND created_at > (NOW() - INTERVAL 15 MINUTE)", [$ip])->fetchColumn();
  if ($id !== '' && $n < 5 && mail_enabled()) {
    q("INSERT INTO login_attempts(username,ip) VALUES('reset',?)", [$ip]);
    $users = q("SELECT * FROM users WHERE active=1 AND email IS NOT NULL AND email<>'' AND (username=? OR email=?) LIMIT 3", [$id, $id])->fetchAll();
    foreach ($users as $u) { send_account_email($u, false); }
  }
  $msg = 'If that account exists and has an email address, a reset link has been sent. It works for one hour.';
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Forgot password</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><div class="container py-5" style="max-width:400px"><h1 class="h3 text-center mb-4"><?=h($cfg['app_name'])?></h1>
<?php if (!mail_enabled()): ?><div class="alert alert-warning">Email is not set up on this system yet. Please ask an administrator to reset your password.</div>
<?php elseif ($msg): ?><div class="alert alert-success"><?=h($msg)?></div>
<?php else: ?><form method="post" class="card card-body gap-3"><?=csrf_field()?><p class="text-muted small mb-0">Enter your username or email address and we will send you a link to choose a new password.</p><input class="form-control" name="identifier" placeholder="Username or email" autofocus required><button class="btn btn-primary">Send reset link</button></form><?php endif; ?>
<div class="text-center mt-3"><a href="login.php">Back to sign in</a></div></div></body></html>
