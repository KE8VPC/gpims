<?php require 'inc/bootstrap.php'; need('viewer'); if(me()['role']==='vendor'){http_response_code(403);exit('Access denied.');}
$id=(int)($_GET['id']??0); $k=q("SELECT * FROM kits WHERE id=?",[$id])->fetch(); if(!$k) redirect('kits.php'); $title=$k['name'];
function kit_lines($id){ return q("SELECT ki.*,i.name,i.type,i.qty onhand,i.unit,i.status,i.category_id,i.location_id,i.archived_at FROM kit_items ki JOIN items i ON i.id=ki.item_id WHERE ki.kit_id=? ORDER BY i.name",[$id])->fetchAll(); }
function kit_shortfall($l){ if($l['archived_at']) return 'archived';
  if($l['type']==='asset') return $l['status']==='available' ? null : 'not available ('.str_replace('_',' ',$l['status']).')';
  if($l['type']==='returnable') return ((float)$l['onhand']>=(float)$l['qty']) ? null : 'only '.fmt_q($l['onhand']).' on hand';
  return null; }
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $a=$_POST['action']??'';
  if (in_array($a,['rename','add','del_line','delete','save_qty']) && !can('items_edit')) { http_response_code(403); exit('Access denied.'); }
  if ($a==='rename') { $n=trim($_POST['name']??''); if($n!=='') q("UPDATE kits SET name=?,description=? WHERE id=?",[mb_substr($n,0,120),mb_substr(trim($_POST['description']??''),0,255)?:null,$id]); flash('Saved.'); }
  elseif ($a==='add') {
    $txt=trim($_POST['item']??''); $it=null; if(preg_match('/#(\d+)\)?\s*$/',$txt,$m)) $it=q("SELECT * FROM items WHERE id=?",[(int)$m[1]])->fetch(); else $it=q("SELECT * FROM items WHERE name=? AND archived_at IS NULL LIMIT 1",[$txt])->fetch();
    $qn=max(0.01,(float)($_POST['qty']??1));
    if(!$it||!item_in_scope($it)||$it['archived_at']) flash('Pick an item from the list.','danger');
    elseif($it['type']==='consumable') flash('Kits hold returnable items and assets. Consumables are used up, so they are not part of a kit.','warning');
    else { if($it['type']==='asset') $qn=1; $ex=q("SELECT id FROM kit_items WHERE kit_id=? AND item_id=?",[$id,$it['id']])->fetch(); if($ex) q("UPDATE kit_items SET qty=? WHERE id=?",[$qn,$ex['id']]); else q("INSERT INTO kit_items(kit_id,item_id,qty) VALUES(?,?,?)",[$id,$it['id'],$qn]); flash('Added to the kit.'); }
  }
  elseif ($a==='del_line') { q("DELETE FROM kit_items WHERE id=? AND kit_id=?",[(int)$_POST['lid'],$id]); }
  elseif ($a==='delete') { q("DELETE FROM kits WHERE id=?",[$id]); audit('kit_delete',$k['name']); flash('Kit deleted. Items are untouched.'); redirect('kits.php'); }
  elseif ($a==='issue') {
    if(!can('checkout')){ http_response_code(403); exit('Access denied.'); }
    [$b,$pid,$perr]=resolve_borrower(); $lines=kit_lines($id);
    if($perr) flash($perr,'danger'); elseif(!$lines) flash('The kit is empty.','warning');
    else {
      $bad=[]; foreach($lines as $l){ $it=['category_id'=>$l['category_id'],'location_id'=>$l['location_id']]; if(!item_in_scope($it)) $bad[]=$l['name'].' (no access)'; elseif($s=kit_shortfall($l)) $bad[]=$l['name'].': '.$s; }
      if($bad) flash('Cannot issue the kit yet - '.implode('; ',$bad).'.','danger');
      else {
        $incId=incident_id_valid($_POST['incident_id']??''); $note=trim($_POST['note']??'').' ['.$k['name'].' kit]'; $due=($_POST['due_at']??'')?:null; $uid=me()['id']; $done=0;
        $pdo->beginTransaction();
        try {
          foreach($lines as $l){ $it=q("SELECT * FROM items WHERE id=?",[$l['item_id']])->fetch();
            if($it['type']==='returnable'){ [$ok,$m]=do_issue($it,(float)$l['qty'],$b,$pid,$incId,$note); if(!$ok) throw new Exception($m); }
            else { q("INSERT INTO checkouts(item_id,borrower,person_id,user_id,due_at,note,incident_id) VALUES(?,?,?,?,?,?,?)",[$it['id'],$b,$pid,$uid,$due,trim($note),$incId]); q("UPDATE items SET status='checked_out' WHERE id=?",[$it['id']]); tx_log($it['id'],'checkout',1,null,"To $b - {$k['name']} kit",$incId); }
            $done++; }
          $pdo->commit(); audit('kit_issue',"{$k['name']} to $b"); flash("Kit issued to $b ($done items).");
        } catch (Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); flash('Could not issue the kit: '.$e->getMessage(),'danger'); }
      }
    }
  }
  redirect("kit.php?id=$id");
}
$lines=kit_lines($id); $incs=incidents_open(); $itemOpts=can('items_edit')?q("SELECT id,name FROM items WHERE type<>'consumable' AND archived_at IS NULL AND ".scope_sql('i')." ORDER BY name LIMIT 1500")->fetchAll():[];
$people=q("SELECT name FROM people WHERE active=1 ORDER BY name")->fetchAll();
include 'inc/header.php'; ?>
<a href="kits.php" class="small">&larr; Kits</a>
<div class="d-flex justify-content-between align-items-start mt-1 mb-2"><div><h1 class="h4 mb-0"><i class="bi bi-bag"></i> <?=h($k['name'])?></h1><div class="text-muted small"><?=h($k['description'])?></div></div>
<?php if(can('items_edit')): ?><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#editK"><i class="bi bi-pencil"></i></button><?php endif; ?></div>
<?php if(can('items_edit')): ?><div class="collapse mb-3" id="editK"><form method="post" class="card card-body"><?=csrf_field()?><input type="hidden" name="action" value="rename"><div class="row g-2"><div class="col-md-5"><input class="form-control" name="name" value="<?=h($k['name'])?>" maxlength="120"></div><div class="col-md-5"><input class="form-control" name="description" value="<?=h($k['description'])?>" maxlength="255"></div><div class="col-md-2"><button class="btn btn-primary w-100">Save</button></div></div></form>
<form method="post" class="px-3 pb-3"><?=csrf_field()?><input type="hidden" name="action" value="delete"><button class="btn btn-outline-danger btn-sm" data-confirm="Delete this kit? The items themselves are not affected.">Delete kit</button></form></div><?php endif; ?>
<div class="card mb-3"><div class="card-header fw-bold">Contents</div><ul class="list-group list-group-flush">
<?php foreach($lines as $l): $s=kit_shortfall($l); ?><li class="list-group-item d-flex justify-content-between align-items-center"><div><a href="item_view.php?id=<?=$l['item_id']?>"><?=h($l['name'])?></a> <span class="text-muted">&times; <?=h(fmt_q($l['qty']))?></span> <?=$s?'<span class="badge text-bg-warning">'.h($s).'</span>':'<span class="badge text-bg-success">ready</span>'?></div>
<?php if(can('items_edit')): ?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="del_line"><input type="hidden" name="lid" value="<?=$l['id']?>"><button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-x-lg"></i></button></form><?php endif; ?></li>
<?php endforeach; if(!$lines) echo '<li class="list-group-item text-muted">Nothing in this kit yet.</li>'; ?>
<?php if(can('items_edit')): ?><li class="list-group-item bg-body-tertiary"><form method="post" class="row g-2"><?=csrf_field()?><input type="hidden" name="action" value="add"><div class="col-8"><input class="form-control form-control-sm" name="item" list="kitItems" placeholder="Add an item (returnable or asset)" required></div><div class="col-2"><input class="form-control form-control-sm" type="number" step="any" min="0.01" name="qty" value="1" aria-label="Quantity"></div><div class="col-2"><button class="btn btn-sm btn-primary w-100">Add</button></div></form>
<datalist id="kitItems"><?php foreach($itemOpts as $o): ?><option value="<?=h($o['name'])?> (#<?=$o['id']?>)"><?php endforeach; ?></datalist></li><?php endif; ?></ul></div>
<?php if(can('checkout') && $lines): ?><div class="card"><div class="card-header fw-bold">Issue this kit</div><form method="post" class="card-body"><?=csrf_field()?><input type="hidden" name="action" value="issue"><div class="row g-2">
<div class="col-12"><div class="input-group"><input class="form-control" name="borrower" list="peopleList" placeholder="Issue to (person or agency, type or scan badge)" required><button type="button" class="btn btn-outline-secondary" data-badge-scan><i class="bi bi-upc-scan"></i></button></div><input type="hidden" name="person_id" value=""><div class="form-text badge-msg"></div><datalist id="peopleList"><?php foreach($people as $p): ?><option value="<?=h($p['name'])?>"><?php endforeach; ?></datalist></div>
<div class="col-md-6"><select class="form-select" name="incident_id"><option value="">No event / incident</option><?php foreach($incs as $n): ?><option value="<?=$n['id']?>"><?=h($n['name'])?></option><?php endforeach; ?></select></div>
<div class="col-md-3"><input class="form-control" type="date" name="due_at" title="Due back (assets)"></div><div class="col-md-3"><input class="form-control" name="note" placeholder="Note" maxlength="150"></div>
<div class="col-12"><button class="btn btn-primary">Issue whole kit</button> <span class="small text-muted">All or nothing: if any piece is short, nothing is issued.</span></div></div></form></div><?php endif; ?>
<?php include 'inc/footer.php'; ?>
<?php if(can('checkout') && $lines): $pl=q("SELECT id,name FROM people WHERE active=1 ORDER BY name")->fetchAll(); ?>
<script>(function(){
var people=<?=json_encode(array_column(array_map(fn($p)=>['k'=>mb_strtolower($p['name']),'v'=>(int)$p['id']],$pl),'v','k'))?>;
document.querySelectorAll('[data-badge-scan]').forEach(function(b){
  var f=b.closest('form'),inp=f.querySelector('input[name=borrower]'),hid=f.querySelector('input[name=person_id]'),msg=f.querySelector('.badge-msg');
  inp.addEventListener('input',function(){hid.value=people[inp.value.trim().toLowerCase()]||'';});
  b.addEventListener('click',function(){GPScan(function(code,fmt){
    fetch('people_lookup.php?code='+encodeURIComponent(code)).then(function(r){return r.json();}).then(function(d){
      if(d.ok){inp.value=d.name;hid.value=d.id;msg.className='form-text badge-msg text-success';msg.textContent='Badge read: '+d.name;}
      else{hid.value='';msg.className='form-text badge-msg text-danger';msg.textContent=d.reason==='inactive'?d.name+' is inactive and cannot be issued equipment.':'Badge not found ('+code+').';}
    }).catch(function(){msg.textContent='Could not look up the badge.';});
  });});
});})();</script><?php endif; ?>
