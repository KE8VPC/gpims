<?php require 'inc/bootstrap.php'; need('admin'); $title = 'Import';
set_time_limit(120);
$step = $_GET['step'] ?? '';
if (isset($_GET['template'])) { $ex = $_GET['template'] === 'example'; while (ob_get_level()) ob_end_clean(); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="gpims-import-template'.($ex ? '-example' : '').'.csv"'); echo imp_template_csv($ex); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $a = $_POST['action'] ?? '';
  if ($a === 'stage') {
    $files = []; $err = [];
    foreach (($_FILES['files']['tmp_name'] ?? []) as $i => $tmp) {
      if (($_FILES['files']['error'][$i] ?? 4) !== UPLOAD_ERR_OK) continue;
      if (($_FILES['files']['size'][$i] ?? 0) > 5 * 1024 * 1024) { $err[] = $_FILES['files']['name'][$i].' is over 5 MB.'; continue; }
      [$hdr, $rows] = imp_parse($tmp); $pr = imp_profile($hdr);
      if (!$pr) { $err[] = h($_FILES['files']['name'][$i]).': not a recognised format. Columns found: '.implode(', ', array_slice($hdr, 0, 12)); continue; }
      $files[] = ['profile' => $pr, 'label' => basename($_FILES['files']['name'][$i]), 'rows' => $rows];
    }
    if ($err) foreach ($err as $e) flash($e, 'danger');
    if (!$files) { if (!$err) flash('Choose at least one CSV file.', 'danger'); redirect('import.php'); }
    $plan = imp_plan($files, !empty($_POST['guess']));
    $_SESSION['imp_plan'] = $plan; redirect('import.php?step=preview');
  }
  if ($a === 'apply') {
    $plan = $_SESSION['imp_plan'] ?? null; if (!$plan) { flash('Nothing staged. Upload the files again.', 'warning'); redirect('import.php'); }
    try { $st = imp_apply($plan, array_map('strval', (array)($_POST['merge'] ?? []))); }
    catch (Throwable $e) { flash('Import failed and nothing was saved: '.$e->getMessage(), 'danger'); redirect('import.php?step=preview'); }
    unset($_SESSION['imp_plan']);
    flash($st['items'].' items imported'.($st['skipped'] ? ', '.$st['skipped'].' skipped (already in GPIMS)' : '').'. Created '.$st['cats'].' categories, '.$st['locs'].' locations, '.$st['people'].' people and '.$st['checkouts'].' assignments.', 'success');
    redirect('import.php');
  }
  if ($a === 'undo') {
    try { $u = imp_undo((int)($_POST['batch'] ?? 0)); } catch (Throwable $e) { flash('Undo failed and nothing was changed: '.$e->getMessage(), 'danger'); redirect('import.php'); }
    if ($u === null) flash('That import was already undone.', 'warning');
    else flash('Import undone: '.$u['removed'].' items removed'.($u['kept'] ? '; '.$u['kept'].' kept because they have been used since ('.implode(', ', $u['kept_names']).($u['kept'] > count($u['kept_names']) ? '…' : '').')' : '').'. Also removed '.$u['cats'].' categories, '.$u['locs'].' locations and '.$u['people'].' people it had created.', 'success');
    redirect('import.php');
  }
  if ($a === 'cancel') { unset($_SESSION['imp_plan']); redirect('import.php'); }
}
$plan = $step === 'preview' ? ($_SESSION['imp_plan'] ?? null) : null; if ($step === 'preview' && !$plan) redirect('import.php');
include 'inc/header.php';
if (!$plan): ?>
<h1 class="h3 mb-1">Import equipment lists</h1>
<p class="text-muted">Upload your Equipment List and Inventory List exports (CSV). You see exactly what will be created before anything is saved. Re-running is safe: items already in GPIMS with the same serial number or asset tag are skipped.</p>
<form method="post" enctype="multipart/form-data" class="card card-body" style="max-width:640px"><?=csrf_field()?><input type="hidden" name="action" value="stage">
<div class="mb-3"><label class="form-label">CSV files</label><input class="form-control" type="file" name="files[]" accept=".csv,text/csv" multiple required><div class="form-text">Select all files at once. Recognised formats: the <strong>GPIMS template</strong> (below), <strong>Equipment List</strong> (Record ID, Equipment, Serial, Ownership) and <strong>Inventory List</strong> (Inventory Name, Inventory ID, Crew Member, Station).</div></div>
<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="guess" value="1" id="g" checked><label class="form-check-label" for="g">Guess categories from item names (radios, pagers, furniture, medical…)</label></div>
<div><button class="btn btn-primary"><i class="bi bi-upload"></i> Read files and preview</button></div></form>
<div class="card card-body mt-3" style="max-width:640px"><div class="fw-bold mb-1"><i class="bi bi-file-earmark-spreadsheet"></i> CSV template</div><div class="small text-muted mb-2">Start from your own spreadsheet instead? Fill in this layout in Excel or Google Sheets and save it as CSV. Only <strong>Name</strong> is required; leave anything else blank.</div>
<div class="d-flex gap-2 flex-wrap mb-2"><a class="btn btn-sm btn-primary" href="import.php?template=1"><i class="bi bi-download"></i> Download blank template</a><a class="btn btn-sm btn-outline-secondary" href="import.php?template=example">Download with 3 example rows</a></div>
<details class="small"><summary class="text-muted">What goes in each column</summary><dl class="mt-2 mb-0"><dt>Name</dt><dd>Required.</dd><dt>Type</dt><dd><code>asset</code> (one serialized thing), <code>returnable</code> (counted gear you lend out) or <code>consumable</code> (supplies that get used up). Blank = guessed.</dd><dt>Category</dt><dd>Use <code>Parent / Child</code> to make sub-categories, e.g. <code>Communication / Radios</code>. Created if missing.</dd><dt>Location</dt><dd>Created if missing.</dd><dt>Quantity, Unit</dt><dd>For returnable and consumable items. Assets are always 1.</dd><dt>Alert At</dt><dd>Low-stock alert level for consumables.</dd><dt>Serial, Tag</dt><dd>Used to spot duplicates, so re-importing the same sheet never doubles up.</dd><dt>Unit Cost</dt><dd>Numbers only; used by the value report.</dd><dt>Assigned To</dt><dd>Assets only. Becomes an open checkout to that person (<code>Last, First</code> or <code>First Last</code>).</dd><dt>Status</dt><dd><code>available</code>, <code>maintenance</code> or <code>retired</code>.</dd><dt>Notes</dt><dd>Added to the item's description.</dd></dl></details></div>
<?php $bs = q("SELECT b.*,u.name uname,(SELECT COUNT(*) FROM items WHERE import_batch=b.id) n FROM import_batches b LEFT JOIN users u ON u.id=b.user_id ORDER BY b.id DESC LIMIT 8")->fetchAll(); if ($bs): ?><div class="card mt-3" style="max-width:640px"><div class="card-header fw-bold">Past imports</div><ul class="list-group list-group-flush small"><?php foreach ($bs as $b): ?><li class="list-group-item d-flex justify-content-between align-items-center gap-2"><span><?=h(date('M j, g:ia', strtotime($b['created_at'])))?> · <?=h($b['uname'] ?: 'someone')?> · <?=h($b['summary'])?><?=$b['undone_at'] ? ' <span class="badge text-bg-secondary">undone</span>' : ''?></span><?php if (!$b['undone_at']): ?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="undo"><input type="hidden" name="batch" value="<?=$b['id']?>"><button class="btn btn-sm btn-outline-danger py-0" data-confirm="Undo this import? Its <?=$b['n']?> items are removed (any that have been used since are kept).">Undo</button></form><?php endif; ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="small text-muted mt-3" style="max-width:640px"><strong>How it works.</strong> Serial numbers and asset tags (like 15190) identify duplicates: the same serial or tag in both lists becomes one item. Rows with only similar names are shown for you to confirm. A name in the owner / crew column becomes an open checkout to that person; group names (EMA, CERT, EOC, EMARV…) become locations. Rows with no name are still imported as "Unnamed item". Quantities written as numbers in the serial column are treated as counts.</div>
<?php else:
$items = $plan['items']; $pairs = $plan['pairs'];
$new = array_filter($items, fn($x) => !$x['exists']); $skip = count($items) - count($new);
$cats = []; $locs = []; $ppl = []; foreach ($new as $x) { if ($x['cat']) $cats[$x['cat']] = ($cats[$x['cat']] ?? 0) + 1; if ($x['loc']) $locs[$x['loc']] = ($locs[$x['loc']] ?? 0) + 1; if ($x['type'] === 'asset' && $x['status'] === 'available') foreach (array_slice($x['people'], 0, 1) as $p) $ppl[$p] = ($ppl[$p] ?? 0) + 1; }
ksort($cats); arsort($locs);
$types = array_count_values(array_column($new, 'type')); $merged = count(array_filter($new, fn($x) => $x['merged'] > 1));
$fl = ['unnamed' => 'No name', 'nocat' => 'No category', 'noloc' => 'No location', 'qty' => 'Quantity unknown', 'status' => 'Out of service / retired', 'merged' => 'Merged duplicates'];
$flagOf = function($x){ $f = []; if (preg_match('/^Unnamed/', $x['name'])) $f[] = 'unnamed'; if ($x['cat'] === '') $f[] = 'nocat'; if ($x['loc'] === '') $f[] = 'noloc'; if ($x['type'] === 'consumable' && !$x['qty']) $f[] = 'qty'; if ($x['status'] !== 'available') $f[] = 'status'; if ($x['merged'] > 1) $f[] = 'merged'; return $f; };
$fc = array_fill_keys(array_keys($fl), 0); foreach ($new as $x) foreach ($flagOf($x) as $k) $fc[$k]++;
$exLoc = array_map('strtolower', q("SELECT name FROM locations")->fetchAll(PDO::FETCH_COLUMN));
?>
<h1 class="h3 mb-1">Import preview</h1>
<p class="text-muted small mb-3">Nothing has been saved yet. <?=h(implode(' + ', array_map(fn($f) => $f['label'].' ('.$f['rows'].' rows)', $plan['files'])))?></p>
<div class="row g-2 mb-3">
<?php foreach([['New items',count($new),'primary'],['Equipment',$types['asset']??0,'secondary'],['Counted gear',$types['returnable']??0,'secondary'],['Consumables',$types['consumable']??0,'secondary'],['Duplicates merged',$merged,'success'],['Already in GPIMS',$skip,'warning']] as [$l,$v,$c]): ?><div class="col-6 col-md-2"><div class="card text-center"><div class="card-body py-2"><div class="fs-4 fw-bold text-<?=$c?>"><?=$v?></div><div class="small text-muted"><?=$l?></div></div></div></div><?php endforeach; ?></div>
<div class="card card-body py-2 mb-3 small"><div class="fw-bold mb-1">You don't have to fix anything now.</div><div class="text-muted mb-2">Everything imports as shown, and you can tidy items later from the Inventory page. Use these buttons to look at the rows that probably need a second look, or undo the whole import afterwards from this page.</div><div class="d-flex flex-wrap gap-1"><button type="button" class="btn btn-sm btn-outline-secondary active" data-flag="">All (<?=count($items)?>)</button><?php foreach ($fl as $k => $lb): if ($fc[$k]): ?><button type="button" class="btn btn-sm btn-outline-secondary" data-flag="<?=$k?>"><?=h($lb)?> (<?=$fc[$k]?>)</button><?php endif; endforeach; ?></div></div>
<form method="post" id="impForm"><?=csrf_field()?><input type="hidden" name="action" value="apply">
<?php if($pairs): ?><div class="card mb-3"><div class="card-header fw-bold">Possible matches to confirm (<?=count($pairs)?>) <span class="small fw-normal text-muted">Tick a pair to merge it into one item. These matched on name only, not on a serial number or tag. <a href="#" data-pairs="1">Tick all</a> · <a href="#" data-pairs="0">none</a></span></div><div class="list-group list-group-flush small" style="max-height:22rem;overflow:auto">
<?php foreach($pairs as $pi => $p): $a = $items[$p['a']]; $b = $items[$p['b']]; ?><label class="list-group-item d-flex gap-2 align-items-start"><input class="form-check-input mt-1" type="checkbox" name="merge[]" value="<?=$pi?>"<?=$p['default']?' checked':''?>><span><strong><?=h($b['name'])?></strong> <span class="text-muted">(Inventory List<?=$b['loc']?', '.h($b['loc']):''?><?=$b['people']?', '.h($b['people'][0]):''?>)</span><br>with <strong><?=h($a['name'])?></strong> <span class="text-muted">(Equipment List<?=$a['serial']?', serial '.h($a['serial']):''?><?=$a['loc']?', '.h($a['loc']):''?>)</span></span></label><?php endforeach; ?></div></div><?php endif; ?>
<div class="row g-3 mb-3"><div class="col-md-4"><div class="card h-100"><div class="card-header fw-bold">Categories (<?=count($cats)?>)</div><ul class="list-group list-group-flush small"><?php foreach($cats as $c=>$n): ?><li class="list-group-item d-flex justify-content-between py-1"><span><?=h($c)?></span><span class="text-muted"><?=$n?></span></li><?php endforeach; if(!$cats): ?><li class="list-group-item text-muted">None</li><?php endif; ?></ul></div></div>
<div class="col-md-4"><div class="card h-100"><div class="card-header fw-bold">Locations (<?=count($locs)?>)</div><ul class="list-group list-group-flush small"><?php foreach($locs as $l=>$n): ?><li class="list-group-item d-flex justify-content-between py-1"><span><?=h($l)?><?=in_array(strtolower($l),$exLoc,true)?' <span class="badge text-bg-light border">exists</span>':''?></span><span class="text-muted"><?=$n?></span></li><?php endforeach; if(!$locs): ?><li class="list-group-item text-muted">None</li><?php endif; ?></ul></div></div>
<div class="col-md-4"><div class="card h-100"><div class="card-header fw-bold">Assigned to people (<?=count($ppl)?>)</div><ul class="list-group list-group-flush small" style="max-height:16rem;overflow:auto"><?php ksort($ppl); foreach($ppl as $pn=>$n): ?><li class="list-group-item d-flex justify-content-between py-1"><span><?=h($pn)?></span><span class="text-muted"><?=$n?></span></li><?php endforeach; if(!$ppl): ?><li class="list-group-item text-muted">None</li><?php endif; ?></ul><div class="card-footer small text-muted">Each becomes an open checkout. Similar spellings (Mike / Michael) are combined.</div></div></div></div>
<div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap"><strong>Every item (<?=count($items)?>)</strong><input class="form-control form-control-sm" style="max-width:240px" id="impFilter" placeholder="Filter…"></div>
<div class="table-responsive" style="max-height:32rem"><table class="table table-sm table-hover mb-0 small" id="impTable"><thead class="table-light" style="position:sticky;top:0"><tr><th>Name</th><th>Tag</th><th>Serial</th><th>Type</th><th>Category</th><th>Location</th><th>Assigned to</th><th></th></tr></thead><tbody>
<?php foreach($items as $x): ?><tr class="<?=$x['exists']?'text-muted':''?>" data-flags="<?=h(implode(' ', $flagOf($x)))?>"><td><?=h($x['name'])?><?php if($x['alt']): ?><div class="text-muted"><?=h('also: '.implode('; ', $x['alt']))?></div><?php endif; ?></td><td><?=h($x['tag'])?></td><td><?=h($x['serial'])?></td><td><?=h($x['type'])?><?=$x['type']!=='asset'?' &times; '.h(fmt_q($x['qty'])):''?></td><td><?=h($x['cat'])?></td><td><?=h($x['loc'])?></td><td><?=h($x['people'][0] ?? '')?></td>
<td class="text-nowrap"><?php if($x['exists']): ?><span class="badge text-bg-warning">in GPIMS</span><?php endif; ?><?php if($x['merged']>1): ?><span class="badge text-bg-success">merged <?=$x['merged']?></span><?php endif; ?><?php if($x['status']!=='available'): ?><span class="badge text-bg-secondary"><?=h($x['status'])?></span><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<div class="d-flex gap-2 flex-wrap mb-5"><button class="btn btn-primary" data-confirm="Create <?=count($new)?> items now?"><i class="bi bi-check2-circle"></i> Import <?=count($new)?> items</button><button class="btn btn-outline-secondary" name="action" value="cancel" formnovalidate>Start over</button></div></form>
<script>document.querySelectorAll('[data-pairs]').forEach(function(a){a.onclick=function(e){e.preventDefault();document.querySelectorAll('input[name="merge[]"]').forEach(function(c){c.checked=a.dataset.pairs==='1';});};});
var impFlag='';function impApplyFilter(){var q=document.getElementById('impFilter').value.toLowerCase();document.querySelectorAll('#impTable tbody tr').forEach(function(r){var ok=(!impFlag||(' '+r.dataset.flags+' ').indexOf(' '+impFlag+' ')>-1)&&r.textContent.toLowerCase().indexOf(q)>-1;r.style.display=ok?'':'none';});}
document.querySelectorAll('[data-flag]').forEach(function(b){b.onclick=function(){impFlag=b.dataset.flag;document.querySelectorAll('[data-flag]').forEach(function(x){x.classList.toggle('active',x===b);});impApplyFilter();document.getElementById('impTable').scrollIntoView({block:'nearest'});};});
document.getElementById('impFilter').addEventListener('input',impApplyFilter);</script>
<?php endif; include 'inc/footer.php'; ?>
