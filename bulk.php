<?php require 'inc/bootstrap.php'; need_perm('items_edit'); $title='Bulk edit items';
// Same filters as the Inventory list, so "filter, then bulk edit" works: bulk.php?<inventory query string>
$w=['1=1']; $p=[]; $G=$_GET;
if ($s=trim($G['s']??'')) { $w[]='(i.name LIKE ? OR i.sku LIKE ? OR i.serial LIKE ? OR i.barcode LIKE ? OR EXISTS(SELECT 1 FROM item_codes ic WHERE ic.item_id=i.id AND ic.code LIKE ?))'; array_push($p,"%$s%","%$s%","%$s%","%$s%","%$s%"); }
if (($G['location_id']??'')!=='') $w[]='i.location_id IN ('.implode(',',loc_descendants((int)$G['location_id'])).')';
$w[]=scope_sql('i');
if (($G['category_id']??'')!=='') $w[]='i.category_id IN ('.implode(',',cat_descendants((int)$G['category_id'])).')';
foreach (['type'=>'i.type','status'=>'i.status'] as $k=>$col) if (($G[$k]??'')!=='') { $w[]="$col=?"; $p[]=$G[$k]; }
if (($G['status']??'')==='') $w[]="i.status<>'retired'";
$w[]='i.archived_at IS NULL AND i.deleted_at IS NULL';
if (!empty($G['low'])) $w[]="i.type<>'asset' AND i.min_qty>0 AND i.qty<=i.min_qty";
$where=implode(' AND ',$w); $back='items.php?'.http_build_query($G); $TYPES=['consumable'=>'Consumable','returnable'=>'Returnable (by quantity)','asset'=>'Asset / Equipment','fixed'=>'Fixed / Stationary'];
$cats=cats_select(); $locs=array_map(fn($x)=>['id'=>$x['id'],'name'=>str_repeat('— ',$x['depth']).$x['name']], locations_tree_flat());
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='apply') {
  $ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['ids']??[])))));
  $nt=$_POST['new_type']??''; if ($nt!=='' && !isset($TYPES[$nt])) $nt='';
  $cs=$_POST['new_category']??''; $ls=$_POST['new_location']??'';   // '' = leave, '0' = clear
  $catId=($cs!==''&&$cs!=='0')?(int)$cs:null; $locId=($ls!==''&&$ls!=='0')?(int)$ls:null;
  if ($catId && !isset(cats_by_id()[$catId])) { flash('That category was not found.','danger'); redirect($back); }
  if ($locId && !q("SELECT 1 FROM locations WHERE id=?",[$locId])->fetch()) { flash('That location was not found.','danger'); redirect($back); }
  if (!$ids) flash('No items were selected.','warning');
  elseif ($nt===''&&$cs===''&&$ls==='') flash('Choose at least one change to make.','warning');
  elseif (count($ids)>500) flash('Select up to 500 items at a time.','danger');
  else {
    global $pdo; $done=0; $skip=[];
    $rows=q("SELECT i.* FROM items i WHERE i.id IN (".implode(',',$ids).") AND i.archived_at IS NULL AND i.deleted_at IS NULL AND ".scope_sql('i'))->fetchAll();
    $pdo->beginTransaction();
    try {
      foreach ($rows as $it) { $id=(int)$it['id'];
        $new=['type'=>$nt!==''?$nt:$it['type'],'category_id'=>$cs===''?$it['category_id']:$catId,'location_id'=>$ls===''?$it['location_id']:$locId];
        if (!item_in_scope(['category_id'=>$new['category_id'],'location_id'=>$new['location_id']])) { $skip[]=$it['name'].' (outside your access)'; continue; }
        if ($new['type']!==$it['type']) {
          if ((int)q("SELECT COUNT(*) FROM checkouts WHERE item_id=? AND in_at IS NULL",[$id])->fetchColumn() || (int)q("SELECT COUNT(*) FROM issues l WHERE l.item_id=? AND l.closed_at IS NULL AND ".OUT_EXPR." > 0.0001",[$id])->fetchColumn()) { $skip[]=$it['name'].' (still has equipment out)'; continue; }
          if ($new['type']==='asset' && (float)$it['qty']!==1.0) { $skip[]=$it['name'].' (an asset must have a count of 1)'; continue; }
        }
        if ($new['type']===$it['type'] && (int)$new['category_id']===(int)$it['category_id'] && (int)$new['location_id']===(int)$it['location_id']) continue;
        q("UPDATE items SET type=?,category_id=?,location_id=? WHERE id=?",[$new['type'],$new['category_id'],$new['location_id'],$id]);
        log_item_changes($id,item_snapshot($it),item_snapshot(array_merge($it,$new))); $done++;
      }
      $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('GPIMS bulk: '.$e->getMessage()); flash('The change failed and nothing was changed.','danger'); redirect($back); }
    audit('bulk_edit',"$done item(s)".($nt!==''?" type=$nt":'').($cs!==''?' category':'').($ls!==''?' location':''));
    flash("Updated $done item(s)".($skip?'. Skipped '.count($skip).': '.implode('; ',array_slice($skip,0,8)).(count($skip)>8?'…':''):'.'),$skip?'warning':'success');
  }
  redirect($back);
}
$total=(int)q("SELECT COUNT(*) FROM items i WHERE $where",$p)->fetchColumn();
$rows=q("SELECT i.id,i.name,i.type,i.qty,i.unit,c.name cat,l.name loc FROM items i LEFT JOIN categories c ON c.id=i.category_id LEFT JOIN locations l ON l.id=i.location_id WHERE $where ORDER BY i.name LIMIT 500",$p)->fetchAll();
include 'inc/header.php'; ?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2"><h1 class="h3 mb-0">Bulk edit items</h1><a class="btn btn-sm btn-outline-secondary" href="<?=h($back)?>"><i class="bi bi-arrow-left"></i> Back to inventory</a></div>
<p class="text-muted small">Shows the items from your current Inventory filter (<?=$total?> match<?=$total>500?'; the first 500 are listed':''?>). Untick any you want to leave alone, choose what to change, then apply. Items with equipment still checked out can't change type.</p>
<form method="post" id="bulkForm"><?=csrf_field()?><input type="hidden" name="action" value="apply">
<div class="card card-body mb-3"><div class="row g-2">
<div class="col-md-4"><label class="form-label small mb-0">Change type to</label><select class="form-select" name="new_type"><option value="">Leave as is</option><?php foreach($TYPES as $k=>$l): ?><option value="<?=$k?>"><?=h($l)?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><label class="form-label small mb-0">Move to category</label><select class="form-select" name="new_category"><option value="">Leave as is</option><option value="0">No category</option><?php foreach($cats as $c): ?><option value="<?=$c['id']?>"><?=h($c['name'])?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><label class="form-label small mb-0">Move to location</label><select class="form-select" name="new_location"><option value="">Leave as is</option><option value="0">No location</option><?php foreach($locs as $c): ?><option value="<?=$c['id']?>"><?=h($c['name'])?></option><?php endforeach; ?></select></div>
</div><div class="mt-3 d-flex gap-2 align-items-center flex-wrap"><button class="btn btn-primary" id="bulkGo" data-confirm="Apply these changes to the ticked items?">Apply to <span id="bulkN"><?=count($rows)?></span> item(s)</button><button type="button" class="btn btn-sm btn-outline-secondary" id="bulkAll">Tick all</button><button type="button" class="btn btn-sm btn-outline-secondary" id="bulkNone">Untick all</button></div></div>
<div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th style="width:2rem"></th><th>Name</th><th>Type</th><th>Category</th><th>Location</th><th>Qty</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr><td><input class="form-check-input bk" type="checkbox" name="ids[]" value="<?=$r['id']?>" checked></td><td><a href="item_view.php?id=<?=$r['id']?>"><?=h($r['name'])?></a></td><td><?=h($TYPES[$r['type']]??$r['type'])?></td><td class="text-muted"><?=h($r['cat']??'')?></td><td class="text-muted"><?=h($r['loc']??'')?></td><td><?=$r['type']==='asset'?'':h($r['qty']+0)?></td></tr><?php endforeach; if(!$rows) echo '<tr><td colspan="6" class="text-muted">No items match the filter.</td></tr>'; ?>
</tbody></table></div></form>
<script>(function(){var b=document.querySelectorAll('.bk'),n=document.getElementById('bulkN');function c(){var k=0;b.forEach(function(x){if(x.checked)k++;});n.textContent=k;}
b.forEach(function(x){x.addEventListener('change',c);});document.getElementById('bulkAll').onclick=function(){b.forEach(function(x){x.checked=true;});c();};document.getElementById('bulkNone').onclick=function(){b.forEach(function(x){x.checked=false;});c();};})();</script>
<?php include 'inc/footer.php'; ?>
