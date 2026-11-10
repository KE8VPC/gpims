<?php
// Soft delete (trash) and undo of recent stock changes.
const TRASH_DEFAULT_DAYS = 30;
const UNDO_WINDOW_DAYS = 7;
function trash_days(){ $d = (int)setting('trash_days', TRASH_DEFAULT_DAYS); return $d < 1 ? TRASH_DEFAULT_DAYS : min(365, $d); }
function item_has_stuff_out($it){
  return $it['status'] === 'checked_out' || (int)q("SELECT COUNT(*) FROM issues l WHERE l.item_id=? AND l.closed_at IS NULL AND ".OUT_EXPR." > 0.0001", [$it['id']])->fetchColumn() > 0;
}
// Move an item to the trash. It stays hidden everywhere (the same way archived items are) until restored or purged.
function item_trash($it){
  if (item_has_stuff_out($it)) return [false, 'Return everything that is out before deleting this item.'];
  q("UPDATE items SET archived_at=COALESCE(archived_at,NOW()), deleted_at=NOW(), deleted_by=? WHERE id=?", [me()['id'] ?? null, $it['id']]);
  log_item_event($it['id'], 'deleted'); audit('item_trash', "#{$it['id']} {$it['name']}");
  return [true, 'Moved to the trash. You can restore it for '.trash_days().' days.'];
}
function item_untrash($it){
  q("UPDATE items SET archived_at=NULL, deleted_at=NULL, deleted_by=NULL WHERE id=?", [$it['id']]);
  log_item_event($it['id'], 'restored'); audit('item_untrash', "#{$it['id']} {$it['name']}");
}
// Permanent removal (photos, documents and everything hanging off the item go too).
function item_purge($it){
  $id = (int)$it['id'];
  foreach (q("SELECT filename FROM item_photos WHERE item_id=?", [$id])->fetchAll() as $p) drop_upload($p['filename']);
  docs_purge($id, null); q("UPDATE checklist_items SET item_id=NULL WHERE item_id=?", [$id]); q("UPDATE vehicles SET item_id=NULL WHERE item_id=?", [$id]);
  q("DELETE FROM items WHERE id=?", [$id]); audit('item_delete', "#$id {$it['name']}");
}
function trash_purge_old(){
  $n = 0;
  foreach (q("SELECT * FROM items WHERE deleted_at IS NOT NULL AND deleted_at < (NOW() - INTERVAL ".trash_days()." DAY)")->fetchAll() as $it) { try { item_purge($it); $n++; } catch (Throwable $e) {} }
  return $n;
}

// Can this stock transaction be undone, and by this user? Returns '' when yes, otherwise the reason it cannot.
function tx_undo_block($t, $it){
  if (!in_array($t['type'], ['in', 'out', 'adjust'], true) || $t['undo_of'] || $t['undone_at']) return 'Only a stock add, use or count change can be undone.';
  if ($it['type'] === 'asset' || $it['archived_at']) return 'This item cannot be changed right now.';
  if (strtotime($t['created_at']) < time() - UNDO_WINDOW_DAYS * 86400) return 'Too old to undo ('.UNDO_WINDOW_DAYS.' days).';
  if (!(level() >= ROLES['admin'] || ((int)$t['user_id'] === (int)(me()['id'] ?? 0) && can('stock_move')))) return 'You can only undo your own changes.';
  $last = q("SELECT id FROM transactions WHERE item_id=? AND balance IS NOT NULL ORDER BY id DESC LIMIT 1", [$t['item_id']])->fetchColumn();
  if ((int)$last !== (int)$t['id'] || abs((float)$it['qty'] - (float)$t['balance']) > 0.0001) return 'Something else has changed this item since, so undo the newer change first.';
  if ($t['type'] === 'adjust' && !q("SELECT 1 FROM transactions WHERE item_id=? AND id<? AND balance IS NOT NULL LIMIT 1", [$t['item_id'], $t['id']])->fetch()) return 'There is no earlier quantity to go back to.';
  return '';
}
function tx_undo($tid){
  $t = q("SELECT * FROM transactions WHERE id=?", [(int)$tid])->fetch(); if (!$t) return [false, 'That entry no longer exists.'];
  $it = q("SELECT * FROM items WHERE id=?", [$t['item_id']])->fetch(); if (!$it || !item_in_scope($it)) return [false, 'Item not found.'];
  if ($why = tx_undo_block($t, $it)) return [false, $why];
  $cur = (float)$it['qty']; $n = (float)$t['qty'];
  if ($t['type'] === 'in') $new = $cur - $n;
  elseif ($t['type'] === 'out') $new = $cur + $n;
  else $new = (float)q("SELECT balance FROM transactions WHERE item_id=? AND id<? AND balance IS NOT NULL ORDER BY id DESC LIMIT 1", [$t['item_id'], $t['id']])->fetchColumn();
  if ($new < 0) return [false, 'Undoing this would take the stock below zero.'];
  q("UPDATE items SET qty=? WHERE id=?", [$new, $it['id']]);
  if ($it['min_qty'] > 0 && $new > $it['min_qty']) q("UPDATE alert_notes SET cleared_at=NOW() WHERE item_id=? AND cleared_at IS NULL", [$it['id']]);
  q("INSERT INTO transactions(item_id,user_id,type,qty,balance,note,undo_of) VALUES(?,?,?,?,?,?,?)", [$it['id'], me()['id'] ?? null, 'undo', abs($new - $cur), $new, 'Undo of #'.$t['id'].' ('.$t['type'].' '.fmt_q($t['qty']).')', $t['id']]);
  q("UPDATE transactions SET undone_at=NOW() WHERE id=?", [$t['id']]);
  audit('stock_undo', "#{$it['id']} {$it['name']} tx{$t['id']}");
  return [true, 'Undone. '.$it['name'].' is back to '.fmt_q($new).'.'];
}
// Recent changes the signed-in user may undo (newest first).
function undoable_recent($limit = 25){
  $rows = q("SELECT t.*,i.name item,i.qty cur_qty,i.type itype,i.archived_at,i.category_id,i.location_id,u.name uname FROM transactions t JOIN items i ON i.id=t.item_id LEFT JOIN users u ON u.id=t.user_id WHERE t.type IN ('in','out','adjust') AND t.undone_at IS NULL AND t.undo_of IS NULL AND t.created_at > (NOW() - INTERVAL ".UNDO_WINDOW_DAYS." DAY) AND ".scope_sql('i')." ORDER BY t.id DESC LIMIT 200")->fetchAll();
  $o = [];
  foreach ($rows as $t) { $it = ['qty' => $t['cur_qty'], 'type' => $t['itype'], 'archived_at' => $t['archived_at']]; if (!tx_undo_block($t, $it)) { $o[] = $t; if (count($o) >= $limit) break; } }
  return $o;
}
