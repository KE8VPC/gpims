<?php require 'inc/bootstrap.php'; need_perm('locations_manage'); $title='Locations';
$edit = !empty($_GET['edit']) ? q("SELECT * FROM locations WHERE id=?",[(int)$_GET['edit']])->fetch() : null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $a=$_POST['action']??'';
  if ($a==='save' && trim($_POST['name'])!=='') {
    $id=(int)($_POST['id']??0); $par=(int)$_POST['parent_id']?:null;
    if ($id && $par && in_array($par, loc_descendants($id), true)) flash('A location cannot be placed inside itself or one of its own sub-locations.','danger');
    elseif ($id) { q("UPDATE locations SET name=?,description=?,parent_id=? WHERE id=?",[trim($_POST['name']),trim($_POST['description']),$par,$id]); audit('location_update',"#$id"); flash('Location updated.'); }
    else { q("INSERT INTO locations(name,description,parent_id) VALUES(?,?,?)",[trim($_POST['name']),trim($_POST['description']),$par]); audit('location_add',$_POST['name']); flash('Location added.'); }
  } elseif ($a==='del') { $id=(int)$_POST['id']; q("UPDATE locations SET parent_id=NULL WHERE parent_id=?",[$id]); q("DELETE FROM locations WHERE id=?",[$id]); audit('location_delete',"#$id"); flash('Location deleted. Its sub-locations moved to the top level.'); }
  redirect('locations.php');
}
$counts=array_column(q("SELECT location_id,COUNT(*) n FROM items WHERE archived_at IS NULL AND location_id IS NOT NULL GROUP BY location_id")->fetchAll(),'n','location_id');
$tree=locations_tree_flat(); $bad = $edit ? loc_descendants($edit['id']) : [];
include 'inc/header.php'; ?>
<h1 class="h3 mb-3">Locations</h1><p class="text-muted small">Locations can sit inside each other, for example Building B &gt; Room 5. Access rules for a building automatically include its rooms.</p>
<div class="row g-4"><div class="col-lg-7"><div class="card"><table class="table mb-0"><tbody>
<?php foreach($tree as $l): $r=$l['row']; ?><tr><td style="padding-left:<?=0.75+$l['depth']*1.5?>rem"><?=$l['depth']?'<span class="text-muted">└</span> ':''?><strong><?=h($l['name'])?></strong><div class="small text-muted"><?=h($r['description'])?></div></td><td><a href="items.php?location_id=<?=$l['id']?>"><?=(int)($counts[$l['id']]??0)?> items</a></td>
<td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="?edit=<?=$l['id']?>">Edit</a> <form method="post" class="d-inline"><?=csrf_field()?><input type="hidden" name="action" value="del"><input type="hidden" name="id" value="<?=$l['id']?>"><button class="btn btn-sm btn-outline-danger" data-confirm="Delete location? Items in it become unassigned.">Delete</button></form></td></tr><?php endforeach; if(!$tree) echo '<tr><td class="text-muted p-3">No locations yet.</td></tr>'; ?></tbody></table></div></div>
<div class="col-lg-5"><div class="card"><div class="card-header fw-bold"><?=$edit?'Edit location':'Add location'?></div><form method="post" class="card-body"><?=csrf_field()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=h($edit['id']??0)?>">
<input class="form-control mb-2" name="name" placeholder="Name" value="<?=h($edit['name']??'')?>" required><input class="form-control mb-2" name="description" placeholder="Description (optional)" value="<?=h($edit['description']??'')?>">
<select class="form-select mb-3" name="parent_id"><option value="0">Top level (not inside another location)</option><?php foreach($tree as $l): if(in_array((int)$l['id'],$bad,true)) continue; ?><option value="<?=$l['id']?>" <?=($edit['parent_id']??0)==$l['id']?'selected':''?>><?=h(str_repeat('— ',$l['depth']).$l['name'])?></option><?php endforeach; ?></select>
<button class="btn btn-primary">Save</button> <?php if($edit): ?><a class="btn btn-link" href="locations.php">Cancel</a><?php endif; ?></form></div></div></div>
<?php include 'inc/footer.php'; ?>
