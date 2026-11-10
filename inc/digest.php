<?php
// Email preferences, digests, reservation reminders and the scheduled-task runner.
const EMAIL_EVENTS = [
  'loans' => 'Loans that are overdue',
  'maint' => 'Maintenance that is overdue',
  'resv'  => 'Reservations starting soon',
  'expiry' => 'Items that are about to expire or have expired',
];
function user_prefs_list($u){ return $u['notify_prefs'] === null || $u['notify_prefs'] === '' ? null : array_filter(explode(',', $u['notify_prefs'])); }
// Does this user want this kind of email? Accounts that never saved preferences keep the old behaviour (admins with alerts on get loan and maintenance mail).
function user_wants($u, $ev){
  $l = user_prefs_list($u);
  if ($l === null) return $u['role'] === 'admin' && (int)$u['notify_stock'] === 1 && in_array($ev, ['loans', 'maint', 'expiry'], true);
  return in_array($ev, $l, true);
}
function email_prefs_save($uid){
  $ev = []; foreach (EMAIL_EVENTS as $k => $_) if (!empty($_POST['ev'][$k])) $ev[] = $k;
  $d = in_array($_POST['digest'] ?? 'off', ['off', 'daily', 'weekly'], true) ? $_POST['digest'] : 'off';
  q("UPDATE users SET notify_prefs=?,digest=? WHERE id=?", [$ev ? implode(',', $ev) : '-', $d, $uid]); // '-' means "none" (an empty value means "never chose": the old defaults)
}
function email_prefs_fields($u){
  $l = user_prefs_list($u); $o = '';
  if ($u['role'] !== 'vendor' && $u['role'] !== 'viewer') {
    $o .= '<div class="small fw-bold mt-1">Also email me about</div>';
    foreach (EMAIL_EVENTS as $k => $lab) { $on = $l === null ? user_wants($u, $k) : in_array($k, $l, true); $o .= '<div class="form-check"><input class="form-check-input" type="checkbox" name="ev['.$k.']" value="1" id="ev'.$k.'" '.($on ? 'checked' : '').'><label class="form-check-label small" for="ev'.$k.'">'.h($lab).'</label></div>'; }
  }
  $o .= '<div class="small fw-bold mt-1">Summary email</div><select class="form-select form-select-sm" name="digest">';
  foreach (['off' => 'No summary email', 'daily' => 'One summary every morning', 'weekly' => 'One summary every Monday'] as $k => $lab) $o .= '<option value="'.$k.'"'.($u['digest'] === $k ? ' selected' : '').'>'.h($lab).'</option>';
  return $o.'</select><div class="form-text">The summary lists low stock, expiring items, overdue and soon-due loans, checks and maintenance, and reservations coming up. It is only sent when there is something to report.</div>';
}

function digest_section($title, $lines, $color = '#212529'){ if (!$lines) return ''; return '<h3 style="font-size:15px;margin:16px 0 4px;color:'.$color.'">'.h($title).' ('.count($lines).')</h3><ul style="margin:0;padding-left:18px">'.implode('', array_map(fn($l) => '<li style="margin:2px 0">'.$l.'</li>', array_slice($lines, 0, 25))).(count($lines) > 25 ? '<li>…and '.(count($lines) - 25).' more</li>' : '').'</ul>'; }
function digest_build($u){
  $b = base_url(); $vis = fn($r) => user_sees_item($u, $r); $S = [];
  $low = []; foreach (q("SELECT * FROM items WHERE archived_at IS NULL AND status<>'retired' AND type<>'asset' AND min_qty>0 AND qty<=min_qty ORDER BY name")->fetchAll() as $i) if ($vis($i)) $low[] = '<a href="'.h($b.($u['role'] === 'vendor' ? 'supply.php' : 'item_view.php?id='.$i['id'])).'">'.h($i['name']).'</a>: '.h($i['qty'] + 0).' '.h($i['unit']).($i['qty'] <= 0 ? ' <strong>(out)</strong>' : ' (alert at '.h($i['min_qty'] + 0).')');
  $S[] = digest_section('Low and out of stock', $low, '#b02a37');
  if ($u['role'] === 'vendor') { $html = implode('', $S); return $html; }
  $win = max(1, min(365, (int)setting('expiry_warn_days', 30) ?: 30)); $ex = [];
  foreach (q("SELECT lt.*,i.name item,i.category_id,i.location_id FROM item_lots lt JOIN items i ON i.id=lt.item_id WHERE i.archived_at IS NULL AND lt.expires_on IS NOT NULL AND lt.expires_on<=DATE_ADD(CURDATE(),INTERVAL $win DAY) ORDER BY lt.expires_on")->fetchAll() as $x) if ($vis($x)) { $d = (int)round((strtotime($x['expires_on']) - strtotime(date('Y-m-d'))) / 86400); $ex[] = '<a href="'.h($b.'item_view.php?id='.$x['item_id']).'">'.h($x['item']).'</a>'.($x['lot_no'] ? ' lot '.h($x['lot_no']) : '').': '.($d < 0 ? '<strong>expired '.(-$d).' day(s) ago</strong>' : 'expires '.h($x['expires_on']).' ('.$d.' day(s))'); }
  $S[] = digest_section('Expired or expiring soon', $ex, '#997404');
  $ln = []; foreach (q("SELECT c.*,i.name item,i.category_id,i.location_id FROM checkouts c JOIN items i ON i.id=c.item_id WHERE c.in_at IS NULL AND c.due_at IS NOT NULL AND c.due_at<=DATE_ADD(CURDATE(),INTERVAL 3 DAY) AND i.archived_at IS NULL ORDER BY c.due_at")->fetchAll() as $x) if ($vis($x)) { $d = (int)round((strtotime(date('Y-m-d')) - strtotime($x['due_at'])) / 86400); $ln[] = '<a href="'.h($b.'item_view.php?id='.$x['item_id']).'">'.h($x['item']).'</a> with '.h($x['borrower']).': '.($d > 0 ? '<strong>'.$d.' day(s) overdue</strong>' : ($d === 0 ? 'due today' : 'due '.h($x['due_at']))); }
  $S[] = digest_section('Loans overdue or due within 3 days', $ln, '#b02a37');
  if (in_array($u['role'], ['admin', 'manager', 'user'], true)) {
    $ck = []; foreach (q("SELECT c.*,v.name vname,v.kind vkind,v.owner_person_id,v.active vactive,p.user_id owner_uid FROM checklists c LEFT JOIN vehicles v ON v.id=c.vehicle_id LEFT JOIN people p ON p.id=v.owner_person_id WHERE c.active=1 AND c.frequency_days IS NOT NULL ORDER BY v.name,c.name")->fetchAll() as $c) {
      if ($c['vehicle_id'] && !$c['vactive']) continue;
      if ($c['vkind'] === 'personal' && $u['role'] !== 'admin' && (int)$c['owner_uid'] !== (int)$u['id'] && !q("SELECT 1 FROM vehicle_users WHERE vehicle_id=? AND user_id=?", [$c['vehicle_id'], $u['id']])->fetchColumn()) continue;
      $st = check_status($c); if (!in_array($st['state'], ['overdue', 'due'], true)) continue;
      $ck[] = '<a href="'.h($b.'checklist_run.php?id='.$c['id']).'">'.h(($c['vname'] ? $c['vname'].' - ' : '').$c['name']).'</a>: '.($st['never'] ? 'never done' : ($st['state'] === 'overdue' ? '<strong>overdue '.(int)$st['late'].' day(s)</strong>' : 'due soon'));
    }
    $S[] = digest_section('Checks overdue or due', $ck, '#b02a37');
  }
  $mt = []; foreach (q("SELECT t.*,i.name item,i.category_id,i.location_id FROM maintenance_tasks t JOIN items i ON i.id=t.item_id WHERE t.active=1 AND i.archived_at IS NULL AND t.next_due<=DATE_ADD(CURDATE(),INTERVAL 7 DAY) ORDER BY t.next_due")->fetchAll() as $x) if ($vis($x)) { $d = (int)round((strtotime(date('Y-m-d')) - strtotime($x['next_due'])) / 86400); $mt[] = '<a href="'.h($b.'maintenance.php?item='.$x['item_id']).'">'.h($x['item']).'</a>: '.h($x['title']).' '.($d > 0 ? '<strong>'.$d.' day(s) overdue</strong>' : 'due '.h($x['next_due'])); }
  $S[] = digest_section('Maintenance due this week', $mt, '#997404');
  $rv = []; foreach (q("SELECT r.*,i.name item,i.category_id,i.location_id FROM reservations r JOIN items i ON i.id=r.item_id WHERE r.status='reserved' AND r.starts_on<=DATE_ADD(CURDATE(),INTERVAL 3 DAY) AND r.ends_on>=CURDATE() AND i.archived_at IS NULL ORDER BY r.starts_on")->fetchAll() as $x) if ($vis($x)) $rv[] = h($x['item']).' for '.h($x['borrower']).', '.h($x['starts_on']).' to '.h($x['ends_on']);
  $S[] = digest_section('Reservations in the next 3 days', $rv);
  return implode('', $S);
}
function digest_send($u, $subjectPrefix = ''){
  $html = digest_build($u); if ($html === '') return false;
  $body = '<p style="margin:0">Hello '.h($u['name']).', here is what needs attention.</p>'.$html.'<p style="margin-top:16px"><a href="'.h(base_url().'attention.php').'">Open the Attention page</a></p>';
  return mail_queue($u['email'], $subjectPrefix.($u['digest'] === 'weekly' ? 'Weekly' : 'Daily').' summary - '.date('M j'), mail_wrap('Summary', $body), 'digest');
}
function digest_run(){
  if (!mail_enabled() || (int)date('G') < (int)setting('digest_hour', 7)) return;
  $today = date('Y-m-d'); $dow = (int)date('N');
  foreach (q("SELECT * FROM users WHERE active=1 AND digest<>'off' AND email IS NOT NULL AND email<>'' AND (digest_last IS NULL OR digest_last<?)", [$today])->fetchAll() as $u) {
    if ($u['digest'] === 'weekly' && $dow !== 1 && $u['digest_last'] && $u['digest_last'] > date('Y-m-d', strtotime('-8 days'))) continue;
    if ($u['digest'] === 'weekly' && $dow !== 1 && !$u['digest_last']) continue;
    try { digest_send($u); } catch (Throwable $e) {}
    q("UPDATE users SET digest_last=? WHERE id=?", [$today, $u['id']]);
  }
}
// Remind the person who reserved (and anyone who opted in) the day before and the day a reservation starts.
function resv_remind(){
  foreach (q("SELECT r.*,i.name item,i.category_id,i.location_id,p.user_id puid FROM reservations r JOIN items i ON i.id=r.item_id LEFT JOIN people p ON p.id=r.person_id WHERE r.status='reserved' AND r.reminded_on IS NULL AND r.starts_on<=DATE_ADD(CURDATE(),INTERVAL 1 DAY) AND r.ends_on>=CURDATE() AND i.archived_at IS NULL")->fetchAll() as $r) {
    $to = []; foreach (q("SELECT * FROM users WHERE active=1 AND email<>''")->fetchAll() as $u) { if (!valid_email($u['email'])) continue; if ((int)$u['id'] === (int)$r['puid'] || (int)$u['id'] === (int)$r['created_by'] || (user_wants($u, 'resv') && user_sees_item($u, $r))) $to[strtolower($u['email'])] = $u['email']; }
    if (!$to) continue;
    $when = $r['starts_on'] <= date('Y-m-d') ? 'today' : 'tomorrow';
    $html = mail_wrap('Reservation reminder', '<p><strong>'.h($r['item']).'</strong>'.($r['qty'] != 1 ? ' (x'.h($r['qty'] + 0).')' : '').' is reserved for <strong>'.h($r['borrower']).'</strong> starting '.$when.' ('.h($r['starts_on']).' to '.h($r['ends_on']).').'.($r['note'] ? '<br>'.h($r['note']) : '').'</p><p><a href="'.h(base_url().'reservations.php').'">Open reservations</a></p>');
    foreach ($to as $e) mail_queue($e, 'Reservation '.$when.': '.$r['item'], $html, 'reservation');
    q("UPDATE reservations SET reminded_on=CURDATE() WHERE id=?", [$r['id']]);
  }
}

// One reminder per lot at each stage: inside the warning window (stage 1), within a week (2), expired (3). Re-armed if the date is edited.
function expiry_stage($days, $warn){ return $days < 0 ? 3 : ($days <= 7 ? 2 : ($days <= $warn ? 1 : 0)); }
function expiry_notify(){
  if (!mail_enabled()) return;
  $warn = max(1, min(365, (int)setting('expiry_warn_days', 30) ?: 30));
  $ml = q("SELECT * FROM users WHERE active=1 AND email<>''")->fetchAll();
  $want = array_filter($ml, fn($u) => $u['role'] !== 'vendor' && user_wants($u, 'expiry') && valid_email($u['email'])); if (!$want) return;
  $rows = q("SELECT lt.*,i.name item,i.category_id,i.location_id,i.unit,i.id iid FROM item_lots lt JOIN items i ON i.id=lt.item_id WHERE i.archived_at IS NULL AND lt.expires_on IS NOT NULL AND lt.expires_on<=DATE_ADD(CURDATE(),INTERVAL $warn DAY) AND lt.reminded_stage<3 ORDER BY lt.expires_on")->fetchAll();
  $per = []; $mark = [];
  foreach ($rows as $l) {
    $days = (int)round((strtotime($l['expires_on']) - strtotime(date('Y-m-d'))) / 86400); $st = expiry_stage($days, $warn); if ($st <= (int)$l['reminded_stage']) continue;
    $mark[$l['id']] = $st;
    foreach ($want as $u) if (user_sees_item($u, $l)) $per[$u['id']][] = ['u' => $u, 'l' => $l, 'd' => $days];
  }
  foreach ($per as $uid => $list) {
    $b = base_url(); $lines = [];
    foreach ($list as $x) { $l = $x['l']; $when = $x['d'] < 0 ? 'EXPIRED '.(-$x['d']).' day(s) ago' : ($x['d'] == 0 ? 'expires today' : 'expires in '.$x['d'].' day(s)');
      $lines[] = '<li><a href="'.h($b.'item_view.php?id='.$l['iid']).'">'.h($l['item']).'</a>'.($l['lot_no'] ? ' (lot '.h($l['lot_no']).')' : '').($l['qty'] !== null ? ' · '.h(fmt_q($l['qty'])).' '.h($l['unit']) : '').' - '.h($when).' ('.h($l['expires_on']).')</li>'; }
    mail_queue($list[0]['u']['email'], 'EXPIRY: '.count($list).' item'.(count($list) == 1 ? '' : 's').' need attention', mail_wrap('Expiry reminder', '<ul style="padding-left:18px">'.implode('', $lines).'</ul><p><a href="'.h($b.'attention.php').'">Open the attention list</a></p>'), 'expiry');
  }
  foreach ($mark as $id => $st) q("UPDATE item_lots SET reminded_stage=? WHERE id=?", [$st, $id]);
}
