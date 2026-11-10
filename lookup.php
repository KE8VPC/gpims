<?php require 'inc/bootstrap.php'; need_perm('items_add'); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
if (!lookup_enabled()) { echo json_encode(['found' => false, 'reason' => 'Barcode lookup is turned off in Settings.']); exit; }
$_SESSION['lk'] = array_values(array_filter($_SESSION['lk'] ?? [], fn($t) => $t > time() - 60)); if (count($_SESSION['lk']) >= 20) { http_response_code(429); echo json_encode(['found' => false, 'reason' => 'Too many lookups, wait a minute.']); exit; }
$_SESSION['lk'][] = time();
$r = lookup_code($_GET['code'] ?? '');
if (!empty($r['found'])) {
  $r['image_url'] = !empty($r['image']) ? 'uploads/'.$r['image'] : '';
  $t = strtolower($r['category'] ?? '').' '.strtolower($r['name']); $best = null; $bl = 0;
  foreach (cats_select() as $c) { $parts = explode(' / ', $c['name']); $nm = strtolower(trim(end($parts))); if (mb_strlen($nm) >= 4 && strpos($t, $nm) !== false && mb_strlen($nm) > $bl) { $best = (int)$c['id']; $bl = mb_strlen($nm); } }
  $r['category_id'] = $best;
}
$dup = code_in_use(preg_replace('/\D/', '', (string)($_GET['code'] ?? ''))); if ($dup) $r['existing'] = ['id' => (int)$dup['id'], 'name' => $dup['name']];
echo json_encode($r);
