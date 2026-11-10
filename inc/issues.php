<?php
// Equipment that goes out and comes back: incidents (details), issued quantities, returns, hand-offs, swaps and collecting gear.

const OUT_EXPR = '(l.qty_out-l.qty_back-l.qty_gone-l.qty_moved)';
const ASSET_OUTCOMES = ['good'=>'Good', 'needs_attention'=>'Needs charging or cleaning', 'damaged'=>'Damaged', 'lost'=>'Lost'];
const LINE_OUTCOMES = ['good'=>'Good, back in stock', 'damaged'=>'Damaged (not back in stock)', 'lost'=>'Lost', 'used'=>'Used up'];

function incidents_open(){
  try { return q("SELECT id,name FROM incidents WHERE status='open' ORDER BY id DESC")->fetchAll(); } catch (Exception $e) { return []; }
}
function incident_select($name = 'incident_id', $cur = null, $label = 'No event / incident'){
  $o = incidents_open(); if (!$o) return '';
  $h = '<select class="form-select" name="'.h($name).'"><option value="">'.h($label).'</option>';
  foreach ($o as $i) $h .= '<option value="'.(int)$i['id'].'"'.((string)$cur === (string)$i['id'] ? ' selected' : '').'>'.h($i['name']).'</option>';
  return $h.'</select>';
}
function incident_id_valid($v){
  $v = (int)$v; if (!$v) return null;
  $r = q("SELECT id FROM incidents WHERE id=? AND status='open'", [$v])->fetch(); return $r ? $v : null;
}
function fmt_q($n){ return rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.'); }
function item_by_id($id){ $it = q("SELECT * FROM items WHERE id=?", [(int)$id])->fetch(); return ($it && item_in_scope($it)) ? $it : null; }
function issue_close_if_done($iid){
  q("UPDATE issues l SET l.closed_at=NOW() WHERE l.id=? AND l.closed_at IS NULL AND ".OUT_EXPR." <= 0.0001", [$iid]);
}
function issue_event($iid, $event, $qty, $outcome = null, $byName = null, $fromName = null, $toName = null, $note = ''){
  q("INSERT INTO issue_events(issue_id,event,qty,outcome,by_user,by_name,from_name,to_name,note) VALUES(?,?,?,?,?,?,?,?,?)",
    [$iid, $event, $qty, $outcome, me()['id'] ?? null, $byName, $fromName, $toName, mb_substr((string)$note, 0, 255) ?: null]);
}
function tx_log($item_id, $type, $qty, $balance, $note, $incident_id = null){
  q("INSERT INTO transactions(item_id,user_id,type,qty,balance,note,incident_id) VALUES(?,?,?,?,?,?,?)", [$item_id, me()['id'] ?? null, $type, $qty, $balance, mb_substr((string)$note, 0, 255) ?: null, $incident_id]);
}
function incident_name($id){ if (!$id) return ''; $r = q("SELECT name FROM incidents WHERE id=?", [$id])->fetchColumn(); return $r ?: ''; }

// Issue a quantity of a returnable item to a person for an optional incident.
function do_issue($it, $n, $holder, $pid, $incident_id, $note){
  global $pdo;
  if ($it['type'] !== 'returnable') return [false, 'This item is not issued by quantity.'];
  if ($n <= 0) return [false, 'Enter a quantity to issue.'];
  if ($n > (float)$it['qty']) return [false, 'Only '.fmt_q($it['qty']).' on hand.'];
  $new = (float)$it['qty'] - $n;
  q("UPDATE items SET qty=? WHERE id=?", [$new, $it['id']]);
  q("INSERT INTO issues(item_id,holder_name,person_id,incident_id,qty_out,note,issued_by) VALUES(?,?,?,?,?,?,?)", [$it['id'], $holder, $pid ?: null, $incident_id, $n, mb_substr((string)$note, 0, 255) ?: null, me()['id'] ?? null]);
  $iid = (int)$pdo->lastInsertId(); issue_event($iid, 'issue', $n, null, null, null, $holder, $note);
  $inc = incident_name($incident_id);
  tx_log($it['id'], 'issue', $n, $new, 'To '.$holder.($inc ? ' for '.$inc : '').($note ? ' - '.$note : ''), $incident_id);
  try { notify_stock($it, $it['qty'], $new); } catch (Throwable $e) {}
  audit('issue', "#{$it['id']} {$it['name']} x".fmt_q($n)." to $holder");
  return [true, fmt_q($n).' issued to '.$holder.'.'];
}

// Return some or all of an issued line. $outcome: good (back in stock) | damaged | lost | used.
function do_return_line($issue_id, $n, $outcome, $byName = '', $fromName = '', $note = ''){
  $l = q("SELECT l.*,i.name iname,i.qty iqty FROM issues l JOIN items i ON i.id=l.item_id WHERE l.id=?", [(int)$issue_id])->fetch();
  if (!$l) return [false, 'That issued line was not found.'];
  if (!isset(LINE_OUTCOMES[$outcome])) $outcome = 'good';
  $out = (float)$l['qty_out'] - (float)$l['qty_back'] - (float)$l['qty_gone'] - (float)$l['qty_moved'];
  if ($n <= 0) return [false, 'Enter how many.'];
  if ($n > $out + 0.0001) return [false, 'Only '.fmt_q($out).' still out for '.$l['holder_name'].'.'];
  $bal = (float)$l['iqty'];
  if ($outcome === 'good') { $bal += $n; q("UPDATE items SET qty=qty+? WHERE id=?", [$n, $l['item_id']]); q("UPDATE issues SET qty_back=qty_back+? WHERE id=?", [$n, $l['id']]); }
  else q("UPDATE issues SET qty_gone=qty_gone+? WHERE id=?", [$n, $l['id']]);
  issue_event($l['id'], 'return', $n, $outcome, $byName ?: null, $fromName ?: null, null, $note);
  $who = ($byName && $byName !== $l['holder_name']) ? "by $byName (collected from {$l['holder_name']})" : "from {$l['holder_name']}";
  tx_log($l['item_id'], $outcome === 'good' ? 'return' : $outcome, $n, $bal, ucfirst(str_replace('_', ' ', $outcome === 'good' ? 'returned' : $outcome))." $who".($note ? ' - '.$note : ''), $l['incident_id']);
  issue_close_if_done($l['id']);
  audit('issue_return', "#{$l['item_id']} {$l['iname']} x".fmt_q($n)." $outcome");
  return [true, fmt_q($n).' '.($outcome === 'good' ? 'returned to stock' : 'recorded as '.str_replace('_', ' ', $outcome)).'.'];
}

// Hand part of an issued line to someone else. The new holder gets their own line.
function do_handoff_line($issue_id, $n, $toName, $toPid, $note = ''){
  global $pdo;
  $l = q("SELECT l.*,i.name iname FROM issues l JOIN items i ON i.id=l.item_id WHERE l.id=?", [(int)$issue_id])->fetch();
  if (!$l) return [false, 'That issued line was not found.'];
  $out = (float)$l['qty_out'] - (float)$l['qty_back'] - (float)$l['qty_gone'] - (float)$l['qty_moved'];
  if ($n <= 0 || $n > $out + 0.0001) return [false, 'Enter a quantity between 0 and '.fmt_q($out).'.'];
  q("UPDATE issues SET qty_moved=qty_moved+? WHERE id=?", [$n, $l['id']]);
  q("INSERT INTO issues(item_id,holder_name,person_id,incident_id,qty_out,note,issued_by) VALUES(?,?,?,?,?,?,?)", [$l['item_id'], $toName, $toPid ?: null, $l['incident_id'], $n, 'From '.$l['holder_name'], me()['id'] ?? null]);
  $nid = (int)$pdo->lastInsertId();
  issue_event($l['id'], 'handoff', $n, null, null, $l['holder_name'], $toName, $note);
  issue_event($nid, 'received', $n, null, null, $l['holder_name'], $toName, $note);
  tx_log($l['item_id'], 'handoff', $n, null, "From {$l['holder_name']} to $toName".($note ? ' - '.$note : ''), $l['incident_id']);
  issue_close_if_done($l['id']);
  audit('issue_handoff', "#{$l['item_id']} {$l['iname']} x".fmt_q($n)." to $toName");
  return [true, fmt_q($n).' handed to '.$toName.'.'];
}

// Return an individually tracked asset. Outcome decides its new status.
function asset_return($item_id, $outcome = 'good', $note = '', $byName = '', $fromName = ''){
  $it = q("SELECT * FROM items WHERE id=?", [(int)$item_id])->fetch();
  $co = q("SELECT * FROM checkouts WHERE item_id=? AND in_at IS NULL ORDER BY id DESC LIMIT 1", [(int)$item_id])->fetch();
  if (!$it || $it['type'] !== 'asset' || !$co) return [false, 'That item is not checked out.'];
  if (!isset(ASSET_OUTCOMES[$outcome])) $outcome = 'good';
  q("UPDATE checkouts SET in_at=NOW() WHERE item_id=? AND in_at IS NULL", [$it['id']]);
  $status = ['good'=>'available', 'needs_attention'=>'maintenance', 'damaged'=>'maintenance', 'lost'=>'retired'][$outcome];
  q("UPDATE items SET status=? WHERE id=?", [$status, $it['id']]);
  $who = ($byName && $byName !== $co['borrower']) ? "by $byName (collected from {$co['borrower']})" : "from {$co['borrower']}";
  tx_log($it['id'], $outcome === 'good' ? 'return' : $outcome, 1, null, ($outcome === 'good' ? 'Returned' : ucfirst(str_replace('_', ' ', $outcome))).' '.$who.($note ? ' - '.$note : ''), $co['incident_id']);
  audit('return', "#{$it['id']} $outcome");
  return [true, $it['name'].' returned'.($outcome === 'good' ? '.' : ' and marked '.$status.'.')];
}

// Swap a checked-out asset for an available spare, keeping the same holder and incident.
function do_swap($it, $spare_id, $reason, $note = ''){
  $co = q("SELECT * FROM checkouts WHERE item_id=? AND in_at IS NULL ORDER BY id DESC LIMIT 1", [$it['id']])->fetch();
  $sp = q("SELECT * FROM items WHERE id=? AND type='asset' AND status='available' AND archived_at IS NULL", [(int)$spare_id])->fetch();
  if ($it['type'] !== 'asset' || !$co) return [false, 'This item is not checked out.'];
  if (!$sp || $sp['id'] == $it['id'] || !item_in_scope($sp)) return [false, 'Pick an available spare.'];
  $reasons = ['battery'=>'battery dead', 'broken'=>'not working', 'damaged'=>'damaged', 'other'=>'other'];
  $r = $reasons[$reason] ?? 'other'; $why = $r.($note ? ' - '.$note : '');
  q("UPDATE checkouts SET in_at=NOW() WHERE id=?", [$co['id']]);
  q("UPDATE items SET status='maintenance' WHERE id=?", [$it['id']]);
  q("INSERT INTO checkouts(item_id,borrower,person_id,user_id,due_at,note,incident_id) VALUES(?,?,?,?,?,?,?)", [$sp['id'], $co['borrower'], $co['person_id'], me()['id'] ?? null, $co['due_at'], 'Swap for '.$it['name'].' ('.$r.')', $co['incident_id']]);
  q("UPDATE items SET status='checked_out' WHERE id=?", [$sp['id']]);
  tx_log($it['id'], 'swap', 1, null, "Swapped out for {$sp['name']} ($why). Needs attention.", $co['incident_id']);
  tx_log($sp['id'], 'swap', 1, null, "To {$co['borrower']} in place of {$it['name']}", $co['incident_id']);
  audit('swap', "#{$it['id']} -> #{$sp['id']} for {$co['borrower']}");
  return [true, "{$sp['name']} issued to {$co['borrower']}. {$it['name']} is set to maintenance."];
}

// Everything currently out, optionally filtered by holder (person id or name) and/or incident.
function field_rows($f = []){
  $wa = ''; $wl = ''; $pa = []; $pl = [];
  if (!empty($f['incident'])) { $wa .= ' AND c.incident_id=?'; $pa[] = (int)$f['incident']; $wl .= ' AND l.incident_id=?'; $pl[] = (int)$f['incident']; }
  if (!empty($f['person'])) { $wa .= ' AND c.person_id=?'; $pa[] = (int)$f['person']; $wl .= ' AND l.person_id=?'; $pl[] = (int)$f['person']; }
  elseif (isset($f['holder']) && $f['holder'] !== '') { $wa .= ' AND c.person_id IS NULL AND c.borrower=?'; $pa[] = $f['holder']; $wl .= ' AND l.person_id IS NULL AND l.holder_name=?'; $pl[] = $f['holder']; }
  if (!empty($f['item'])) { $wa .= ' AND c.item_id=?'; $pa[] = (int)$f['item']; $wl .= ' AND l.item_id=?'; $pl[] = (int)$f['item']; }
  $a = q("SELECT 'asset' kind,c.id rid,c.item_id,i.name item,i.unit,c.borrower holder,c.person_id,c.incident_id,n.name incident,1 qty,c.out_at since,c.due_at due,c.note FROM checkouts c JOIN items i ON i.id=c.item_id LEFT JOIN incidents n ON n.id=c.incident_id WHERE c.in_at IS NULL AND ".scope_sql('i').$wa, $pa)->fetchAll();
  $l = q("SELECT 'line' kind,l.id rid,l.item_id,i.name item,i.unit,l.holder_name holder,l.person_id,l.incident_id,n.name incident,".OUT_EXPR." qty,l.issued_at since,NULL due,l.note FROM issues l JOIN items i ON i.id=l.item_id LEFT JOIN incidents n ON n.id=l.incident_id WHERE l.closed_at IS NULL AND ".OUT_EXPR." > 0.0001 AND ".scope_sql('i').$wl, $pl)->fetchAll();
  $rows = array_merge($a, $l);
  usort($rows, fn($x, $y) => strcasecmp($x['holder'], $y['holder']) ?: strcasecmp($x['item'], $y['item']));
  return $rows;
}
function field_count(){
  try {
    $a = (int)q("SELECT COUNT(*) FROM checkouts c JOIN items i ON i.id=c.item_id WHERE c.in_at IS NULL AND ".scope_sql('i'))->fetchColumn();
    $l = (int)q("SELECT COUNT(*) FROM issues l JOIN items i ON i.id=l.item_id WHERE l.closed_at IS NULL AND ".OUT_EXPR." > 0.0001 AND ".scope_sql('i'))->fetchColumn();
    return $a + $l;
  } catch (Exception $e) { return 0; }
}

// Handles the return / collect buttons wherever field_table() is shown. Call before any output.
function field_post(){
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
  $a = $_POST['action'] ?? ''; if (!in_array($a, ['ret_asset', 'ret_line', 'collect'], true)) return;
  if (!can('checkout')) { http_response_code(403); exit('Access denied.'); }
  $me = me()['name'] ?: me()['username']; $msgs = []; $bad = false;
  if ($a === 'ret_asset') {
    $it = item_by_id($_POST['item_id'] ?? 0); if (!$it) { http_response_code(403); exit('Access denied.'); }
    [$ok, $m] = asset_return($it['id'], $_POST['outcome'] ?? 'good', trim($_POST['note'] ?? ''), $me, ''); $msgs[] = $m; $bad = !$ok;
  } elseif ($a === 'ret_line') {
    $l = q("SELECT item_id,holder_name FROM issues WHERE id=?", [(int)($_POST['issue_id'] ?? 0)])->fetch();
    if (!$l || !item_by_id($l['item_id'])) { http_response_code(403); exit('Access denied.'); }
    [$ok, $m] = do_return_line((int)$_POST['issue_id'], (float)($_POST['qty'] ?? 0), $_POST['outcome'] ?? 'good', $me, $me === $l['holder_name'] ? '' : $l['holder_name'], trim($_POST['note'] ?? '')); $msgs[] = $m; $bad = !$ok;
  } else {
    $f = ['incident' => (int)($_POST['incident_id'] ?? 0), 'person' => (int)($_POST['person_id'] ?? 0), 'holder' => trim($_POST['holder'] ?? '')];
    if (!$f['incident'] && !$f['person'] && $f['holder'] === '') { flash('Choose who or what to collect.', 'danger'); redirect($_SERVER['REQUEST_URI']); }
    $n = 0;
    foreach (field_rows($f) as $r) {
      $from = $r['holder'] === $me ? '' : $r['holder'];
      if ($r['kind'] === 'asset') { [$ok] = asset_return($r['item_id'], 'good', 'Collected', $me, $from); } else { [$ok] = do_return_line($r['rid'], (float)$r['qty'], 'good', $me, $from, 'Collected'); }
      if ($ok) $n++;
    }
    audit('collect', ($f['holder'] ?: ('person #'.$f['person'].($f['incident'] ? ' event #'.$f['incident'] : ''))).' x'.$n);
    $msgs[] = $n ? "$n item(s) collected and returned." : 'Nothing was out.';
  }
  flash(implode(' ', $msgs), $bad ? 'danger' : 'success'); redirect($_SERVER['REQUEST_URI']);
}

// Table of things that are out, grouped by 'holder' (default), 'incident' or 'item'.
function field_table($rows, $group = 'holder', $showCollect = true){
  if (!$rows) return '<div class="text-muted">Nothing is out right now.</div>';
  $can = can('checkout'); $groups = [];
  foreach ($rows as $r) {
    $k = $group === 'incident' ? ($r['incident'] ?: 'No event') : ($group === 'item' ? $r['item'] : $r['holder']);
    $groups[$k][] = $r;
  }
  ksort($groups, SORT_NATURAL | SORT_FLAG_CASE); $o = '';
  foreach ($groups as $name => $rs) {
    $first = $rs[0]; $o .= '<div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center py-2"><span class="fw-bold">'.($group === 'holder' && $first['person_id'] ? '<a class="text-reset" href="person.php?id='.(int)$first['person_id'].'">'.h($name).'</a>' : h($name)).' <span class="badge text-bg-secondary">'.count($rs).'</span></span>';
    if ($can && $showCollect && $group !== 'item') {
      $ls = $group === 'incident' ? ($first['incident_id'] ? 'incident='.(int)$first['incident_id'] : '') : ($first['person_id'] ? 'person='.(int)$first['person_id'] : 'holder='.urlencode($first['holder']));
      $o .= '<span class="d-inline-flex gap-1 align-items-center">'.($ls !== '' ? '<a class="btn btn-sm btn-outline-secondary" href="loan_sheet.php?'.$ls.'" title="Print loan sheet"><i class="bi bi-printer"></i></a>' : '');
      $o .= '<form method="post" class="d-inline">'.csrf_field().'<input type="hidden" name="action" value="collect">'.($group === 'incident' ? '<input type="hidden" name="incident_id" value="'.(int)$first['incident_id'].'">' : ($first['person_id'] ? '<input type="hidden" name="person_id" value="'.(int)$first['person_id'].'">' : '<input type="hidden" name="holder" value="'.h($first['holder']).'">')).'<button class="btn btn-sm btn-outline-success" data-confirm="Mark everything shown for '.h($name).' as collected and returned in good condition?"><i class="bi bi-check2-all"></i> Collect all</button></form></span>';
    }
    $o .= '</div><div class="list-group list-group-flush">';
    foreach ($rs as $r) {
      $isA = $r['kind'] === 'asset'; $od = $r['due'] && $r['due'] < date('Y-m-d');
      $o .= '<div class="list-group-item py-2"><div class="d-flex justify-content-between flex-wrap gap-2"><div><a href="item_view.php?id='.(int)$r['item_id'].'">'.h($r['item']).'</a>'.(!$isA ? ' <span class="fw-bold">&times; '.h(fmt_q($r['qty'])).'</span> <span class="text-muted small">'.h($r['unit']).'</span>' : '').'<div class="small text-muted">'
        .($group !== 'holder' ? h($r['holder']).' · ' : '').($group !== 'incident' && $r['incident'] ? h($r['incident']).' · ' : '').'since '.h(substr($r['since'], 5, 11)).($r['due'] ? ' · <span class="'.($od ? 'text-danger fw-bold' : '').'">due '.h($r['due']).'</span>' : '').($r['note'] ? ' · '.h($r['note']) : '').'</div></div>';
      if ($can) {
        $o .= '<form method="post" class="d-flex gap-1 align-items-start flex-wrap">'.csrf_field().($isA ? '<input type="hidden" name="action" value="ret_asset"><input type="hidden" name="item_id" value="'.(int)$r['item_id'].'">' : '<input type="hidden" name="action" value="ret_line"><input type="hidden" name="issue_id" value="'.(int)$r['rid'].'"><input class="form-control form-control-sm" style="width:5rem" type="number" step="any" min="0" name="qty" value="'.h(fmt_q($r['qty'])).'" aria-label="Quantity">')
          .'<select class="form-select form-select-sm" style="width:auto" name="outcome" aria-label="Condition">';
        foreach (($isA ? ASSET_OUTCOMES : LINE_OUTCOMES) as $k => $lab) $o .= '<option value="'.$k.'">'.h($lab).'</option>';
        $o .= '</select><button class="btn btn-sm btn-success">Return</button></form>';
      }
      $o .= '</div></div>';
    }
    $o .= '</div></div>';
  }
  return $o;
}
