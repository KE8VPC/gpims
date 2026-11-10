<?php
// Report definitions shared by the Reports page, CSV download and scheduled emails.
const REPORTS = [
  'inventory'   => ['Inventory list', 'Every item with category, location, quantity and status', ['cat', 'loc']],
  'valuation'   => ['Inventory value', 'Units and dollar value by category', ['cat', 'loc']],
  'reorder'     => ['Reorder list', 'Items at or below their alert level, with suggested order and vendor', ['cat', 'loc']],
  'expiring'    => ['Expiring items', 'Lots that expired or expire soon', ['days', 'cat', 'loc']],
  'activity'    => ['Stock activity', 'Every stock movement in a date range', ['range', 'cat', 'loc']],
  'loans'       => ['Out now / loans', 'Everything currently out, who has it and since when', []],
  'person'      => ['Held by person', 'How much each person or agency has out, with oldest and overdue', []],
  'reservations'=> ['Reservations', 'Reservations that overlap a date range', ['range']],
  'maintenance' => ['Maintenance schedule', 'Every recurring task with its next due date', ['cat', 'loc']],
  'maintlog'    => ['Maintenance history', 'Maintenance marked done in a date range', ['range']],
  'checks'      => ['Check results', 'Every completed check in a date range', ['range']],
  'compliance'  => ['Check compliance', 'Are scheduled checks being done on time?', ['range']],
  'problems'    => ['Problem lines', 'Check lines that failed most often in a date range', ['range']],
  'recounts'    => ['Recount differences', 'Counts that differed from the system', ['range']],
];
const REPORT_GROUPS = ['Stock' => ['inventory', 'valuation', 'reorder', 'expiring', 'activity', 'recounts'], 'Loans & planning' => ['loans', 'person', 'reservations', 'maintenance', 'maintlog'], 'Checks' => ['checks', 'compliance', 'problems']];

function report_params($src){
  $d = fn($k, $def) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $src[$k] ?? '') ? $src[$k] : $def;
  return ['from' => $d('from', date('Y-m-d', strtotime('-30 days'))), 'to' => $d('to', date('Y-m-d')), 'days' => max(1, min(730, (int)($src['days'] ?? 90))), 'cat' => (int)($src['cat'] ?? 0), 'loc' => (int)($src['loc'] ?? 0)];
}
function loc_path_name($id){
  static $m = null; if ($m === null) { $m = []; foreach (locations_all() as $l) $m[(int)$l['id']] = $l; }
  $o = []; $n = 0; while ($id && isset($m[(int)$id]) && $n++ < 8) { array_unshift($o, $m[(int)$id]['name']); $id = $m[(int)$id]['parent_id']; } return implode(' / ', $o);
}
function report_scope_where($P, $a = 'i'){
  $w = "$a.archived_at IS NULL AND ".(!empty($GLOBALS['REPORT_ALL']) ? '1=1' : scope_sql($a));
  if (!empty($P['cat'])) $w .= " AND $a.category_id IN (".implode(',', array_map('intval', cat_descendants($P['cat']))).")";
  if (!empty($P['loc'])) $w .= " AND $a.location_id IN (".implode(',', array_map('intval', loc_descendants($P['loc']))).")";
  return $w;
}
// Returns ['head'=>[], 'rows'=>[], 'note'=>'', 'sub'=>'filters text']
function report_build($r, $P){
  $head = []; $rows = []; $note = ''; $W = report_scope_where($P); $from = $P['from']; $to = $P['to']; $days = $P['days'];
  $today = date('Y-m-d');
  switch ($r) {
  case 'inventory':
    $head = ['Item', 'Type', 'Category', 'Location', 'On hand', 'Unit', 'Status', 'Serial', 'Tag / SKU'];
    foreach (q("SELECT i.* FROM items i WHERE $W ORDER BY i.name")->fetchAll() as $x) $rows[] = [$x['name'], $x['type'], cat_path($x['category_id']), loc_path_name($x['location_id']), $x['type'] === 'asset' ? 1 : fmt_q($x['qty']), $x['type'] === 'asset' ? '' : $x['unit'], $x['status'], $x['serial'], $x['sku']];
    break;
  case 'valuation':
    $head = ['Category', 'Items', 'Units', 'Value'];
    $agg = []; foreach (q("SELECT i.category_id,COUNT(*) n,SUM(CASE WHEN i.type='asset' THEN 1 ELSE i.qty END) units,SUM(CASE WHEN i.type='asset' THEN 1 ELSE i.qty END*COALESCE(i.unit_cost,0)) val FROM items i WHERE $W AND i.status<>'retired' GROUP BY i.category_id")->fetchAll() as $x) $agg[] = [cat_path($x['category_id']) ?: '(none)', $x['n'], (float)$x['units'], (float)$x['val']];
    usort($agg, fn($a, $b) => $b[3] <=> $a[3]); foreach ($agg as $x) $rows[] = [$x[0], $x[1], fmt_q($x[2]), number_format($x[3], 2)];
    $t = q("SELECT SUM(CASE WHEN i.type='asset' THEN 1 ELSE i.qty END*COALESCE(i.unit_cost,0)) FROM items i WHERE $W AND i.status<>'retired'")->fetchColumn(); $rows[] = ['TOTAL', '', '', number_format((float)$t, 2)];
    $miss = (int)q("SELECT COUNT(*) FROM items i WHERE $W AND i.status<>'retired' AND (i.unit_cost IS NULL OR i.unit_cost=0)")->fetchColumn(); if ($miss) $note = "$miss item(s) have no unit cost and count as 0."; break;
  case 'reorder':
    $head = ['Item', 'Category', 'On hand', 'Alert at', 'Suggested order', 'Vendor'];
    foreach (q("SELECT i.* FROM items i WHERE $W AND i.status<>'retired' AND i.type<>'asset' AND i.min_qty>0 AND i.qty<=i.min_qty ORDER BY i.name")->fetchAll() as $x) { $v = q("SELECT v.name FROM item_vendors iv JOIN vendors v ON v.id=iv.vendor_id WHERE iv.item_id=? AND v.active=1 AND iv.unavailable=0 ORDER BY iv.rank_no LIMIT 1", [$x['id']])->fetchColumn(); $h = reorder_hint($x); $rows[] = [$x['name'], cat_path($x['category_id']), fmt_q($x['qty']), fmt_q($x['min_qty']), $h ? fmt_q($h) : '', $v ?: '']; } break;
  case 'expiring':
    $head = ['Item', 'Lot', 'Qty', 'Expires', 'Days left'];
    foreach (q("SELECT lt.*,i.name item FROM item_lots lt JOIN items i ON i.id=lt.item_id WHERE $W AND lt.expires_on IS NOT NULL AND lt.expires_on <= DATE_ADD(CURDATE(), INTERVAL $days DAY) ORDER BY lt.expires_on")->fetchAll() as $x) $rows[] = [$x['item'], $x['lot_no'], $x['qty'] !== null ? fmt_q($x['qty']) : '', $x['expires_on'], (int)round((strtotime($x['expires_on']) - strtotime($today)) / 86400)]; break;
  case 'activity':
    $head = ['When', 'Item', 'Type', 'Qty', 'Balance', 'User', 'Note'];
    foreach (q("SELECT t.*,i.name item,u.name uname FROM transactions t JOIN items i ON i.id=t.item_id LEFT JOIN users u ON u.id=t.user_id WHERE $W AND DATE(t.created_at) BETWEEN ? AND ? ORDER BY t.id DESC LIMIT 2000", [$from, $to])->fetchAll() as $x) $rows[] = [$x['created_at'], $x['item'], $x['type'], fmt_q($x['qty']), $x['balance'] !== null ? fmt_q($x['balance']) : '', $x['uname'], $x['note']]; break;
  case 'loans':
    $head = ['Item', 'Qty', 'With', 'Since', 'Due', 'Status', 'Event'];
    foreach (q("SELECT i.name item,1 qty,c.borrower holder,c.out_at since,c.due_at due,n.name inc FROM checkouts c JOIN items i ON i.id=c.item_id LEFT JOIN incidents n ON n.id=c.incident_id WHERE c.in_at IS NULL AND $W UNION ALL SELECT i.name,".OUT_EXPR.",l.holder_name,l.issued_at,NULL,n.name FROM issues l JOIN items i ON i.id=l.item_id LEFT JOIN incidents n ON n.id=l.incident_id WHERE l.closed_at IS NULL AND ".OUT_EXPR." > 0.0001 AND $W ORDER BY 4")->fetchAll() as $x) $rows[] = [$x['item'], fmt_q($x['qty']), $x['holder'], substr($x['since'], 0, 10), $x['due'], ($x['due'] && $x['due'] < $today) ? 'OVERDUE' : '', $x['inc']]; break;
  case 'person':
    $head = ['Held by', 'Lines out', 'Units out', 'Oldest since', 'Overdue'];
    $agg = [];
    foreach (q("SELECT c.borrower holder,1 qty,c.out_at since,c.due_at due FROM checkouts c JOIN items i ON i.id=c.item_id WHERE c.in_at IS NULL AND $W UNION ALL SELECT l.holder_name,".OUT_EXPR.",l.issued_at,NULL FROM issues l JOIN items i ON i.id=l.item_id WHERE l.closed_at IS NULL AND ".OUT_EXPR." > 0.0001 AND $W")->fetchAll() as $x) { $k = $x['holder'] ?: '(unnamed)'; $a = &$agg[$k]; if (!$a) $a = ['n' => 0, 'u' => 0, 'since' => '9999', 'od' => 0]; $a['n']++; $a['u'] += (float)$x['qty']; $a['since'] = min($a['since'], substr($x['since'], 0, 10)); if ($x['due'] && $x['due'] < $today) $a['od']++; unset($a); }
    uasort($agg, fn($a, $b) => $b['u'] <=> $a['u']); foreach ($agg as $k => $a) $rows[] = [$k, $a['n'], fmt_q($a['u']), $a['since'], $a['od'] ?: '']; break;
  case 'reservations':
    $head = ['Item', 'Borrower', 'From', 'To', 'Qty', 'Status', 'Event'];
    foreach (q("SELECT r.*,i.name item,n.name inc FROM reservations r JOIN items i ON i.id=r.item_id LEFT JOIN incidents n ON n.id=r.incident_id WHERE r.status<>'cancelled' AND r.starts_on<=? AND r.ends_on>=? AND ".(!empty($GLOBALS['REPORT_ALL']) ? '1=1' : scope_sql('i'))." ORDER BY r.starts_on,i.name", [$to, $from])->fetchAll() as $x) $rows[] = [$x['item'], $x['borrower'], $x['starts_on'], $x['ends_on'], fmt_q($x['qty']), $x['status'], $x['inc']]; break;
  case 'maintenance':
    $head = ['Item', 'Task', 'Every', 'Last done', 'Next due', 'Status'];
    foreach (q("SELECT t.*,i.name item FROM maintenance_tasks t JOIN items i ON i.id=t.item_id WHERE t.active=1 AND $W ORDER BY t.next_due,i.name")->fetchAll() as $x) { $d = (int)round((strtotime($today) - strtotime($x['next_due'])) / 86400); $rows[] = [$x['item'], $x['title'], $x['every_days'].' days', $x['last_done'], $x['next_due'], $d > 0 ? "OVERDUE $d day(s)" : ($d >= -(int)setting('maint_warn_days', 7) ? 'Due soon' : 'OK')]; } break;
  case 'maintlog':
    $head = ['Done on', 'Item', 'Task', 'By', 'Note'];
    foreach (q("SELECT l.*,i.name item,u.name uname FROM maintenance_log l JOIN items i ON i.id=l.item_id LEFT JOIN users u ON u.id=l.user_id WHERE l.done_on BETWEEN ? AND ? AND $W ORDER BY l.done_on DESC,l.id DESC LIMIT 2000", [$from, $to])->fetchAll() as $x) $rows[] = [$x['done_on'], $x['item'], $x['title'], $x['uname'], $x['note']]; break;
  case 'checks':
    $head = ['When', 'Vehicle', 'Check', 'By', 'Issues', 'Photos', 'Notes'];
    foreach (q("SELECT r.*,c.name cname,c.vehicle_id,v.name vname,u.name uname,(SELECT COUNT(*) FROM checklist_run_items ri WHERE ri.run_id=r.id AND ri.photo IS NOT NULL)+(SELECT COUNT(*) FROM check_photos cp JOIN checklist_run_items ri2 ON ri2.id=cp.run_item_id WHERE ri2.run_id=r.id) np FROM checklist_runs r JOIN checklists c ON c.id=r.checklist_id LEFT JOIN vehicles v ON v.id=c.vehicle_id LEFT JOIN users u ON u.id=r.user_id WHERE DATE(r.created_at) BETWEEN ? AND ? ORDER BY r.id DESC LIMIT 2000", [$from, $to])->fetchAll() as $x) if (!empty($GLOBALS['REPORT_ALL']) || checklist_can_view($x)) $rows[] = [$x['created_at'], $x['vname'], $x['cname'], $x['uname'], $x['issues'], $x['np'] ?: '', $x['notes']]; break;
  case 'compliance':
    $head = ['Vehicle', 'Check', 'Schedule', 'Last done', 'Status', 'Runs in range', 'Runs with issues'];
    foreach (q("SELECT c.*,v.name vname,v.active vactive FROM checklists c LEFT JOIN vehicles v ON v.id=c.vehicle_id WHERE c.active=1 AND c.frequency_days IS NOT NULL ORDER BY v.name,c.name")->fetchAll() as $c) {
      if ($c['vehicle_id'] && !$c['vactive']) continue; if (empty($GLOBALS['REPORT_ALL']) && !checklist_can_view($c)) continue;
      $st = check_status($c); $n = (int)q("SELECT COUNT(*) FROM checklist_runs WHERE checklist_id=? AND DATE(created_at) BETWEEN ? AND ?", [$c['id'], $from, $to])->fetchColumn(); $ni = (int)q("SELECT COUNT(*) FROM checklist_runs WHERE checklist_id=? AND issues>0 AND DATE(created_at) BETWEEN ? AND ?", [$c['id'], $from, $to])->fetchColumn();
      $rows[] = [$c['vname'], $c['name'], freq_label($c['frequency_days']), $st['last'] ? substr($st['last']['created_at'], 0, 10) : 'never', $st['never'] ? 'NEVER DONE' : ($st['state'] === 'overdue' ? 'OVERDUE '.(int)$st['late'].' day(s)' : ($st['state'] === 'due' ? 'Due today' : 'On time')), $n, $ni];
    } break;
  case 'problems':
    $head = ['Vehicle', 'Check', 'Section', 'Line', 'Times failed', 'Last failed', 'Latest note'];
    foreach (q("SELECT c.name cname,c.vehicle_id,v.name vname,ri.section,ri.label,COUNT(*) n,MAX(r.created_at) last,SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(ri.note,'') ORDER BY r.id DESC SEPARATOR '\n'),'\n',1) note FROM checklist_run_items ri JOIN checklist_runs r ON r.id=ri.run_id JOIN checklists c ON c.id=r.checklist_id LEFT JOIN vehicles v ON v.id=c.vehicle_id WHERE ri.status<>'ok' AND DATE(r.created_at) BETWEEN ? AND ? GROUP BY c.id,ri.section,ri.label ORDER BY n DESC,last DESC LIMIT 500", [$from, $to])->fetchAll() as $x) if (!empty($GLOBALS['REPORT_ALL']) || checklist_can_view($x)) $rows[] = [$x['vname'], $x['cname'], $x['section'], $x['label'], $x['n'], substr($x['last'], 0, 10), $x['note']]; break;
  case 'recounts':
    $head = ['When', 'Scope', 'Item', 'Was', 'Counted', 'Difference'];
    foreach (q("SELECT r.created_at,r.scope,l.item_name,l.expected,l.counted FROM recount_lines l JOIN recounts r ON r.id=l.recount_id LEFT JOIN items i ON i.id=l.item_id WHERE l.counted<>l.expected AND DATE(r.created_at) BETWEEN ? AND ? AND (l.item_id IS NULL OR (i.archived_at IS NULL AND ".(!empty($GLOBALS['REPORT_ALL']) ? '1=1' : scope_sql('i')).")) ORDER BY r.id DESC,l.item_name LIMIT 2000", [$from, $to])->fetchAll() as $x) $rows[] = [$x['created_at'], $x['scope'], $x['item_name'], fmt_q($x['expected']), fmt_q($x['counted']), fmt_q($x['counted'] - $x['expected'])]; break;
  }
  $sub = []; $f = REPORTS[$r][2] ?? [];
  if (in_array('range', $f, true)) $sub[] = "$from to $to"; if (in_array('days', $f, true)) $sub[] = "within $days days";
  if (!empty($P['cat'])) $sub[] = 'category: '.cat_path($P['cat']); if (!empty($P['loc'])) $sub[] = 'location: '.loc_path_name($P['loc']);
  return ['head' => $head, 'rows' => $rows, 'note' => $note, 'sub' => implode(' · ', $sub)];
}
function report_param_string($r, $P){ $o = []; foreach (REPORTS[$r][2] ?? [] as $f) { if ($f === 'range') { $o['from'] = $P['from']; $o['to'] = $P['to']; } elseif (!empty($P[$f])) $o[$f] = $P[$f]; } return http_build_query($o); }
function report_html_table($head, $rows, $max = 150){
  $o = '<table style="border-collapse:collapse;width:100%;font-size:12px"><tr>'.implode('', array_map(fn($c) => '<th style="text-align:left;border-bottom:2px solid #dee2e6;padding:3px 6px;background:#f8f9fa">'.h($c).'</th>', $head)).'</tr>';
  foreach (array_slice($rows, 0, $max) as $row) $o .= '<tr>'.implode('', array_map(fn($c) => '<td style="border-bottom:1px solid #eee;padding:3px 6px">'.h($c).'</td>', $row)).'</tr>';
  $o .= '</table>'; if (count($rows) > $max) $o .= '<p style="font-size:12px;color:#6c757d">Showing the first '.$max.' of '.count($rows).' rows. Open the report for the rest.</p>';
  return $o;
}
// Email scheduled reports that are due (runs from the mail cycle and cron.php).
function reports_scheduled_send(){
  if (!mail_enabled() || (int)date('G') < (int)setting('digest_hour', 7)) return;
  $today = date('Y-m-d'); $dow = (int)date('N'); $dom = (int)date('j');
  foreach (q("SELECT * FROM report_schedules WHERE active=1 AND (last_sent IS NULL OR last_sent<?)", [$today])->fetchAll() as $s) {
    if (!isset(REPORTS[$s['report']])) continue;
    $ls = $s['last_sent'];
    $due = $s['frequency'] === 'daily' || ($s['frequency'] === 'weekly' && ($dow === 1 || !$ls || $ls <= date('Y-m-d', strtotime('-8 days')))) || ($s['frequency'] === 'monthly' && ($dom === 1 || !$ls || $ls <= date('Y-m-d', strtotime('-32 days'))));
    if ($s['frequency'] === 'weekly' && $dow !== 1 && !$ls) $due = false; if ($s['frequency'] === 'monthly' && $dom !== 1 && !$ls) $due = false;
    if (!$due) continue;
    report_email($s['report'], $s['params'], parse_emails($s['recipients']), 'Scheduled report');
    q("UPDATE report_schedules SET last_sent=? WHERE id=?", [$today, $s['id']]);
  }
}
function report_email($key, $paramStr, $to, $prefix = ''){
  parse_str((string)$paramStr, $src); $P = report_params($src); $GLOBALS['REPORT_ALL'] = true;
  try { $d = report_build($key, $P); } finally { $GLOBALS['REPORT_ALL'] = false; }
  $link = base_url().'reports.php?r='.$key.($paramStr ? '&'.$paramStr : '');
  $html = mail_wrap(REPORTS[$key][0], '<p style="margin:0 0 8px;color:#6c757d;font-size:13px">'.h(date('M j, Y')).($d['sub'] ? ' · '.h($d['sub']) : '').'</p>'.($d['note'] ? '<p style="font-size:12px">'.h($d['note']).'</p>' : '').($d['rows'] ? report_html_table($d['head'], $d['rows']) : '<p>Nothing to report.</p>').'<p style="margin-top:12px"><a href="'.h($link).'">Open this report</a></p>');
  foreach ($to as $e) mail_queue($e, ($prefix ? $prefix.': ' : '').REPORTS[$key][0].' - '.date('M j'), $html, 'report');
  return count($d['rows']);
}
