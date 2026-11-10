<?php require 'inc/bootstrap.php'; need('viewer'); if(me()['role']==='vendor'){http_response_code(403);exit('Access denied.');} $title='Vehicles';
$admin = me()['role']==='admin' || can('vehicles_manage');
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!vehicle_can_create()) { http_response_code(403); exit('Access denied.'); }
  $name=trim($_POST['name']??''); $kind=($_POST['kind']??'')==='personal'?'personal':'agency'; $plate=trim($_POST['plate']??'')?:null; $conv=(int)($_POST['convert_item']??0);
  $owner=(int)($_POST['owner_person_id']??0)?:null;
  if (!$admin) { $kind='personal'; $owner=my_person_id(); }          // regular users can only add their own personal vehicle
  if ($kind==='personal' && !$owner) $owner=my_person_id();
  $it=null; if ($conv) { $it=q("SELECT * FROM items WHERE id=? AND type='asset' AND archived_at IS NULL",[$conv])->fetch(); if(!$it||!item_in_scope($it)){ flash('That asset was not found.','danger'); redirect('vehicles.php'); } if($name==='') $name=$it['name']; }
  if ($name==='') { flash('Give the vehicle a name.','danger'); redirect('vehicles.php'); }
  if ($owner && !q("SELECT 1 FROM people WHERE id=?",[$owner])->fetch()) $owner=null;
  q("INSERT INTO vehicles(name,kind,owner_person_id,item_id,plate) VALUES(?,?,?,?,?)",[mb_substr($name,0,120),$kind,$owner,$it?$it['id']:null,$plate?mb_substr($plate,0,30):null]); $vid=(int)$pdo->lastInsertId();
  if ($it) { // the asset becomes the vehicle: release any assignment, then archive the asset record (history is kept)
    if ($it['status']==='checked_out') asset_return($it['id'],'good','Converted to a vehicle',me()['name']);
    q("UPDATE items SET archived_at=NOW() WHERE id=?",[$it['id']]); log_item_event($it['id'],'archived'); audit('vehicle_convert',"#{$it['id']} {$it['name']} -> vehicle #$vid");
    flash('Vehicle created from the asset. The old asset record was archived (its history is kept).');
  } else flash('Vehicle added. Now add a check for it.');
  audit('vehicle_add',$name); redirect("vehicle.php?id=$vid");
}
$list=vehicles_visible(); $assets=[]; $people=[];
if (vehicle_can_create()) {
  $assets=array_values(array_filter(q("SELECT i.*,(SELECT borrower FROM checkouts WHERE item_id=i.id AND in_at IS NULL ORDER BY id DESC LIMIT 1) holder FROM items i WHERE i.type='asset' AND i.archived_at IS NULL AND i.id NOT IN (SELECT item_id FROM vehicles WHERE item_id IS NOT NULL) ORDER BY i.name")->fetchAll(),'item_in_scope'));
  $people=q("SELECT id,name,user_id FROM people WHERE active=1 ORDER BY name")->fetchAll();
}
include 'inc/header.php'; ?>
<div class="d-flex justify-content-between align-items-center mb-3"><h1 class="h3 mb-0">Vehicles</h1><?php if(vehicle_can_create()): ?><button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#newV"><i class="bi bi-plus-lg"></i> Add vehicle</button><?php endif; ?></div>
<?php if(vehicle_can_create()): ?><div class="collapse mb-3" id="newV"><form method="post" class="card card-body"><?=csrf_field()?>
<div class="row g-2">
<div class="col-md-4"><input class="form-control" name="name" placeholder="Name (e.g. Heavy Rescue 1)" maxlength="120"></div>
<div class="col-md-3"><input class="form-control" name="plate" placeholder="Plate / unit # (optional)" maxlength="30"></div>
<?php if($admin): ?><div class="col-md-2"><select class="form-select" name="kind" id="vkind"><option value="agency">Agency vehicle</option><option value="personal">Personal vehicle</option></select></div>
<div class="col-md-3"><select class="form-select" name="owner_person_id"><option value="">Owner (personal vehicles)</option><?php foreach($people as $p): ?><option value="<?=$p['id']?>"<?=$p['id']==my_person_id()?'':''?>><?=h($p['name'])?><?=$p['user_id']?'':' (no login)'?></option><?php endforeach; ?></select></div>
<?php else: ?><div class="col-md-5 small text-muted align-self-center">This will be your personal vehicle. Only you and admins can see it unless you share it.</div><?php endif; ?>
<?php if($assets): ?><div class="col-12"><select class="form-select" name="convert_item"><option value="">New vehicle (not converted from an asset)</option><?php foreach($assets as $a): ?><option value="<?=$a['id']?>">Convert existing asset: <?=h($a['name'])?><?=$a['holder']?' (with '.h($a['holder']).')':''?></option><?php endforeach; ?></select><div class="form-text">Converting archives the old asset record and keeps its history.</div></div><?php endif; ?>
<div class="col-12"><button class="btn btn-primary">Create vehicle</button></div></div></form></div><?php endif; ?>
<div class="row g-3">
<?php foreach($list as $v):
  $cls=q("SELECT * FROM checklists WHERE vehicle_id=? AND active=1 ORDER BY name",[$v['id']])->fetchAll(); $bad=0;$over=0; foreach($cls as $c){ $st=check_status($c); if($st['state']==='overdue')$over++; if($st['open_issues'])$bad++; } ?>
<div class="col-md-6 col-lg-4"><a href="vehicle.php?id=<?=$v['id']?>" class="card h-100 text-decoration-none text-body"><div class="card-body">
<div class="d-flex justify-content-between"><strong><i class="bi bi-truck"></i> <?=h($v['name'])?></strong><span class="badge text-bg-<?=$v['kind']==='personal'?'info':'secondary'?>"><?=$v['kind']==='personal'?'Personal':'Agency'?></span></div>
<div class="small text-muted"><?=h($v['plate'])?><?=$v['owner']?($v['plate']?' · ':'').'Owner: '.h($v['owner']):''?></div>
<div class="mt-2 small"><?=count($cls)?> check<?=count($cls)===1?'':'s'?>
<?php if($over): ?> <span class="badge text-bg-danger"><?=$over?> overdue</span><?php endif; ?><?php if($bad): ?> <span class="badge text-bg-danger"><?=$bad?> with open issues</span><?php endif; ?><?php if($cls && !$over && !$bad): ?> <span class="badge text-bg-success">OK</span><?php endif; ?></div>
</div></a></div>
<?php endforeach; if(!$list) echo '<div class="col-12"><div class="card card-body text-muted">No vehicles yet.</div></div>'; ?></div>
<?php include 'inc/footer.php'; ?>
