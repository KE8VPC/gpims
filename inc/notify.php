<?php
function user_sees_item($u, $item){
  if ($u['role'] === 'admin' || (int)$u['scope_all'] === 1) return true;
  foreach (q("SELECT category_id,location_id FROM user_access WHERE user_id=?", [$u['id']])->fetchAll() as $r) {
    if (!$r['category_id'] && !$r['location_id']) continue;
    if ($r['category_id'] && !in_array((int)$item['category_id'], cat_descendants($r['category_id']), true)) continue;
    if ($r['location_id'] && !in_array((int)$item['location_id'], loc_descendants($r['location_id']), true)) continue;
    return true;
  }
  return false;
}
function create_reset_token($uid, $hours = 1){
  $t = bin2hex(random_bytes(32));
  q("DELETE FROM password_resets WHERE user_id=? OR expires_at < NOW()", [$uid]);
  q("INSERT INTO password_resets(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(), INTERVAL ? HOUR))", [$uid, hash('sha256', $t), (int)$hours]);
  return $t;
}
function send_account_email($u, $invite){
  global $cfg; if (!mail_enabled() || !valid_email($u['email'] ?? '')) return false;
  $t = create_reset_token($u['id'], $invite ? 168 : 1); $link = base_url().'reset.php?t='.$t;
  if ($invite) {
    $html = mail_wrap('Your account is ready', '<p>An account was created for you on '.h($cfg['app_name']).'.</p><p>Username: <strong>'.h($u['username']).'</strong></p><p><a href="'.h($link).'" style="background:#0d6efd;color:#fff;padding:10px 16px;text-decoration:none;border-radius:4px;display:inline-block">Set your password</a></p><p style="font-size:13px;color:#6c757d">This link works for 7 days and can be used once.</p>');
    return mail_queue($u['email'], 'Your '.$cfg['app_name'].' account', $html, 'account_created');
  }
  $html = mail_wrap('Reset your password', '<p>Someone asked to reset the password for <strong>'.h($u['username']).'</strong>. If that was you, use the button below.</p><p><a href="'.h($link).'" style="background:#0d6efd;color:#fff;padding:10px 16px;text-decoration:none;border-radius:4px;display:inline-block">Choose a new password</a></p><p style="font-size:13px;color:#6c757d">This link works for 1 hour and can be used once. If you did not ask for this, ignore this email; your password has not changed.</p>');
  return mail_queue($u['email'], 'Reset your '.$cfg['app_name'].' password', $html, 'password_reset');
}
function notify_stock($it, $old, $new){
  global $cfg; if (!mail_enabled() || $it['type'] === 'asset' || $it['min_qty'] <= 0) return;
  $kind = ($old > 0 && $new <= 0) ? 'out' : (($old > $it['min_qty'] && $new > 0 && $new <= $it['min_qty']) ? 'low' : null); if (!$kind) return;
  $vend = q("SELECT v.name,v.phone,iv.part_no FROM item_vendors iv JOIN vendors v ON v.id=iv.vendor_id WHERE iv.item_id=? AND v.active=1 AND iv.unavailable=0 ORDER BY iv.rank_no LIMIT 3", [$it['id']])->fetchAll();
  $order = (!empty($it['par_qty']) && $it['par_qty'] > $new) ? $it['par_qty'] - $new : ($it['reorder_qty'] ?? null);
  $users = q("SELECT * FROM users WHERE active=1 AND notify_stock=1 AND email IS NOT NULL AND email<>''")->fetchAll();
  foreach ($users as $u) {
    if (!user_sees_item($u, $it)) continue;
    $link = base_url().($u['role'] === 'vendor' ? 'supply.php' : 'item_view.php?id='.$it['id']);
    $rows = '<p style="font-size:15px"><strong>'.h($it['name']).'</strong><br>'.h($new + 0).' '.h($it['unit']).' on hand (alert level '.h($it['min_qty'] + 0).')'.($order ? '<br>Suggested order: <strong>'.h($order + 0).'</strong>' : '').'</p>';
    if ($vend && $u['role'] !== 'vendor') { $rows .= '<p>Order from:<br>'; foreach ($vend as $k => $v) $rows .= ($k + 1).'. '.h($v['name']).($v['phone'] ? ' '.h($v['phone']) : '').($v['part_no'] ? ' #'.h($v['part_no']) : '').'<br>'; $rows .= '</p>'; }
    $rows .= '<p><a href="'.h($link).'">'.($u['role'] === 'vendor' ? 'Open the supply page to acknowledge' : 'View the item').'</a></p>';
    mail_queue($u['email'], ($kind === 'out' ? 'OUT OF STOCK: ' : 'LOW STOCK: ').$it['name'], mail_wrap($kind === 'out' ? 'Out of stock' : 'Low stock', $rows), 'stock_'.$kind);
  }
}
function notify_checklist($cl, $rid, $issues, $failed, $notes){
  global $cfg; if (!mail_enabled()) return;
  $done = parse_emails($cl['notify_complete'] ?? ''); $fail = $issues > 0 ? parse_emails($cl['notify_fail'] ?? '') : [];
  if ($fail) $done = array_values(array_udiff($done, $fail, 'strcasecmp'));
  $link = base_url().'checklist_runs.php?view='.$rid; $who = me()['name'] ?? '';
  $summary = '<p><strong>'.h($cl['name']).'</strong><br>Completed by '.h($who).' on '.h(date('M j, Y g:i a')).'</p>';
  if ($notes) $summary .= '<p>Notes: '.h($notes).'</p>';
  $list = ''; foreach ($failed as $f) $list .= '<li>'.h($f['label']).' &ndash; <strong>'.h($f['status']).'</strong>'.($f['note'] !== '' ? ': '.h($f['note']) : '').(!empty($f['photos']) ? ' &ndash; '.implode(', ', array_map(fn($p, $n) => '<a href="'.h(base_url().'uploads/'.$p).'">photo '.($n + 1).'</a>', $f['photos'], array_keys($f['photos']))) : '').'</li>';
  foreach ($done as $e) mail_queue($e, 'Checklist completed: '.$cl['name'], mail_wrap('Checklist completed', $summary.($issues ? '<p>'.(int)$issues.' item(s) need attention.</p><ul>'.$list.'</ul>' : '<p>Everything passed.</p>').'<p><a href="'.h($link).'">View the results</a></p>'), 'checklist_complete');
  foreach ($fail as $e) mail_queue($e, 'ISSUES FOUND: '.$cl['name'], mail_wrap('Checklist found issues', $summary.'<p><strong>'.(int)$issues.' item(s) failed:</strong></p><ul>'.$list.'</ul><p><a href="'.h($link).'">View the results</a></p>'), 'checklist_fail');
}
