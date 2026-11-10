<?php
function attention_count(){
  static $n = null;
  if ($n === null) {
    try {
      $w = "i.archived_at IS NULL AND i.status<>'retired' AND ".scope_sql('i');
      $low = (int)q("SELECT COUNT(*) FROM items i WHERE $w AND i.type<>'asset' AND i.min_qty>0 AND i.qty<=i.min_qty")->fetchColumn();
      $days = max(0, (int)setting('expiry_warn_days', 30));
      $exp = (int)q("SELECT COUNT(*) FROM item_lots lt JOIN items i ON i.id=lt.item_id WHERE $w AND lt.expires_on IS NOT NULL AND lt.expires_on <= DATE_ADD(CURDATE(), INTERVAL $days DAY)")->fetchColumn();
      $n = $low + $exp + count(checks_attention()) + count(loans_overdue()) + count(maint_attention()) + count(res_attention());
    } catch (Exception $e) { $n = 0; }
  }
  return $n;
}
function attention_items(){
  $w = "i.archived_at IS NULL AND i.status<>'retired' AND ".scope_sql('i'); $days = max(0, (int)setting('expiry_warn_days', 30));
  $low = q("SELECT i.*,c.name cat,l.name loc FROM items i LEFT JOIN categories c ON c.id=i.category_id LEFT JOIN locations l ON l.id=i.location_id WHERE $w AND i.type<>'asset' AND i.min_qty>0 AND i.qty<=i.min_qty ORDER BY (i.qty<=0) DESC, i.name")->fetchAll();
  foreach ($low as &$_i) $_i['cat'] = cat_path($_i['category_id']); unset($_i);
  $exp = q("SELECT lt.*,i.name item FROM item_lots lt JOIN items i ON i.id=lt.item_id WHERE $w AND lt.expires_on IS NOT NULL AND lt.expires_on <= DATE_ADD(CURDATE(), INTERVAL $days DAY) ORDER BY lt.expires_on")->fetchAll();
  return [$low, $exp];
}
function item_vendor_map($ids){
  if (!$ids) return [];
  $rows = q("SELECT iv.*,v.name vname,v.phone,v.email,v.contact_name FROM item_vendors iv JOIN vendors v ON v.id=iv.vendor_id WHERE v.active=1 AND iv.item_id IN (".implode(',', array_map('intval', $ids)).") ORDER BY iv.unavailable, iv.rank_no, iv.id")->fetchAll();
  $m = []; foreach ($rows as $r) $m[$r['item_id']][] = $r; return $m;
}
function open_notes_map($ids){
  if (!$ids) return [];
  $rows = q("SELECT n.*,u.name uname FROM alert_notes n LEFT JOIN users u ON u.id=n.user_id WHERE n.cleared_at IS NULL AND n.item_id IN (".implode(',', array_map('intval', $ids)).") ORDER BY n.id DESC")->fetchAll();
  $m = []; foreach ($rows as $r) $m[$r['item_id']][] = $r; return $m;
}
function reorder_hint($i){
  if (!empty($i['par_qty']) && $i['par_qty'] > $i['qty']) return $i['par_qty'] - $i['qty'];
  if (!empty($i['reorder_qty'])) return $i['reorder_qty'];
  return null;
}

// Assets still out past their due-back date (loans to other agencies, rentals, long-term assignments).
function loans_overdue(){
  static $c = null; if ($c !== null) return $c;
  try { $c = q("SELECT c.id,c.item_id,c.borrower,c.person_id,c.due_at,c.out_at,c.note,i.name item,p.phone,DATEDIFF(CURDATE(),c.due_at) late FROM checkouts c JOIN items i ON i.id=c.item_id LEFT JOIN people p ON p.id=c.person_id WHERE c.in_at IS NULL AND c.due_at IS NOT NULL AND c.due_at < CURDATE() AND i.archived_at IS NULL AND ".scope_sql('i')." ORDER BY c.due_at")->fetchAll(); }
  catch (Throwable $e) { $c = []; }
  return $c;
}
