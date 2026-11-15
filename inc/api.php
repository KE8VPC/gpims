<?php // Integration API support: commit-ordered sequence numbers, change feed, central transaction writer.
function api_uuid(){ $b = random_bytes(16); $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); $b[8] = chr((ord($b[8]) & 0x3f) | 0x80); $h = bin2hex($b); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); }
// installation_id never changes; data_generation changes every time a backup is restored.
function api_ids(){
  foreach (['installation_id', 'data_generation'] as $k) if (setting($k, '') === '') { q("INSERT IGNORE INTO settings(k,v) VALUES(?,?)", [$k, api_uuid()]); $v = q("SELECT v FROM settings WHERE k=?", [$k])->fetchColumn(); $GLOBALS['SETTINGS'][$k] = $v; }
  return [setting('installation_id'), setting('data_generation')];
}
function api_new_generation(){ api_ids(); set_setting('data_generation', api_uuid()); }
// The counter row stays locked until the surrounding transaction commits, so sequence order equals commit order.
function api_seq_next(){ global $pdo; q("UPDATE api_seq SET v=LAST_INSERT_ID(v+1) WHERE id=1"); return (int)$pdo->lastInsertId(); }
function api_in_txn($fn){
  global $pdo; $own = !$pdo->inTransaction(); if ($own) $pdo->beginTransaction();
  try { $r = $fn(); if ($own && $pdo->inTransaction()) $pdo->commit(); return $r; }
  catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
// Record that an item's details changed (create, edit, archive, trash, restore, purge).
function api_change($item_id, $kind){
  try { api_in_txn(function() use ($item_id, $kind){ q("INSERT INTO api_changes(seq,item_id,kind) VALUES(?,?,?)", [api_seq_next(), (int)$item_id, $kind]); }); } catch (Throwable $e) { error_log('GPIMS api_change: '.$e->getMessage()); }
}
// The one place a stock/asset movement is written to the transactions table.
function tx_add($item_id, $user_id, $type, $qty, $balance, $note, $incident_id = null, $undo_of = null, $ref = null){
  global $pdo; $c = $GLOBALS['API_CTX'] ?? null;
  $note = mb_substr((string)$note, 0, 255);
  if ($c) { if ($ref === null) $ref = $c['ref']; if ($ref !== null && $ref !== '' && strpos($note, 'Ref: '.$ref) === false) $note = mb_substr('Ref: '.$ref.($note !== '' ? ' - '.$note : ''), 0, 255); }
  return api_in_txn(function() use ($pdo, $item_id, $user_id, $type, $qty, $balance, $note, $incident_id, $undo_of, $ref, $c){
    q("INSERT INTO transactions(item_id,user_id,type,qty,balance,note,incident_id,undo_of,ref,by_name,by_id,integration_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)",
      [$item_id, $user_id, $type, $qty, $balance, $note !== '' ? $note : null, $incident_id, $undo_of, ($ref === '' ? null : $ref), $c['by'] ?? null, $c['by_id'] ?? null, $c['integration_id'] ?? null]);
    $id = (int)$pdo->lastInsertId();
    q("UPDATE transactions SET seq=? WHERE id=?", [api_seq_next(), $id]);   // sequence taken after the item lock, before commit
    return $id;
  });
}
function api_housekeeping(){
  q("DELETE FROM api_idem WHERE created_at < (NOW() - INTERVAL 30 DAY)");
  q("DELETE FROM api_rate WHERE win < ?", [(int)(time() / 60) - 10]);
  $cut = q("SELECT MAX(seq) FROM api_changes WHERE at < (NOW() - INTERVAL 90 DAY)")->fetchColumn();
  if ($cut) { q("DELETE FROM api_changes WHERE seq <= ?", [$cut]); set_setting('api_changes_pruned_through', (string)max((int)setting('api_changes_pruned_through', 0), (int)$cut)); }
}
