<?php require 'inc/bootstrap.php'; need('admin'); $title='User access';
$id=(int)($_GET['id']??0); $u=q("SELECT * FROM users WHERE id=?",[$id])->fetch(); if(!$u) redirect('users.php');
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $a=$_POST['action']??'';
  if ($a==='perms') {
    $sel=array_values(array_intersect(array_keys(PERMS), (array)($_POST['perms']??[]))); $tpl=ROLE_PERMS[$u['role']] ?? [];
    $same = !array_diff($sel,$tpl) && !array_diff($tpl,$sel);
    q("UPDATE users SET perms=? WHERE id=?",[$same?null:json_encode($sel),$id]); audit('user_perms',"#$id"); flash($same?'Matches the role defaults, so no custom permissions are stored.':'Permissions saved.');
  } elseif ($a==='reset_perms') { q("UPDATE users SET perms=NULL WHERE id=?",[$id]); audit('user_perms',"#$id reset"); flash('Permissions reset to the role defaults.');
  } elseif ($a==='contact') {
    $em=trim($_POST['email']); if ($em!=='' && !valid_email($em)) flash('That email address is not valid.','danger');
    else { q("UPDATE users SET email=?,notify_stock=? WHERE id=?",[$em?:null,isset($_POST['notify_stock'])?1:0,$id]); email_prefs_save($id); audit('user_contact',"#$id"); flash('Email settings saved.'); }
  } elseif ($a==='send_reset') {
    $tu=q("SELECT * FROM users WHERE id=?",[$id])->fetch();
    if (!mail_enabled()) flash('Set up email in Settings first.','danger'); elseif (!valid_email($tu['email'] ?? '')) flash('This user has no email address.','danger');
    else { send_account_email($tu,false); audit('reset_link_sent',"#$id"); flash('Password reset link emailed to '.$tu['email'].'.'); }
  } elseif ($a==='clear_passkeys') { q("DELETE FROM passkeys WHERE user_id=?",[$id]); audit('passkeys_cleared',"#$id"); passkey_security_mail($id,'removed','all passkeys (by an administrator)'); flash('All passkeys removed for this user.');
  } elseif ($a==='scope') { q("UPDATE users SET scope_all=? WHERE id=?",[($_POST['scope']??'all')==='all'?1:0,$id]); audit('user_scope',"#$id"); flash('Visibility updated.');
  } elseif ($a==='add_rule') {
    $c=(int)$_POST['category_id']; $l=(int)$_POST['location_id'];
    if (!$c && !$l) flash('Pick a category, a location, or both.','danger');
    else { q("INSERT INTO user_access(user_id,category_id,location_id) VALUES(?,?,?)",[$id,$c?:null,$l?:null]); audit('user_rule_add',"#$id"); flash('Rule added.'); }
  } elseif ($a==='del_rule') { q("DELETE FROM user_access WHERE id=? AND user_id=?",[(int)$_POST['rid'],$id]); audit('user_rule_del',"#$id"); flash('Rule removed.'); }
  redirect("user_access.php?id=$id");
}
$cats=cats_select(); $catName=array_column($cats,'name','id'); $locName=array_column(locations_all(),'name','id');
$rules=q("SELECT * FROM user_access WHERE user_id=? ORDER BY id",[$id])->fetchAll(); $have=user_perms($u); $isAdmin=$u['role']==='admin';
include 'inc/header.php'; ?>
<a href="users.php">&larr; All users</a><h1 class="h3 mt-2 mb-1"><?=h($u['name'])?> <small class="text-muted fs-6">(<?=h($u['username'])?> · <?=h(ROLE_LABELS[$u['role']] ?? $u['role'])?>)</small></h1>
<form method="post" class="row g-2 align-items-center mt-2 mb-2"><?=csrf_field()?><input type="hidden" name="action" value="contact"><div class="col-md-4"><input class="form-control form-control-sm" type="email" name="email" placeholder="Email address" value="<?=h($u['email'])?>"></div>
<div class="col-md-5"><div class="form-check"><input class="form-check-input" type="checkbox" name="notify_stock" id="ns" <?=$u['notify_stock']?'checked':''?>><label class="form-check-label small" for="ns">Email low and out-of-stock alerts for items they can see</label></div></div>
<div class="col-12"><?=email_prefs_fields($u)?></div><div class="col-12"><?=email_prefs_fields($u)?></div><div class="col-md-3"><button class="btn btn-sm btn-outline-primary">Save</button> <button class="btn btn-sm btn-outline-secondary" name="action" value="send_reset" <?=mail_enabled()&&valid_email($u['email'] ?? '')?'':'disabled'?> title="Emails a one-hour password reset link">Send reset link</button></div></form>
<?php $pkn=(int)q("SELECT COUNT(*) FROM passkeys WHERE user_id=?",[$id])->fetchColumn(); ?><form method="post" class="small text-muted mt-2 mb-0"><?=csrf_field()?><input type="hidden" name="action" value="clear_passkeys">Passkeys registered: <strong><?=$pkn?></strong> <?php if($pkn): ?><button class="btn btn-sm btn-link text-danger p-0 ms-2" data-confirm="Remove all passkeys for this user? They can still sign in with their password.">Remove all</button><?php endif; ?></form>
<?php if($isAdmin): ?><div class="alert alert-info mt-3">Admins can see and do everything. There is nothing to restrict.</div><?php else: ?>
<div class="row g-4 mt-1"><div class="col-lg-6"><div class="card"><div class="card-header fw-bold">What they can see</div><div class="card-body">
<form method="post" class="mb-3"><?=csrf_field()?><input type="hidden" name="action" value="scope">
<div class="form-check"><input class="form-check-input" type="radio" name="scope" value="all" id="sa" <?=$u['scope_all']?'checked':''?>><label class="form-check-label" for="sa">Everything in the inventory</label></div>
<div class="form-check"><input class="form-check-input" type="radio" name="scope" value="rules" id="sr" <?=!$u['scope_all']?'checked':''?>><label class="form-check-label" for="sr">Only items matching the rules below</label></div>
<button class="btn btn-sm btn-outline-primary mt-2">Save</button></form>
<?php if($u['role']==='vendor' && $u['scope_all']): ?><div class="alert alert-warning py-2 small">This vendor can currently see everything. Choose "Only items matching the rules".</div><?php endif; ?>
<ul class="list-group mb-3"><?php foreach($rules as $r): ?><li class="list-group-item d-flex justify-content-between align-items-center"><span><?=$r['category_id']?'Category <strong>'.h($catName[$r['category_id']]??'?').'</strong>'.(count(cat_descendants($r['category_id']))>1?' and its sub-categories':''):'Any category'?> in <?=$r['location_id']?'<strong>'.h($locName[$r['location_id']]??'?').'</strong> and sub-locations':'any location'?></span>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="del_rule"><input type="hidden" name="rid" value="<?=$r['id']?>"><button class="btn btn-sm btn-link text-danger p-0">remove</button></form></li><?php endforeach; if(!$rules) echo '<li class="list-group-item text-muted">No rules yet. With "only matching" selected they would see nothing.</li>'; ?></ul>
<form method="post" class="row g-2"><?=csrf_field()?><input type="hidden" name="action" value="add_rule">
<div class="col-6"><select class="form-select form-select-sm" name="category_id"><option value="0">Any category</option><?php foreach($cats as $c): ?><option value="<?=$c['id']?>"><?=h($c['name'])?></option><?php endforeach; ?></select></div>
<div class="col-6"><select class="form-select form-select-sm" name="location_id"><option value="0">Any location</option><?php foreach(locations_tree_flat() as $l): ?><option value="<?=$l['id']?>"><?=h(str_repeat('— ',$l['depth']).$l['name'])?></option><?php endforeach; ?></select></div>
<div class="col-12"><button class="btn btn-sm btn-outline-primary">Add rule</button> <span class="small text-muted">Category and location together mean both must match. Separate rules add together.</span></div></form></div></div></div>
<div class="col-lg-6"><div class="card"><div class="card-header fw-bold">What they can do</div><div class="card-body">
<?php if($u['role']==='vendor'): ?><p class="text-muted">Vendors only see the Supply page, so these options do not apply.</p><?php else: ?>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="perms">
<?php foreach(PERMS as $k=>$lbl): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="perms[]" value="<?=$k?>" id="p_<?=$k?>" <?=in_array($k,$have,true)?'checked':''?>><label class="form-check-label" for="p_<?=$k?>"><?=h($lbl)?></label></div><?php endforeach; ?>
<div class="mt-3"><button class="btn btn-sm btn-primary">Save permissions</button>
<button class="btn btn-sm btn-link" name="action" value="reset_perms">Reset to role defaults</button></div>
<div class="form-text mt-2"><?=$u['perms']!==null&&$u['perms']!==''?'This person has custom permissions.':'Using the defaults for their role.'?></div></form><?php endif; ?></div></div></div></div><?php endif; ?>
<?php include 'inc/footer.php'; ?>
