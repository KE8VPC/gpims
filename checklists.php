<?php require 'inc/bootstrap.php'; need('viewer'); if(me()['role']==='vendor'){http_response_code(403);exit('Access denied.');} $title='Checklists';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if(!can('checklists_manage')){ http_response_code(403); exit('Access denied.'); } $a=$_POST['action']??'';
  if ($a==='new') {
    $n=trim($_POST['name']??''); if($n==='') { flash('Name is required.','danger'); redirect('checklists.php'); }
    $f=(int)($_POST['freq']??0); q("INSERT INTO checklists(name,description,frequency_days) VALUES(?,?,?)",[mb_substr($n,0,150),mb_substr(trim($_POST['description']??''),0,255)?:null,$f?:null]);
    audit('checklist_save',$n); redirect('checklist_edit.php?id='.$pdo->lastInsertId());
  } elseif ($a==='del') { $c=q("SELECT * FROM checklists WHERE id=? AND vehicle_id IS NULL",[(int)$_POST['id']])->fetch(); if($c){ checklist_purge($c['id']); audit('checklist_delete',$c['name']); flash('Checklist deleted.'); } }
  redirect('checklists.php');
}
$lists=q("SELECT c.*,(SELECT COUNT(*) FROM checklist_items WHERE checklist_id=c.id) n FROM checklists c WHERE c.vehicle_id IS NULL ORDER BY name")->fetchAll();
include 'inc/header.php'; ?>
<div class="d-flex justify-content-between align-items-center mb-3 gap-2"><h1 class="h4 mb-0">Checklists</h1><div class="d-flex gap-1"><a class="btn btn-outline-secondary btn-sm" href="vehicles.php"><i class="bi bi-truck"></i> Vehicles</a><a class="btn btn-outline-secondary btn-sm" href="checklist_runs.php">History</a><?php if(can('checklists_manage')): ?><button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#newC"><i class="bi bi-plus-lg"></i> New</button><?php endif; ?></div></div>
<p class="text-muted small">General checklists (not tied to a vehicle). Vehicle checks live on each vehicle's page.</p>
<?php if(can('checklists_manage')): ?><div class="collapse mb-3" id="newC"><form method="post" class="card card-body"><?=csrf_field()?><input type="hidden" name="action" value="new"><div class="row g-2"><div class="col-md-5"><input class="form-control" name="name" placeholder="Name (e.g. EOC supply room check)" maxlength="150" required></div><div class="col-md-4"><input class="form-control" name="description" placeholder="Description (optional)" maxlength="255"></div><div class="col-md-2"><select class="form-select" name="freq"><?php foreach(FREQ_PRESETS as $k=>$lb): ?><option value="<?=$k?>"><?=$lb?></option><?php endforeach; ?></select></div><div class="col-md-1"><button class="btn btn-primary w-100">Next</button></div></div></form></div><?php endif; ?>
<div class="card"><div class="list-group list-group-flush">
<?php foreach($lists as $c): if(!$c['active'] && !can('checklists_manage')) continue; $st=check_status($c); ?><div class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2"><div><strong><?=h($c['name'])?></strong> <?=$c['active']?'':'<span class="badge text-bg-secondary">Inactive</span> '?><?=check_badge($st)?><div class="small text-muted"><?=$c['n']?> line<?=$c['n']==1?'':'s'?> · <?=h(freq_label($c['frequency_days']))?><?=$st['last']?' · last run '.h(date('M j',strtotime($st['last']['created_at']))):' · never run'?><?=$c['description']?' · '.h($c['description']):''?></div></div>
<div class="d-flex gap-1"><?php if(can('checklists_run')&&$c['active']&&$c['n']): ?><a class="btn btn-sm btn-primary" href="checklist_run.php?id=<?=$c['id']?>">Run</a><?php endif; ?><a class="btn btn-sm btn-outline-secondary" href="checklist_runs.php?cl=<?=$c['id']?>">History</a>
<?php if(can('checklists_manage')): ?><a class="btn btn-sm btn-outline-secondary" href="checklist_edit.php?id=<?=$c['id']?>">Edit</a><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="del"><input type="hidden" name="id" value="<?=$c['id']?>"><button class="btn btn-sm btn-outline-danger" data-confirm="Delete checklist and its history?"><i class="bi bi-trash"></i></button></form><?php endif; ?></div></div>
<?php endforeach; if(!$lists) echo '<div class="list-group-item text-muted">No general checklists yet.</div>'; ?></div></div>
<?php include 'inc/footer.php'; ?>
