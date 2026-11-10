<?php require 'inc/bootstrap.php'; need_perm('categories_manage'); $title='Categories';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $a=$_POST['action']??''; $by=cats_by_id();
  $parentOk=function($pid,$self=0) use($by){ $pid=(int)$pid; if(!$pid) return null; if(!isset($by[$pid])) return false; if($self && in_array($pid,cat_descendants($self),true)) return false; return $pid; };
  if ($a==='add_cat' && trim($_POST['name'])!=='') {
    $pid=$parentOk($_POST['parent_id']??0);
    if ($pid===false) flash('That parent category was not found.','danger');
    else { q("INSERT INTO categories(name,description,parent_id) VALUES(?,?,?)",[mb_substr(trim($_POST['name']),0,100),mb_substr(trim($_POST['description']??''),0,255),$pid]); audit('category_add',$_POST['name']); flash($pid?'Sub-category added.':'Category added.'); }
  }
  elseif ($a==='edit_cat' && isset($by[(int)($_POST['id']??0)]) && trim($_POST['name'])!=='') {
    $id=(int)$_POST['id']; $pid=$parentOk($_POST['parent_id']??0,$id);
    if ($pid===false) flash('A category cannot be moved under itself or one of its own sub-categories.','danger');
    else { q("UPDATE categories SET name=?,description=?,parent_id=? WHERE id=?",[mb_substr(trim($_POST['name']),0,100),mb_substr(trim($_POST['description']??''),0,255),$pid,$id]); audit('category_edit',"#$id ".$_POST['name']); flash('Saved.'); }
  }
  elseif ($a==='del_cat' && isset($by[(int)($_POST['id']??0)])) {
    $id=(int)$_POST['id']; $par=$by[$id]['parent_id']?:null;
    q("UPDATE categories SET parent_id=? WHERE parent_id=?",[$par,$id]); q("DELETE FROM categories WHERE id=?",[$id]); audit('category_delete',"#$id");
    flash('Category deleted. Its items are now uncategorized and any sub-categories moved up one level.');
  }
  elseif ($a==='add_field' && trim($_POST['label'])!=='') { q("INSERT INTO custom_fields(category_id,label,field_type,options,sort) VALUES(?,?,?,?,?)",[(int)$_POST['category_id'],trim($_POST['label']),$_POST['field_type'],trim($_POST['options']),(int)$_POST['sort']]); audit('field_add',$_POST['label']); flash('Custom field added.'); }
  elseif ($a==='del_field') { q("DELETE FROM custom_fields WHERE id=?",[(int)$_POST['id']]); flash('Field removed.'); }
  redirect('categories.php');
}
$tree=cats_tree_flat(); $kids=cat_children_map(); $counts=array_column(q("SELECT category_id,COUNT(*) n FROM items WHERE category_id IS NOT NULL GROUP BY category_id")->fetchAll(),'n','category_id');
$fields=[]; foreach(q("SELECT * FROM custom_fields ORDER BY sort,id")->fetchAll() as $f) $fields[$f['category_id']][]=$f;
$seen=[];
function cat_node($c,$depth){
  global $kids,$counts,$fields,$tree,$seen; if(isset($seen[$c['id']])) return; $seen[$c['id']]=1; $id=(int)$c['id'];
  $ch=array_values(array_filter($kids[$id]??[],fn($x)=>!isset($seen[$x['id']]))); $own=$counts[$id]??0; $all=array_sum(array_map(fn($x)=>$counts[$x]??0,cat_descendants($id))); $nf=count($fields[$id]??[]); ?>
<div class="cat-node" data-id="<?=$id?>">
<div class="cat-row d-flex align-items-center gap-2 py-1 px-2 border rounded mb-1 bg-body">
<?php if($ch): ?><button type="button" class="btn btn-sm btn-link p-0 cat-toggle text-body" data-target="ch<?=$id?>" aria-label="Expand or collapse"><i class="bi bi-chevron-down"></i></button><?php else: ?><span style="width:1.1rem" class="d-inline-block"></span><?php endif; ?>
<i class="bi <?=$ch?'bi-folder2-open':'bi-tag'?> text-muted"></i>
<a class="fw-semibold text-decoration-none text-body flex-grow-1 min-w-0 text-truncate" data-bs-toggle="collapse" href="#p<?=$id?>" role="button"><?=h($c['name'])?></a>
<span class="badge text-bg-secondary" title="Items directly in this category"><?=$own?></span><?php if($all!=$own): ?><span class="badge text-bg-light border" title="Items including sub-categories"><?=$all?> total</span><?php endif; ?><?php if($nf): ?><span class="badge text-bg-light border" title="Custom fields"><i class="bi bi-input-cursor-text"></i> <?=$nf?></span><?php endif; ?>
<button class="btn btn-sm btn-outline-secondary py-0" data-bs-toggle="collapse" data-bs-target="#p<?=$id?>" title="Edit, fields, add sub-category"><i class="bi bi-pencil"></i></button>
</div>
<div class="collapse mb-2 ms-3" id="p<?=$id?>"><div class="card card-body py-2">
<form method="post" class="row g-2 mb-2"><?=csrf_field()?><input type="hidden" name="action" value="edit_cat"><input type="hidden" name="id" value="<?=$id?>">
<div class="col-md-4"><input class="form-control form-control-sm" name="name" value="<?=h($c['name'])?>" maxlength="100" required></div>
<div class="col-md-4"><select class="form-select form-select-sm" name="parent_id"><option value="0">Top level</option><?php $bad=cat_descendants($id); foreach($tree as $o): if(in_array($o['id'],$bad,true)) continue; ?><option value="<?=$o['id']?>"<?=$o['id']==(int)$c['parent_id']?' selected':''?>>Under: <?=h(cat_path($o['id']))?></option><?php endforeach; ?></select></div>
<div class="col-md-3"><input class="form-control form-control-sm" name="description" value="<?=h($c['description'])?>" maxlength="255" placeholder="Description"></div><div class="col-md-1"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-check2"></i></button></div></form>
<form method="post" class="row g-2 mb-2"><?=csrf_field()?><input type="hidden" name="action" value="add_cat"><input type="hidden" name="parent_id" value="<?=$id?>"><div class="col-8 col-md-6"><input class="form-control form-control-sm" name="name" maxlength="100" placeholder="Add a sub-category under <?=h($c['name'])?>" required></div><div class="col-4 col-md-2"><button class="btn btn-sm btn-outline-primary w-100">+ Sub</button></div></form>
<?php foreach($fields[$id]??[] as $f): ?><div class="d-flex justify-content-between small border-top pt-1"><span><?=h($f['label'])?> <span class="text-muted">(<?=h($f['field_type'])?><?=$f['options']?': '.h($f['options']):''?>)</span></span><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="del_field"><input type="hidden" name="id" value="<?=$f['id']?>"><button class="btn btn-sm btn-link text-danger p-0" data-confirm="Remove field? Stored values stay hidden.">remove</button></form></div><?php endforeach; ?>
<form method="post" class="row g-2 border-top pt-2 mt-1"><?=csrf_field()?><input type="hidden" name="action" value="add_field"><input type="hidden" name="category_id" value="<?=$id?>"><input type="hidden" name="sort" value="0">
<div class="col-12 col-md-4"><input class="form-control form-control-sm" name="label" placeholder="Custom field label" required></div><div class="col-6 col-md-3"><select class="form-select form-select-sm" name="field_type"><option>text</option><option>number</option><option>date</option><option>select</option></select></div>
<div class="col-6 col-md-3"><input class="form-control form-control-sm" name="options" placeholder="select: a,b,c"></div><div class="col-12 col-md-2"><button class="btn btn-sm btn-outline-primary w-100">+ Field</button></div></form>
<form method="post" class="mt-2"><?=csrf_field()?><input type="hidden" name="action" value="del_cat"><input type="hidden" name="id" value="<?=$id?>"><button class="btn btn-sm btn-outline-danger" data-confirm="Delete this category? Its items become uncategorized; sub-categories move up one level."><i class="bi bi-trash"></i> Delete category</button></form>
</div></div>
<?php if($ch): ?><div class="cat-children ms-3 ps-2 border-start" id="ch<?=$id?>"><?php foreach($ch as $k) cat_node($k,$depth+1); ?></div><?php endif; ?>
</div>
<?php }
include 'inc/header.php'; ?>
<h1 class="h3 mb-2">Categories &amp; custom fields</h1>
<p class="text-muted small">Categories can have sub-categories, for example <em>Communication / Radios / Portable</em>. Filtering or giving someone access to a parent also includes everything under it. Custom fields on a parent also apply to its sub-categories. Tap a name (or the pencil) to edit, add a sub-category or add custom fields.</p>
<form method="post" class="row g-2 mb-3"><?=csrf_field()?><input type="hidden" name="action" value="add_cat"><div class="col-md-3"><input class="form-control" name="name" placeholder="New category" maxlength="100" required></div>
<div class="col-md-3"><select class="form-select" name="parent_id"><option value="0">Top level</option><?php foreach($tree as $t): ?><option value="<?=$t['id']?>">Under: <?=h(cat_path($t['id']))?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><input class="form-control" name="description" placeholder="Description (optional)" maxlength="255"></div><div class="col-md-2"><button class="btn btn-primary w-100">Add</button></div></form>
<div class="d-flex flex-wrap gap-2 mb-2"><button type="button" class="btn btn-sm btn-outline-secondary" id="catExpand"><i class="bi bi-arrows-expand"></i> Expand all</button><button type="button" class="btn btn-sm btn-outline-secondary" id="catCollapse"><i class="bi bi-arrows-collapse"></i> Collapse all</button><input class="form-control form-control-sm" style="max-width:240px" id="catFind" placeholder="Find a category…"></div>
<div id="catTree"><?php foreach($tree as $t) if($t['depth']===0) cat_node($t['row'],0); if(!$tree) echo '<div class="text-muted">No categories yet.</div>'; ?></div>
<script>(function(){var KEY='gp-cats-collapsed',store=[];try{store=JSON.parse(localStorage.getItem(KEY)||'[]');}catch(e){}
function save(){try{localStorage.setItem(KEY,JSON.stringify(store));}catch(e){}}
function set(btn,collapsed){var box=document.getElementById(btn.dataset.target);if(!box)return;box.style.display=collapsed?'none':'';var i=btn.querySelector('i');i.className='bi '+(collapsed?'bi-chevron-right':'bi-chevron-down');var id=box.id.slice(2),k=store.indexOf(id);if(collapsed&&k<0)store.push(id);if(!collapsed&&k>-1)store.splice(k,1);}
document.querySelectorAll('.cat-toggle').forEach(function(b){set(b,store.indexOf(b.dataset.target.slice(2))>-1);b.addEventListener('click',function(){set(b,document.getElementById(b.dataset.target).style.display!=='none');save();});});
document.getElementById('catExpand').onclick=function(){document.querySelectorAll('.cat-toggle').forEach(function(b){set(b,false);});save();};
document.getElementById('catCollapse').onclick=function(){document.querySelectorAll('.cat-toggle').forEach(function(b){set(b,true);});save();};
document.getElementById('catFind').addEventListener('input',function(){var v=this.value.toLowerCase().trim();document.querySelectorAll('.cat-node').forEach(function(n){n.style.display='';});
 if(!v)return;document.querySelectorAll('.cat-toggle').forEach(function(b){var bx=document.getElementById(b.dataset.target);bx.style.display='';b.querySelector('i').className='bi bi-chevron-down';});
 var nodes=document.querySelectorAll('.cat-node');nodes.forEach(function(n){var name=n.querySelector('.cat-row a').textContent.toLowerCase();n._hit=name.indexOf(v)>-1;});
 nodes.forEach(function(n){var show=n._hit||Array.prototype.some.call(n.querySelectorAll('.cat-node'),function(d){return d._hit;});var p=n.parentNode.closest('.cat-node');n.style.display=show?'':'none';});
 nodes.forEach(function(n){if(n._hit)n.querySelectorAll('.cat-node').forEach(function(d){d.style.display='';});});});
})();</script>
<?php include 'inc/footer.php'; ?>
