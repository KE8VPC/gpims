<?php require 'inc/bootstrap.php'; require 'inc/webauthn.php'; need('vendor'); $title='Account';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (($_POST['action'] ?? '') === 'profile') {
    $em=trim($_POST['email']); if ($em!=='' && !valid_email($em)) flash('That email address is not valid.','danger');
    else { q("UPDATE users SET email=?,notify_stock=? WHERE id=?",[$em?:null,isset($_POST['notify_stock'])?1:0,me()['id']]); email_prefs_save(me()['id']); audit('profile_update'); flash('Saved.'); }
    redirect('account.php');
  }
  if (($_POST['action'] ?? '') === 'del_passkey') { $pk = q("SELECT name FROM passkeys WHERE id=? AND user_id=?", [(int)$_POST['pid'], me()['id']])->fetchColumn(); if ($pk !== false) { q("DELETE FROM passkeys WHERE id=? AND user_id=?", [(int)$_POST['pid'], me()['id']]); audit('passkey_remove', $pk); passkey_security_mail(me()['id'], 'removed', $pk); flash('Passkey removed.'); } redirect('account.php'); }
  if (($_POST['action'] ?? '') === 'rename_passkey') { $nm = mb_substr(trim($_POST['name'] ?? ''), 0, 80); if ($nm === '') flash('Give the passkey a name.', 'danger'); else { q("UPDATE passkeys SET name=? WHERE id=? AND user_id=?", [$nm, (int)$_POST['pid'], me()['id']]); audit('passkey_rename', $nm); flash('Renamed.'); } redirect('account.php'); }
  $u=q("SELECT * FROM users WHERE id=?",[me()['id']])->fetch();
  if (!password_verify($_POST['current'],$u['pass_hash'])) flash('Current password is wrong.','danger');
  elseif (strlen($_POST['new'])<8) flash('New password must be 8+ characters.','danger');
  else { q("UPDATE users SET pass_hash=? WHERE id=?",[password_hash($_POST['new'],PASSWORD_DEFAULT),$u['id']]); audit('password_change'); flash('Password changed.'); }
  redirect('account.php');
}
$pks = q("SELECT * FROM passkeys WHERE user_id=? ORDER BY id",[me()['id']])->fetchAll();
include 'inc/header.php'; ?>
<h1 class="h3 mb-3">My account</h1>
<div class="row g-4"><div class="col-lg-4"><div class="card mb-3"><div class="card-header fw-bold"><i class="bi bi-envelope"></i> Email</div><form method="post" class="card-body d-grid gap-2"><?=csrf_field()?><input type="hidden" name="action" value="profile"><input class="form-control" type="email" name="email" placeholder="Your email address" value="<?=h(me()['email'] ?? '')?>"><?php if(me()['role']!=='viewer'): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="notify_stock" id="ns" <?=me()['notify_stock']?'checked':''?>><label class="form-check-label small" for="ns">Email me when items I can see run low or out</label></div><?php endif; ?><?php $mu=q("SELECT * FROM users WHERE id=?",[me()['id']])->fetch(); echo email_prefs_fields($mu); ?><button class="btn btn-outline-primary">Save</button><?php if(!mail_enabled()): ?><div class="small text-muted">Email is not set up on this system yet, so nothing will be sent.</div><?php endif; ?></form></div>
<div class="card"><div class="card-header fw-bold">Change password</div>
<form method="post" class="card-body d-grid gap-2"><?=csrf_field()?><input class="form-control" type="password" name="current" placeholder="Current password" required><input class="form-control" type="password" name="new" placeholder="New password (8+)" required><button class="btn btn-primary">Change password</button></form></div></div>
<div class="col-lg-5"><div class="card"><div class="card-header fw-bold"><i class="bi bi-fingerprint"></i> Passkeys</div><div class="card-body">
<?php if(!passkeys_ok()): ?><p class="text-muted mb-0">Passkeys are not available right now. They need the site on HTTPS and must be turned on by an administrator.</p><?php else: ?>
<p class="small text-muted">Sign in with your fingerprint, face or screen lock instead of a password. Add one on each device you use, so losing a phone does not lock you out.</p>
<ul class="list-group mb-3"><?php foreach($pks as $p): ?><li class="list-group-item"><div class="d-flex justify-content-between align-items-start gap-2"><div><i class="bi bi-<?=['This device'=>'phone','Phone or tablet'=>'phone','Security key'=>'usb-drive'][passkey_kind($p['transports'])]??'key'?>"></i> <strong><?=h($p['name'])?></strong><div class="small text-muted"><?=h(passkey_kind($p['transports']))?> · added <?=h(substr($p['created_at'],0,10))?> · <?=$p['last_used']?'last used '.h(substr($p['last_used'],0,10)):'not used yet'?></div></div>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="del_passkey"><input type="hidden" name="pid" value="<?=$p['id']?>"><button class="btn btn-sm btn-outline-danger" data-confirm="Remove the passkey <?=h($p['name'])?>?">Remove</button></form></div>
<form method="post" class="input-group input-group-sm mt-2"><?=csrf_field()?><input type="hidden" name="action" value="rename_passkey"><input type="hidden" name="pid" value="<?=$p['id']?>"><input class="form-control" name="name" value="<?=h($p['name'])?>" maxlength="80" aria-label="Passkey name"><button class="btn btn-outline-secondary">Rename</button></form></li><?php endforeach; if(!$pks) echo '<li class="list-group-item text-muted">None yet.</li>'; ?></ul>
<div class="input-group"><input class="form-control" id="pkName" placeholder="Name this device (e.g. My phone)" maxlength="80"><button class="btn btn-primary" id="pkAdd" type="button">Add passkey</button></div><div class="small mt-2" id="pkMsg"></div>
<script src="assets/passkey.js?v=<?=APP_VERSION?>"></script>
<script>document.getElementById('pkAdd').onclick=function(){var m=document.getElementById('pkMsg');m.className='small mt-2';m.textContent='';
if(!GPPasskey.supported()){m.className='small mt-2 text-danger';m.textContent='This browser does not support passkeys.';return;}
GPPasskey.register(document.getElementById('pkName').value.trim()).then(function(r){if(r.ok)location.reload();else{m.className='small mt-2 text-danger';m.textContent=r.error;}}).catch(function(e){m.className='small mt-2 text-danger';m.textContent=e&&e.name==='NotAllowedError'?'Cancelled, or this device cannot create a passkey.':(e&&e.message)||'Could not add the passkey.';});};</script><?php endif; ?></div></div></div>
<div class="col-lg-3"><div class="card"><div class="card-header fw-bold"><i class="bi bi-phone"></i> Install the app</div><div class="card-body small">
<button class="btn btn-primary w-100 mb-2" id="installBtn" style="display:none">Install on this device</button>
<p class="text-muted mb-1"><strong>Android (Chrome):</strong> menu &rarr; Install app.</p><p class="text-muted mb-0"><strong>iPhone (Safari):</strong> Share &rarr; Add to Home Screen.</p></div></div></div></div>
<?php include 'inc/footer.php'; ?>
<script>(function(){var b=document.getElementById('installBtn');function show(){if(window.GP_INSTALL){b.style.display='block';}}show();document.addEventListener('gp-install',show);b.onclick=function(){window.GP_INSTALL.prompt();window.GP_INSTALL=null;b.style.display='none';};})();</script>
