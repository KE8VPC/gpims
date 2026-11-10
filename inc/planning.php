<?php
// Planning helpers: item picker, reservations, maintenance schedules and the per-item timeline.

// Text box + datalist item picker. Values look like "Truck mobile [#12]" and are resolved by item_pick_resolve().
function item_pick($name = 'item_q', $types = null, $cur = null, $required = true){
  $w = "archived_at IS NULL AND status<>'retired' AND ".scope_sql('items');
  if ($types) $w .= " AND type IN ('".implode("','", $types)."')";
  $rows = q("SELECT id,name,sku FROM items WHERE $w ORDER BY name LIMIT 2000")->fetchAll();
  $val = ''; $o = '';
  foreach ($rows as $r) { $lab = $r['name'].' [#'.$r['id'].']'; if ($cur && (int)$cur === (int)$r['id']) $val = $lab; $o .= '<option value="'.h($lab).'">'.h($r['sku']).'</option>'; }
  return '<input class="form-control" name="'.h($name).'" list="dl_'.h($name).'" value="'.h($val).'" placeholder="Start typing an item name" autocomplete="off"'.($required ? ' required' : '').'><datalist id="dl_'.h($name).'">'.$o.'</datalist>';
}
function item_pick_resolve($name = 'item_q', $types = null){
  $v = trim($_POST[$name] ?? ''); if ($v === '') return null;
  if (preg_match('/\[#(\d+)\]\s*$/', $v, $m)) $it = item_by_id((int)$m[1]);
  else { $r = q("SELECT id FROM items WHERE name=? AND archived_at IS NULL LIMIT 2", [$v])->fetchAll(); $it = count($r) === 1 ? item_by_id((int)$r[0]['id']) : null; }
  if ($it && $types && !in_array($it['type'], $types, true)) return null;
  return $it;
}
function valid_date($d){ return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false; }

// ---------- Reservations ----------
function res_capacity($it){
  if ($it['type'] === 'asset') return 1.0;
  $out = (float)q("SELECT COALESCE(SUM(".OUT_EXPR."),0) FROM issues l WHERE l.item_id=? AND l.closed_at IS NULL", [$it['id']])->fetchColumn();
  return (float)$it['qty'] + max(0, $out);
}
// Is the item over-booked if $qty more is reserved for these dates? Returns [over?, overlapping rows, capacity].
function res_conflicts($it, $qty, $s, $e, $ex = 0){
  $rows = q("SELECT r.*,n.name ev FROM reservations r LEFT JOIN incidents n ON n.id=r.incident_id WHERE r.item_id=? AND r.status='reserved' AND r.id<>? AND r.starts_on<=? AND r.ends_on>=? ORDER BY r.starts_on", [$it['id'], $ex, $e, $s])->fetchAll();
  $sum = (float)$qty; foreach ($rows as $r) $sum += (float)$r['qty'];
  $cap = res_capacity($it);
  return [$sum > $cap + 0.0001, $rows, $cap];
}
function res_add($it, $qty, $borrower, $pid, $incident, $s, $e, $note, $force = false){
  if (!in_array($it['type'], ['returnable', 'asset'], true)) return [false, 'Only returnable gear and assets can be reserved.'];
  if (!valid_date($s) || !valid_date($e)) return [false, 'Choose a start and end date.'];
  if ($e < $s) return [false, 'The end date is before the start date.'];
  if ($it['type'] === 'asset') $qty = 1;
  if ($qty <= 0) return [false, 'Enter a quantity.'];
  if ($borrower === '') return [false, 'Enter who the gear is reserved for.'];
  [$over, $rows, $cap] = res_conflicts($it, $qty, $s, $e);
  if ($over && !$force) {
    $who = []; foreach ($rows as $r) $who[] = $r['borrower'].' ('.$r['starts_on'].($r['ends_on'] !== $r['starts_on'] ? ' to '.$r['ends_on'] : '').')';
    return [false, ($it['name']).' is already booked then'.($who ? ': '.implode('; ', $who) : ' (only '.fmt_q($cap).' owned)').'. Tick "Book anyway" to double-book.'];
  }
  q("INSERT INTO reservations(item_id,incident_id,borrower,person_id,qty,starts_on,ends_on,note,created_by) VALUES(?,?,?,?,?,?,?,?,?)", [$it['id'], $incident ?: null, mb_substr($borrower, 0, 100), $pid ?: null, $qty, $s, $e, mb_substr((string)$note, 0, 255) ?: null, me()['id'] ?? null]);
  audit('reserve', "#{$it['id']} {$it['name']} x".fmt_q($qty)." for $borrower $s".($over ? ' (double-booked)' : ''));
  return [true, $it['name'].' reserved for '.$borrower.'.'.($over ? ' (Double-booked.)' : '')];
}
function res_fulfil($rid){
  global $pdo;
  $r = q("SELECT * FROM reservations WHERE id=?", [(int)$rid])->fetch(); if (!$r) return [false, 'Reservation not found.'];
  if ($r['status'] !== 'reserved') return [false, 'That reservation is already '.$r['status'].'.'];
  $it = item_by_id($r['item_id']); if (!$it) return [false, 'Item not available.'];
  if ($it['archived_at']) return [false, $it['name'].' is archived.'];
  if ($it['type'] === 'asset') {
    if ($it['status'] !== 'available') return [false, $it['name'].' is not available right now ('.str_replace('_', ' ', $it['status']).').'];
    $note = 'Reserved'.($r['note'] ? ' - '.$r['note'] : '');
    q("INSERT INTO checkouts(item_id,borrower,person_id,user_id,due_at,note,incident_id) VALUES(?,?,?,?,?,?,?)", [$it['id'], $r['borrower'], $r['person_id'], me()['id'] ?? null, $r['ends_on'], mb_substr($note, 0, 255), $r['incident_id']]);
    q("UPDATE items SET status='checked_out' WHERE id=?", [$it['id']]);
    tx_log($it['id'], 'checkout', 1, null, 'To '.$r['borrower'].' - reservation', $r['incident_id']);
    audit('checkout', "#{$it['id']} to {$r['borrower']} (reservation)");
    $res = [true, $it['name'].' checked out to '.$r['borrower'].'.'];
  } else {
    $res = do_issue($it, (float)$r['qty'], $r['borrower'], $r['person_id'], $r['incident_id'] ? (int)$r['incident_id'] : null, 'Reserved'.($r['note'] ? ' - '.$r['note'] : ''));
  }
  if ($res[0]) q("UPDATE reservations SET status='fulfilled' WHERE id=?", [$r['id']]);
  return $res;
}
function res_badge($r){
  if ($r['status'] === 'fulfilled') return '<span class="badge text-bg-success">Issued</span>';
  if ($r['status'] === 'cancelled') return '<span class="badge text-bg-secondary">Cancelled</span>';
  $t = date('Y-m-d');
  if ($r['ends_on'] < $t) return '<span class="badge text-bg-danger">Missed</span>';
  if ($r['starts_on'] <= $t) return '<span class="badge text-bg-warning">Due out now</span>';
  $d = (int)round((strtotime($r['starts_on']) - strtotime($t)) / 86400);
  return '<span class="badge text-bg-info">'.($d === 1 ? 'Tomorrow' : 'In '.$d.' days').'</span>';
}
function res_range($r){ return $r['starts_on'] === $r['ends_on'] ? date('M j', strtotime($r['starts_on'])) : date('M j', strtotime($r['starts_on'])).' – '.date('M j', strtotime($r['ends_on'])); }
// Reservations the user can see (scoped to items they can access). $f: item, incident, status ('open' | 'all'), from
function res_list($f = []){
  $w = scope_sql('i'); $p = [];
  if (!empty($f['item'])) { $w .= ' AND r.item_id=?'; $p[] = (int)$f['item']; }
  if (!empty($f['incident'])) { $w .= ' AND r.incident_id=?'; $p[] = (int)$f['incident']; }
  $st = $f['status'] ?? 'open';
  if ($st === 'open') $w .= " AND r.status='reserved'";
  elseif ($st === 'past') $w .= " AND r.status<>'reserved'";
  return q("SELECT r.*,i.name item,i.type itype,i.unit,n.name event FROM reservations r JOIN items i ON i.id=r.item_id LEFT JOIN incidents n ON n.id=r.incident_id WHERE $w ORDER BY r.starts_on, r.id", $p)->fetchAll();
}
// Reservations that need action: due out today / overdue to be issued, or starting within 2 days.
function res_attention(){
  static $c = null; if ($c !== null) return $c;
  try { $c = q("SELECT r.*,i.name item,n.name event FROM reservations r JOIN items i ON i.id=r.item_id LEFT JOIN incidents n ON n.id=r.incident_id WHERE r.status='reserved' AND r.starts_on <= DATE_ADD(CURDATE(), INTERVAL 2 DAY) AND r.ends_on >= CURDATE() AND i.archived_at IS NULL AND ".scope_sql('i')." ORDER BY r.starts_on")->fetchAll(); }
  catch (Throwable $e) { $c = []; }
  return $c;
}

// ---------- Maintenance ----------
const MAINT_PRESETS = [7 => 'Weekly', 30 => 'Monthly', 90 => 'Every 3 months', 180 => 'Every 6 months', 365 => 'Yearly'];
function maint_label($d){ return MAINT_PRESETS[(int)$d] ?? 'Every '.(int)$d.' days'; }
function maint_warn_days(){ return max(0, (int)setting('maint_warn_days', 7)); }
function maint_state($t){
  $days = (int)round((strtotime($t['next_due']) - strtotime(date('Y-m-d'))) / 86400);
  if ($days < 0) return ['overdue', $days, '<span class="badge text-bg-danger">Overdue '.(-$days).' day'.($days === -1 ? '' : 's').'</span>'];
  if ($days === 0) return ['soon', 0, '<span class="badge text-bg-warning">Due today</span>'];
  if ($days <= maint_warn_days()) return ['soon', $days, '<span class="badge text-bg-warning">Due in '.$days.' day'.($days === 1 ? '' : 's').'</span>'];
  return ['ok', $days, '<span class="badge text-bg-light text-muted border">Due '.h(date('M j', strtotime($t['next_due']))).'</span>'];
}
function maint_tasks($f = []){
  $w = "t.active=1 AND i.archived_at IS NULL AND ".scope_sql('i'); $p = [];
  if (!empty($f['item'])) { $w .= ' AND t.item_id=?'; $p[] = (int)$f['item']; }
  if (!empty($f['due'])) $w .= ' AND t.next_due <= DATE_ADD(CURDATE(), INTERVAL '.maint_warn_days().' DAY)';
  return q("SELECT t.*,i.name item FROM maintenance_tasks t JOIN items i ON i.id=t.item_id WHERE $w ORDER BY t.next_due, i.name", $p)->fetchAll();
}
function maint_attention(){
  static $c = null; if ($c !== null) return $c;
  try { $c = maint_tasks(['due' => 1]); } catch (Throwable $e) { $c = []; }
  return $c;
}
function maint_add($it, $title, $every, $first, $notes){
  $title = mb_substr(trim($title), 0, 150); $every = (int)$every;
  if ($title === '') return [false, 'Give the task a name.'];
  if ($every < 1 || $every > 3650) return [false, 'Repeat interval must be between 1 and 3650 days.'];
  if (!valid_date($first)) $first = date('Y-m-d', strtotime("+$every days"));
  q("INSERT INTO maintenance_tasks(item_id,title,every_days,next_due,notes) VALUES(?,?,?,?,?)", [$it['id'], $title, $every, $first, mb_substr((string)$notes, 0, 255) ?: null]);
  log_item_event($it['id'], 'maintenance_set', "$title, ".maint_label($every).", first due $first"); audit('maint_add', "#{$it['id']} {$it['name']}: $title");
  return [true, 'Maintenance task added.'];
}
function maint_complete($tid, $note = '', $doneOn = null){
  $t = q("SELECT t.*,i.* , t.id tid, i.id iid FROM maintenance_tasks t JOIN items i ON i.id=t.item_id WHERE t.id=?", [(int)$tid])->fetch();
  if (!$t || !item_in_scope($t)) return [false, 'Task not found.'];
  $d = valid_date((string)$doneOn) ? $doneOn : date('Y-m-d'); if ($d > date('Y-m-d')) $d = date('Y-m-d');
  $next = date('Y-m-d', strtotime($d.' +'.(int)$t['every_days'].' days'));
  q("UPDATE maintenance_tasks SET last_done=?,next_due=?,notified_on=NULL WHERE id=?", [$d, $next, $t['tid']]);
  q("INSERT INTO maintenance_log(item_id,title,done_on,user_id,note) VALUES(?,?,?,?,?)", [$t['iid'], $t['title'], $d, me()['id'] ?? null, mb_substr((string)$note, 0, 255) ?: null]);
  log_item_event($t['iid'], 'maintenance_done', $t['title'].($note ? ' - '.$note : '')); audit('maint_done', "#{$t['iid']} {$t['name']}: {$t['title']}");
  return [true, $t['title'].' done. Next due '.date('M j, Y', strtotime($next)).'.'];
}
// Small panel for the item page.
function maint_panel($it, $canManage){
  $tasks = maint_tasks(['item' => $it['id']]); $log = q("SELECT l.*,u.name uname FROM maintenance_log l LEFT JOIN users u ON u.id=l.user_id WHERE l.item_id=? ORDER BY l.id DESC LIMIT 5", [$it['id']])->fetchAll();
  if (!$tasks && !$canManage) return '';
  ob_start(); ?>
<div class="card mb-3"><div class="card-header py-1 d-flex justify-content-between align-items-center"><strong class="small"><i class="bi bi-wrench-adjustable"></i> Maintenance</strong><a class="small" href="maintenance.php?item=<?=$it['id']?>">All</a></div>
<?php if($tasks): ?><ul class="list-group list-group-flush small"><?php foreach($tasks as $t): [$st,,$badge]=maint_state($t); ?><li class="list-group-item d-flex justify-content-between align-items-center gap-2 flex-wrap"><span><?=h($t['title'])?> <?=$badge?><br><span class="text-muted"><?=h(maint_label($t['every_days']))?><?=$t['last_done']?' · last '.h($t['last_done']):''?></span></span>
<?php if($canManage): ?><form method="post" action="maintenance.php" class="d-flex gap-1"><?=csrf_field()?><input type="hidden" name="action" value="done"><input type="hidden" name="id" value="<?=$t['id']?>"><input type="hidden" name="back" value="item_view.php?id=<?=$it['id']?>"><button class="btn btn-sm btn-outline-success py-0">Done</button></form><?php endif; ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php if($log): ?><div class="card-body py-1 small text-muted"><?php foreach($log as $l): ?><div><?=h($l['done_on'])?> · <?=h($l['title'])?><?=$l['uname']?' · '.h($l['uname']):''?></div><?php endforeach; ?></div><?php endif; ?>
<?php if($canManage): ?><div class="card-body py-2"><a class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" href="#mAdd"><i class="bi bi-plus-lg"></i> Add task</a>
<form method="post" action="maintenance.php" class="collapse mt-2" id="mAdd"><?=csrf_field()?><input type="hidden" name="action" value="add"><input type="hidden" name="item_q" value="<?=h($it['name'].' [#'.$it['id'].']')?>"><input type="hidden" name="back" value="item_view.php?id=<?=$it['id']?>">
<div class="row g-2"><div class="col-12"><input class="form-control form-control-sm" name="title" placeholder="e.g. Swap battery, Oil change, Calibrate" required maxlength="150"></div>
<div class="col-6"><select class="form-select form-select-sm" name="every_days"><?php foreach(MAINT_PRESETS as $d=>$l): ?><option value="<?=$d?>"><?=$l?></option><?php endforeach; ?></select></div>
<div class="col-6"><input class="form-control form-control-sm" type="date" name="first_due" title="First due (optional)"></div>
<div class="col-12"><button class="btn btn-sm btn-primary">Add</button></div></div></form></div><?php endif; ?></div>
<?php return ob_get_clean();
}
function maint_notify(){
  $rows = q("SELECT t.*,i.name item,i.category_id icat,i.location_id iloc FROM maintenance_tasks t JOIN items i ON i.id=t.item_id WHERE t.active=1 AND i.archived_at IS NULL AND t.next_due < CURDATE() AND (t.notified_on IS NULL OR t.notified_on < DATE_SUB(CURDATE(), INTERVAL 7 DAY))")->fetchAll();
  if (!$rows) return;
  $ml = q("SELECT * FROM users WHERE active=1 AND email<>''")->fetchAll();
  if (!mail_enabled() || !array_filter($ml, fn($u) => user_wants($u, 'maint') && valid_email($u['email']))) return; // nobody to tell yet: keep the tasks un-notified so turning email on later still sends
  foreach ($rows as $t) {
    $late = (int)round((strtotime(date('Y-m-d')) - strtotime($t['next_due'])) / 86400);
    $html = mail_wrap('Maintenance overdue', '<p><strong>'.h($t['item']).'</strong>: '.h($t['title']).' is '.$late.' day(s) overdue (was due '.h($t['next_due']).').</p><p><a href="'.h(base_url().'maintenance.php?item='.$t['item_id']).'">Open maintenance</a></p>');
    $to = []; foreach ($ml as $u) if (valid_email($u['email']) && user_wants($u, 'maint') && user_sees_item($u, ['category_id' => $t['icat'], 'location_id' => $t['iloc']])) $to[strtolower($u['email'])] = $u['email'];
    foreach ($to as $e) mail_queue($e, 'MAINTENANCE OVERDUE: '.$t['item'].' - '.$t['title'], $html, 'maint_overdue');
    q("UPDATE maintenance_tasks SET notified_on=CURDATE() WHERE id=?", [$t['id']]);
  }
}

// ---------- Item timeline ----------
const TL_KINDS = ['stock' => 'Stock & loans', 'edit' => 'Edits', 'maint' => 'Maintenance', 'check' => 'Checks & counts', 'plan' => 'Reservations', 'file' => 'Documents'];
function item_timeline($id, $limit = 400){
  $e = [];
  $add = function($ts, $kind, $icon, $text, $user = '', $detail = '') use (&$e){ $e[] = ['ts' => $ts, 'kind' => $kind, 'icon' => $icon, 'text' => $text, 'user' => $user, 'detail' => $detail]; };
  foreach (q("SELECT h.*,u.name uname FROM item_history h LEFT JOIN users u ON u.id=h.user_id WHERE h.item_id=? ORDER BY h.id DESC LIMIT 300", [$id])->fetchAll() as $r) {
    if ($r['action'] === 'edit') $add($r['created_at'], 'edit', 'bi-pencil', 'Changed '.$r['field'], $r['uname'], ($r['old_value'] !== '' && $r['old_value'] !== null ? $r['old_value'] : '(blank)').' → '.($r['new_value'] !== '' && $r['new_value'] !== null ? $r['new_value'] : '(blank)'));
    elseif (strpos($r['action'], 'maintenance') === 0) continue; // shown from the maintenance log
    else $add($r['created_at'], 'edit', 'bi-journal', ucfirst(str_replace('_', ' ', $r['action'])), $r['uname'], (string)$r['new_value']);
  }
  $icons = ['in' => 'bi-box-arrow-in-down', 'out' => 'bi-box-arrow-up', 'set' => 'bi-123', 'issue' => 'bi-box-arrow-up-right', 'checkout' => 'bi-box-arrow-up-right', 'return' => 'bi-box-arrow-in-left', 'handoff' => 'bi-arrow-left-right', 'recount' => 'bi-clipboard-check', 'damaged' => 'bi-exclamation-octagon', 'lost' => 'bi-question-octagon', 'used' => 'bi-fire', 'swap' => 'bi-arrow-repeat', 'transfer' => 'bi-arrow-left-right'];
  foreach (q("SELECT t.*,u.name uname FROM transactions t LEFT JOIN users u ON u.id=t.user_id WHERE t.item_id=? ORDER BY t.id DESC LIMIT 300", [$id])->fetchAll() as $r)
    $add($r['created_at'], 'stock', $icons[$r['type']] ?? 'bi-dot', ucfirst($r['type']).((float)$r['qty'] != 0 ? ' '.fmt_q($r['qty']) : '').($r['balance'] !== null ? ' (now '.fmt_q($r['balance']).')' : ''), $r['uname'], (string)$r['note']);
  foreach (q("SELECT l.*,u.name uname FROM maintenance_log l LEFT JOIN users u ON u.id=l.user_id WHERE l.item_id=?", [$id])->fetchAll() as $r) $add($r['created_at'], 'maint', 'bi-wrench-adjustable', 'Maintenance: '.$r['title'], $r['uname'], 'Done '.$r['done_on'].($r['note'] ? ' - '.$r['note'] : ''));
  foreach (q("SELECT i.*,r.created_at ts,r.id rid,r.user_id,c.name cname,u.name uname FROM checklist_run_items i JOIN checklist_runs r ON r.id=i.run_id JOIN checklists c ON c.id=r.checklist_id LEFT JOIN users u ON u.id=r.user_id WHERE i.item_id=? ORDER BY r.id DESC LIMIT 100", [$id])->fetchAll() as $r)
    $add($r['ts'], 'check', 'bi-clipboard2-check', 'Checked on "'.$r['cname'].'": '.$r['status'], $r['uname'], trim(($r['actual'] !== null && $r['actual'] !== '' ? 'Counted '.$r['actual'].($r['expected'] !== null ? ' of '.fmt_q($r['expected']) : '') : '').' '.$r['note']));
  foreach (q("SELECT l.*,r.created_at ts,u.name uname FROM recount_lines l JOIN recounts r ON r.id=l.recount_id LEFT JOIN users u ON u.id=r.user_id WHERE l.item_id=? ORDER BY l.id DESC LIMIT 100", [$id])->fetchAll() as $r)
    $add($r['ts'], 'check', 'bi-clipboard-check', 'Recount: '.fmt_q($r['expected']).' → '.fmt_q($r['counted']), $r['uname'], (float)$r['expected'] == (float)$r['counted'] ? 'Matched' : 'Difference '.fmt_q($r['counted'] - $r['expected']));
  foreach (q("SELECT r.*,u.name uname,n.name ev FROM reservations r LEFT JOIN users u ON u.id=r.created_by LEFT JOIN incidents n ON n.id=r.incident_id WHERE r.item_id=? ORDER BY r.id DESC LIMIT 100", [$id])->fetchAll() as $r)
    $add($r['created_at'], 'plan', 'bi-calendar-event', 'Reserved for '.$r['borrower'].' ('.res_range($r).')', $r['uname'], trim(($r['ev'] ? $r['ev'].' · ' : '').$r['status']));
  foreach (q("SELECT d.*,u.name uname FROM documents d LEFT JOIN users u ON u.id=d.uploaded_by WHERE d.item_id=? ORDER BY d.id DESC LIMIT 50", [$id])->fetchAll() as $r) $add($r['created_at'], 'file', 'bi-file-earmark', 'Document added: '.$r['title'], $r['uname'], $r['orig_name']);
  usort($e, fn($a, $b) => strcmp($b['ts'], $a['ts']));
  return array_slice($e, 0, $limit);
}
