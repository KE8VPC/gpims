<?php require 'inc/bootstrap.php'; need('viewer'); $id=(int)($_GET['id']??0); $v=vehicle_get($id);
if(!$v) redirect('vehicles.php'); if(!vehicle_can_view($v)){ http_response_code(403); exit('You do not have access to this vehicle.'); }
$canEdit=vehicle_can_edit($v); $isAdmin=me()['role']==='admin'; $title=$v['name'];
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $a=$_POST['action']??'';
  if (!$canEdit) { http_response_code(403); exit('Access denied.'); }
  if ($a==='save') {
    $name=trim($_POST['name']??''); if($name===''){ flash('Name is required.','danger'); redirect("vehicle.php?id=$id"); }
    $owner=$v['owner_person_id']; $kind=$v['kind'];
    if ($isAdmin || can('vehicles_manage')) { $kind=($_POST['kind']??'')==='personal'?'personal':'agency'; $o=(int)($_POST['owner_person_id']??0); $owner=($o&&q("SELECT 1 FROM people WHERE id=?",[$o])->fetch())?$o:null; }
    q("UPDATE vehicles SET name=?,plate=?,notes=?,kind=?,owner_person_id=? WHERE id=?",[mb_substr($name,0,120),trim($_POST['plate']??'')?:null,trim($_POST['notes']??'')?mb_substr(trim($_POST['notes']),0,500):null,$kind,$owner,$id]); audit('vehicle_edit',$name); flash('Saved.');
  } elseif ($a==='share') {
    q("DELETE FROM vehicle_users WHERE vehicle_id=?",[$id]);
    foreach((array)($_POST['share']??[]) as $u){ $u=(int)$u; if($u && q("SELECT 1 FROM users WHERE id=? AND active=1 AND role<>'vendor'",[$u])->fetch()) q("INSERT IGNORE INTO vehicle_users(vehicle_id,user_id) VALUES(?,?)",[$id,$u]); }
    audit('vehicle_share',$v['name']); flash('Sharing updated.');
  } elseif ($a==='active') { q("UPDATE vehicles SET active=? WHERE id=?",[$v['active']?0:1,$id]); audit('vehicle_active',$v['name']); flash($v['active']?'Vehicle retired. It is hidden from lists.':'Vehicle restored.');
  } elseif ($a==='delete' && $isAdmin) {
    docs_purge(null,$id); foreach(q("SELECT id FROM checklists WHERE vehicle_id=?",[$id])->fetchAll() as $c) checklist_purge($c['id']);
    q("DELETE FROM vehicles WHERE id=?",[$id]); audit('vehicle_delete',$v['name']); flash('Vehicle and its checks deleted.'); redirect('vehicles.php');
  } elseif (in_array($a,['doc_add','doc_del'])) { docs_post(null,$id);
  } elseif ($a==='addcheck') {
    $n=trim($_POST['cname']??''); if($n===''){ flash('Name the check.','danger'); redirect("vehicle.php?id=$id"); }
    $f=(int)($_POST['freq']??0); if($f<0||$f>3650)$f=0;
    q("INSERT INTO checklists(name,vehicle_id,frequency_days) VALUES(?,?,?)",[mb_substr($n,0,150),$id,$f?:null]); $cid=(int)$pdo->lastInsertId(); audit('checklist_save',$n); redirect("checklist_edit.php?id=$cid");
  }
  redirect("vehicle.php?id=$id");
}
$cls=q("SELECT c.*,(SELECT COUNT(*) FROM checklist_items WHERE checklist_id=c.id) n,(SELECT COUNT(*) FROM checklist_sections WHERE checklist_id=c.id) ns FROM checklists c WHERE c.vehicle_id=? ORDER BY c.name",[$id])->fetchAll();
$gal=[]; { foreach(q("SELECT ri.id,ri.photo f,ri.label,ri.section,r.id rid,r.created_at FROM checklist_run_items ri JOIN checklist_runs r ON r.id=ri.run_id JOIN checklists c ON c.id=r.checklist_id WHERE c.vehicle_id=? AND ri.status<>'ok' AND ri.photo IS NOT NULL UNION ALL SELECT ri.id,cp.filename,ri.label,ri.section,r.id,r.created_at FROM check_photos cp JOIN checklist_run_items ri ON ri.id=cp.run_item_id JOIN checklist_runs r ON r.id=ri.run_id JOIN checklists c ON c.id=r.checklist_id WHERE c.vehicle_id=? AND ri.status<>'ok' ORDER BY created_at DESC LIMIT 12",[$id,$id])->fetchAll() as $g) $gal[]=$g; }
$runs=q("SELECT r.*,c.name cname,u.name uname FROM checklist_runs r JOIN checklists c ON c.id=r.checklist_id LEFT JOIN users u ON u.id=r.user_id WHERE c.vehicle_id=? ORDER BY r.id DESC LIMIT 8",[$id])->fetchAll();
$people=$canEdit?q("SELECT id,name,user_id FROM people WHERE active=1 ORDER BY name")->fetchAll():[];
$shared=array_column(q("SELECT user_id FROM vehicle_users WHERE vehicle_id=?",[$id])->fetchAll(),'user_id');
$users=($canEdit&&$v['kind']==='personal')?q("SELECT id,name,username FROM users WHERE active=1 AND role<>'vendor' ORDER BY name")->fetchAll():[];
include 'inc/header.php'; ?>
<a href="vehicles.php" class="small">&larr; Vehicles</a>
<div class="d-flex justify-content-between align-items-start mt-1 mb-2"><div><h1 class="h3 mb-0"><i class="bi bi-truck"></i> <?=h($v['name'])?> <?php if(!$v['active']): ?><span class="badge text-bg-secondary">Retired</span><?php endif; ?></h1>
<div class="text-muted small"><span class="badge text-bg-<?=$v['kind']==='personal'?'info':'secondary'?>"><?=$v['kind']==='personal'?'Personal':'Agency'?></span> <?=h($v['plate'])?> <?=$v['owner']?'· Owner: '.h($v['owner']):''?></div><?php if($v['notes']): ?><div class="small mt-1"><?=nl2br(h($v['notes']))?></div><?php endif; ?></div>
<?php if($canEdit): ?><button class="btn btn-outline-secondary btn-sm" data-bs-toggle="collapse" data-bs-target="#editV"><i class="bi bi-pencil"></i> Edit</button><?php endif; ?></div>
<?php if($canEdit): ?><div class="collapse mb-3" id="editV"><div class="card card-body"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save"><div class="row g-2">
<div class="col-md-4"><input class="form-control" name="name" value="<?=h($v['name'])?>" maxlength="120" required></div><div class="col-md-3"><input class="form-control" name="plate" value="<?=h($v['plate'])?>" placeholder="Plate / unit #" maxlength="30"></div>
<?php if($isAdmin||can('vehicles_manage')): ?><div class="col-md-2"><select class="form-select" name="kind"><option value="agency"<?=$v['kind']==='agency'?' selected':''?>>Agency</option><option value="personal"<?=$v['kind']==='personal'?' selected':''?>>Personal</option></select></div>
<div class="col-md-3"><select class="form-select" name="owner_person_id"><option value="">No owner</option><?php foreach($people as $p): ?><option value="<?=$p['id']?>"<?=$p['id']==$v['owner_person_id']?' selected':''?>><?=h($p['name'])?><?=$p['user_id']?'':' (no login)'?></option><?php endforeach; ?></select></div><?php endif; ?>
<div class="col-12"><textarea class="form-control" name="notes" rows="2" maxlength="500" placeholder="Notes"><?=h($v['notes'])?></textarea></div>
<div class="col-12 d-flex gap-2"><button class="btn btn-primary btn-sm">Save</button></div></div></form>
<?php if($v['kind']==='personal'): ?><hr><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="share"><div class="fw-semibold small mb-1">Who else can see this vehicle? <span class="text-muted fw-normal">(the owner and admins always can)</span></div><div class="row row-cols-2 row-cols-md-4 g-1 mb-2"><?php foreach($users as $u): if($u['id']==vehicle_owner_user($v)) continue; ?><div class="col"><label class="form-check"><input type="checkbox" class="form-check-input" name="share[]" value="<?=$u['id']?>"<?=in_array($u['id'],$shared)?' checked':''?>> <span class="form-check-label small"><?=h($u['name'])?></span></label></div><?php endforeach; ?></div><button class="btn btn-outline-primary btn-sm">Save sharing</button></form><?php endif; ?>
<hr><div class="d-flex gap-2"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="active"><button class="btn btn-outline-secondary btn-sm"><?=$v['active']?'Retire vehicle':'Restore vehicle'?></button></form>
<?php if($isAdmin): ?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="delete"><button class="btn btn-outline-danger btn-sm" data-confirm="Delete this vehicle, its checks and their history?">Delete</button></form><?php endif; ?></div></div></div><?php endif; ?>

<div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center"><strong>Checks</strong>
<?php if($canEdit): ?><button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#addC"><i class="bi bi-plus-lg"></i> Add check</button><?php endif; ?></div>
<?php if($canEdit): ?><div class="collapse" id="addC"><form method="post" class="card-body border-bottom"><?=csrf_field()?><input type="hidden" name="action" value="addcheck"><div class="row g-2">
<div class="col-md-6"><input class="form-control" name="cname" placeholder="e.g. Weekly rig check" maxlength="150" required></div>
<div class="col-md-4"><select class="form-select" name="freq"><?php foreach(FREQ_PRESETS as $k=>$l): ?><option value="<?=$k?>"<?=$k==='7'?' selected':''?>><?=$l?></option><?php endforeach; ?></select></div>
<div class="col-md-2"><button class="btn btn-primary w-100">Create</button></div></div><div class="form-text">Next you add compartments and lines. Each vehicle has its own checks.</div></form></div><?php endif; ?>
<div class="list-group list-group-flush">
<?php foreach($cls as $c): $st=check_status($c); ?><div class="list-group-item"><div class="d-flex justify-content-between align-items-center flex-wrap gap-2"><div><strong><?=h($c['name'])?></strong> <?=check_badge($st)?>
<div class="small text-muted"><?=$c['ns']?> compartment<?=$c['ns']==1?'':'s'?> · <?=$c['n']?> line<?=$c['n']==1?'':'s'?> · <?=h(freq_label($c['frequency_days']))?><?=$st['next']&&$st['state']!=='none'?' · next '.h($st['next']):''?><?=$st['last']?' · last '.h(date('M j',strtotime($st['last']['created_at']))):''?></div></div>
<div class="d-flex gap-1"><?php if(vehicle_can_run($v)&&$c['n']): ?><a class="btn btn-sm btn-primary" href="checklist_run.php?id=<?=$c['id']?>">Run</a><?php endif; ?><?php if($canEdit): ?><a class="btn btn-sm btn-outline-secondary" href="checklist_edit.php?id=<?=$c['id']?>">Edit</a><?php endif; ?><a class="btn btn-sm btn-outline-secondary" href="checklist_runs.php?cl=<?=$c['id']?>">History</a></div></div></div>
<?php endforeach; if(!$cls) echo '<div class="list-group-item text-muted">No checks yet.'.($canEdit?' Use Add check.':'').'</div>'; ?></div></div>
<?php if($gal): ?><div class="card mb-3"><div class="card-header"><strong><i class="bi bi-camera"></i> Recent problem photos</strong></div><div class="card-body d-flex flex-wrap gap-2"><?php foreach($gal as $g): ?><a href="checklist_runs.php?view=<?=$g['rid']?>" data-lb="uploads/<?=h($g['f'])?>" data-cap="<?=h(($g['section']?$g['section'].': ':'').$g['label'].' - '.date('M j',strtotime($g['created_at'])))?>" class="text-decoration-none small text-center" style="width:92px"><img src="uploads/<?=h($g['f'])?>" alt="" class="rounded" style="height:70px;width:92px;object-fit:cover"><div class="text-truncate text-muted"><?=h($g['label'])?></div></a><?php endforeach; ?></div></div><?php endif; ?>
<?php if($runs): ?><div class="card"><div class="card-header"><strong>Recent check results</strong></div><table class="table table-sm mb-0"><tbody><?php foreach($runs as $r): ?><tr><td><a href="checklist_runs.php?view=<?=$r['id']?>"><?=h($r['cname'])?></a></td><td class="text-muted small"><?=h(date('M j g:ia',strtotime($r['created_at'])))?></td><td class="small"><?=h($r['uname'])?></td><td><?=$r['issues']?'<span class="badge text-bg-danger">'.$r['issues'].' issue(s)</span>':'<span class="badge text-bg-success">All OK</span>'?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?=docs_panel(null,$id,$canEdit)?>
<?php include 'inc/footer.php'; ?>
