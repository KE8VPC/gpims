<?php // GPIMS integration API v1. See the contract in the project docs. JSON only, token auth, no sessions.
define('GP_API', 1);
require __DIR__.'/../inc/bootstrap.php';
set_exception_handler(function($e){ error_log('GPIMS api: '.get_class($e).': '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine()); if ($GLOBALS['pdo']->inTransaction()) { try { $GLOBALS['pdo']->rollBack(); } catch (Throwable $x) {} } api_fail(500, 'server_error', 'The server could not complete the request.'); });
class ApiStop extends Exception { public $code2; public $http; public $extra; function __construct($http, $code, $msg, $extra = []) { parent::__construct($msg); $this->http = $http; $this->code2 = $code; $this->extra = $extra; } }
function api_send($http, $data, $headers = []){ while (ob_get_level()) ob_end_clean(); http_response_code($http); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); foreach ($headers as $k => $v) header("$k: $v"); echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION); exit; }
function api_fail($http, $code, $msg, $extra = [], $headers = []){ api_send($http, ['error' => array_merge(['code' => $code, 'message' => $msg], $extra)], $headers); }
function d2($v){ return number_format((float)$v, 2, '.', ''); }
function iso($dt){ return $dt ? date('c', strtotime($dt)) : null; }

// ---------- route + transport checks
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ''; $pos = strpos($path, '/v1/');
$route = trim($pos === false ? '' : substr($path, $pos + 4), '/'); $method = $_SERVER['REQUEST_METHOD'];
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$hostname = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
if (!$https && !in_array($hostname, ['localhost', '127.0.0.1', '[::1]'], true) && setting('api_allow_http', '0') !== '1') api_fail(403, 'https_required', 'The API only works over HTTPS.');
if ($route === '') api_fail(404, 'not_found', 'Unknown endpoint.');
if ($db_outdated) api_fail(503, 'upgrade_required', 'GPIMS needs its database upgrade first.');

// ---------- authentication
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$tok = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : ($_SERVER['HTTP_X_API_TOKEN'] ?? '');
if ((int)q("SELECT COUNT(*) FROM login_attempts WHERE username='api' AND ip=? AND created_at > (NOW() - INTERVAL 15 MINUTE)", [$ip])->fetchColumn() >= 20) api_fail(429, 'too_many_failures', 'Too many failed attempts. Try again later.', [], ['Retry-After' => '900']);
$row = $tok !== '' ? q("SELECT t.id tid,i.* FROM api_tokens t JOIN api_integrations i ON i.id=t.integration_id WHERE t.token_hash=? AND t.revoked_at IS NULL AND i.active=1", [hash('sha256', $tok)])->fetch() : false;
if (!$row) { q("INSERT INTO login_attempts(username,ip) VALUES('api',?)", [$ip]); api_fail(401, 'unauthorized', 'Missing, invalid or revoked token.'); }
$INT = ['id' => (int)$row['id'], 'name' => $row['name'], 'scopes' => array_filter(explode(',', $row['scopes']))];
q("UPDATE api_tokens SET last_used_at=NOW() WHERE id=?", [$row['tid']]); q("UPDATE api_integrations SET last_used_at=NOW() WHERE id=?", [$INT['id']]);
$win = (int)(time() / 60); q("INSERT INTO api_rate(integration_id,win,n) VALUES(?,?,1) ON DUPLICATE KEY UPDATE n=n+1", [$INT['id'], $win]);
if ((int)q("SELECT n FROM api_rate WHERE integration_id=? AND win=?", [$INT['id'], $win])->fetchColumn() > 120) api_fail(429, 'rate_limited', 'Too many requests. Slow down.', [], ['Retry-After' => (string)(60 - time() % 60)]);
function has($s){ global $INT; return in_array($s, $INT['scopes'], true); }
function need_scope($s){ if (!has($s)) api_fail(403, 'forbidden', "This token does not have the '$s' permission."); }
$names = has('read_people') && has('read');   // holder names are only for integrations that were given read_people

// ---------- shared builders
function api_rev($item_id){ return (int)q("SELECT COALESCE(MAX(seq),0) FROM transactions WHERE item_id=?", [$item_id])->fetchColumn(); }
function api_ver($item_id){ return (int)q("SELECT COALESCE(MAX(seq),0) FROM api_changes WHERE item_id=?", [$item_id])->fetchColumn(); }
function api_item($r, $detail = false){
  global $names;
  $codes = []; foreach ([$r['barcode'], $r['sku'], $r['serial']] as $c) if ($c !== null && $c !== '') $codes[$c] = 1;
  foreach (q("SELECT code FROM item_codes WHERE item_id=?", [$r['id']])->fetchAll(PDO::FETCH_COLUMN) as $c) $codes[$c] = 1;
  $o = ['id' => (int)$r['id'], 'name' => $r['name'], 'type' => $r['type'], 'description' => $r['description'], 'qty' => d2($r['qty']), 'unit' => $r['unit'], 'min_qty' => d2($r['min_qty']), 'par_qty' => $r['par_qty'] === null ? null : d2($r['par_qty']),
    'status' => $r['status'], 'category_id' => $r['category_id'] === null ? null : (int)$r['category_id'], 'category' => !empty($r['category_id']) ? cat_path($r['category_id']) : null,
    'location_id' => $r['location_id'] === null ? null : (int)$r['location_id'], 'location' => !empty($r['location_id']) ? q("SELECT name FROM locations WHERE id=?", [$r['location_id']])->fetchColumn() : null,
    'barcode' => $r['barcode'], 'sku' => $r['sku'], 'serial' => $r['serial'], 'codes' => array_map('strval', array_keys($codes)), 'archived' => $r['archived_at'] !== null,
    'rev' => api_rev($r['id']), 'version' => api_ver($r['id']), 'updated_at' => iso($r['updated_at'])];
  if ($detail) {
    $o['lots'] = array_map(function($l){ return ['id' => (int)$l['id'], 'lot_no' => $l['lot_no'], 'qty' => $l['qty'] === null ? null : d2($l['qty']), 'expires_on' => $l['expires_on']]; }, q("SELECT * FROM item_lots WHERE item_id=? ORDER BY expires_on", [$r['id']])->fetchAll());
    $co = q("SELECT * FROM checkouts WHERE item_id=? AND in_at IS NULL ORDER BY id DESC LIMIT 1", [$r['id']])->fetch();
    $o['open_checkout'] = $co ? array_merge(['checkout_id' => (int)$co['id'], 'person_id' => $co['person_id'] === null ? null : (int)$co['person_id'], 'out_at' => iso($co['out_at']), 'due_at' => $co['due_at']], $names ? ['person_name' => $co['borrower']] : []) : null;
  }
  return $o;
}
function api_page($sql, $params, $cursorField, $limit, $map){
  $rows = q($sql.' LIMIT '.($limit + 1), $params)->fetchAll(); $more = count($rows) > $limit; if ($more) array_pop($rows);
  $out = array_map($map, $rows); $last = $rows ? (int)end($rows)[$cursorField] : null;
  return ['rows' => $out, 'next_cursor' => $last, 'has_more' => $more];
}
function api_limit($def, $max){ $l = (int)($_GET['limit'] ?? $def); return max(1, min($max, $l ?: $def)); }
function api_cursor_check($after){
  $max = (int)q("SELECT v FROM api_seq WHERE id=1")->fetchColumn();
  if ($after > $max) api_fail(410, 'cursor_ahead', 'This cursor is newer than anything on the server. The GPIMS data may have been restored from a backup; run a full reconcile.', ['current_max_seq' => $max]);
}
function api_item_get($id, $lock = false){ return q("SELECT * FROM items WHERE id=? AND deleted_at IS NULL".($lock ? ' FOR UPDATE' : ''), [(int)$id])->fetch(); }

// ---------- GET endpoints
if ($method === 'GET') {
  need_scope('read'); $seg = explode('/', $route);
  if ($route === 'meta') {
    [$inst, $gen] = api_ids();
    api_send(200, ['api_version' => '1.4', 'installation_id' => $inst, 'data_generation' => $gen, 'integration_id' => $INT['id'], 'integration_name' => $INT['name'], 'scopes' => array_values($INT['scopes']), 'server_time' => date('c'),
      'safe_tx_seq' => (int)q("SELECT COALESCE(MAX(seq),0) FROM transactions")->fetchColumn(), 'safe_change_seq' => (int)q("SELECT COALESCE(MAX(seq),0) FROM api_changes")->fetchColumn(),
      'retention_days' => ['item_changes' => 90, 'idempotency_keys' => 30], 'app_version' => APP_VERSION]);
  }
  if ($route === 'items') {
    $w = ['i.deleted_at IS NULL', 'i.id > ?']; $p = [max(0, (int)($_GET['after_id'] ?? 0))];
    if (!empty($_GET['type']) && in_array($_GET['type'], ['consumable', 'returnable', 'asset', 'fixed'], true)) { $w[] = 'i.type=?'; $p[] = $_GET['type']; }
    if (trim($_GET['q'] ?? '') !== '') { $w[] = 'i.name LIKE ?'; $p[] = '%'.trim($_GET['q']).'%'; }
    api_send(200, api_page('SELECT i.* FROM items i WHERE '.implode(' AND ', $w).' ORDER BY i.id', $p, 'id', api_limit(100, 200), function($r){ return api_item($r); }));
  }
  if ($route === 'items/changes') {
    $after = max(0, (int)($_GET['after'] ?? 0)); api_cursor_check($after);
    if ($after < (int)setting('api_changes_pruned_through', 0)) api_fail(410, 'cursor_expired', 'This cursor is older than the retained history. Run a full reconcile.');
    api_send(200, api_page('SELECT * FROM api_changes WHERE seq > ? ORDER BY seq', [$after], 'seq', api_limit(200, 500), function($r){ return ['seq' => (int)$r['seq'], 'item_id' => (int)$r['item_id'], 'kind' => $r['kind'], 'at' => iso($r['at'])]; }));
  }
  if (count($seg) === 3 && $seg[0] === 'items' && $seg[1] === 'by-code') { $c = rawurldecode($seg[2]); $cand = code_candidates($c); $ph = implode(',', array_fill(0, count($cand), '?'));
    $r = q("SELECT i.* FROM items i WHERE i.deleted_at IS NULL AND (i.barcode IN ($ph) OR i.sku IN ($ph) OR i.serial IN ($ph) OR EXISTS(SELECT 1 FROM item_codes c WHERE c.item_id=i.id AND c.code IN ($ph))) LIMIT 1", array_merge($cand, $cand, $cand, $cand))->fetch();
    if (!$r) api_fail(404, 'not_found', 'No item has that code.'); api_send(200, ['item' => api_item($r, true)]); }
  if (count($seg) === 2 && $seg[0] === 'items' && ctype_digit($seg[1])) { $r = api_item_get($seg[1]); if (!$r) api_fail(404, 'not_found', 'Item not found.'); api_send(200, ['item' => api_item($r, true)]); }
  if ($route === 'transactions') {
    $after = max(0, (int)($_GET['after'] ?? 0)); api_cursor_check($after); $w = 't.seq > ?'; $p = [$after];
    if (isset($_GET['ref'])) { $ref = trim((string)$_GET['ref']); $w .= ' AND t.ref = ? AND BINARY t.ref = BINARY ?'; $p[] = $ref; $p[] = $ref; }
    api_send(200, api_page("SELECT t.*,u.name uname,u.username uuser,g.name gname FROM transactions t LEFT JOIN users u ON u.id=t.user_id LEFT JOIN api_integrations g ON g.id=t.integration_id WHERE $w ORDER BY t.seq", $p, 'seq', api_limit(200, 500), function($t) use ($names){
      $plain = in_array($t['type'], ['in', 'out', 'adjust', 'recount', 'undo', 'initial', 'status'], true);   // other notes name the person who held the item
      return ['seq' => (int)$t['seq'], 'id' => (int)$t['id'], 'item_id' => (int)$t['item_id'], 'type' => $t['type'], 'qty' => d2($t['qty']), 'balance' => $t['balance'] === null ? null : d2($t['balance']),
        'note' => ($plain || $names) ? $t['note'] : null, 'ref' => $t['ref'], 'by' => $t['by_name'] ?? ($t['uname'] ?: $t['uuser']), 'by_id' => $t['by_id'] ?? ($t['user_id'] ? (string)$t['user_id'] : null),
        'source' => $t['integration_id'] ? 'integration' : 'user', 'integration' => $t['gname'], 'undo_of' => $t['undo_of'] === null ? null : (int)$t['undo_of'], 'at' => iso($t['created_at'])]; }));
  }
  if ($route === 'alerts') {
    $w = "i.archived_at IS NULL AND i.deleted_at IS NULL AND i.status<>'retired'"; $days = max(0, (int)setting('expiry_warn_days', 30));
    $low = array_map(function($r){ return ['item_id' => (int)$r['id'], 'name' => $r['name'], 'qty' => d2($r['qty']), 'min_qty' => d2($r['min_qty']), 'unit' => $r['unit']]; }, q("SELECT i.* FROM items i WHERE $w AND i.type<>'asset' AND i.min_qty>0 AND i.qty<=i.min_qty ORDER BY i.name")->fetchAll());
    $lots = q("SELECT l.*,i.name,i.unit FROM item_lots l JOIN items i ON i.id=l.item_id WHERE $w AND l.expires_on IS NOT NULL AND l.expires_on <= DATE_ADD(CURDATE(), INTERVAL ? DAY) ORDER BY l.expires_on", [$days])->fetchAll();
    $mk = function($l){ return ['item_id' => (int)$l['item_id'], 'name' => $l['name'], 'lot_id' => (int)$l['id'], 'lot_no' => $l['lot_no'], 'qty' => $l['qty'] === null ? null : d2($l['qty']), 'expires_on' => $l['expires_on'], 'days_left' => (int)floor((strtotime($l['expires_on']) - strtotime(date('Y-m-d'))) / 86400)]; };
    $exp = []; $soon = []; foreach ($lots as $l) { $x = $mk($l); if ($x['days_left'] < 0) $exp[] = $x; else $soon[] = $x; }
    api_send(200, ['low_stock' => $low, 'expiring' => $soon, 'expired' => $exp, 'warn_days' => $days]);
  }
  if ($route === 'checkouts') {
    $rows = [];
    foreach (q("SELECT c.*,i.name iname FROM checkouts c JOIN items i ON i.id=c.item_id WHERE c.in_at IS NULL AND i.deleted_at IS NULL ORDER BY c.id")->fetchAll() as $c)
      $rows[] = array_merge(['kind' => 'asset', 'item_id' => (int)$c['item_id'], 'item_name' => $c['iname'], 'checkout_id' => (int)$c['id'], 'issue_id' => null, 'person_id' => $c['person_id'] === null ? null : (int)$c['person_id'], 'qty_open' => '1.00', 'out_at' => iso($c['out_at']), 'due_at' => $c['due_at'], 'overdue' => $c['due_at'] !== null && $c['due_at'] < date('Y-m-d')], $names ? ['person_name' => $c['borrower']] : []);
    foreach (q("SELECT l.*,i.name iname FROM issues l JOIN items i ON i.id=l.item_id WHERE l.closed_at IS NULL AND i.deleted_at IS NULL ORDER BY l.id")->fetchAll() as $l) { $open = (float)$l['qty_out'] - (float)$l['qty_back'] - (float)$l['qty_gone'] - (float)$l['qty_moved']; if ($open <= 0.0001) continue;
      $rows[] = array_merge(['kind' => 'issue', 'item_id' => (int)$l['item_id'], 'item_name' => $l['iname'], 'checkout_id' => null, 'issue_id' => (int)$l['id'], 'person_id' => $l['person_id'] === null ? null : (int)$l['person_id'], 'qty_open' => d2($open), 'out_at' => iso($l['issued_at']), 'due_at' => null, 'overdue' => false], $names ? ['person_name' => $l['holder_name']] : []); }
    api_send(200, ['rows' => $rows]);
  }
  if ($route === 'people') { if (!$names) api_fail(403, 'forbidden', "Listing people needs both the 'read' and 'read_people' permissions."); api_send(200, ['rows' => array_map(function($p){ return ['id' => (int)$p['id'], 'name' => $p['name'], 'department' => $p['department']]; }, q("SELECT id,name,department FROM people WHERE active=1 ORDER BY name")->fetchAll())]); }
  if ($route === 'locations') api_send(200, ['rows' => array_map(function($r){ return ['id' => (int)$r['id'], 'name' => $r['name'], 'parent_id' => $r['parent_id'] === null ? null : (int)$r['parent_id']]; }, q("SELECT id,name,parent_id FROM locations ORDER BY name")->fetchAll())]);
  if ($route === 'categories') api_send(200, ['rows' => array_map(function($r){ return ['id' => (int)$r['id'], 'name' => $r['name'], 'parent_id' => $r['parent_id'] === null ? null : (int)$r['parent_id']]; }, q("SELECT id,name,parent_id FROM categories ORDER BY name")->fetchAll())]);
  api_fail(404, 'not_found', 'Unknown endpoint.');
}

// ---------- write endpoints
if ($method !== 'POST') api_fail(405, 'method_not_allowed', 'Use GET or POST.', [], ['Allow' => 'GET, POST']);
need_scope('write');
$ikey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
if (!preg_match('/^[A-Za-z0-9._:-]{8,80}$/', $ikey)) api_fail(400, 'idempotency_key_required', 'Send an Idempotency-Key header of 8-80 characters (letters, digits . _ : -).');
$raw = file_get_contents('php://input'); $body = $raw === '' ? [] : json_decode($raw, true);
if (!is_array($body)) api_fail(400, 'invalid_json', 'The request body must be a JSON object.');
function canon($v){ if (is_array($v)) { ksort($v); return array_map('canon', $v); } return $v; }
$reqHash = hash('sha256', $method.' '.$route."\n".json_encode(canon($body), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

function body_str($k, $max, $req = false){ global $body; $v = $body[$k] ?? null; if ($v === null || $v === '') { if ($req) throw new ApiStop(422, 'invalid_field', "'$k' is required."); return null; } if (!is_scalar($v)) throw new ApiStop(422, 'invalid_field', "'$k' must be text."); $v = trim((string)$v); if (mb_strlen($v) > $max) throw new ApiStop(422, 'invalid_field', "'$k' is longer than $max characters."); return $v; }
function body_qty($k, $allowZero = false){ global $body; if (!isset($body[$k]) || !is_numeric($body[$k])) throw new ApiStop(422, 'invalid_qty', "'$k' must be a number."); $n = (float)$body[$k]; if (abs(round($n, 2) - $n) > 1e-9) throw new ApiStop(422, 'invalid_qty', "'$k' can have at most 2 decimal places."); if ($n < 0 || (!$allowZero && $n == 0) || $n > 9999999999) throw new ApiStop(422, 'invalid_qty', "'$k' is out of range."); return round($n, 2); }
function check_rev($it){ global $body; if (isset($body['expected_rev']) && $body['expected_rev'] !== null && (int)$body['expected_rev'] !== api_rev($it['id'])) throw new ApiStop(409, 'stale_stock', 'The item has changed since you last read it.', ['item' => api_item(api_item_get($it['id']), true)]); }
function the_item($id, $types = null){ $it = api_item_get($id, true); if (!$it) throw new ApiStop(404, 'not_found', 'Item not found.'); if ($it['archived_at'] !== null) throw new ApiStop(409, 'item_archived', 'The item is archived.'); if ($types && !in_array($it['type'], $types, true)) throw new ApiStop(422, 'wrong_type', 'This action is not available for a '.$it['type'].' item.'); return $it; }
function tx_result($id, $itemId, $extra = []){ $t = q("SELECT id,seq FROM transactions WHERE item_id=? ORDER BY seq DESC LIMIT 1", [$itemId])->fetch(); return array_merge(['transaction_id' => (int)$t['id'], 'seq' => (int)$t['seq'], 'item' => api_item(api_item_get($itemId), true)], $extra); }
function the_person(){ global $body; if (!isset($body['person_id']) || !is_numeric($body['person_id'])) throw new ApiStop(422, 'unknown_person', "'person_id' is required (see GET people)."); $p = q("SELECT * FROM people WHERE id=?", [(int)$body['person_id']])->fetch(); if (!$p || !$p['active']) throw new ApiStop(422, 'unknown_person', 'That person does not exist or is inactive.'); return $p; }

$seg = explode('/', $route);
$handler = null;
if (count($seg) === 3 && $seg[0] === 'items' && ctype_digit($seg[1])) {
  $iid = (int)$seg[1]; $act = $seg[2];
  if ($act === 'use') $handler = function() use ($iid){ $it = the_item($iid, ['consumable']); check_rev($it); $n = body_qty('qty'); $new = round((float)$it['qty'] - $n, 2); if ($new < 0) throw new ApiStop(409, 'insufficient_stock', 'Only '.fmt_q($it['qty']).' on hand.', ['item' => api_item($it)]);
    q("UPDATE items SET qty=? WHERE id=?", [$new, $iid]); if ($it['min_qty'] > 0 && $new > $it['min_qty']) q("UPDATE alert_notes SET cleared_at=NOW() WHERE item_id=? AND cleared_at IS NULL", [$iid]);
    tx_add($iid, null, 'out', $n, $new, $GLOBALS['API_CTX']['note']); try { notify_stock($it, $it['qty'], $new); } catch (Throwable $e) {} audit('stock_out', "#$iid $n"); return [200, tx_result(0, $iid)]; };
  elseif ($act === 'restock') $handler = function() use ($iid){ $it = the_item($iid, ['consumable', 'returnable', 'fixed']); check_rev($it); $n = body_qty('qty'); $new = round((float)$it['qty'] + $n, 2);
    $exp = body_str('lot_expiry', 10); if ($exp !== null && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) || !checkdate((int)substr($exp, 5, 2), (int)substr($exp, 8, 2), (int)substr($exp, 0, 4)))) throw new ApiStop(422, 'invalid_field', "'lot_expiry' must be a date like 2027-03-31.");
    $lotNo = body_str('lot_no', 60);
    q("UPDATE items SET qty=? WHERE id=?", [$new, $iid]); if ($it['min_qty'] > 0 && $new > $it['min_qty']) q("UPDATE alert_notes SET cleared_at=NOW() WHERE item_id=? AND cleared_at IS NULL", [$iid]);
    if ($exp !== null) { q("INSERT INTO item_lots(item_id,lot_no,qty,expires_on,note) VALUES(?,?,?,?,?)", [$iid, $lotNo, $n, $exp, 'Added by '.$GLOBALS['API_CTX']['name']]); api_change($iid, 'updated'); }
    tx_add($iid, null, 'in', $n, $new, $GLOBALS['API_CTX']['note']); try { notify_stock($it, $it['qty'], $new); } catch (Throwable $e) {} audit('stock_in', "#$iid $n"); return [200, tx_result(0, $iid)]; };
  elseif ($act === 'adjust') $handler = function() use ($iid){ global $body; $it = the_item($iid, ['consumable', 'returnable', 'fixed']); if (!isset($body['expected_rev'])) throw new ApiStop(422, 'expected_rev_required', "'expected_rev' is required for a count."); check_rev($it); $n = body_qty('counted_qty', true);
    q("UPDATE items SET qty=? WHERE id=?", [$n, $iid]); if ($it['min_qty'] > 0 && $n > $it['min_qty']) q("UPDATE alert_notes SET cleared_at=NOW() WHERE item_id=? AND cleared_at IS NULL", [$iid]);
    tx_add($iid, null, 'adjust', $n, $n, $GLOBALS['API_CTX']['note']); try { notify_stock($it, $it['qty'], $n); } catch (Throwable $e) {} audit('stock_set', "#$iid $n"); return [200, tx_result(0, $iid)]; };
  elseif ($act === 'checkout') $handler = function() use ($iid){ global $body; $it = the_item($iid, ['asset', 'returnable']); check_rev($it); $p = the_person(); $due = body_str('due', 10);
    if ($due !== null && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) || !checkdate((int)substr($due, 5, 2), (int)substr($due, 8, 2), (int)substr($due, 0, 4)))) throw new ApiStop(422, 'invalid_field', "'due' must be a date like 2027-03-31.");
    $note = $GLOBALS['API_CTX']['note'];
    if ($it['type'] === 'asset') { if ($it['status'] !== 'available') throw new ApiStop(409, 'not_available', 'The item is not available (status: '.$it['status'].').');
      q("INSERT INTO checkouts(item_id,borrower,person_id,user_id,due_at,note) VALUES(?,?,?,?,?,?)", [$iid, $p['name'], $p['id'], null, $due, $note]); q("UPDATE items SET status='checked_out' WHERE id=?", [$iid]);
      tx_add($iid, null, 'checkout', 1, null, 'To '.$p['name'].($note ? ' - '.$note : '')); audit('checkout', "#$iid to {$p['name']}"); return [200, tx_result(0, $iid, ['checkout_id' => (int)q("SELECT id FROM checkouts WHERE item_id=? ORDER BY id DESC LIMIT 1", [$iid])->fetchColumn()])]; }
    $n = body_qty('qty'); [$ok, $msg] = do_issue($it, $n, $p['name'], (int)$p['id'], null, $note); if (!$ok) throw new ApiStop(409, 'insufficient_stock', $msg, ['item' => api_item(api_item_get($iid))]);
    return [200, tx_result(0, $iid, ['issue_id' => (int)q("SELECT id FROM issues WHERE item_id=? ORDER BY id DESC LIMIT 1", [$iid])->fetchColumn()])]; };
  elseif ($act === 'return') $handler = function() use ($iid){ global $body; $it = the_item($iid, ['asset', 'returnable']); check_rev($it); $note = $GLOBALS['API_CTX']['note']; $by = $GLOBALS['API_CTX']['by'] ?: $GLOBALS['API_CTX']['name']; $out = body_str('outcome', 20) ?: 'good';
    if ($it['type'] === 'asset') { if (!isset(ASSET_OUTCOMES[$out])) throw new ApiStop(422, 'invalid_field', "'outcome' must be one of: ".implode(', ', array_keys(ASSET_OUTCOMES)).'.'); [$ok, $msg] = asset_return($iid, $out, $note, $by, ''); if (!$ok) throw new ApiStop(409, 'not_checked_out', $msg); return [200, tx_result(0, $iid)]; }
    if (!isset(LINE_OUTCOMES[$out])) throw new ApiStop(422, 'invalid_field', "'outcome' must be one of: ".implode(', ', array_keys(LINE_OUTCOMES)).'.');
    $lid = (int)($body['issue_id'] ?? 0); if (!$lid || (int)q("SELECT item_id FROM issues WHERE id=?", [$lid])->fetchColumn() !== $iid) throw new ApiStop(422, 'invalid_field', "'issue_id' must be an open issue line of this item (see GET checkouts).");
    [$ok, $msg] = do_return_line($lid, body_qty('qty'), $out, $by, '', $note); if (!$ok) throw new ApiStop(409, 'return_rejected', $msg); return [200, tx_result(0, $iid)]; };
} elseif (count($seg) === 3 && $seg[0] === 'transactions' && ctype_digit($seg[1]) && $seg[2] === 'undo') {
  $tid = (int)$seg[1];
  $handler = function() use ($tid){ $t = q("SELECT * FROM transactions WHERE id=? FOR UPDATE", [$tid])->fetch(); if (!$t) throw new ApiStop(404, 'not_found', 'Transaction not found.');
    if ((int)$t['integration_id'] !== (int)$GLOBALS['API_CTX']['integration_id']) throw new ApiStop(403, 'forbidden', 'An integration can only undo changes it made itself.');
    q("SELECT id FROM items WHERE id=? FOR UPDATE", [$t['item_id']]); [$ok, $msg] = tx_undo($tid); if (!$ok) throw new ApiStop(409, 'undo_not_allowed', $msg); return [200, tx_result(0, (int)$t['item_id'])]; };
}
if (!$handler) api_fail(404, 'not_found', 'Unknown endpoint.');

// shared fields every write may carry
try { $ref = body_str('ref', 60); $note = body_str('note', 250); $by = body_str('by', 100); $byId = body_str('by_id', 40); }
catch (ApiStop $e) { api_fail($e->http, $e->code2, $e->getMessage()); }
$GLOBALS['API_CTX'] = ['integration_id' => $INT['id'], 'name' => $INT['name'], 'ref' => $ref, 'note' => $note ?? '', 'by' => $by, 'by_id' => $byId];

// one database transaction: idempotency record + stock change + result are saved together or not at all
$pdo->beginTransaction();
try {
  try { q("INSERT INTO api_idem(integration_id,ikey,req_hash) VALUES(?,?,?)", [$INT['id'], $ikey, $reqHash]); }
  catch (PDOException $e) {
    if ((string)$e->getCode() !== '23000') throw $e;
    $old = q("SELECT * FROM api_idem WHERE integration_id=? AND ikey=? FOR UPDATE", [$INT['id'], $ikey])->fetch(); $pdo->rollBack();
    if (!$old) api_fail(409, 'in_progress', 'That request is still being processed. Retry shortly.');
    if (!hash_equals($old['req_hash'], $reqHash)) api_fail(422, 'idempotency_conflict', 'That Idempotency-Key was already used for a different request.');
    if ($old['response'] === null) api_fail(409, 'in_progress', 'That request is still being processed. Retry shortly.');
    api_send((int)$old['http_code'], json_decode($old['response'], true), ['Idempotent-Replay' => 'true']);
  }
  [$code, $data] = $handler();
  q("UPDATE api_idem SET http_code=?,response=? WHERE integration_id=? AND ikey=?", [$code, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), $INT['id'], $ikey]);
  $pdo->commit();
} catch (ApiStop $e) { if ($pdo->inTransaction()) $pdo->rollBack(); api_fail($e->http, $e->code2, $e->getMessage(), $e->extra); }
api_send($code, $data);
