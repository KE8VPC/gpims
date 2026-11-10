<?php
// Barcode lookup for quick-add: asks free public product databases (Open Food Facts, Open Products Facts, UPCitemdb) by code only.
function lookup_enabled(){ return setting('lookup_enabled', '1') === '1'; }
function lookup_http($url, $max = 2000000){
  if (!empty($GLOBALS['LOOKUP_HTTP_MOCK'])) return $GLOBALS['LOOKUP_HTTP_MOCK']($url);   // used by the automated tests only
  $ua = 'GPIMS-inventory/'.APP_VERSION;
  if (function_exists('curl_init')) {
    $c = curl_init($url); curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => $ua, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_MAXFILESIZE => $max, CURLOPT_HTTPHEADER => ['Accept: application/json,image/*']]);
    $r = curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c); return ($r !== false && $code >= 200 && $code < 300 && strlen($r) <= $max) ? $r : null;
  }
  if (!ini_get('allow_url_fopen')) return null;
  $r = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 6, 'header' => "User-Agent: $ua\r\n", 'follow_location' => 0, 'ignore_errors' => false]]), 0, $max + 1);
  return ($r !== false && strlen($r) <= $max) ? $r : null;
}
function lookup_save_image($url){
  $h = parse_url($url, PHP_URL_HOST); if (!$h || parse_url($url, PHP_URL_SCHEME) !== 'https' || !preg_match('/(^|\.)(openfoodfacts|openproductsfacts|openbeautyfacts|openpetfoodfacts)\.org$/i', $h)) return null;
  $data = lookup_http($url, 3000000); if (!$data) return null; $tmp = tempnam(sys_get_temp_dir(), 'gpl'); file_put_contents($tmp, $data);
  $dir = dirname(__DIR__).'/uploads'; if (!is_dir($dir)) @mkdir($dir, 0755); $n = save_photo($tmp, $dir); @unlink($tmp); return $n;
}
function lookup_clean($s, $n){ return mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string)$s))), 0, $n); }
function lookup_fetch($code){
  $t0 = microtime(true); $over = fn() => microtime(true) - $t0 > 14;   // give up on slow networks instead of making the person wait
  // Books: the barcode is the ISBN
  if (preg_match('/^97[89]\d{10}$/', $code)) {
    $j = json_decode((string)lookup_http('https://openlibrary.org/api/books?bibkeys=ISBN:'.$code.'&format=json&jscmd=data'), true); $b = $j['ISBN:'.$code] ?? null;
    if ($b && lookup_clean($b['title'] ?? '', 200) !== '') { $nm = lookup_clean($b['title'].(!empty($b['subtitle']) ? ': '.$b['subtitle'] : ''), 200);
      return ['name' => $nm, 'brand' => lookup_clean($b['authors'][0]['name'] ?? '', 120), 'size' => lookup_clean(($b['publishers'][0]['name'] ?? '').(!empty($b['number_of_pages']) ? ' · '.(int)$b['number_of_pages'].' pages' : ''), 80), 'category' => 'Books', 'image_url' => '', 'source' => 'Open Library']; }
  }
  // Open Food / Products / Beauty / Pet Food Facts: free, same format, no key
  foreach (['world.openfoodfacts.org' => 'Open Food Facts', 'world.openproductsfacts.org' => 'Open Products Facts', 'world.openbeautyfacts.org' => 'Open Beauty Facts', 'world.openpetfoodfacts.org' => 'Open Pet Food Facts'] as $host => $label) {
    if ($over()) break;
    $j = json_decode((string)lookup_http("https://$host/api/v2/product/".rawurlencode($code).".json?fields=product_name,generic_name,brands,quantity,categories,image_front_url"), true);
    $p = $j['product'] ?? null; $nm = lookup_clean($p['product_name'] ?? ($p['generic_name'] ?? ''), 200);
    if (($j['status'] ?? 0) == 1 && $nm !== '') return ['name' => $nm, 'brand' => lookup_clean(explode(',', (string)($p['brands'] ?? ''))[0], 120), 'size' => lookup_clean($p['quantity'] ?? '', 80), 'category' => lookup_clean($p['categories'] ?? '', 160), 'image_url' => (string)($p['image_front_url'] ?? ''), 'source' => $label];
  }
  if (!$over()) {
    $j = json_decode((string)lookup_http('https://api.upcitemdb.com/prod/trial/lookup?upc='.rawurlencode($code)), true);
    $p = $j['items'][0] ?? null; if ($p && lookup_clean($p['title'] ?? '', 200) !== '') return ['name' => lookup_clean($p['title'], 200), 'brand' => lookup_clean($p['brand'] ?? '', 120), 'size' => lookup_clean($p['size'] ?? '', 80), 'category' => lookup_clean($p['category'] ?? '', 160), 'image_url' => '', 'source' => 'UPCitemdb'];
  }
  return null;
}
// Remember what a barcode is once someone has entered it, so it fills in next time (for everyone). Never overwrites a lookup result.
function lookup_learn($code, $name, $category = ''){
  $code = preg_replace('/\D/', '', (string)$code); $name = lookup_clean($name, 200); if (!preg_match('/^\d{8,14}$/', $code) || $name === '') return;
  $r = q("SELECT found,source FROM barcode_cache WHERE code=?", [$code])->fetch(); if ($r && $r['found'] && $r['source'] !== 'Your inventory') return;
  q("REPLACE INTO barcode_cache(code,found,name,brand,size,category,image,source,looked_up_at) VALUES(?,1,?,NULL,NULL,?,NULL,?,NOW())", [$code, $name, lookup_clean($category, 160) ?: null, 'Your inventory']);
}
// Look a code up (cached). Returns ['found'=>bool, ...]. Misses are cached for a week, hits for good.
function lookup_code($code){
  $code = preg_replace('/\D/', '', (string)$code); if (!preg_match('/^\d{8,14}$/', $code)) return ['found' => false, 'reason' => 'Only product barcodes (UPC / EAN, 8 to 14 digits) can be looked up.'];
  $r = q("SELECT * FROM barcode_cache WHERE code=?", [$code])->fetch();
  if ($r && ($r['found'] || strtotime($r['looked_up_at']) > time() - 7 * 86400)) return $r['found'] ? ['found' => true, 'name' => $r['name'], 'brand' => $r['brand'], 'size' => $r['size'], 'category' => $r['category'], 'image' => $r['image'], 'source' => $r['source'], 'cached' => true] : ['found' => false, 'cached' => true];
  $d = lookup_fetch($code);
  if (!$d) { q("REPLACE INTO barcode_cache(code,found,looked_up_at) VALUES(?,0,NOW())", [$code]); return ['found' => false]; }
  $img = $d['image_url'] ? lookup_save_image($d['image_url']) : null;
  q("REPLACE INTO barcode_cache(code,found,name,brand,size,category,image,source,looked_up_at) VALUES(?,1,?,?,?,?,?,?,NOW())", [$code, $d['name'], $d['brand'], $d['size'], $d['category'], $img, $d['source']]);
  return ['found' => true, 'name' => $d['name'], 'brand' => $d['brand'], 'size' => $d['size'], 'category' => $d['category'], 'image' => $img, 'source' => $d['source']];
}
// A lookup photo is copied onto the item so the cache copy can be tidied independently.
function lookup_attach_photo($item_id, $code, $fname){
  $fname = basename((string)$fname); $code = preg_replace('/\D/', '', (string)$code); if ($fname === '' || $code === '') return false;
  if (!q("SELECT 1 FROM barcode_cache WHERE code=? AND image=?", [$code, $fname])->fetch()) return false;
  $src = dirname(__DIR__).'/uploads/'.$fname; if (!is_file($src)) return false;
  $ext = pathinfo($fname, PATHINFO_EXTENSION); $n = bin2hex(random_bytes(12)).'.'.$ext; if (!@copy($src, dirname(__DIR__).'/uploads/'.$n)) return false;
  q("INSERT INTO item_photos(item_id,filename) VALUES(?,?)", [$item_id, $n]); return true;
}
// Remove cached lookup photos nobody ever used; forget old misses.
function lookup_cleanup(){
  q("DELETE FROM barcode_cache WHERE found=0 AND looked_up_at < (NOW() - INTERVAL 14 DAY)");
  foreach (q("SELECT code,image FROM barcode_cache WHERE found=1 AND image IS NOT NULL AND looked_up_at < (NOW() - INTERVAL 3 DAY)")->fetchAll() as $r) { drop_upload($r['image']); q("UPDATE barcode_cache SET image=NULL WHERE code=?", [$r['code']]); }
}
