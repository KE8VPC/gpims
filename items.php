<?php require 'inc/bootstrap.php'; need('viewer'); $title='Inventory';
$w=['1=1']; $p=[];
if ($s=trim($_GET['s']??'')) { $w[]='(i.name LIKE ? OR i.sku LIKE ? OR i.serial LIKE ? OR i.barcode LIKE ? OR EXISTS(SELECT 1 FROM item_codes ic WHERE ic.item_id=i.id AND ic.code LIKE ?))'; array_push($p,"%$s%","%$s%","%$s%","%$s%","%$s%"); }
if (($_GET['location_id']??'')!=='') $w[]='i.location_id IN ('.implode(',',loc_descendants((int)$_GET['location_id'])).')';
$w[]=scope_sql('i');
if (($_GET['category_id']??'')!=='') $w[]='i.category_id IN ('.implode(',',cat_descendants((int)$_GET['category_id'])).')';
foreach (['type'=>'i.type','status'=>'i.status'] as $k=>$col) if (($_GET[$k]??'')!=='') { $w[]="$col=?"; $p[]=$_GET[$k]; }
if (($_GET['status']??'')==='') $w[]="i.status<>'retired'";
$w[] = !empty($_GET['archived']) ? 'i.archived_at IS NOT NULL AND i.deleted_at IS NULL' : 'i.archived_at IS NULL';
$EXPW=max(1,min(365,(int)setting('expiry_warn_days',30)?:30));
if (!empty($_GET['expiring'])) $w[]="EXISTS(SELECT 1 FROM item_lots lt WHERE lt.item_id=i.id AND lt.expires_on IS NOT NULL AND lt.expires_on<=DATE_ADD(CURDATE(),INTERVAL $EXPW DAY))";
if (!empty($_GET['low'])) $w[]="i.type<>'asset' AND i.min_qty>0 AND i.qty<=i.min_qty";
$where=implode(' AND ',$w);
$SEL="SELECT i.*,c.name cat,l.name loc,(SELECT filename FROM item_photos WHERE item_id=i.id ORDER BY id LIMIT 1) photo,(SELECT MIN(expires_on) FROM item_lots lt WHERE lt.item_id=i.id AND lt.expires_on IS NOT NULL) nexp FROM items i LEFT JOIN categories c ON c.id=i.category_id LEFT JOIN locations l ON l.id=i.location_id";
if (isset($_GET['export'])) {
  $rows=q("$SEL WHERE $where ORDER BY i.name",$p)->fetchAll(); header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="inventory-'.date('Ymd').'.csv"');
  $o=fopen('php://output','w'); fputcsv($o,['ID','Name','SKU','Type','Category','Location','Qty','Unit','Min','Serial','Status','Next expiry']);
  foreach($rows as $r) fputcsv($o,[$r['id'],$r['name'],$r['sku'],$r['type'],cat_path($r['category_id']),$r['loc'],$r['qty'],$r['unit'],$r['min_qty'],$r['serial'],$r['status'],$r['nexp']]); exit;
}
$rowFn=function($i,$showCat){ $low=$i['type']!=='asset'&&$i['min_qty']>0&&$i['qty']<=$i['min_qty']; ob_start(); ?>
<tr><td style="width:56px"><?php if($i['photo']): ?><img src="uploads/<?=h($i['photo'])?>" alt="" width="44" height="44" class="rounded" style="object-fit:cover" loading="lazy"><?php else: ?><div class="rounded bg-body-secondary text-center text-muted" style="width:44px;height:44px;line-height:44px"><i class="bi bi-image"></i></div><?php endif; ?></td><td><a href="item_view.php?id=<?=$i['id']?>" class="fw-semibold"><?=h($i['name'])?></a><?php if(!empty($i['nexp'])): $xd=(int)round((strtotime($i['nexp'])-strtotime(date('Y-m-d')))/86400); if($xd<0): ?> <span class="badge text-bg-danger" title="Expired <?=h($i['nexp'])?>">expired</span><?php elseif($xd<=$GLOBALS['EXPW']): ?> <span class="badge text-bg-warning" title="Expires <?=h($i['nexp'])?>">exp <?=h(date('M j',strtotime($i['nexp'])))?></span><?php endif; endif; ?></td><td><?=h($i['sku'])?></td><td><?=h($i['type'])?></td><?php if($showCat): ?><td><?=h(cat_path($i['category_id']))?></td><?php endif; ?><td><?=h($i['loc'])?></td>
<td><?php if($i['type']!=='asset'): ?><span class="<?=$low?'text-danger fw-bold':''?>"><?=h($i['qty']+0)?></span><?=$low?' <span class="badge text-bg-danger">LOW</span>':''?><?php else: ?><span class="text-muted">&mdash;</span><?php endif; ?></td>
<td class="text-muted"><?=$i['type']!=='asset'?h($i['unit']):''?></td>
<td><?php if($i['type']==='asset') echo status_badge($i['status']); else echo '<span class="text-muted">&mdash;</span>'; ?></td></tr>
<?php return ob_get_clean(); };
$thead=function($showCat){ return '<thead><tr><th style="width:56px"></th><th>Name</th><th>SKU</th><th>Type</th>'.($showCat?'<th>Category</th>':'').'<th>Location</th><th>Qty</th><th>Unit</th><th>Status</th></tr></thead>'; };

const INV_LAZY_OVER = 120;   // bigger result sets load each category's items on demand
$PPS=[25,50,100,200]; $pp=in_array((int)($_GET['pp']??50),$PPS,true)?(int)($_GET['pp']??50):50; $flat=($_GET['view']??'')==='flat';
$ajax=$_GET['ajax']??'';
if ($ajax==='cat') { // rows of one category (own items only), for the by-category view
  $c=(int)($_GET['c']??0); $off=max(0,(int)($_GET['off']??0));
  $cw=$where.' AND '.($c?'i.category_id=?':'i.category_id IS NULL'); $cp=$c?array_merge($p,[$c]):$p;
  $n=(int)q("SELECT COUNT(*) FROM items i WHERE $cw",$cp)->fetchColumn();
  $rows=q("$SEL WHERE $cw ORDER BY i.name LIMIT $pp OFFSET $off",$cp)->fetchAll(); $html=''; foreach($rows as $i) $html.=$rowFn($i,false);
  $next=$off+$pp<$n?$off+$pp:null; header('Content-Type: application/json'); echo json_encode(['html'=>$html,'next'=>$next,'left'=>max(0,$n-$off-count($rows))]); exit;
}
$total=(int)q("SELECT COUNT(*) FROM items i WHERE $where",$p)->fetchColumn();
$pages=max(1,(int)ceil($total/$pp)); $pg=max(1,min($pages,(int)($_GET['pg']??1)));
$lazy=!$flat && $total>INV_LAZY_OVER;
$vq=$_GET; unset($vq['ajax'],$vq['pg'],$vq['c'],$vq['off']);
$link=fn($extra=[])=>'?'.http_build_query(array_filter(array_merge($vq,$extra),fn($v)=>$v!==null));
$render=function() use($where,$p,$SEL,$rowFn,$thead,$flat,$pp,$pg,$pages,$total,$lazy,$PPS,$vq,$link){
  $flatLink=$link(['view'=>'flat','pg'=>null]); $uv=$vq; unset($uv['view']); $groupLink='?'.http_build_query($uv); $PPSEL=$pp; ?>
<div class="d-flex flex-wrap gap-2 mb-2 align-items-center"><div class="btn-group btn-group-sm"><a class="btn btn-<?=$flat?'outline-secondary':'secondary'?> inv-nav" href="<?=h($groupLink)?>"><i class="bi bi-folder2-open"></i> By category</a><a class="btn btn-<?=$flat?'secondary':'outline-secondary'?> inv-nav" href="<?=h($flatLink)?>"><i class="bi bi-list"></i> Flat list</a></div>
<?php if(!$flat): ?><?php if(!$lazy): ?><button type="button" class="btn btn-sm btn-outline-secondary" id="invExpand"><i class="bi bi-arrows-expand"></i> Expand all</button><?php endif; ?><button type="button" class="btn btn-sm btn-outline-secondary" id="invCollapse"><i class="bi bi-arrows-collapse"></i> Collapse all</button><?php endif; ?>
<span class="ms-auto small text-muted"><label class="me-1" for="invPP">Show</label><select class="form-select form-select-sm d-inline-block w-auto" id="invPP"><?php foreach($PPS as $n): ?><option value="<?=$n?>"<?=$n===$PPSEL?' selected':''?>><?=$n?></option><?php endforeach; ?></select> <?=$lazy?'per category':'per page'?></span></div>
<?php if($flat): $off=($pg-1)*$pp; $items=q("$SEL WHERE $where ORDER BY i.name LIMIT $pp OFFSET $off",$p)->fetchAll(); ?>
<div class="card"><div class="table-responsive"><table class="table table-hover mb-0"><?=$thead(true)?><tbody><?php foreach($items as $i) echo $rowFn($i,true); if(!$items) echo '<tr><td colspan="9" class="text-center text-muted py-4">No items found.</td></tr>'; ?></tbody></table></div></div>
<?php if($pages>1): $win=range(max(1,$pg-2),min($pages,$pg+2)); ?>
<nav class="mt-3" aria-label="Pages"><ul class="pagination pagination-sm justify-content-center mb-1 flex-wrap">
<li class="page-item<?=$pg<=1?' disabled':''?>"><a class="page-link inv-nav" href="<?=h($link(['pg'=>$pg-1]))?>">&laquo; Prev</a></li>
<?php if($win[0]>1): ?><li class="page-item"><a class="page-link inv-nav" href="<?=h($link(['pg'=>1]))?>">1</a></li><?php if($win[0]>2): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; endif; ?>
<?php foreach($win as $n): ?><li class="page-item<?=$n===$pg?' active':''?>"><a class="page-link inv-nav" href="<?=h($link(['pg'=>$n]))?>"><?=$n?></a></li><?php endforeach; ?>
<?php if(end($win)<$pages): if(end($win)<$pages-1): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?><li class="page-item"><a class="page-link inv-nav" href="<?=h($link(['pg'=>$pages]))?>"><?=$pages?></a></li><?php endif; ?>
<li class="page-item<?=$pg>=$pages?' disabled':''?>"><a class="page-link inv-nav" href="<?=h($link(['pg'=>$pg+1]))?>">Next &raquo;</a></li></ul></nav><?php endif; ?>
<div class="text-muted small mt-2 text-center"><?=$total?($off+1).'–'.min($total,$off+$pp).' of '.$total:'0'?> item(s)</div>
<?php else:
  $cnt=[]; foreach(q("SELECT i.category_id cid,COUNT(*) n FROM items i WHERE $where GROUP BY i.category_id",$p)->fetchAll() as $r) $cnt[(int)$r['cid']]=(int)$r['n'];
  $byCat=[]; if(!$lazy) foreach(q("$SEL WHERE $where ORDER BY i.name",$p)->fetchAll() as $i) $byCat[(int)$i['category_id']][]=$i;
  $kids=cat_children_map(); $known=cats_by_id();
  $subtotal=function($id) use(&$subtotal,$kids,$cnt){ $n=$cnt[$id]??0; foreach($kids[$id]??[] as $k) $n+=$subtotal((int)$k['id']); return $n; };
  $drawn=[]; $ownBody=function($id,$own) use($lazy,$cnt,$thead,$rowFn){
    if($lazy){ $n=$cnt[$id]??0; if(!$n) return; ?><div class="inv-lazy" data-c="<?=$id?>"><div class="card mb-2"><div class="table-responsive"><table class="table table-hover table-sm mb-0"><?=$thead(false)?><tbody></tbody></table></div></div><div class="text-center mb-2 inv-more" hidden><button type="button" class="btn btn-sm btn-outline-secondary">Show more</button></div></div><?php return; }
    if($own): ?><div class="card mb-2"><div class="table-responsive"><table class="table table-hover table-sm mb-0"><?=$thead(false)?><tbody><?php foreach($own as $i) echo $rowFn($i,false); ?></tbody></table></div></div><?php endif; };
  $section=function($c,$depth) use(&$section,$kids,$byCat,$ownBody,&$drawn,$subtotal){
    $id=(int)$c['id']; if(isset($drawn[$id])) return; $drawn[$id]=1; $tot=$subtotal($id); if(!$tot) return; $own=$byCat[$id]??[]; ?>
<div class="inv-sec mb-2" data-id="<?=$id?>"><div class="d-flex align-items-center gap-2 py-1 px-2 border rounded bg-body-tertiary"><button type="button" class="btn btn-sm btn-link p-0 text-body inv-toggle" data-target="ic<?=$id?>"><i class="bi bi-chevron-down"></i></button><i class="bi bi-folder2-open text-muted"></i><strong class="flex-grow-1"><?=h($c['name'])?></strong><?php if($depth): ?><span class="small text-muted d-none d-md-inline"><?=h(cat_path($id))?></span><?php endif; ?><span class="badge text-bg-secondary"><?=$tot?></span></div>
<div class="inv-body ms-1 ms-md-3 ps-1 ps-md-2 border-start mt-1" id="ic<?=$id?>"><?php $ownBody($id,$own); foreach($kids[$id]??[] as $k) $section($k,$depth+1); ?></div></div>
<?php };
  echo '<div id="invTree" data-lazy="'.($lazy?1:0).'">';
  foreach(cats_tree_flat() as $t) if($t['depth']===0) $section($t['row'],0);
  $unc=0; $uncat=[]; foreach($cnt as $cid=>$n) if(!$cid || !isset($known[$cid])) $unc+=$n;
  if(!$lazy) foreach($byCat as $cid=>$rs) if(!$cid || !isset($known[$cid])) foreach($rs as $i) $uncat[]=$i;
  if($unc): ?>
<div class="inv-sec mb-2"><div class="d-flex align-items-center gap-2 py-1 px-2 border rounded bg-body-tertiary"><button type="button" class="btn btn-sm btn-link p-0 text-body inv-toggle" data-target="icU"><i class="bi bi-chevron-down"></i></button><i class="bi bi-question-circle text-muted"></i><strong class="flex-grow-1">Uncategorized</strong><span class="badge text-bg-secondary"><?=$unc?></span></div>
<div class="inv-body ms-1 ms-md-3 ps-1 ps-md-2 border-start mt-1" id="icU"><?php $ownBody(0,$uncat); ?></div></div>
<?php endif; if(!$total) echo '<div class="card card-body text-center text-muted">No items found.</div>'; echo '</div>'; ?>
<div class="text-muted small mt-2 text-center"><?=$total?> item(s)<?=$lazy?' · open a category to see its items':''?></div>
<?php endif; };
if ($ajax==='list') { $render(); exit; }
$cats=cats_select(); $locs=array_map(fn($x)=>['id'=>$x['id'],'name'=>str_repeat('— ',$x['depth']).$x['name']], locations_tree_flat());
$sel=fn($a,$k)=>(($_GET[$k]??'')==$a)?'selected':'';
include 'inc/header.php'; ?>
<div class="d-flex justify-content-between mb-3"><h1 class="h3">Inventory</h1><div><a class="btn btn-outline-primary btn-sm" href="scan.php"><i class="bi bi-upc-scan"></i> Scan</a> 
<a class="btn btn-outline-secondary" id="invCsv" href="?<?=h(http_build_query($_GET+['export'=>1]))?>"><i class="bi bi-download"></i> CSV</a>
<?php if(can('items_add')): ?><a class="btn btn-primary" href="item_edit.php"><i class="bi bi-plus-lg"></i> Add item</a><?php endif; ?></div></div>
<form class="row g-2 mb-3" id="invForm" method="get"><input type="hidden" name="view" value="<?=h($_GET['view']??'')?>"><input type="hidden" name="pp" value="<?=$pp?>"><div class="col-md-3"><input class="form-control" name="s" value="<?=h($s)?>" placeholder="Search name, SKU, serial"></div>
<div class="col-md-2"><select class="form-select" name="type"><option value="">All types</option><option value="consumable" <?=$sel('consumable','type')?>>Consumables</option><option value="returnable" <?=$sel('returnable','type')?>>Returnable (by qty)</option><option value="asset" <?=$sel('asset','type')?>>Assets</option></select></div>
<div class="col-md-2"><select class="form-select" name="category_id"><option value="">All categories</option><?php foreach($cats as $c): ?><option value="<?=$c['id']?>" <?=$sel($c['id'],'category_id')?>><?=h($c['name'])?></option><?php endforeach; ?></select></div>
<div class="col-md-2"><select class="form-select" name="location_id"><option value="">All locations</option><?php foreach($locs as $c): ?><option value="<?=$c['id']?>" <?=$sel($c['id'],'location_id')?>><?=h($c['name'])?></option><?php endforeach; ?></select></div>
<div class="col-md-2"><select class="form-select" name="status"><option value="">Active</option><?php foreach(['available','checked_out','maintenance','retired'] as $st): ?><option value="<?=$st?>" <?=$sel($st,'status')?>><?=$st?></option><?php endforeach; ?></select></div>
<div class="col-md-1 d-grid"><button class="btn btn-secondary">Go</button></div>
<div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="low" value="1" id="low" <?=!empty($_GET['low'])?'checked':''?>><label class="form-check-label" for="low">Low stock only</label></div> <div class="form-check d-inline-block ms-3"><input class="form-check-input" type="checkbox" name="expiring" value="1" id="expg" <?=!empty($_GET['expiring'])?'checked':''?>><label class="form-check-label" for="expg">Expired or expiring soon</label></div> <div class="form-check d-inline-block ms-3"><input class="form-check-input" type="checkbox" name="archived" value="1" id="arch" <?=!empty($_GET['archived'])?'checked':''?>><label class="form-check-label" for="arch">Show archived</label></div></div></form>
<div id="invResults"><?php $render(); ?></div>
<?php include 'inc/footer.php'; ?>
