<?php require 'inc/bootstrap.php';
$t = preg_replace('/[^a-f0-9]/', '', $_GET['t'] ?? $_POST['t'] ?? ''); $err = ''; $done = false; $row = null;
if (!$db_outdated && strlen($t) === 64) $row = q("SELECT r.*,u.username FROM password_resets r JOIN users u ON u.id=r.user_id WHERE r.token_hash=? AND r.used_at IS NULL AND r.expires_at > NOW() AND u.active=1", [hash('sha256', $t)])->fetch();
if ($row && $_SERVER['REQUEST_METHOD']==='POST') {
  if (strlen($_POST['new']) < 8) $err = 'Password must be at least 8 characters.';
  elseif ($_POST['new'] !== $_POST['confirm']) $err = 'The two passwords do not match.';
  else {
    q("UPDATE users SET pass_hash=? WHERE id=?", [password_hash($_POST['new'], PASSWORD_DEFAULT), $row['user_id']]);
    q("UPDATE password_resets SET used_at=NOW() WHERE id=?", [$row['id']]); q("DELETE FROM password_resets WHERE user_id=? AND used_at IS NULL", [$row['user_id']]);
    q("DELETE FROM login_attempts WHERE username=?", [$row['username']]);
    $GLOBALS['pdo']->prepare("INSERT INTO audit_log(user_id,action,details) VALUES(?,?,?)")->execute([$row['user_id'], 'password_reset', 'via email link']);
    flash('Password updated. You can sign in now.'); redirect('login.php');
  }
}
?><!doctype html><html lang="en" data-bs-theme="light"><head><meta charset="utf-8"><script>try{var t=localStorage.getItem('gpims-theme');if(t)document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}</script><meta name="viewport" content="width=device-width,initial-scale=1"><title>Choose a new password</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"></head><body class="bg-body-tertiary"><button type="button" id="themeBtn" class="btn btn-sm btn-outline-secondary position-fixed top-0 end-0 m-2" title="Dark mode" aria-label="Toggle dark mode"><i class="bi bi-moon-stars"></i></button><script>document.getElementById('themeBtn').onclick=function(){var r=document.documentElement,n=r.getAttribute('data-bs-theme')==='dark'?'light':'dark';r.setAttribute('data-bs-theme',n);try{localStorage.setItem('gpims-theme',n);}catch(e){}};</script><div class="container py-5" style="max-width:400px"><h1 class="h3 text-center mb-4"><?=h($cfg['app_name'])?></h1>
<?php if (!$row): ?><div class="alert alert-danger">This link is invalid or has expired. <a href="forgot.php">Request a new one</a>.</div>
<?php else: ?><?php if ($err): ?><div class="alert alert-danger"><?=h($err)?></div><?php endif; ?>
<form method="post" class="card card-body gap-3"><?=csrf_field()?><input type="hidden" name="t" value="<?=h($t)?>"><div class="small text-muted">Account: <strong><?=h($row['username'])?></strong></div>
<input class="form-control" type="password" name="new" placeholder="New password (8+ characters)" required autofocus><input class="form-control" type="password" name="confirm" placeholder="Repeat new password" required><button class="btn btn-primary">Save password</button></form><?php endif; ?>
<div class="text-center mt-3"><a href="login.php">Back to sign in</a></div></div></body></html>
