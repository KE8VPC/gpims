<?php require 'inc/bootstrap.php'; need_perm('labels_print'); $title='Labels';
$sel = $_POST['ids'] ?? (isset($_GET['ids']) ? (is_array($_GET['ids']) ? $_GET['ids'] : explode(',', $_GET['ids'])) : null);
$fmtChoice = $_POST['fmt'] ?? $_GET['fmt'] ?? 'auto'; if (!in_array($fmtChoice, ['auto','qr','code128','code39','itf'], true)) $fmtChoice = 'auto';
const LABEL_STYLES = [
  'std'   => ['Standard 2.4 x 1.3 in', 2.4, 1.3, 'side'],
  'avery' => ['Address sheet 2.6 x 1 in (30 per sheet)', 2.625, 1.0, 'side'],
  'small' => ['Small tag 1.5 x 0.8 in', 1.5, 0.8, 'side'],
  'strip' => ['Wide strip 3.5 x 1.1 in', 3.5, 1.1, 'wide'],
  'large' => ['Large 4 x 2.5 in (cases, bins)', 4.0, 2.5, 'stack'],
  'custom'=> ['Custom size', 2.4, 1.3, 'side'],
];
$style = $_POST['style'] ?? $_GET['style'] ?? 'std'; if (!isset(LABEL_STYLES[$style])) $style = 'std';
$cw = max(0.8, min(8, (float)($_POST['w'] ?? $_GET['w'] ?? 2.4))); $ch = max(0.5, min(8, (float)($_POST['h'] ?? $_GET['h'] ?? 1.3)));
$opt = ['name' => !isset($_POST['style']) || isset($_POST['o_name']), 'text' => !isset($_POST['style']) || isset($_POST['o_text']), 'loc' => !isset($_POST['style']) || isset($_POST['o_loc']), 'org' => !isset($_POST['style']) || isset($_POST['o_org'])];
$copies = max(1, min(50, (int)($_POST['copies'] ?? $_GET['copies'] ?? 1)));
$labels = null;
if ($sel) {
  $sel = array_values(array_filter(array_map('intval', (array)$sel)));
  if ($sel) $labels = q("SELECT i.*,l.name loc,(SELECT code_type FROM item_codes WHERE item_id=i.id AND is_primary=1 LIMIT 1) ptype FROM items i LEFT JOIN locations l ON l.id=i.location_id WHERE i.id IN (".implode(',', array_fill(0,count($sel),'?')).") AND ".scope_sql('i')." ORDER BY i.name", $sel)->fetchAll();
}
const LAB_PP = 50;
$LW = "i.status<>'retired' AND i.archived_at IS NULL AND i.deleted_at IS NULL AND ".scope_sql('i'); $LP = [];
if (($qq = trim($_GET['q'] ?? '')) !== '') { $LW .= " AND (i.name LIKE ? OR i.sku LIKE ? OR i.serial LIKE ? OR i.barcode LIKE ? OR EXISTS(SELECT 1 FROM item_codes ic WHERE ic.item_id=i.id AND ic.code LIKE ?))"; $LP = array_fill(0, 5, "%$qq%"); }
$labRow = function($i){ $code = item_code($i); $pt = $i['ptype'] ?: ($i['barcode'] ? 'other' : 'code128');
  return '<tr data-id="'.(int)$i['id'].'" data-name="'.h($i['name']).'" data-code="'.h($code).'" data-fmt="'.h(label_fmt('auto', $pt, $code)).'" data-loc="'.h($i['loc']).'"><td style="width:30px"><input type="checkbox" class="form-check-input pick" value="'.(int)$i['id'].'"></td><td>'.h($i['name']).'</td><td class="text-muted">'.h($i['sku']).'</td><td class="text-muted">'.h($code).'</td></tr>'; };
$labSel = "SELECT i.id,i.name,i.sku,i.type,i.barcode,l.name loc,(SELECT code_type FROM item_codes WHERE item_id=i.id AND is_primary=1 LIMIT 1) ptype FROM items i LEFT JOIN locations l ON l.id=i.location_id WHERE $LW ORDER BY i.name";
if (($_GET['ajax'] ?? '') === 'rows') {
  $total = (int)q("SELECT COUNT(*) FROM items i WHERE $LW", $LP)->fetchColumn(); $pages = max(1, (int)ceil($total / LAB_PP)); $pg = max(1, min($pages, (int)($_GET['pg'] ?? 1)));
  $html = ''; foreach (q("$labSel LIMIT ".LAB_PP." OFFSET ".(($pg - 1) * LAB_PP), $LP)->fetchAll() as $i) $html .= $labRow($i);
  header('Content-Type: application/json'); echo json_encode(['html' => $html, 'total' => $total, 'pages' => $pages, 'pg' => $pg, 'pp' => LAB_PP]); exit;
}
if (($_GET['ajax'] ?? '') === 'ids') { header('Content-Type: application/json'); echo json_encode(array_map('intval', q("SELECT i.id FROM items i WHERE $LW ORDER BY i.name LIMIT 3000", $LP)->fetchAll(PDO::FETCH_COLUMN))); exit; }
if ($labels === null) { $labTotal = (int)q("SELECT COUNT(*) FROM items i WHERE $LW", $LP)->fetchColumn(); $all = q("$labSel LIMIT ".LAB_PP)->fetchAll(); }
$styleJs = []; foreach (LABEL_STYLES as $k => $s) $styleJs[$k] = ['w'=>$s[1], 'h'=>$s[2], 'layout'=>$s[3]];
include 'inc/header.php'; ?>
<style>.lbl{display:inline-block;border:1px dashed #999;padding:.07in;margin:.04in;vertical-align:top;overflow:hidden;background:#fff;color:#000;font-size:11px;line-height:1.15;box-sizing:border-box}
.lbl .nm{font-weight:700;overflow:hidden}.lbl .cd{font-family:monospace}.lbl .row1{display:flex;align-items:center;gap:6px;height:100%}.lbl .code svg,.lbl .code canvas,.lbl .code img{display:block;max-width:100%}.lbl .meta{min-width:0;overflow:hidden}
.lbl.wide .row1,.lbl.stack .row1{flex-direction:column;align-items:flex-start;justify-content:center;gap:3px}
@media print{nav,footer,.noprint,.alert{display:none!important}body{background:#fff}main{padding:0!important}.lbl{border:1px dotted #bbb;margin:0}#sheet{line-height:0}.lbl{line-height:1.15}}</style>
<div class="noprint mb-3"><h1 class="h3 mb-2"><?=$labels===null?'Print labels':'Labels ready'?></h1></div>
<form method="post" id="lform" class="noprint"><?=csrf_field()?>
<?php if ($labels !== null): foreach ($sel as $sid): ?><input type="hidden" name="ids[]" value="<?=$sid?>"><?php endforeach; endif; ?>
<div class="row g-3 mb-3"><div class="col-lg-7"><div class="row g-2">
<div class="col-md-7"><label class="form-label small mb-0">Label style</label><select class="form-select" name="style" id="style"><?php foreach(LABEL_STYLES as $k=>$s): ?><option value="<?=$k?>"<?=$k===$style?' selected':''?>><?=h($s[0])?></option><?php endforeach; ?></select></div>
<div class="col-md-5"><label class="form-label small mb-0">Code type</label><select class="form-select" name="fmt" id="fmt"><?php foreach(['auto'=>"Each code's own type",'qr'=>'QR code','code128'=>'Code 128','code39'=>'Code 39','itf'=>'ITF (digits only)'] as $k=>$l): ?><option value="<?=$k?>"<?=$k===$fmtChoice?' selected':''?>><?=$l?></option><?php endforeach; ?></select></div>
<div class="col-6 col-md-3 cust"><label class="form-label small mb-0">Width (in)</label><input class="form-control" type="number" step="0.05" min="0.8" max="8" name="w" id="cw" value="<?=h($cw)?>"></div>
<div class="col-6 col-md-3 cust"><label class="form-label small mb-0">Height (in)</label><input class="form-control" type="number" step="0.05" min="0.5" max="8" name="h" id="chh" value="<?=h($ch)?>"></div>
<div class="col-6 col-md-3"><label class="form-label small mb-0">Copies each</label><input class="form-control" type="number" min="1" max="50" name="copies" value="<?=$copies?>"></div>
<div class="col-12 d-flex flex-wrap gap-3 small"><?php foreach(['name'=>'Item name','text'=>'Code text','loc'=>'Location','org'=>'Organization'] as $k=>$l): ?><label class="form-check"><input class="form-check-input opt" type="checkbox" name="o_<?=$k?>" id="o_<?=$k?>"<?=$opt[$k]?' checked':''?>> <span class="form-check-label"><?=$l?></span></label><?php endforeach; ?></div>
</div></div>
<div class="col-lg-5"><div class="small text-muted mb-1">Preview <span id="pvName"></span></div><div class="border rounded p-2 bg-body-tertiary overflow-auto"><div id="preview"></div></div></div></div>
<?php if ($labels === null): ?>
<div class="d-flex gap-2 mb-2 flex-wrap align-items-center"><input class="form-control w-auto" id="labQ" placeholder="Search name, SKU, code…" autocomplete="off"><button type="button" class="btn btn-outline-secondary" id="selAll">Select all on this page</button><button type="button" class="btn btn-outline-secondary" id="selMatch">Select all matching</button><button type="button" class="btn btn-link" id="selNone">Clear</button><button class="btn btn-primary">Create labels <span class="badge text-bg-light" id="selCount">0</span></button></div>
<div class="form-text mb-2">Ticked items stay ticked while you search and page. The first ticked item is used for the preview. "Each code's own type" reprints a stored UPC-A or EAN-13 as that type; anything that cannot be printed as the chosen type falls back to Code 128. The code printed is the one marked as primary on the item.</div>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0" id="filterTable"><tbody id="labRows">
<?php foreach($all as $i) echo $labRow($i); if(!$all) echo '<tr><td class="text-muted p-3">No items found.</td></tr>'; ?></tbody></table></div></div>
<div class="d-flex justify-content-between align-items-center mt-2 small text-muted"><span id="labInfo"><?=min($labTotal,LAB_PP)?> of <?=$labTotal?> item(s)</span><span><button type="button" class="btn btn-sm btn-outline-secondary" id="labPrev" disabled>&laquo; Prev</button> <span id="labPage">1 / <?=max(1,(int)ceil($labTotal/LAB_PP))?></span> <button type="button" class="btn btn-sm btn-outline-secondary" id="labNext" <?=$labTotal>LAB_PP?'':'disabled'?>>Next &raquo;</button></span></div>
<?php else: ?>
<div class="mb-3"><button type="button" class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button> <button class="btn btn-outline-secondary">Apply style changes</button> <a class="btn btn-link" href="labels.php">Back</a> <span class="text-muted small">Tip: set print margins to "None" or "Default" and scale to 100%.</span></div>
<?php endif; ?></form>
<?php if ($labels !== null): ?><div id="sheet"></div><?php endif; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jsbarcode/3.11.5/JsBarcode.all.min.js"></script>
<script>
var STYLES=<?=json_encode($styleJs)?>,ORG=<?=json_encode($cfg['app_name'])?>,JB={code128:'CODE128',code39:'CODE39',itf:'ITF',upca:'UPC',ean13:'EAN13'};
function g(id){return document.getElementById(id);}
function cur(){var k=g('style').value,s=STYLES[k]||STYLES.std,w=s.w,h=s.h;if(k==='custom'){w=parseFloat(g('cw').value)||2.4;h=parseFloat(g('chh').value)||1.3;}
 var lay=s.layout;if(k==='custom')lay=(w/h>2.4)?'wide':(h>w*0.9?'stack':'side');
 return {w:w,h:h,lay:lay,name:g('o_name').checked,text:g('o_text').checked,loc:g('o_loc').checked,org:g('o_org').checked};}
function drawCode(box,code,fmt,px){
 if(fmt==='qr'){try{new QRCode(box,{text:code,width:px,height:px,correctLevel:QRCode.CorrectLevel.M});}catch(e){box.textContent=code;}return;}
 var mk=function(f){var s=document.createElementNS('http://www.w3.org/2000/svg','svg');box.appendChild(s);JsBarcode(s,code,{format:f,height:Math.round(px*0.7),width:1.2,displayValue:false,margin:0});return s;};
 try{mk(JB[fmt]||'CODE128');}catch(x){box.innerHTML='';try{mk('CODE128');}catch(y){box.textContent=code;}}}
function label(o,d){var e=document.createElement('div');e.className='lbl '+o.lay;e.style.width=o.w+'in';e.style.height=o.h+'in';
 var nm=Math.max(9,Math.min(26,Math.round(o.h*9))),px=Math.round(Math.max(36,Math.min(o.lay==='stack'?150:96,Math.min(o.h*72-14,o.w*38))));
 var r=document.createElement('div');r.className='row1';var code=document.createElement('div');code.className='code';
 var meta=document.createElement('div');meta.className='meta';
 if(o.name){var n=document.createElement('div');n.className='nm';n.style.fontSize=nm+'px';n.textContent=d.name;meta.appendChild(n);}
 if(o.text){var c=document.createElement('div');c.className='cd';c.textContent=d.code;meta.appendChild(c);}
 if(o.loc&&d.loc){var l=document.createElement('div');l.textContent=d.loc;meta.appendChild(l);}
 if(o.org){var z=document.createElement('div');z.style.color='#555';z.textContent=ORG;meta.appendChild(z);}
 if(o.lay==='side'){r.appendChild(code);r.appendChild(meta);}else{if(o.name){r.appendChild(meta);}else r.appendChild(meta);r.appendChild(code);}
 e.appendChild(r);drawCode(code,d.code,d.fmt,px);return e;}
function fmtFor(d){var c=g('fmt').value;return c==='auto'?d.fmt:c;}
<?php if ($labels !== null): ?>
var DATA=<?=json_encode(array_map(function($i) use($fmtChoice){ $code=item_code($i); $pt=$i['ptype'] ?: ($i['barcode'] ? 'other' : 'code128'); return ['name'=>$i['name'],'code'=>$code,'fmt'=>label_fmt($fmtChoice,$pt,$code),'loc'=>$i['loc']]; }, $labels))?>,COPIES=<?=$copies?>;
function sheet(){var o=cur(),s=g('sheet');s.innerHTML='';DATA.forEach(function(d){for(var k=0;k<COPIES;k++)s.appendChild(label(o,d));});}
<?php endif; ?>
function preview(){var o=cur(),d={name:'Sample item name',code:'GPIMS-123',fmt:g('fmt').value==='auto'?'code128':g('fmt').value,loc:'Storage shed'},p=document.querySelector('.pick:checked');
 <?php if ($labels !== null): ?>if(DATA.length)d=DATA[0];<?php else: ?>var fs=SEL.values().next().value;if(fs&&fs.name){d={name:fs.name,code:fs.code,fmt:fmtFor({fmt:fs.fmt}),loc:fs.loc};}<?php endif; ?>
 g('pvName').textContent='('+o.w+' x '+o.h+' in)';var pv=g('preview');pv.innerHTML='';pv.appendChild(label(o,d));
 document.querySelectorAll('.cust').forEach(function(e){e.style.display=g('style').value==='custom'?'':'none';});}
['style','fmt','cw','chh'].forEach(function(i){g(i).addEventListener('input',preview);});document.querySelectorAll('.opt').forEach(function(e){e.addEventListener('change',preview);});
var SEL=new Map(),LQ='',LPG=1,LPAGES=<?=$labels===null?max(1,(int)ceil($labTotal/LAB_PP)):1?>,lt=null,lseq=0;
function rowData(tr){return {name:tr.dataset.name,code:tr.dataset.code,fmt:tr.dataset.fmt,loc:tr.dataset.loc};}
function cnt(){if(g('selCount'))g('selCount').textContent=SEL.size;}
function syncRows(){document.querySelectorAll('#labRows tr[data-id]').forEach(function(tr){var c=tr.querySelector('input');if(c)c.checked=SEL.has(tr.dataset.id);});cnt();}
function loadRows(pg){var my=++lseq;fetch('labels.php?ajax=rows&q='+encodeURIComponent(LQ)+'&pg='+pg,{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){if(my!==lseq)return;
 g('labRows').innerHTML=d.html||'<tr><td class="text-muted p-3">No items found.</td></tr>';LPG=d.pg;LPAGES=d.pages;g('labPage').textContent=d.pg+' / '+d.pages;g('labInfo').textContent=(d.total?((d.pg-1)*d.pp+1)+'–'+Math.min(d.total,d.pg*d.pp):0)+' of '+d.total+' item(s)';
 g('labPrev').disabled=d.pg<=1;g('labNext').disabled=d.pg>=d.pages;syncRows();});}
if(g('labRows')){
 g('labRows').addEventListener('change',function(e){var c=e.target.closest('.pick');if(!c)return;var tr=c.closest('tr');if(c.checked)SEL.set(tr.dataset.id,rowData(tr));else SEL.delete(tr.dataset.id);cnt();preview();});
 g('labQ').addEventListener('input',function(){clearTimeout(lt);lt=setTimeout(function(){LQ=g('labQ').value.trim();loadRows(1);},250);});
 g('labQ').addEventListener('keydown',function(e){if(e.key==='Enter')e.preventDefault();});
 g('labPrev').onclick=function(){if(LPG>1)loadRows(LPG-1);};g('labNext').onclick=function(){if(LPG<LPAGES)loadRows(LPG+1);};
 g('selAll').onclick=function(){document.querySelectorAll('#labRows tr[data-id]').forEach(function(tr){SEL.set(tr.dataset.id,rowData(tr));});syncRows();preview();};
 g('selNone').onclick=function(){SEL.clear();syncRows();preview();};
 g('selMatch').onclick=function(){fetch('labels.php?ajax=ids&q='+encodeURIComponent(LQ),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(ids){
   if(ids.length>=3000&&!confirm('Only the first 3000 matching items can be selected at once. Continue?'))return;
   var need=ids.filter(function(i){return !SEL.has(String(i));});
   // preview data is only known for rows we have seen; fetch is not needed for printing, so store a light record
   need.forEach(function(i){SEL.set(String(i),{name:'',code:'',fmt:'code128',loc:''});});syncRows();preview();});};
 g('lform').addEventListener('submit',function(){document.querySelectorAll('#lform input[name="ids[]"]').forEach(function(x){x.remove();});SEL.forEach(function(v,k){var h=document.createElement('input');h.type='hidden';h.name='ids[]';h.value=k;g('lform').appendChild(h);});});
}
window.addEventListener('load',function(){preview();<?php if ($labels !== null): ?>sheet();<?php endif; ?>});
</script>
<?php include 'inc/footer.php'; ?>
