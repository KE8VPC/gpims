<?php
const LEVELS = ['Empty', '1/4', '1/2', '3/4', 'Full'];
const CHECK_KINDS = ['ok'=>'OK / Not OK', 'count'=>'Count (expected qty)', 'level'=>'Level (fuel, fluid)', 'value'=>'Reading / note'];
const FREQ_PRESETS = [''=>'No schedule', '1'=>'Daily', '7'=>'Weekly', '30'=>'Monthly', '365'=>'Annual'];

function my_person_id(){
  static $c = false; if ($c !== false) return $c; if (!me()) return $c = null;
  $r = q("SELECT id FROM people WHERE user_id=? AND active=1 ORDER BY id LIMIT 1", [me()['id']])->fetch(); return $c = $r ? (int)$r['id'] : null;
}
function vehicle_owner_user($v){
  if (empty($v['owner_person_id'])) return null;
  $r = q("SELECT user_id FROM people WHERE id=?", [$v['owner_person_id']])->fetch(); return ($r && $r['user_id']) ? (int)$r['user_id'] : null;
}
function vehicle_is_owner($v){ return me() && ($ou = vehicle_owner_user($v)) && $ou === (int)me()['id']; }
function vehicle_can_view($v){
  if (!me() || me()['role'] === 'vendor' || level() < ROLES['viewer']) return false;
  if (me()['role'] === 'admin' || $v['kind'] === 'agency' || vehicle_is_owner($v)) return true;
  return (bool)q("SELECT 1 FROM vehicle_users WHERE vehicle_id=? AND user_id=?", [$v['id'], me()['id']])->fetch();
}
function vehicle_can_edit($v){
  if (!me()) return false;
  if (me()['role'] === 'admin' || vehicle_is_owner($v)) return true;
  return $v['kind'] === 'agency' && can('vehicles_manage');
}
function vehicle_can_run($v){ return vehicle_can_view($v) && (can('checklists_run') || vehicle_is_owner($v)); }
function vehicle_get($id){ $v = q("SELECT v.*,p.name owner FROM vehicles v LEFT JOIN people p ON p.id=v.owner_person_id WHERE v.id=?", [(int)$id])->fetch(); return $v ?: null; }
function vehicles_visible($activeOnly = true){
  $out = []; foreach (q("SELECT v.*,p.name owner FROM vehicles v LEFT JOIN people p ON p.id=v.owner_person_id ".($activeOnly ? "WHERE v.active=1 " : "")."ORDER BY v.name")->fetchAll() as $v) if (vehicle_can_view($v)) $out[] = $v;
  return $out;
}
function vehicle_can_create(){ return me() && (can('vehicles_manage') || (level() >= ROLES['user'] && my_person_id())); }

// Checklist access: vehicle checklists follow the vehicle, general ones follow the normal permissions.
function checklist_vehicle($cl){ static $c = []; if (empty($cl['vehicle_id'])) return null; return $c[$cl['vehicle_id']] ??= vehicle_get($cl['vehicle_id']); }
function checklist_can_view($cl){ $v = checklist_vehicle($cl); if ($v) return vehicle_can_view($v); return me() && level() >= ROLES['viewer'] && me()['role'] !== 'vendor'; }
function checklist_can_run($cl){ $v = checklist_vehicle($cl); return $v ? vehicle_can_run($v) : can('checklists_run'); }
function checklist_can_edit($cl){ $v = checklist_vehicle($cl); return $v ? vehicle_can_edit($v) : can('checklists_manage'); }

function freq_label($d){ if (!$d) return 'No schedule'; return FREQ_PRESETS[(string)$d] ?? "Every $d days"; }
// State of a scheduled checklist: none / ok / due / overdue
function check_status($cl){
  $last = q("SELECT id,issues,created_at FROM checklist_runs WHERE checklist_id=? ORDER BY id DESC LIMIT 1", [$cl['id']])->fetch();
  $base = $last ? $last['created_at'] : $cl['created_at']; $f = (int)($cl['frequency_days'] ?? 0);
  $since = (int)round((strtotime(date('Y-m-d')) - strtotime(substr($base, 0, 10))) / 86400);
  $state = 'none'; $late = 0;
  if ($f > 0) { $state = $since > $f ? 'overdue' : ($since === $f ? 'due' : 'ok'); $late = max(0, $since - $f); }
  return ['state'=>$state, 'late'=>$late, 'last'=>$last, 'never'=>!$last, 'open_issues'=>($last && (int)$last['issues'] > 0) ? (int)$last['issues'] : 0, 'next'=>$f > 0 ? date('M j', strtotime(substr($base, 0, 10)." +$f day")) : null];
}
function check_badge($st){
  $b = '';
  if ($st['state'] === 'overdue') $b .= '<span class="badge text-bg-danger">'.($st['never'] ? 'Never checked' : 'Overdue '.$st['late'].'d').'</span> ';
  elseif ($st['state'] === 'due') $b .= '<span class="badge text-bg-warning">Due today</span> ';
  if ($st['open_issues']) $b .= '<span class="badge text-bg-danger">'.$st['open_issues'].' open issue'.($st['open_issues'] > 1 ? 's' : '').'</span> ';
  if (!$b && $st['state'] === 'ok') $b = '<span class="badge text-bg-success">OK</span> ';
  return $b;
}
// Scheduled / vehicle checklists the current user can see that need action
function checks_attention(){
  static $c = null; if ($c !== null) return $c; $c = [];
  try {
    foreach (q("SELECT c.*,v.name vname,v.active vactive FROM checklists c LEFT JOIN vehicles v ON v.id=c.vehicle_id WHERE c.active=1 AND (c.frequency_days IS NOT NULL OR c.vehicle_id IS NOT NULL) ORDER BY v.name,c.name")->fetchAll() as $cl) {
      if ($cl['vehicle_id'] && !$cl['vactive']) continue; if (!checklist_can_view($cl)) continue;
      $st = check_status($cl); if (in_array($st['state'], ['overdue', 'due'], true) || $st['open_issues']) $c[] = ['cl'=>$cl, 'st'=>$st];
    }
  } catch (Throwable $e) { $c = []; }
  return $c;
}

// One uploaded image from $_FILES[$field] (optionally an index for array fields). Returns stored filename or null.
function upload_image($field, $idx = null){
  $f = $_FILES[$field] ?? null; if (!$f) return null;
  $err = $idx === null ? $f['error'] : ($f['error'][$idx] ?? UPLOAD_ERR_NO_FILE); $tmp = $idx === null ? $f['tmp_name'] : ($f['tmp_name'][$idx] ?? '');
  if ($err !== UPLOAD_ERR_OK || !$tmp || !is_uploaded_file($tmp)) return null;
  $dir = dirname(__DIR__).'/uploads'; if (!is_dir($dir)) @mkdir($dir, 0755);
  return save_photo($tmp, $dir);
}
// Up to $max photos from a field posted as name[idx][] (or the older name[idx]).
function upload_images($field, $idx, $max = 4){
  $f = $_FILES[$field] ?? null; if (!$f || !isset($f['tmp_name'][$idx])) return [];
  $tmps = (array)$f['tmp_name'][$idx]; $errs = (array)$f['error'][$idx]; $dir = dirname(__DIR__).'/uploads'; if (!is_dir($dir)) @mkdir($dir, 0755);
  $o = []; foreach ($tmps as $k => $tmp) { if (count($o) >= $max) break; if (($errs[$k] ?? 4) !== UPLOAD_ERR_OK || !$tmp || !is_uploaded_file($tmp)) continue; $n = save_photo($tmp, $dir); if ($n) $o[] = $n; }
  return $o;
}
// All photos for a set of run lines: id => [file, file...]
function run_photos($ids){
  $ids = array_values(array_filter(array_map('intval', (array)$ids))); if (!$ids) return [];
  $in = implode(',', $ids); $m = [];
  foreach (q("SELECT id,photo FROM checklist_run_items WHERE id IN ($in) AND photo IS NOT NULL")->fetchAll() as $r) $m[$r['id']][] = $r['photo'];
  foreach (q("SELECT run_item_id,filename FROM check_photos WHERE run_item_id IN ($in) ORDER BY id")->fetchAll() as $r) $m[$r['run_item_id']][] = $r['filename'];
  return $m;
}
function drop_upload($name){ if ($name) @unlink(dirname(__DIR__).'/uploads/'.basename($name)); }

// Where did a linked item go? Shown when a count comes up short.
function movement_hint($item_id){
  $item_id = (int)$item_id; if (!$item_id) return '';
  $parts = [];
  foreach (q("SELECT l.holder_name,n.name inc,l.issued_at,".OUT_EXPR." out_q,i.unit FROM issues l JOIN items i ON i.id=l.item_id LEFT JOIN incidents n ON n.id=l.incident_id WHERE l.item_id=? AND ".OUT_EXPR." > 0.0001 ORDER BY l.issued_at DESC LIMIT 3", [$item_id])->fetchAll() as $r)
    $parts[] = fmt_q($r['out_q']).' still out with '.$r['holder_name'].($r['inc'] ? ' ('.$r['inc'].')' : '').' since '.date('M j', strtotime($r['issued_at']));
  if (!$parts) foreach (q("SELECT type,qty,note,created_at FROM transactions WHERE item_id=? AND type IN ('out','use','issue','handoff','transfer','lost','damaged') ORDER BY id DESC LIMIT 2", [$item_id])->fetchAll() as $r)
    $parts[] = 'last '.$r['type'].' '.fmt_q($r['qty']).' on '.date('M j', strtotime($r['created_at'])).($r['note'] ? ' - '.mb_substr($r['note'], 0, 60) : '');
  return implode('; ', $parts);
}
// Remove a checklist with its photos (sections, lines and runs go with it by cascade / explicit delete).
function checklist_purge($cid){
  $cid = (int)$cid;
  foreach (q("SELECT ref_photo p FROM checklist_sections WHERE checklist_id=? AND ref_photo IS NOT NULL UNION SELECT ref_photo FROM checklist_items WHERE checklist_id=? AND ref_photo IS NOT NULL UNION SELECT cp.filename FROM check_photos cp JOIN checklist_run_items ri ON ri.id=cp.run_item_id JOIN checklist_runs r ON r.id=ri.run_id WHERE r.checklist_id=$cid UNION SELECT ri.photo FROM checklist_run_items ri JOIN checklist_runs r ON r.id=ri.run_id WHERE r.checklist_id=? AND ri.photo IS NOT NULL", [$cid, $cid, $cid])->fetchAll() as $r) drop_upload($r['p']);
  q("DELETE FROM checklists WHERE id=?", [$cid]);
}
