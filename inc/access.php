<?php
const PERMS = [
  'items_add'=>'Add items', 'items_edit'=>'Edit items', 'items_archive'=>'Archive and restore items',
  'stock_move'=>'Add or use stock (consumables)', 'checkout'=>'Issue, hand off and return equipment', 'incidents_manage'=>'Create and close events / incidents',
  'photos_add'=>'Add photos', 'photos_delete'=>'Remove photos', 'labels_print'=>'Print labels',
  'checklists_run'=>'Run checklists', 'checklists_manage'=>'Create and edit checklists', 'vehicles_manage'=>'Add and edit agency vehicles',
  'categories_manage'=>'Manage categories and custom fields', 'locations_manage'=>'Manage locations', 'people_manage'=>'Manage the people list', 'vendors_manage'=>'Manage the vendors list', 'alerts_note'=>'Add notes to supply alerts', 'reports_view'=>'View reports', 'maintenance_manage'=>'Set up and complete maintenance tasks',
];
const ROLE_PERMS = [
  'user'=>['items_add','items_edit','stock_move','checkout','incidents_manage','photos_add','labels_print','checklists_run','alerts_note','reports_view','maintenance_manage'],
  'manager'=>['items_add','items_edit','stock_move','checkout','photos_add','labels_print','checklists_run','reports_view','maintenance_manage'],
  'viewer'=>['reports_view'], 'vendor'=>[],
];
const ROLE_LABELS = ['admin'=>'Admin','user'=>'User','viewer'=>'Viewer (read only)','vendor'=>'Vendor (supply view only)'];
function user_perms($u){
  if (!$u) return [];
  if ($u['role'] === 'admin') return array_keys(PERMS);
  if (isset($u['perms']) && $u['perms'] !== '') { $p = json_decode($u['perms'], true); if (is_array($p)) return $p; }
  return ROLE_PERMS[$u['role']] ?? [];
}
function can($p){ return in_array($p, user_perms(me()), true); }
function need_perm($p){
  if (!me()) { header('Location: login.php'); exit; }
  if (!can($p)) { http_response_code(403); exit('Access denied.'); }
}
function locations_all(){
  static $c = null;
  if ($c === null) { try { $c = q("SELECT * FROM locations ORDER BY name")->fetchAll(); } catch (Exception $e) { $c = []; } }
  return $c;
}
function loc_children_map(){ $m = []; foreach (locations_all() as $l) $m[(int)($l['parent_id'] ?? 0)][] = $l; return $m; }
function loc_descendants($id){
  $m = loc_children_map(); $out = [(int)$id]; $stack = [(int)$id];
  while ($stack) { $x = array_pop($stack); foreach ($m[$x] ?? [] as $c) { $cid = (int)$c['id']; if (!in_array($cid, $out, true)) { $out[] = $cid; $stack[] = $cid; } } }
  return $out;
}
function locations_tree_flat(){
  $m = loc_children_map(); $res = []; $seen = [];
  $walk = function($pid, $d) use (&$walk, &$res, &$seen, $m){
    foreach ($m[$pid] ?? [] as $l) { if (isset($seen[$l['id']])) continue; $seen[$l['id']] = 1; $res[] = ['id'=>$l['id'], 'name'=>$l['name'], 'depth'=>$d, 'row'=>$l]; $walk((int)$l['id'], $d + 1); }
  };
  $walk(0, 0);
  foreach (locations_all() as $l) if (!isset($seen[$l['id']])) $res[] = ['id'=>$l['id'], 'name'=>$l['name'], 'depth'=>0, 'row'=>$l];
  return $res;
}

// ---- Category tree (categories can have sub-categories, e.g. Communication / Radios / Portable) ----
function cats_all(){
  static $c = null;
  if ($c === null) { try { $c = q("SELECT * FROM categories ORDER BY name")->fetchAll(); } catch (Exception $e) { $c = []; } }
  return $c;
}
function cats_by_id(){ static $m = null; if ($m === null) { $m = []; foreach (cats_all() as $c) $m[(int)$c['id']] = $c; } return $m; }
function cat_children_map(){ $m = []; foreach (cats_all() as $c) $m[(int)($c['parent_id'] ?? 0)][] = $c; return $m; }
function cat_descendants($id){
  $m = cat_children_map(); $out = [(int)$id]; $stack = [(int)$id];
  while ($stack) { $x = array_pop($stack); foreach ($m[$x] ?? [] as $c) { $cid = (int)$c['id']; if (!in_array($cid, $out, true)) { $out[] = $cid; $stack[] = $cid; } } }
  return $out;
}
// this category and its parents, nearest first (used so a sub-category inherits its parents' custom fields)
function cat_lineage($id){
  $by = cats_by_id(); $out = []; $id = (int)$id;
  while ($id && isset($by[$id]) && !in_array($id, $out, true)) { $out[] = $id; $id = (int)($by[$id]['parent_id'] ?? 0); }
  return $out;
}
function cat_path($id){
  if (!$id) return ''; $by = cats_by_id(); $names = [];
  foreach (array_reverse(cat_lineage($id)) as $x) $names[] = $by[$x]['name'];
  return implode(' / ', $names);
}
function cats_tree_flat(){
  $m = cat_children_map(); $res = []; $seen = [];
  $walk = function($pid, $d) use (&$walk, &$res, &$seen, $m){
    foreach ($m[$pid] ?? [] as $c) { if (isset($seen[$c['id']])) continue; $seen[$c['id']] = 1; $res[] = ['id'=>(int)$c['id'], 'name'=>$c['name'], 'depth'=>$d, 'row'=>$c]; $walk((int)$c['id'], $d + 1); }
  };
  $walk(0, 0);
  foreach (cats_all() as $c) if (!isset($seen[$c['id']])) $res[] = ['id'=>(int)$c['id'], 'name'=>$c['name'], 'depth'=>0, 'row'=>$c];
  return $res;
}
// options for category drop-downs: id + full path, in tree order
function cats_select(){ $o = []; foreach (cats_tree_flat() as $c) $o[] = ['id'=>$c['id'], 'name'=>cat_path($c['id'])]; return $o; }
function scope_all(){ $u = me(); return !$u || $u['role'] === 'admin' || (int)($u['scope_all'] ?? 1) === 1; }
function scope_rules(){
  static $r = null;
  if ($r === null) $r = q("SELECT category_id,location_id FROM user_access WHERE user_id=?", [me()['id']])->fetchAll();
  return $r;
}
function scope_sql($a = 'i'){
  if (scope_all()) return '1=1';
  $parts = [];
  foreach (scope_rules() as $r) {
    $c = [];
    if ($r['category_id']) $c[] = "$a.category_id IN (".implode(',', cat_descendants($r['category_id'])).")";
    if ($r['location_id']) $c[] = "$a.location_id IN (".implode(',', loc_descendants($r['location_id'])).")";
    if ($c) $parts[] = '('.implode(' AND ', $c).')';
  }
  return $parts ? '('.implode(' OR ', $parts).')' : '0=1';
}
function item_in_scope($it){
  if (scope_all()) return true;
  foreach (scope_rules() as $r) {
    if (!$r['category_id'] && !$r['location_id']) continue;
    if ($r['category_id'] && !in_array((int)($it['category_id'] ?? 0), cat_descendants($r['category_id']), true)) continue;
    if ($r['location_id'] && !in_array((int)($it['location_id'] ?? 0), loc_descendants($r['location_id']), true)) continue;
    return true;
  }
  return false;
}
function resolve_borrower(){
  $b = trim($_POST['borrower'] ?? ''); $pid = (int)($_POST['person_id'] ?? 0);
  if ($pid) {
    $p = q("SELECT * FROM people WHERE id=?", [$pid])->fetch();
    if (!$p) return [null, null, 'Person not found.'];
    if (!$p['active']) return [null, null, $p['name'].' is inactive and cannot be issued equipment.'];
    return [$p['name'], $pid, null];
  }
  if ($b === '') return [null, null, 'Enter who is taking it.'];
  $p = q("SELECT * FROM people WHERE LOWER(name)=LOWER(?) LIMIT 1", [$b])->fetch();
  if ($p) { if (!$p['active']) return [null, null, $p['name'].' is inactive and cannot be issued equipment.']; return [$p['name'], (int)$p['id'], null]; }
  return [$b, null, null];
}
