<?php require 'inc/bootstrap.php'; need_perm('people_manage'); $title='People';
$edit = !empty($_GET['edit']) ? q("SELECT * FROM people WHERE id=?",[(int)$_GET['edit']])->fetch() : null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $pid=(int)($_POST['id']??0); $name=trim($_POST['name']); $badge=trim($_POST['badge_code'])?:null; $uid=(int)($_POST['user_id']??0)?:null;
  if ($name==='') flash('Name is required.','danger');
  elseif ($badge && q("SELECT id FROM people WHERE badge_code=? AND id<>?",[$badge,$pid])->fetch()) flash('That badge code is already assigned to someone else.','danger');
  else {
    $vals=[$name,$badge,trim($_POST['department']),$uid,isset($_POST['active'])?1:0,mb_substr(trim($_POST['phone']??''),0,40)?:null,isset($_POST['is_agency'])?1:0];
    if ($pid) { q("UPDATE people SET name=?,badge_code=?,department=?,user_id=?,active=?,phone=?,is_agency=? WHERE id=?",array_merge($vals,[$pid])); audit('person_update',"#$pid $name"); }
    else { q("INSERT INTO people(name,badge_code,department,user_id,active,phone,is_agency) VALUES(?,?,?,?,?,?,?)",$vals); audit('person_add',$name); }
    flash('Saved.'); redirect('people.php');
  }
  $edit = ['id'=>$pid,'name'=>$name,'badge_code'=>$badge,'department'=>$_POST['department'],'user_id'=>$uid,'active'=>isset($_POST['active'])?1:0,'phone'=>$_POST['phone']??'','is_agency'=>isset($_POST['is_agency'])?1:0];
}
$show = !empty($_GET['all']);
$people=q("SELECT p.*,u.username,(SELECT COUNT(*) FROM checkouts c WHERE c.person_id=p.id AND c.in_at IS NULL) holding FROM people p LEFT JOIN users u ON u.id=p.user_id ".($show?'':'WHERE p.active=1 ')."ORDER BY p.name")->fetchAll();
$users=q("SELECT id,username,name FROM users WHERE active=1 ORDER BY username")->fetchAll();
include 'inc/header.php'; ?>
<h1 class="h3 mb-3">People</h1><p class="text-muted small">Anyone who can borrow equipment. They do not need a login. A badge barcode only speeds up entering their name at check-out; it is never used to sign in.</p>
<div class="row g-4"><div class="col-lg-7">
<div class="d-flex justify-content-between mb-2"><input class="form-control w-auto" id="quickFilter" placeholder="Filter…"><a href="?<?=$show?'':'all=1'?>" class="btn btn-sm btn-outline-secondary"><?=$show?'Hide inactive':'Show inactive'?></a></div>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0" id="filterTable"><thead><tr><th>Name</th><th>Department</th><th>Badge</th><th>Has out</th><th></th></tr></thead><tbody>
<?php foreach($people as $p): ?><tr class="<?=$p['active']?'':'text-muted'?>"><td><?=h($p['name'])?><?=$p['is_agency']?' <span class="badge text-bg-info">agency</span>':''?><?=$p['phone']?' <span class="small text-muted">'.h($p['phone']).'</span>':''?><?=$p['username']?' <span class="badge text-bg-light">login: '.h($p['username']).'</span>':''?><?=$p['active']?'':' <span class="badge text-bg-secondary">inactive</span>'?></td><td><?=h($p['department'])?></td><td class="font-monospace small"><?=h($p['badge_code'])?></td><td><?=$p['holding']?:''?></td><td class="text-nowrap"><a href="person.php?id=<?=$p['id']?>">View</a> · <a href="?edit=<?=$p['id']?>">Edit</a></td></tr><?php endforeach; if(!$people) echo '<tr><td colspan="5" class="text-muted p-3">No people yet.</td></tr>'; ?></tbody></table></div></div></div>
<div class="col-lg-5"><div class="card"><div class="card-header fw-bold"><?=$edit?'Edit person':'Add person'?></div><form method="post" class="card-body"><?=csrf_field()?><input type="hidden" name="id" value="<?=h($edit['id']??0)?>">
<input class="form-control mb-2" name="name" placeholder="Full name" value="<?=h($edit['name']??'')?>" required>
<input class="form-control mb-2" name="department" placeholder="Department (optional)" value="<?=h($edit['department']??'')?>">
<input class="form-control mb-2" name="phone" placeholder="Phone (optional)" maxlength="40" value="<?=h($edit['phone']??'')?>">
<div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_agency" id="isag" <?=!empty($edit['is_agency'])?'checked':''?>><label class="form-check-label" for="isag">This is an outside agency or organization (loans / rentals)</label></div>
<div class="input-group mb-2"><input class="form-control" name="badge_code" id="badge_code" placeholder="Badge barcode (scan or type)" value="<?=h($edit['badge_code']??'')?>"><button type="button" class="btn btn-outline-secondary" id="badgeScan" title="Scan badge"><i class="bi bi-upc-scan"></i></button></div><div class="form-text mb-2" id="badgeFmt"></div>
<select class="form-select mb-2" name="user_id"><option value="0">No login account</option><?php foreach($users as $u): ?><option value="<?=$u['id']?>" <?=($edit['user_id']??0)==$u['id']?'selected':''?>><?=h($u['name'])?> (<?=h($u['username'])?>)</option><?php endforeach; ?></select>
<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="active" id="act" <?=($edit['active']??1)?'checked':''?>><label class="form-check-label" for="act">Active (can be issued equipment)</label></div>
<button class="btn btn-primary">Save</button> <?php if($edit): ?><a class="btn btn-link" href="people.php">Cancel</a><?php endif; ?></form></div></div></div>
<?php include 'inc/footer.php'; ?>
<script>document.getElementById('badgeScan').addEventListener('click',function(){GPScan(function(v,f){document.getElementById('badge_code').value=v;document.getElementById('badgeFmt').textContent=f?'Read as '+f.replace(/_/g,' ')+': '+v:'Read: '+v;});});</script>
