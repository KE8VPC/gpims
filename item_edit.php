<?php require 'inc/bootstrap.php'; need('viewer');
$id=(int)($_GET['id']??0); $item = $id ? q("SELECT * FROM items WHERE id=?",[$id])->fetch() : null;
if ($id && !$item) redirect('items.php');
if (($id ? !can('items_edit') : !can('items_add')) || ($item && !item_in_scope($item))) { http_response_code(403); exit('Access denied.'); }
if ($item && $item['archived_at']) { flash('Restore this item before editing it.', 'warning'); redirect("item_view.php?id=$id"); }
$cats=cats_select(); $locs=array_map(fn($x)=>['id'=>$x['id'],'name'=>str_repeat('— ',$x['depth']).$x['name']], locations_tree_flat());
$fields=q("SELECT * FROM custom_fields ORDER BY category_id,sort,id")->fetchAll();
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $cat = $_POST['category_id']!=='' ? (int)$_POST['category_id'] : null; $loc = $_POST['location_id']!=='' ? (int)$_POST['location_id'] : null;
  $cd=[]; foreach($fields as $f) if (in_array((int)$f['category_id'], cat_lineage($cat), true) && isset($_POST['cf'][$f['id']])) $cd[$f['id']]=trim($_POST['cf'][$f['id']]);
  $type = in_array($_POST['type'] ?? '', ['asset','returnable'], true) ? $_POST['type'] : 'consumable';
  $codes=[]; $code_err=null; $dup=null; $seen=[]; $pri=isset($_POST['primary'])?(int)$_POST['primary']:-1;
  foreach ((array)($_POST['codes'] ?? []) as $ci=>$row) {
    $raw=trim($row['code'] ?? ''); if ($raw==='') continue;
    [$ct,$cc]=normalize_code($row['type'] ?? '', $raw);
    if ($e=validate_code($ct,$cc)) { $code_err=$e; break; }
    if (isset($seen[strtolower($cc)])) continue; $seen[strtolower($cc)]=1;
    if (!$dup && ($u=code_in_use($cc,$id))) $dup="Code $cc is already used by \"{$u['name']}\".";
    $codes[]=['code'=>$cc,'type'=>$ct,'primary'=>((int)$ci===$pri)?1:0];
  }
  if ($codes && !array_sum(array_column($codes,'primary'))) $codes[0]['primary']=1;
  $_POST['barcode']=''; foreach ($codes as $cr) if ($cr['primary']) $_POST['barcode']=$cr['code'];
  $cl=fn($k,$n)=>mb_substr(trim((string)($_POST[$k]??'')),0,$n);
  $vals=[$cl('name',150),$cl('sku',80)?:null,trim($_POST['barcode'])?:null,$type,$cat,$loc,$cl('description',2000),$cl('unit',20),(float)$_POST['min_qty'],$cl('serial',100)?:null,json_encode($cd),($_POST['par_qty']??'')!==''?(float)$_POST['par_qty']:null,($_POST['reorder_qty']??'')!==''?(float)$_POST['reorder_qty']:null,($_POST['unit_cost']??'')!==''?(float)$_POST['unit_cost']:null];
  if ($vals[0]==='') { flash('Name is required.','danger'); }
  elseif (!item_in_scope(['category_id'=>$cat,'location_id'=>$loc])) { flash('Choose a category and location you have access to.','danger'); }
  elseif ($code_err) { flash($code_err,'danger'); }
  elseif ($dup) { flash($dup,'danger'); }
  elseif ($vals[2] && q("SELECT id FROM items WHERE (barcode=? OR sku=?) AND id<>?",[$vals[2],$vals[2],$id])->fetch()) { flash('That barcode is already used by another item.','danger'); }
  elseif ($item && $item['type']!==$type && ((int)q("SELECT COUNT(*) FROM checkouts WHERE item_id=? AND in_at IS NULL",[$id])->fetchColumn() || (int)q("SELECT COUNT(*) FROM issues l WHERE l.item_id=? AND l.closed_at IS NULL AND ".OUT_EXPR." > 0.0001",[$id])->fetchColumn())) { flash('This item still has equipment out. Return it before changing the item type.','danger'); }
  elseif ($item) {
    q("UPDATE items SET name=?,sku=?,barcode=?,type=?,category_id=?,location_id=?,description=?,unit=?,min_qty=?,serial=?,custom_data=?,par_qty=?,reorder_qty=?,unit_cost=? WHERE id=?", array_merge($vals,[$id]));
    log_item_changes($id, item_snapshot($item), item_snapshot(array_merge($item, ['name'=>$vals[0],'sku'=>$vals[1],'barcode'=>$vals[2],'type'=>$vals[3],'category_id'=>$cat,'location_id'=>$loc,'description'=>$vals[6],'unit'=>$vals[7],'min_qty'=>$vals[8],'serial'=>$vals[9],'custom_data'=>$vals[10],'par_qty'=>$vals[11],'reorder_qty'=>$vals[12],'unit_cost'=>$vals[13],'_codes'=>codes_summary($codes)])));
    sync_item_codes($id,$codes);
    audit('item_update',"#$id {$vals[0]}"); flash('Item updated.'); redirect("item_view.php?id=$id");
  } else {
    $qty=(float)($_POST['qty']??0);
    q("INSERT INTO items(name,sku,barcode,type,category_id,location_id,description,unit,min_qty,serial,custom_data,par_qty,reorder_qty,unit_cost,qty) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", array_merge($vals,[$type!=='asset'?$qty:1]));
    $nid=$pdo->lastInsertId(); sync_item_codes($nid,$codes); if($type!=='asset' && $qty>0) q("INSERT INTO transactions(item_id,user_id,type,qty,balance,note) VALUES(?,?,?,?,?,?)",[$nid,me()['id'],'initial',$qty,$qty,'Initial stock']);
    $okp=0; $f=$_FILES['photos']??null; if ($f && is_array($f['name'])) foreach ($f['name'] as $k=>$_) { if ($okp>=4) break; if (($f['error'][$k]??4)!==UPLOAD_ERR_OK || $f['size'][$k]>15*1024*1024) continue; $pn=save_photo($f['tmp_name'][$k],$root.'/uploads'); if ($pn) { q("INSERT INTO item_photos(item_id,filename) VALUES(?,?)",[$nid,$pn]); $okp++; } }
    if (!$okp && !empty($_POST['lookup_photo'])) lookup_attach_photo($nid,$_POST['lookup_code']??'',$_POST['lookup_photo']);
    $exp=trim($_POST['exp_date']??''); if ($exp!=='' && $type!=='asset' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$exp)) q("INSERT INTO item_lots(item_id,qty,expires_on) VALUES(?,?,?)",[$nid,$qty>0?$qty:null,$exp]);
    log_item_event($nid,'created'); audit('item_create',"#$nid {$vals[0]}"); flash('Item created.'); redirect("item_view.php?id=$nid");
  }
}
$initCodes=[];
if ($_SERVER['REQUEST_METHOD']==='POST') { foreach ((array)($_POST['codes'] ?? []) as $ci=>$r) $initCodes[]=['code'=>$r['code'] ?? '','type'=>$r['type'] ?? '','primary'=>((int)$ci===(int)($_POST['primary'] ?? -1))]; }
elseif ($item) { foreach (q("SELECT * FROM item_codes WHERE item_id=? ORDER BY is_primary DESC,id",[$id])->fetchAll() as $r) $initCodes[]=['code'=>$r['code'],'type'=>$r['code_type'],'primary'=>(bool)$r['is_primary']]; }
elseif (!empty($_GET['barcode'])) { $initCodes[]=['code'=>$_GET['barcode'],'type'=>$_GET['ctype'] ?? '','primary'=>true]; }
$cd = $item ? (json_decode($item['custom_data']??'[]',true) ?: []) : [];
$v=fn($k,$d='')=>h($_POST[$k] ?? ($item[$k] ?? ($_GET[$k] ?? $d))); $title=$item?'Edit item':'Add item';
include 'inc/header.php'; ?>
<h1 class="h3 mb-3"><?=$title?></h1>
<form method="post" enctype="multipart/form-data" class="card card-body" style="max-width:820px"><?=csrf_field()?><input type="hidden" name="lookup_code" id="lkCode"><input type="hidden" name="lookup_photo" id="lkPhoto"><div class="row g-3">
<?php if(!$item): ?><div class="col-12"><div class="border rounded p-2 bg-body-tertiary"><div class="d-flex flex-wrap gap-2 align-items-center"><strong class="me-1"><i class="bi bi-lightning-charge"></i> Quick add</strong>
<button type="button" class="btn btn-sm btn-primary" id="qaScan"><i class="bi bi-upc-scan"></i> Scan a barcode</button>
<button type="button" class="btn btn-sm btn-outline-primary" id="qaPhotoCode"><i class="bi bi-camera"></i> Barcode from a photo</button>
<button type="button" class="btn btn-sm btn-outline-secondary" id="qaTake"><i class="bi bi-image"></i> Take a photo of the item</button>
<input type="file" id="bcFile" accept="image/*" capture="environment" hidden><input type="file" name="photos[]" id="itemPhotos" accept="image/*" multiple hidden></div>
<div class="small text-muted mt-1" id="lkMsg">Scan a product barcode and the name and photo are filled in for you (found in free public product databases, only the number is sent). You can edit everything before saving.</div>
<div id="lkBox" class="d-flex gap-2 align-items-center mt-2" style="display:none!important"></div></div></div><?php endif; ?>
<div class="col-md-8"><label class="form-label">Name *</label><input class="form-control" name="name" value="<?=$v('name')?>" required></div>
<div class="col-md-4"><label class="form-label">SKU / Tag #</label><input class="form-control" name="sku" value="<?=$v('sku')?>"></div>

<div class="col-md-4"><label class="form-label">Type</label><select class="form-select" name="type" id="type"><option value="consumable" <?=($item['type']??'')==='consumable'?'selected':''?>>Consumable (used up)</option><option value="returnable" <?=($item['type']??'')==='returnable'?'selected':''?>>Returnable by quantity (cones, flares, tarps)</option><option value="asset" <?=($item['type']??'')==='asset'?'selected':''?>>Asset / Equipment (checked out)</option></select></div>
<div class="col-md-4"><label class="form-label">Category</label><select class="form-select" name="category_id" id="category_id"><option value="">—</option><?php foreach($cats as $c): ?><option value="<?=$c['id']?>" <?=($item['category_id']??'')==$c['id']?'selected':''?>><?=h($c['name'])?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><label class="form-label">Location</label><select class="form-select" name="location_id"><option value="">—</option><?php foreach($locs as $c): ?><option value="<?=$c['id']?>" <?=($item['location_id']??'')==$c['id']?'selected':''?>><?=h($c['name'])?></option><?php endforeach; ?></select></div>
<?php if(!$item): ?><div class="col-md-4 only-consumable"><label class="form-label">Starting quantity</label><input class="form-control" type="number" step="any" name="qty" value="0"></div><?php endif; ?>
<div class="col-md-4 only-consumable"><label class="form-label">Unit</label><input class="form-control" name="unit" value="<?=$v('unit')?>" placeholder="each, box, pack…"></div>
<div class="col-md-4 only-consumable"><label class="form-label">Low-stock alert at</label><input class="form-control" type="number" step="any" name="min_qty" value="<?=$v('min_qty','0')?>"></div>
<div class="col-md-4 only-consumable"><label class="form-label">Par level (target stock)</label><input class="form-control" type="number" step="any" name="par_qty" value="<?=$v('par_qty')?>"></div>
<div class="col-md-4 only-consumable"><label class="form-label">Reorder quantity</label><input class="form-control" type="number" step="any" name="reorder_qty" value="<?=$v('reorder_qty')?>"></div>
<?php if(!$item): ?><div class="col-md-4 only-consumable"><label class="form-label">Expiry date (optional)</label><input class="form-control" type="date" name="exp_date" value="<?=$v('exp_date')?>"><div class="form-text">You can add more dates and lots on the item later.</div></div><?php endif; ?>
<div class="col-md-4"><label class="form-label">Unit cost</label><input class="form-control" type="number" step="0.01" name="unit_cost" value="<?=$v('unit_cost')?>"></div>
<div class="col-md-6 only-asset"><label class="form-label">Serial number</label><input class="form-control" name="serial" value="<?=$v('serial')?>"></div>
<div class="col-12"><label class="form-label">Description / notes</label><textarea class="form-control" name="description" rows="3"><?=$v('description')?></textarea></div>
<div class="col-12"><label class="form-label">Codes (barcodes and QR codes)</label><div id="codeRows"></div>
<button type="button" class="btn btn-sm btn-outline-secondary" id="addCode">+ Add code</button> <button type="button" class="btn btn-sm btn-outline-secondary" id="scanCode"><i class="bi bi-upc-scan"></i> Scan a code</button>
<div class="form-text">Scanning any of these finds the item. The selected one is printed on your own labels. A manufacturer's UPC can sit alongside your own code.</div></div>
<script>window.CAT_LINEAGE=<?=json_encode((object)array_combine(array_map('strval',array_column($cats,'id')),array_map(fn($c)=>cat_lineage($c['id']),$cats)))?>;</script>
<?php foreach($fields as $f): $val=$cd[$f['id']]??''; ?><div class="col-md-6 cf-wrap" data-cat="<?=$f['category_id']?>"><label class="form-label"><?=h($f['label'])?></label>
<?php if($f['field_type']==='select'): ?><select class="form-select" name="cf[<?=$f['id']?>]" disabled><option value=""></option><?php foreach(array_filter(array_map('trim',explode(',',$f['options']))) as $o): ?><option <?=$o===$val?'selected':''?>><?=h($o)?></option><?php endforeach; ?></select>
<?php else: ?><input class="form-control" type="<?=$f['field_type']?>" step="any" name="cf[<?=$f['id']?>]" value="<?=h($val)?>" disabled><?php endif; ?></div><?php endforeach; ?>
</div><div class="mt-4"><button class="btn btn-primary">Save</button> <a class="btn btn-link" href="<?=$item?'item_view.php?id='.$id:'items.php'?>">Cancel</a></div></form>
<?php include 'inc/footer.php'; ?>
<script>(function(){
var box=document.getElementById('codeRows'),n=0,TYPES=<?=json_encode(CODE_TYPES)?>,init=<?=json_encode($initCodes)?>;
function row(c){var i=n++,d=document.createElement('div');d.className='input-group mb-1';
 d.innerHTML='<span class="input-group-text" title="Printed on your own labels"><input class="form-check-input mt-0" type="radio" name="primary" value="'+i+'"></span><input class="form-control" name="codes['+i+'][code]" placeholder="Scan or type a code"><select class="form-select" style="max-width:200px" name="codes['+i+'][type]"><option value="">Auto-detect</option>'+Object.keys(TYPES).map(function(k){return '<option value="'+k+'">'+TYPES[k]+'</option>';}).join('')+'</select><button type="button" class="btn btn-outline-danger" title="Remove">&times;</button>';
 var ins=d.querySelectorAll('input');ins[1].value=c.code||'';d.querySelector('select').value=c.type||'';if(c.primary)ins[0].checked=true;
 d.querySelector('button').onclick=function(){d.remove();};box.appendChild(d);return d;}
init.forEach(row);
document.getElementById('addCode').onclick=function(){row({});};
window.GPAddCode=function(v,f){var have=false;box.querySelectorAll('input[name$="[code]"]').forEach(function(i){if(i.value===v)have=true;});if(!have){var rows=box.querySelectorAll('input[name$="[code]"]'),last=rows[rows.length-1];if(last&&!last.value){last.value=v;last.closest('.input-group').querySelector('select').value=GPCodeType(f);}else row({code:v,type:GPCodeType(f),primary:!rows.length});}};
document.getElementById('scanCode').onclick=function(){GPScan(function(v,f){row({code:v,type:GPCodeType(f)});});};
})();</script>
<script>
(function(){
var msg=document.getElementById('lkMsg'),box=document.getElementById('lkBox'),LK=<?=lookup_enabled()?'true':'false'?>;
function $(i){return document.getElementById(i);}
function setIf(el,v){if(el&&v&&!el.value.trim()){el.value=v;el.dispatchEvent(new Event('input'));el.dispatchEvent(new Event('change'));}}
function lookup(code){
 code=String(code||'').replace(/\D/g,'');if(!LK||!/^\d{8,14}$/.test(code)||!msg)return;
 msg.textContent='Looking up '+code+'...';
 fetch('lookup.php?code='+code,{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
  if(d.existing){msg.innerHTML='';var a=document.createElement('a');a.href='item_view.php?id='+d.existing.id;a.textContent=d.existing.name;msg.appendChild(document.createTextNode('That code is already in your inventory as '));msg.appendChild(a);msg.appendChild(document.createTextNode('.'));return;}
  if(!d.found){msg.textContent=d.reason||'No product found for that code. Type the details in yourself.';return;}
  var nm=d.name;if(d.brand&&nm.toLowerCase().indexOf(d.brand.toLowerCase())<0)nm=d.brand+' '+nm;
  setIf(document.querySelector('[name=name]'),nm);setIf(document.querySelector('[name=description]'),[d.brand,d.size].filter(Boolean).join(' · '));
  if(d.category_id){var c=$('category_id');if(c&&!c.value){c.value=String(d.category_id);c.dispatchEvent(new Event('change'));}}
  $('lkCode').value=code;
  msg.textContent='Filled in from '+d.source+'. Check it over, add the quantity and location, then save.';
  box.innerHTML='';box.style.removeProperty('display');
  if(d.image&&d.image_url){$('lkPhoto').value=d.image;var im=document.createElement('img');im.src=d.image_url;im.width=56;im.height=56;im.className='rounded';im.style.objectFit='cover';im.alt='';box.appendChild(im);var t=document.createElement('span');t.className='small text-muted';t.textContent='This photo will be added to the item (unless you take your own).';box.appendChild(t);}
 }).catch(function(){msg.textContent='Lookup is not available right now. Type the details in yourself.';});
}
window.GPLookup=lookup;
var qs=$('qaScan');if(qs){
 qs.onclick=function(){GPScan(function(v,f){window.GPAddCode&&window.GPAddCode(v,f);lookup(v);});};
 $('qaPhotoCode').onclick=function(){$('bcFile').click();};
 $('bcFile').onchange=function(){var f=this.files[0];if(!f)return;msg.textContent='Reading the barcode from the photo...';GPScanFile(f,function(v,fm){window.GPAddCode&&window.GPAddCode(v,fm);lookup(v);},function(){msg.textContent='Could not find a barcode in that photo. Try again closer and in good light, or use Scan a barcode.';});this.value='';};
 $('qaTake').onclick=function(){$('itemPhotos').click();};
 $('itemPhotos').onchange=function(){var n=this.files.length;if(n)msg.textContent=n+' photo'+(n==1?'':'s')+' will be added when you save.';};
 <?php if(!empty($_GET['barcode'])): ?>window.addEventListener('load',function(){lookup(<?=json_encode($_GET['barcode'])?>);});<?php endif; ?>
}
})();</script>
