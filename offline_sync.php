<?php require 'inc/bootstrap.php'; need('viewer'); if (me()['role']==='vendor') redirect('supply.php'); $title='Offline scans';
function js_out($d, $code = 200){ http_response_code($code); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo json_encode($d); exit; }
// Lookup list the phone keeps so the offline page can name scanned codes.
if (isset($_GET['index'])) {
  if (!can('stock_move')) js_out(['error' => 'Not allowed'], 403);
  $out = [];
  foreach (q("SELECT id,name,type,qty,unit,barcode,sku,serial FROM items WHERE archived_at IS NULL AND status<>'retired' AND type IN ('consumable','returnable') AND ".scope_sql('items')." ORDER BY name LIMIT 5000")->fetchAll() as $r) {
    $codes = array_filter([$r['barcode'], $r['sku'], $r['serial'], 'GPIMS-'.$r['id']]);
    $out[(int)$r['id']] = ['id' => (int)$r['id'], 'n' => $r['name'], 't' => $r['type'], 'q' => (float)$r['qty'], 'u' => (string)$r['unit'], 'c' => array_map('strtoupper', array_values($codes))];
  }
  foreach (q("SELECT c.item_id,c.code FROM item_codes c")->fetchAll() as $c) if (isset($out[(int)$c['item_id']])) $out[(int)$c['item_id']]['c'][] = strtoupper($c['code']);
  js_out(['items' => array_values($out), 'at' => time()]);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!can('stock_move')) js_out(['error' => 'You do not have permission to change stock.'], 403);
  $in = json_decode(file_get_contents('php://input'), true); $entries = is_array($in['entries'] ?? null) ? array_slice($in['entries'], 0, 200) : [];
  $res = [];
  foreach ($entries as $e) {
    $eid = (string)($e['id'] ?? ''); $code = trim((string)($e['code'] ?? '')); $mode = (string)($e['mode'] ?? ''); $n = (float)($e['qty'] ?? 0); $at = (int)(($e['at'] ?? 0) / 1000);
    $r = ['id' => $eid, 'ok' => false, 'msg' => '', 'name' => '']; $res[] = &$r;
    if (!preg_match('/^[A-Za-z0-9_-]{6,40}$/', $eid) || $code === '' || !in_array($mode, ['use', 'add', 'set'], true)) { $r['msg'] = 'Invalid entry.'; $r['drop'] = true; unset($r); continue; }
    if (q("SELECT 1 FROM audit_log WHERE action='offline_scan' AND details LIKE ? LIMIT 1", ['id:'.$eid.' %'])->fetchColumn()) { $r['ok'] = true; $r['msg'] = 'Already applied earlier.'; unset($r); continue; }
    $cand = code_candidates($code); $ph = implode(',', array_fill(0, count($cand), '?')); $m = preg_match('/^GPIMS-(\d+)$/i', $code, $mm) ? (int)$mm[1] : 0;
    $it = q("SELECT i.* FROM items i WHERE (i.barcode IN ($ph) OR i.sku IN ($ph) OR i.serial IN ($ph) OR i.id=? OR EXISTS(SELECT 1 FROM item_codes c WHERE c.item_id=i.id AND c.code IN ($ph))) AND i.archived_at IS NULL AND ".scope_sql('i')." LIMIT 1", array_merge($cand, $cand, $cand, [$m], $cand))->fetch();
    if (!$it) { $r['msg'] = 'No item matches '.$code.'.'; unset($r); continue; }
    $r['name'] = $it['name'];
    if ($it['type'] === 'asset') { $r['msg'] = 'Assets are checked out on the item page, not from the offline queue.'; unset($r); continue; }
    if (($mode !== 'set' && $n <= 0) || $n < 0) { $r['msg'] = 'Invalid quantity.'; $r['drop'] = true; unset($r); continue; }
    $new = $mode === 'add' ? $it['qty'] + $n : ($mode === 'use' ? $it['qty'] - $n : $n);
    if ($new < 0) { $r['msg'] = 'Only '.fmt_q($it['qty']).' on hand now, so '.fmt_q($n).' cannot be used.'; unset($r); continue; }
    q("UPDATE items SET qty=? WHERE id=?", [$new, $it['id']]);
    if ($it['min_qty'] > 0 && $new > $it['min_qty']) q("UPDATE alert_notes SET cleared_at=NOW() WHERE item_id=? AND cleared_at IS NULL", [$it['id']]);
    try { notify_stock($it, $it['qty'], $new); } catch (Throwable $ex) {}
    $type = $mode === 'set' ? 'adjust' : ($mode === 'use' ? 'out' : 'in');
    q("INSERT INTO transactions(item_id,user_id,type,qty,balance,note) VALUES(?,?,?,?,?,?)", [$it['id'], me()['id'], $type, $n, $new, 'Offline scan'.($at > 1e9 ? ' from '.date('M j g:ia', $at) : '')]);
    audit('offline_scan', 'id:'.$eid.' #'.$it['id'].' '.$mode.' '.fmt_q($n));
    $r['ok'] = true; $r['msg'] = fmt_q($it['qty']).' → '.fmt_q($new).($it['unit'] ? ' '.$it['unit'] : ''); unset($r);
  }
  js_out(['results' => $res]);
}
include 'inc/header.php'; ?>
<h1 class="h3 mb-1">Offline scans</h1>
<p class="text-muted small">When you lose signal, the app lets you log stock used or added on the offline page. They wait here on your phone until you review and send them. Each one is applied once, using the stock on hand now.</p>
<div id="qEmpty" class="alert alert-success d-none">Nothing waiting. All synced.</div>
<div id="qBox" class="d-none"><div class="card mb-3"><ul class="list-group list-group-flush" id="qList"></ul></div>
<div class="d-flex gap-2"><button class="btn btn-primary" id="qSync"><i class="bi bi-cloud-upload"></i> Sync now</button><button class="btn btn-outline-danger" id="qClear">Discard all</button></div></div>
<div id="qOut" class="mt-3"></div>
<?php if(!can('stock_move')): ?><div class="alert alert-warning">Your account cannot change stock, so queued scans cannot be applied.</div><?php endif; ?>
<script>(function(){
var KEY='gp-offline-queue',MODES={use:'Used',add:'Added',set:'Counted'};
function load(){try{return JSON.parse(localStorage.getItem(KEY)||'[]')||[];}catch(e){return [];}}
function save(q){try{localStorage.setItem(KEY,JSON.stringify(q));}catch(e){}}
function esc(s){var d=document.createElement('div');d.textContent=s==null?'':String(s);return d.innerHTML;}
function draw(extra){var q=load();document.getElementById('qEmpty').classList.toggle('d-none',q.length>0);document.getElementById('qBox').classList.toggle('d-none',q.length===0);
var ul=document.getElementById('qList');ul.innerHTML='';q.forEach(function(e,i){var x=extra&&extra[e.id];var li=document.createElement('li');li.className='list-group-item d-flex justify-content-between align-items-center gap-2';
li.innerHTML='<div><span class="font-monospace">'+esc(e.code)+'</span> <strong>'+esc(MODES[e.mode]||e.mode)+' '+esc(e.qty)+'</strong><div class="small text-muted">'+esc(new Date(e.at).toLocaleString())+(x&&!x.ok?' · <span class="text-danger">'+esc((x.name?x.name+': ':'')+x.msg)+'</span>':'')+'</div></div><button class="btn btn-sm btn-outline-secondary" data-i="'+i+'">Remove</button>';ul.appendChild(li);});}
document.getElementById('qList').addEventListener('click',function(ev){var b=ev.target.closest('button[data-i]');if(!b)return;var q=load();q.splice(+b.dataset.i,1);save(q);draw();});
document.getElementById('qClear').addEventListener('click',function(){if(confirm('Discard every queued scan?')){save([]);draw();}});
document.getElementById('qSync').addEventListener('click',function(){var btn=this,q=load();if(!q.length)return;btn.disabled=true;
fetch('offline_sync.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':window.GP_CSRF},body:JSON.stringify({entries:q})}).then(function(r){return r.json().then(function(j){if(!r.ok)throw new Error(j.error||'Failed');return j;});}).then(function(j){
var by={},keep=[];(j.results||[]).forEach(function(r){by[r.id]=r;});var ok=0;q.forEach(function(e){var r=by[e.id];if(r&&(r.ok||r.drop))ok+=r.ok?1:0;else keep.push(e);});save(keep);draw(by);
document.getElementById('qOut').innerHTML='<div class="alert alert-'+(keep.length?'warning':'success')+'">'+ok+' applied'+(keep.length?', '+keep.length+' need attention (shown above)':'')+'.</div>'+(j.results||[]).filter(function(r){return r.ok;}).map(function(r){return '<div class="small text-muted">'+esc(r.name)+': '+esc(r.msg)+'</div>';}).join('');}).catch(function(e){document.getElementById('qOut').innerHTML='<div class="alert alert-danger">Could not sync: '+esc(e.message)+'. Try again when you have signal.</div>';}).finally(function(){btn.disabled=false;});});
draw();})();</script>
<?php include 'inc/footer.php'; ?>
