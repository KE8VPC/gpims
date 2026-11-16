<?php require 'inc/bootstrap.php'; require 'inc/webauthn.php'; need('vendor'); $title='Account';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (($_POST['action'] ?? '') === 'profile') {
    $em=trim($_POST['email']); if ($em!=='' && !valid_email($em)) flash('That email address is not valid.','danger');
    else { q("UPDATE users SET email=?,notify_stock=? WHERE id=?",[$em?:null,isset($_POST['notify_stock'])?1:0,me()['id']]); email_prefs_save(me()['id']); audit('profile_update'); flash('Saved.'); }
    redirect('account.php');
  }
  if (($_POST['action'] ?? '') === 'del_passkey') { $pk = q("SELECT name FROM passkeys WHERE id=? AND user_id=?", [(int)$_POST['pid'], me()['id']])->fetchColumn(); if ($pk !== false) { q("DELETE FROM passkeys WHERE id=? AND user_id=?", [(int)$_POST['pid'], me()['id']]); audit('passkey_remove', $pk); passkey_security_mail(me()['id'], 'removed', $pk); flash('Passkey removed.'); } redirect('account.php'); }
  if (($_POST['action'] ?? '') === 'rename_passkey') { $nm = mb_substr(trim($_POST['name'] ?? ''), 0, 80); if ($nm === '') flash('Give the passkey a name.', 'danger'); else { q("UPDATE passkeys SET name=? WHERE id=? AND user_id=?", [$nm, (int)$_POST['pid'], me()['id']]); audit('passkey_rename', $nm); flash('Renamed.'); } redirect('account.php'); }
  $tact = $_POST['action'] ?? '';
  if (in_array($tact, ['totp_start','totp_confirm','totp_disable','totp_regen','totp_cancel'], true)) {
    $u = q("SELECT * FROM users WHERE id=?", [me()['id']])->fetch();
    if ($tact === 'totp_start' && !$u['totp_enabled']) { $_SESSION['totp_setup'] = totp_new_secret(); }
    elseif ($tact === 'totp_cancel') { unset($_SESSION['totp_setup']); }
    elseif ($tact === 'totp_confirm' && !empty($_SESSION['totp_setup'])) {
      if (totp_check($_SESSION['totp_setup'], $_POST['code'] ?? '')) { [$plain, $hash] = totp_recovery_make();
        q("UPDATE users SET totp_secret=?,totp_enabled=1,totp_last=?,totp_recovery=? WHERE id=?", [$_SESSION['totp_setup'], (int)floor(time()/30), json_encode($hash), $u['id']]);
        unset($_SESSION['totp_setup']); $_SESSION['totp_codes'] = $plain; audit('totp_enabled'); flash('Two-factor sign-in is on. Save your recovery codes now.'); }
      else flash('That code did not match. Check the time on your phone and try again.', 'danger');
    }
    elseif (in_array($tact, ['totp_disable','totp_regen'], true) && $u['totp_enabled']) {
      if (!password_verify($_POST['current'] ?? '', $u['pass_hash'])) flash('Password is wrong.', 'danger');
      elseif (!totp_user_verify($u, $_POST['code'] ?? '')) flash('That code was not accepted.', 'danger');
      elseif ($tact === 'totp_disable') { q("UPDATE users SET totp_secret=NULL,totp_enabled=0,totp_last=0,totp_recovery=NULL WHERE id=?", [$u['id']]); audit('totp_disabled'); flash('Two-factor sign-in is off.'); }
      else { [$plain, $hash] = totp_recovery_make(); q("UPDATE users SET totp_recovery=? WHERE id=?", [json_encode($hash), $u['id']]); $_SESSION['totp_codes'] = $plain; audit('totp_recovery_regen'); flash('New recovery codes made. The old ones no longer work.'); }
    }
    redirect('account.php');
  }
  $u=q("SELECT * FROM users WHERE id=?",[me()['id']])->fetch();
  if (!password_verify($_POST['current'],$u['pass_hash'])) flash('Current password is wrong.','danger');
  elseif (strlen($_POST['new'])<8) flash('New password must be 8+ characters.','danger');
  else { $nh=password_hash($_POST['new'],PASSWORD_DEFAULT); q("UPDATE users SET pass_hash=? WHERE id=?",[$nh,$u['id']]); $_SESSION['pwfp']=substr(hash('sha256',$nh),0,20); audit('password_change'); flash('Password changed.'); }
  redirect('account.php');
}
$tu = q("SELECT totp_enabled,totp_recovery FROM users WHERE id=?", [me()['id']])->fetch(); $tSetup = $_SESSION['totp_setup'] ?? ''; $tCodes = $_SESSION['totp_codes'] ?? []; unset($_SESSION['totp_codes']);
$pks = q("SELECT * FROM passkeys WHERE user_id=? ORDER BY id",[me()['id']])->fetchAll();
include 'inc/header.php'; ?>
<h1 class="h3 mb-3">My account</h1>
<div class="row g-4"><div class="col-lg-4"><div class="card mb-3"><div class="card-header fw-bold"><i class="bi bi-envelope"></i> Email</div><form method="post" class="card-body d-grid gap-2"><?=csrf_field()?><input type="hidden" name="action" value="profile"><input class="form-control" type="email" name="email" placeholder="Your email address" value="<?=h(me()['email'] ?? '')?>"><?php if(me()['role']!=='viewer'): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="notify_stock" id="ns" <?=me()['notify_stock']?'checked':''?>><label class="form-check-label small" for="ns">Email me when items I can see run low or out</label></div><?php endif; ?><?php $mu=q("SELECT * FROM users WHERE id=?",[me()['id']])->fetch(); echo email_prefs_fields($mu); ?><button class="btn btn-outline-primary">Save</button><?php if(!mail_enabled()): ?><div class="small text-muted">Email is not set up on this system yet, so nothing will be sent.</div><?php endif; ?></form></div>
<div class="card"><div class="card-header fw-bold">Change password</div>
<form method="post" class="card-body d-grid gap-2"><?=csrf_field()?><input class="form-control" type="password" name="current" placeholder="Current password" required><input class="form-control" type="password" name="new" placeholder="New password (8+)" required><button class="btn btn-primary">Change password</button></form></div>
<div class="card mt-3"><div class="card-header fw-bold"><i class="bi bi-shield-lock"></i> Two-factor sign-in</div><div class="card-body small">
<?php if ($tCodes): ?><div class="alert alert-warning"><strong>Recovery codes.</strong> Each works once if you lose your phone. They are shown only now. Copy or print them.<pre class="mb-0 mt-2 fs-6"><?=h(implode("\n", $tCodes))?></pre></div><?php endif; ?>
<?php if ($tu['totp_enabled']): $left = count(json_decode((string)$tu['totp_recovery'], true) ?: []); ?>
<p class="text-success mb-2"><i class="bi bi-check-circle"></i> On. <?=$left?> recovery code<?=$left==1?'':'s'?> left.</p>
<form method="post" class="d-grid gap-2"><?=csrf_field()?><input class="form-control form-control-sm" type="password" name="current" placeholder="Your password" required><input class="form-control form-control-sm" name="code" placeholder="Authenticator or recovery code" autocomplete="off" required>
<div class="d-flex gap-2"><button class="btn btn-sm btn-outline-secondary" name="action" value="totp_regen">New recovery codes</button><button class="btn btn-sm btn-outline-danger" name="action" value="totp_disable">Turn off</button></div></form>
<?php elseif ($tSetup): ?>
<p>1. In your authenticator app choose <em>Add account</em>, then enter this key (time-based):</p><p class="font-monospace fs-6 user-select-all text-break"><?=h(trim(chunk_split($tSetup, 4, ' ')))?></p>
<p><a class="btn btn-sm btn-outline-primary" href="<?=h(totp_uri($tSetup, me()['username']))?>">Open in authenticator app</a> <span class="text-muted">(on this phone)</span></p>
<p>2. Type the 6-digit code it shows:</p><form method="post" class="d-flex gap-2"><?=csrf_field()?><input class="form-control form-control-sm" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" required><button class="btn btn-sm btn-primary" name="action" value="totp_confirm">Turn on</button><button class="btn btn-sm btn-outline-secondary" name="action" value="totp_cancel" formnovalidate>Cancel</button></form>
<?php else: ?><p class="text-muted">Ask for a code from an authenticator app (Google Authenticator, Microsoft Authenticator, Authy, 1Password) after your password.</p>
<form method="post"><?=csrf_field()?><button class="btn btn-sm btn-primary" name="action" value="totp_start">Set up</button></form><?php endif; ?></div></div>
</div>
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
