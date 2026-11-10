<?php require 'inc/bootstrap.php'; need_perm('stock_move'); $title='Recount';
$view=(int)($_GET['view']??0);
if ($view) {
  $rc=q("SELECT r.*,u.name uname FROM recounts r LEFT JOIN users u ON u.id=r.user_id WHERE r.id=?",[$view])->fetch(); if(!$rc) redirect('recount.php');
  $rl=q("SELECT * FROM recount_lines WHERE recount_id=? ORDER BY ABS(counted-expected) DESC,item_name",[$view])->fetchAll();
  include 'inc/header.php'; ?>
<a href="recount.php" class="small">&larr; Recount</a><h1 class="h4 mt-1">Recount results</h1><p class="text-muted small"><?=h(date('M j, Y g:ia',strtotime($rc['created_at'])))?> by <?=h($rc['uname'])?> · <?=h($rc['scope'])?></p>
<div class="card"><table class="table table-sm mb-0"><thead><tr><th>Item</th><th class="text-end">Was</th><th class="text-end">Counted</th><th class="text-end">Difference</th></tr></thead><tbody>
<?php foreach($rl as $l): $d=(float)$l['counted']-(float)$l['expected']; ?><tr><td><?=$l['item_id']?'<a href="item_view.php?id='.$l['item_id'].'">'.h($l['item_name']).'</a>':h($l['item_name'])?></td><td class="text-end"><?=h(fmt_q($l['expected']))?></td><td class="text-end"><?=h(fmt_q($l['counted']))?></td><td class="text-end <?=$d<0?'text-danger':($d>0?'text-success':'text-muted')?>"><?=$d==0?'-':($d>0?'+':'').h(fmt_q($d))?></td></tr><?php endforeach; if(!$rl) echo '<tr><td class="text-muted p-3" colspan="4">No items were counted.</td></tr>'; ?></tbody></table></div>
<?php include 'inc/footer.php'; exit; }
$cat=(int)($_GET['cat']??$_POST['cat']??0); $loc=(int)($_GET['loc']??$_POST['loc']??0);
function recount_items($cat,$loc){
  $w="i.type<>'asset' AND i.archived_at IS NULL AND i.status<>'retired' AND ".scope_sql('i'); $p=[];
  if($cat){ $w.=" AND i.category_id IN (".implode(",",cat_descendants($cat)).")"; }
  if($loc){ $ids=loc_descendants($loc); $w.=" AND i.location_id IN (".implode(',',array_map('intval',$ids)).")"; }
  return q("SELECT i.*,l.name loc,(SELECT GROUP_CONCAT(code SEPARATOR '|') FROM item_codes WHERE item_id=i.id) codes FROM items i LEFT JOIN locations l ON l.id=i.location_id WHERE $w ORDER BY l.name,i.name",$p)->fetchAll();
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $items=recount_items($cat,$loc); $scope=[]; if($cat) $scope[]='category '.cat_path($cat); if($loc) $scope[]='location '.q("SELECT name FROM locations WHERE id=?",[$loc])->fetchColumn();
  q("INSERT INTO recounts(user_id,scope) VALUES(?,?)",[me()['id'],$scope?implode(', ',$scope):'All items']); $rid=(int)$pdo->lastInsertId(); $changed=0; $counted=0;
  foreach($items as $it){ $raw=trim($_POST['c'][$it['id']]??''); if($raw===''||!is_numeric($raw)||(float)$raw<0) continue; $n=round((float)$raw,2); $old=(float)$it['qty']; $counted++;
    q("INSERT INTO recount_lines(recount_id,item_id,item_name,expected,counted) VALUES(?,?,?,?,?)",[$rid,$it['id'],mb_substr($it['name'],0,150),$old,$n]);
    if(abs($n-$old)<0.0001) continue;
    q("UPDATE items SET qty=? WHERE id=?",[$n,$it['id']]); if($it['min_qty']>0 && $n>$it['min_qty']) q("UPDATE alert_notes SET cleared_at=NOW() WHERE item_id=? AND cleared_at IS NULL",[$it['id']]);
    tx_log($it['id'],'recount',$n,$n,'Recount: was '.fmt_q($old).', counted '.fmt_q($n)); try { notify_stock($it,$old,$n); } catch (Throwable $e) {} $changed++; }
  audit('recount',"$counted counted, $changed changed"); flash("Recount saved: $counted counted, $changed changed.",$changed?'warning':'success'); redirect("recount.php?view=$rid");
}
$items=($cat||$loc||isset($_GET['go']))?recount_items($cat,$loc):null;
$cats=cats_select(); $locs=locations_tree_flat();
include 'inc/header.php'; ?>
<h1 class="h4 mb-2">Recount</h1><p class="text-muted small">Walk a shelf or room and enter what you actually count. Leave an item blank to skip it. Only items you change are adjusted, and every change is logged with the old and new number. Scan an item's barcode to jump to its row.</p>
<form method="get" class="row g-2 mb-3"><div class="col-6 col-md-4"><select class="form-select" name="loc"><option value="">All locations</option><?php foreach($locs as $l): ?><option value="<?=$l['id']?>"<?=$l['id']==$loc?' selected':''?>><?=str_repeat('&nbsp;&nbsp;',$l['depth']).h($l['name'])?></option><?php endforeach; ?></select></div>
<div class="col-6 col-md-4"><select class="form-select" name="cat"><option value="">All categories</option><?php foreach($cats as $c): ?><option value="<?=$c['id']?>"<?=$c['id']==$cat?' selected':''?>><?=h($c['name'])?></option><?php endforeach; ?></select></div><div class="col-md-2"><button class="btn btn-primary w-100" name="go" value="1">Start</button></div></form>
<?php if($items!==null): ?><form method="post"><?=csrf_field()?><input type="hidden" name="cat" value="<?=$cat?>"><input type="hidden" name="loc" value="<?=$loc?>">
<div class="d-flex gap-2 mb-2 flex-wrap"><button type="button" class="btn btn-outline-secondary btn-sm" id="rcScan"><i class="bi bi-upc-scan"></i> Scan to find item</button><input class="form-control form-control-sm w-auto" id="quickFilter" placeholder="Filter…"><span class="small text-muted align-self-center" id="rcMsg"></span></div>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0 align-middle" id="filterTable"><thead><tr><th>Item</th><th class="text-end">System</th><th>Unit</th><th style="width:120px">Counted</th></tr></thead><tbody>
<?php foreach($items as $i): ?><tr data-codes="<?=h($i['codes'].'|'.$i['barcode'].'|GPIMS-'.$i['id'])?>"><td><?=h($i['name'])?><div class="small text-muted"><?=h($i['loc'])?><?=$i['type']==='returnable'?' · in storage only':''?></div></td><td class="text-end"><?=h(fmt_q($i['qty']))?></td><td class="text-muted"><?=h($i['unit'])?></td><td><input class="form-control form-control-sm" type="number" inputmode="decimal" step="any" min="0" name="c[<?=$i['id']?>]"></td></tr><?php endforeach; if(!$items) echo '<tr><td colspan="4" class="text-muted p-3">No items match.</td></tr>'; ?></tbody></table></div></div>
<?php if($items): ?><button class="btn btn-primary mt-3">Save recount</button><?php endif; ?></form>
<script>document.getElementById('rcScan').addEventListener('click',function(){GPScan(function(code){var m=document.getElementById('rcMsg'),hit=null;document.querySelectorAll('#filterTable tbody tr').forEach(function(r){if(!hit&&(r.dataset.codes||'').split('|').indexOf(code)>-1)hit=r;});
 if(hit){hit.style.display='';hit.scrollIntoView({block:'center'});var i=hit.querySelector('input');i.focus();hit.classList.add('table-warning');m.textContent='Found: '+hit.cells[0].firstChild.textContent;}else m.textContent='No item with code '+code+' in this list.';});});</script><?php endif; ?>
<?php $rcs=q("SELECT r.*,u.name uname,(SELECT COUNT(*) FROM recount_lines WHERE recount_id=r.id AND counted<>expected) diffs FROM recounts r LEFT JOIN users u ON u.id=r.user_id ORDER BY r.id DESC LIMIT 8")->fetchAll(); if($rcs): ?>
<h2 class="h6 text-muted text-uppercase mt-4">Recent recounts</h2><div class="card"><ul class="list-group list-group-flush"><?php foreach($rcs as $r): ?><li class="list-group-item d-flex justify-content-between"><a href="?view=<?=$r['id']?>"><?=h(date('M j g:ia',strtotime($r['created_at'])))?> · <?=h($r['scope'])?></a><span class="small text-muted"><?=h($r['uname'])?> · <?=$r['diffs']?$r['diffs'].' changed':'no changes'?></span></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php include 'inc/footer.php'; ?>
