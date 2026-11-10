<?php require 'inc/bootstrap.php'; need_perm('checkout'); $title='Reservations';
function r_back($d){ $b = $_POST['back'] ?? ''; return (preg_match('/^[a-z_]+\.php(\?[a-z0-9_=&]*)?$/i', $b)) ? $b : $d; }
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $a = $_POST['action'] ?? ''; $back = r_back('reservations.php');
  if ($a === 'add') {
    $it = item_pick_resolve('item_q', ['returnable', 'asset']);
    [$b, $pid, $perr] = resolve_borrower();
    $inc = incident_id_valid($_POST['incident_id'] ?? '');
    if (!$it) flash('Pick a returnable item or asset from the list.', 'danger');
    elseif ($perr) flash($perr, 'danger');
    else {
      $s = trim($_POST['starts_on'] ?? ''); $e = trim($_POST['ends_on'] ?? ''); if ($e === '') $e = $s;
      [$ok, $m] = res_add($it, (float)($_POST['qty'] ?? 1), $b, $pid, $inc, $s, $e, trim($_POST['note'] ?? ''), !empty($_POST['force']));
      flash($m, $ok ? 'success' : 'danger');
      if (!$ok) { $_SESSION['res_keep'] = array_intersect_key($_POST, array_flip(['item_q','borrower','person_id','incident_id','starts_on','ends_on','qty','note'])); }
    }
  } elseif ($a === 'issue') { [$ok, $m] = res_fulfil((int)$_POST['id']); flash($m, $ok ? 'success' : 'danger');
  } elseif ($a === 'issue_all') {
    $n = 0; $bad = [];
    foreach (res_list(['incident' => (int)$_POST['incident_id'], 'status' => 'open']) as $r) { [$ok, $m] = res_fulfil($r['id']); if ($ok) $n++; else $bad[] = $m; }
    flash($n.' reserved item'.($n === 1 ? '' : 's').' issued.'.($bad ? ' Not issued: '.implode(' ', $bad) : ''), $bad ? 'warning' : 'success');
  } elseif ($a === 'cancel') {
    $r = q("SELECT r.*,i.category_id,i.location_id,i.name iname FROM reservations r JOIN items i ON i.id=r.item_id WHERE r.id=? AND r.status='reserved'", [(int)$_POST['id']])->fetch();
    if ($r && item_in_scope($r)) { q("UPDATE reservations SET status='cancelled' WHERE id=?", [$r['id']]); audit('reserve_cancel', "#{$r['item_id']} {$r['iname']} for {$r['borrower']}"); flash('Reservation cancelled.'); }
  }
  redirect($back);
}
$keep = $_SESSION['res_keep'] ?? []; unset($_SESSION['res_keep']);
$itemF = (int)($_GET['item'] ?? 0); $incF = (int)($_GET['incident'] ?? 0); $view = in_array($_GET['view'] ?? '', ['past', 'all', 'cal'], true) ? $_GET['view'] : 'open';
$mon = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m'); $m1 = $mon.'-01'; $mEnd = date('Y-m-t', strtotime($m1));
$rows = res_list(['item' => $itemF, 'incident' => $incF, 'status' => $view === 'cal' ? 'open' : $view]); if ($view === 'past' || $view === 'all') $rows = array_reverse($rows);
if ($view === 'cal') $rows = array_values(array_filter($rows, fn($r) => $r['starts_on'] <= $mEnd && $r['ends_on'] >= $m1));
$people = q("SELECT id,name FROM people WHERE active=1 ORDER BY name")->fetchAll();
$byDay = []; foreach ($rows as $r) $byDay[$r['starts_on']][] = $r;
$pre = $keep['item_q'] ?? ''; if (!$pre && $itemF) { $x = item_by_id($itemF); if ($x) $pre = $x['name'].' [#'.$x['id'].']'; }
$preInc = $keep['incident_id'] ?? ($incF ?: null);
include 'inc/header.php'; ?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"><h1 class="h3 mb-0">Reservations</h1>
<div class="d-flex gap-2"><div class="btn-group btn-group-sm"><a class="btn btn-<?=$view==='open'?'secondary':'outline-secondary'?>" href="reservations.php">Upcoming</a><a class="btn btn-<?=$view==='past'?'secondary':'outline-secondary'?>" href="reservations.php?view=past">Past</a><a class="btn btn-<?=$view==='cal'?'secondary':'outline-secondary'?> d-none d-md-inline-block" href="reservations.php?view=cal">Calendar</a></div>
<button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#rAdd"><i class="bi bi-plus-lg"></i> Reserve gear</button></div></div>
<p class="text-muted small">Book returnable gear and assets ahead of time for an event or a loan, such as the LED sign for another agency. Nothing leaves stock until you press <strong>Issue now</strong>.</p>
<div class="collapse<?=($keep||$itemF||$incF)?' show':''?> mb-3" id="rAdd"><form method="post" class="card card-body"><?=csrf_field()?><input type="hidden" name="action" value="add">
<div class="row g-2">
<div class="col-md-6"><label class="form-label small mb-0">Item</label><?=str_replace('value=""','value="'.h($pre).'"',item_pick('item_q',['returnable','asset'],$itemF))?></div>
<div class="col-4 col-md-2"><label class="form-label small mb-0">Qty</label><input class="form-control" type="number" step="any" min="0.01" name="qty" value="<?=h($keep['qty'] ?? 1)?>"></div>
<div class="col-8 col-md-4"><label class="form-label small mb-0">Event / incident</label><?=incident_select('incident_id',$preInc,'None')?></div>
<div class="col-md-6"><label class="form-label small mb-0">Reserved for</label><div class="input-group"><select class="form-select" name="person_id" style="max-width:45%"><option value="">Someone else…</option><?php foreach($people as $p): ?><option value="<?=$p['id']?>"<?=((int)($keep['person_id'] ?? 0)===(int)$p['id'])?' selected':''?>><?=h($p['name'])?></option><?php endforeach; ?></select><input class="form-control" name="borrower" placeholder="Name or agency" value="<?=h($keep['borrower'] ?? '')?>" maxlength="100"></div></div>
<div class="col-6 col-md-3"><label class="form-label small mb-0">From</label><input class="form-control" type="date" name="starts_on" value="<?=h($keep['starts_on'] ?? date('Y-m-d'))?>" required></div>
<div class="col-6 col-md-3"><label class="form-label small mb-0">Through</label><input class="form-control" type="date" name="ends_on" value="<?=h($keep['ends_on'] ?? '')?>"></div>
<div class="col-12"><input class="form-control" name="note" placeholder="Note (optional)" maxlength="255" value="<?=h($keep['note'] ?? '')?>"></div>
<div class="col-12 d-flex flex-wrap gap-3 align-items-center"><button class="btn btn-primary">Reserve</button><div class="form-check"><input class="form-check-input" type="checkbox" name="force" value="1" id="rf"><label class="form-check-label small" for="rf">Book anyway if already booked</label></div></div></div></form></div>
<?php if($view==='cal'): $first=strtotime($m1); $lead=(int)date('w',$first); $days=(int)date('t',$first); $prev=date('Y-m',strtotime('-1 month',$first)); $next=date('Y-m',strtotime('+1 month',$first)); ?>
<div class="d-flex justify-content-between align-items-center mb-2"><a class="btn btn-sm btn-outline-secondary" href="reservations.php?view=cal&month=<?=$prev?>">&laquo;</a><strong><?=h(date('F Y',$first))?></strong><a class="btn btn-sm btn-outline-secondary" href="reservations.php?view=cal&month=<?=$next?>">&raquo;</a></div>
<div class="card"><table class="table table-bordered mb-0 small" style="table-layout:fixed"><thead><tr><?php foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dn): ?><th class="text-center"><?=$dn?></th><?php endforeach; ?></tr></thead><tbody>
<?php $cell=0; echo '<tr>'; for($i=0;$i<$lead;$i++){ echo '<td class="bg-body-tertiary"></td>'; $cell++; }
for($dd=1;$dd<=$days;$dd++){ $ds=sprintf('%s-%02d',$mon,$dd); $here=array_values(array_filter($rows,fn($r)=>$r['starts_on']<=$ds&&$r['ends_on']>=$ds));
  echo '<td style="height:5.5rem;vertical-align:top"'.($ds===date('Y-m-d')?' class="table-primary"':'').'><div class="text-muted">'.$dd.'</div>';
  foreach(array_slice($here,0,3) as $r) echo '<div class="text-truncate" title="'.h($r['item'].' - '.$r['borrower']).'"><a href="item_view.php?id='.(int)$r['item_id'].'">'.h($r['item']).'</a></div>';
  if(count($here)>3) echo '<div class="text-muted">+'.(count($here)-3).' more</div>';
  echo '</td>'; $cell++; if($cell%7===0 && $dd<$days) echo '</tr><tr>'; }
while($cell%7!==0){ echo '<td class="bg-body-tertiary"></td>'; $cell++; } echo '</tr>'; ?></tbody></table></div>
<?php else: ?>
<?php if(!$rows): ?><div class="text-muted"><?=$view==='open'?'Nothing reserved yet.':'No past reservations.'?></div><?php endif; ?>
<?php foreach($byDay as $day=>$list): ?><h2 class="h6 text-muted text-uppercase mt-3 mb-1"><?=h(date('D, M j, Y', strtotime($day)))?></h2><div class="card mb-2"><div class="list-group list-group-flush">
<?php foreach($list as $r): ?><div class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2"><div><a class="fw-semibold" href="item_view.php?id=<?=$r['item_id']?>"><?=h($r['item'])?></a><?=$r['itype']==='returnable'?' &times; '.h(fmt_q($r['qty'])):''?> <?=res_badge($r)?>
<div class="small text-muted">For <?=$r['person_id']?'<a href="person.php?id='.(int)$r['person_id'].'">'.h($r['borrower']).'</a>':h($r['borrower'])?> · <?=h(res_range($r))?><?=$r['event']?' · <a href="incident.php?id='.(int)$r['incident_id'].'">'.h($r['event']).'</a>':''?><?=$r['note']?' · '.h($r['note']):''?></div></div>
<?php if($r['status']==='reserved'): ?><form method="post" class="d-flex gap-1"><?=csrf_field()?><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="back" value="reservations.php<?=$view==='open'?'':'?view='.$view?>"><button class="btn btn-sm btn-success" name="action" value="issue" data-confirm="Issue this to <?=h($r['borrower'])?> now?">Issue now</button><button class="btn btn-sm btn-outline-secondary" name="action" value="cancel" data-confirm="Cancel this reservation?">Cancel</button></form><?php endif; ?></div><?php endforeach; ?></div></div>
<?php endforeach; ?>
<?php endif; ?>
<?php include 'inc/footer.php'; ?>
