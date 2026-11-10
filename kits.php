<?php require 'inc/bootstrap.php'; need('viewer'); if(me()['role']==='vendor'){http_response_code(403);exit('Access denied.');} $title='Kits';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if(!can('items_edit')){ http_response_code(403); exit('Access denied.'); }
  $n=trim($_POST['name']??''); if($n===''){ flash('Name the kit.','danger'); redirect('kits.php'); }
  q("INSERT INTO kits(name,description) VALUES(?,?)",[mb_substr($n,0,120),mb_substr(trim($_POST['description']??''),0,255)?:null]); $id=$pdo->lastInsertId(); audit('kit_add',$n); redirect("kit.php?id=$id");
}
$kits=q("SELECT k.*,(SELECT COUNT(*) FROM kit_items WHERE kit_id=k.id) n FROM kits k WHERE k.active=1 ORDER BY k.name")->fetchAll();
include 'inc/header.php'; ?>
<div class="d-flex justify-content-between align-items-center mb-2"><h1 class="h4 mb-0">Kits</h1><?php if(can('items_edit')): ?><button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#newK"><i class="bi bi-plus-lg"></i> New kit</button><?php endif; ?></div>
<p class="text-muted small">A kit is a named bundle of equipment (for example "Traffic control kit": 10 cones, 4 flares, 2 vests, 1 radio). Issue the whole kit in one step; each piece is tracked and returned like any other gear.</p>
<?php if(can('items_edit')): ?><div class="collapse mb-3" id="newK"><form method="post" class="card card-body"><?=csrf_field()?><div class="row g-2"><div class="col-md-5"><input class="form-control" name="name" placeholder="Kit name" maxlength="120" required></div><div class="col-md-5"><input class="form-control" name="description" placeholder="Description (optional)" maxlength="255"></div><div class="col-md-2"><button class="btn btn-primary w-100">Create</button></div></div></form></div><?php endif; ?>
<div class="card"><div class="list-group list-group-flush"><?php foreach($kits as $k): ?><a class="list-group-item list-group-item-action d-flex justify-content-between" href="kit.php?id=<?=$k['id']?>"><span><i class="bi bi-bag"></i> <strong><?=h($k['name'])?></strong> <span class="text-muted small"><?=h($k['description'])?></span></span><span class="badge text-bg-secondary"><?=$k['n']?> item<?=$k['n']==1?'':'s'?></span></a><?php endforeach; if(!$kits) echo '<div class="list-group-item text-muted">No kits yet.</div>'; ?></div></div>
<?php include 'inc/footer.php'; ?>
