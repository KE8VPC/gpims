<?php
function mail_key(){ global $cfg; return hash('sha256', 'gpims|'.$cfg['host'].'|'.$cfg['name'].'|'.$cfg['user'].'|'.$cfg['pass'], true); }
function mail_enc($s){
  if (!function_exists('openssl_encrypt')) return 'p:'.base64_encode($s);
  $iv = random_bytes(16); return 'e:'.base64_encode($iv.openssl_encrypt($s, 'aes-256-cbc', mail_key(), OPENSSL_RAW_DATA, $iv));
}
function mail_dec($s){
  if (strpos($s, 'p:') === 0) return base64_decode(substr($s, 2));
  if (strpos($s, 'e:') !== 0 || !function_exists('openssl_decrypt')) return '';
  $r = base64_decode(substr($s, 2)); if (strlen($r) < 17) return '';
  $p = openssl_decrypt(substr($r, 16), 'aes-256-cbc', mail_key(), OPENSSL_RAW_DATA, substr($r, 0, 16)); return $p === false ? '' : $p;
}
function valid_email($e){ return (bool)filter_var($e, FILTER_VALIDATE_EMAIL) && !preg_match('/[\r\n]/', $e); }
function parse_emails($s){ $o = []; foreach (preg_split('/[\s,;]+/', (string)$s) as $e) { $e = trim($e); if ($e !== '' && valid_email($e)) $o[strtolower($e)] = $e; } return array_values($o); }
function mail_enabled(){ return in_array(setting('mail_method', 'off'), ['smtp', 'php'], true) && valid_email(setting('mail_from_email')); }
function base_url(){
  $s = trim(setting('site_url', '')); if ($s !== '') return rtrim($s, '/').'/';
  global $https; $host = $_SERVER['HTTP_HOST'] ?? 'localhost'; if (!preg_match('/^[A-Za-z0-9.\-:]+$/', $host)) $host = 'localhost';
  return ($https ? 'https' : 'http').'://'.$host.rtrim(dirname($_SERVER['SCRIPT_NAME']), '/').'/';
}
function mail_wrap($title, $html){
  global $cfg; $org = h($cfg['app_name']);
  return '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#212529"><div style="background:#0d6efd;color:#fff;padding:12px 16px;font-size:16px;font-weight:bold">'.$org.'</div><div style="padding:16px;border:1px solid #dee2e6;border-top:0"><h2 style="font-size:18px;margin:0 0 12px">'.h($title).'</h2>'.$html.'</div><div style="font-size:12px;color:#6c757d;padding:10px 4px">Sent by '.$org.'. You can change email preferences on your Account page.</div></div>';
}
function mail_text($html){ $html = preg_replace_callback('/<a\s[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is', function($m){ $l = trim(strip_tags($m[3])); $u = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'); return ($l !== '' && $l !== $u ? $l.': ' : '').$u; }, $html); $t = str_replace(['</p>', '<br>', '<br/>', '</tr>', '</li>', '</h2>'], "\n", $html); $t = html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8'); return trim(preg_replace("/\n{3,}/", "\n\n", $t)); }
function mail_message($from, $fromName, $to, $subject, $html, $text){
  $b = 'b'.bin2hex(random_bytes(8)); $enc = fn($s) => '=?UTF-8?B?'.base64_encode($s).'?='; $dom = substr(strrchr($from, '@'), 1);
  $common = ['MIME-Version: 1.0', 'Content-Type: multipart/alternative; boundary="'.$b.'"'];
  $head = array_merge(['Date: '.date('r'), 'From: '.$enc($fromName).' <'.$from.'>', 'Message-ID: <'.bin2hex(random_bytes(12)).'@'.$dom.'>'], $common);
  $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($text))."--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($html))."--$b--\r\n";
  return ['head'=>$head, 'to'=>$to, 'subject'=>$enc($subject), 'body'=>$body];
}
function smtp_deliver($m, $from, &$err){
  $host = setting('smtp_host'); $port = (int)setting('smtp_port', 587); $sec = setting('smtp_secure', 'tls'); $t = 10;
  $ctx = stream_context_create(['ssl'=>['verify_peer'=>true, 'verify_peer_name'=>true]]);
  $fp = @stream_socket_client(($sec === 'ssl' ? 'ssl://' : 'tcp://').$host.':'.$port, $en, $es, $t, STREAM_CLIENT_CONNECT, $ctx);
  if (!$fp) { $err = "Could not connect to $host:$port ($es)"; return false; }
  stream_set_timeout($fp, $t);
  $rd = function() use ($fp){ $r = ''; while (($l = fgets($fp, 515)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $r; };
  $cmd = function($c, $ok) use ($fp, $rd, &$err){ if ($c !== null) fwrite($fp, $c."\r\n"); $r = $rd(); if (!preg_match('/^('.$ok.')/', $r)) { $err = 'Mail server said: '.trim($r); return false; } return true; };
  $name = preg_replace('/[^A-Za-z0-9.\-]/', '', $_SERVER['SERVER_NAME'] ?? 'localhost') ?: 'localhost';
  $fail = function() use ($fp){ @fwrite($fp, "QUIT\r\n"); @fclose($fp); return false; };
  if (!$cmd(null, '220') || !$cmd('EHLO '.$name, '250')) return $fail();
  if ($sec === 'tls') {
    if (!$cmd('STARTTLS', '220')) return $fail();
    if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { $err = 'Could not start TLS encryption with the mail server.'; return $fail(); }
    if (!$cmd('EHLO '.$name, '250')) return $fail();
  }
  $u = setting('smtp_user');
  if ($u !== '') {
    $pw = mail_dec(setting('smtp_pass_enc'));
    if (!$cmd('AUTH LOGIN', '334') || !$cmd(base64_encode($u), '334') || !$cmd(base64_encode($pw), '235')) return $fail();
  }
  if (!$cmd('MAIL FROM:<'.$from.'>', '250') || !$cmd('RCPT TO:<'.$m['to'].'>', '250|251') || !$cmd('DATA', '354')) return $fail();
  $data = implode("\r\n", array_merge($m['head'], ['To: <'.$m['to'].'>', 'Subject: '.$m['subject']]))."\r\n\r\n".$m['body'];
  $data = preg_replace('/^\./m', '..', str_replace("\r\n", "\n", $data)); $data = str_replace("\n", "\r\n", $data);
  fwrite($fp, $data."\r\n.\r\n");
  if (!$cmd(null, '250')) return $fail();
  @fwrite($fp, "QUIT\r\n"); @fclose($fp); return true;
}
function mail_deliver($to, $subject, $html, $text, &$err){
  $err = ''; $from = setting('mail_from_email'); $fn = setting('mail_from_name', $GLOBALS['cfg']['app_name']);
  if (!valid_email($to) || !valid_email($from)) { $err = 'Invalid email address.'; return false; }
  $m = mail_message($from, $fn, $to, $subject, $html, $text);
  if (setting('mail_method') === 'smtp') return smtp_deliver($m, $from, $err);
  $ok = @mail($to, $m['subject'], $m['body'], implode("\r\n", $m['head']), '-f'.$from);
  if (!$ok) $err = 'PHP mail() refused the message. Your host may require SMTP.';
  return $ok;
}
function mail_queue($to, $subject, $html, $event = '', $toName = ''){
  if (!mail_enabled() || !valid_email($to)) return false;
  q("INSERT INTO email_queue(to_email,subject,body_html,body_text,event) VALUES(?,?,?,?,?)", [$to, mb_substr($subject, 0, 200), $html, mail_text($html), $event]);
  static $reg = false; if (!$reg) { $reg = true; register_shutdown_function('mail_flush'); }
  return true;
}
function mail_maybe_flush(){
  try {
    if (!mail_enabled() || time() - (int)setting('mail_last_flush', 0) < 300) return;
    set_setting('mail_last_flush', (string)time());
    check_overdue_notify();
    if ((int)q("SELECT COUNT(*) FROM email_queue WHERE status='pending' AND attempts<5")->fetchColumn() > 0) register_shutdown_function('mail_flush');
  } catch (Throwable $e) {}
}
function mail_flush(){
  try {
    if (!mail_enabled()) return;
    if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
    @set_time_limit(60);
    foreach (q("SELECT * FROM email_queue WHERE status='pending' AND attempts<5 ORDER BY id LIMIT 8")->fetchAll() as $r) {
      q("UPDATE email_queue SET attempts=attempts+1 WHERE id=?", [$r['id']]);
      $ok = mail_deliver($r['to_email'], $r['subject'], $r['body_html'], $r['body_text'], $err);
      if ($ok) q("UPDATE email_queue SET status='sent',sent_at=NOW(),last_error=NULL WHERE id=?", [$r['id']]);
      else q("UPDATE email_queue SET last_error=?,status=IF(attempts>=5,'failed','pending') WHERE id=?", [mb_substr($err, 0, 250), $r['id']]);
    }
  } catch (Throwable $e) {}
}

// Email the people responsible when a scheduled check goes overdue (once, then a reminder after 7 days).
function check_overdue_notify(){
  try { loans_overdue_notify(); } catch (Throwable $e) {}
  try { maint_notify(); } catch (Throwable $e) {}
  try { resv_remind(); } catch (Throwable $e) {}
  try { expiry_notify(); } catch (Throwable $e) {}
  try { housekeeping(); } catch (Throwable $e) {}
  try { digest_run(); } catch (Throwable $e) {}
  try { if (function_exists('reports_scheduled_send')) reports_scheduled_send(); } catch (Throwable $e) {}
  try {
    foreach (q("SELECT c.*,v.name vname,v.owner_person_id,v.active vactive FROM checklists c LEFT JOIN vehicles v ON v.id=c.vehicle_id WHERE c.active=1 AND c.frequency_days IS NOT NULL AND (c.overdue_notified_on IS NULL OR c.overdue_notified_on < DATE_SUB(CURDATE(), INTERVAL 7 DAY))")->fetchAll() as $c) {
      if ($c['vehicle_id'] && !$c['vactive']) continue;
      $st = check_status($c); if ($st['state'] !== 'overdue') continue;
      $to = parse_emails($c['notify_fail'] ?? '');
      if (!empty($c['owner_person_id'])) { $e = q("SELECT u.email FROM people p JOIN users u ON u.id=p.user_id AND u.active=1 WHERE p.id=?", [$c['owner_person_id']])->fetchColumn(); if ($e && valid_email($e)) $to[] = $e; }
      $to = array_values(array_unique(array_map('strtolower', $to))); if (!$to) { q("UPDATE checklists SET overdue_notified_on=CURDATE() WHERE id=?", [$c['id']]); continue; }
      $nm = ($c['vname'] ? $c['vname'].' - ' : '').$c['name'];
      $html = mail_wrap('Check overdue', '<p><strong>'.h($nm).'</strong> is '.($st['never'] ? 'not yet done' : 'overdue by '.(int)$st['late'].' day(s)').'. It is scheduled '.h(strtolower(freq_label($c['frequency_days']))).'.</p><p><a href="'.h(base_url().'checklist_run.php?id='.$c['id']).'">Run the check now</a></p>');
      foreach ($to as $e) mail_queue($e, 'CHECK OVERDUE: '.$nm, $html, 'check_overdue');
      q("UPDATE checklists SET overdue_notified_on=CURDATE() WHERE id=?", [$c['id']]);
    }
  } catch (Throwable $e) {}
}

// Overdue loans: email whoever issued the item plus admins who get alert emails; once, then a reminder after 7 days.
function loans_overdue_notify(){
  foreach (q("SELECT c.*,i.name item,i.category_id icat,i.location_id iloc,p.phone FROM checkouts c JOIN items i ON i.id=c.item_id LEFT JOIN people p ON p.id=c.person_id WHERE c.in_at IS NULL AND c.due_at IS NOT NULL AND c.due_at < CURDATE() AND i.archived_at IS NULL AND (c.overdue_notified_on IS NULL OR c.overdue_notified_on < DATE_SUB(CURDATE(), INTERVAL 7 DAY))")->fetchAll() as $c) {
    $to = []; foreach (q("SELECT * FROM users WHERE active=1 AND email<>''")->fetchAll() as $u) if (valid_email($u['email']) && ((int)$u['id'] === (int)$c['user_id'] || (user_wants($u, 'loans') && user_sees_item($u, ['category_id' => $c['icat'], 'location_id' => $c['iloc']])))) $to[strtolower($u['email'])] = $u['email'];
    $late = (int)round((strtotime(date('Y-m-d')) - strtotime($c['due_at'])) / 86400);
    $html = mail_wrap('Loan overdue', '<p><strong>'.h($c['item']).'</strong> is '.$late.' day(s) overdue.</p><p>With <strong>'.h($c['borrower']).'</strong>'.($c['phone'] ? ' ('.h($c['phone']).')' : '').' since '.h(substr($c['out_at'], 0, 10)).', due back '.h($c['due_at']).'.</p><p><a href="'.h(base_url().'item_view.php?id='.$c['item_id']).'">Open the item</a></p>');
    foreach ($to as $e) mail_queue($e, 'LOAN OVERDUE: '.$c['item'], $html, 'loan_overdue');
    q("UPDATE checkouts SET overdue_notified_on=CURDATE() WHERE id=?", [$c['id']]);
  }
}

// Once a day: drop old sign-in attempts and delivered mail so those tables stay small.
function housekeeping(){
  $today = date('Y-m-d'); if (setting('housekeeping_on', '') === $today) return; set_setting('housekeeping_on', $today);
  q("DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 30 DAY)");
  q("DELETE FROM email_queue WHERE status IN ('sent','failed') AND created_at < (NOW() - INTERVAL 90 DAY)");
  q("DELETE FROM password_resets WHERE created_at < (NOW() - INTERVAL 7 DAY)");
  try { trash_purge_old(); } catch (Throwable $e) {}
  try { backup_auto_run(); } catch (Throwable $e) {}
  try { lookup_cleanup(); } catch (Throwable $e) {}
}
