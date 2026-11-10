<?php
const CODE_TYPES = ['qr'=>'QR code','code128'=>'Code 128','code39'=>'Code 39','itf'=>'ITF (Interleaved 2 of 5)','upca'=>'UPC-A','ean13'=>'EAN-13','ean8'=>'EAN-8','upce'=>'UPC-E','codabar'=>'Codabar','other'=>'Other / unknown'];
function gs1_check($d){
  $n = strlen($d); $sum = 0;
  for ($i = 0; $i < $n - 1; $i++) { $v = (int)$d[$n - 2 - $i]; $sum += ($i % 2 === 0) ? $v * 3 : $v; }
  return ((10 - ($sum % 10)) % 10) === (int)$d[$n - 1];
}
function fmt_to_type($f){
  $f = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$f));
  $m = ['qrcode'=>'qr','code128'=>'code128','code39'=>'code39','ean13'=>'ean13','ean8'=>'ean8','upca'=>'upca','upce'=>'upce','itf'=>'itf','codabar'=>'codabar'];
  return $f === '' ? '' : ($m[$f] ?? 'other');
}
function guess_code_type($c){
  if (ctype_digit($c)) { $n = strlen($c); if ($n === 12 && gs1_check($c)) return 'upca'; if ($n === 13 && gs1_check($c)) return 'ean13'; if ($n === 8 && gs1_check($c)) return 'ean8'; }
  return 'other';
}
function normalize_code($type, $code){
  $code = trim($code); $type = strtolower(trim($type));
  if (!isset(CODE_TYPES[$type])) $type = '';
  if ($type === 'code39') { $code = strtoupper($code); if (strlen($code) > 2 && $code[0] === '*' && substr($code, -1) === '*') $code = substr($code, 1, -1); }
  if ($type === 'ean13' && strlen($code) === 13 && $code[0] === '0' && ctype_digit($code)) { $type = 'upca'; $code = substr($code, 1); }
  if ($type === '') $type = guess_code_type($code);
  return [$type, $code];
}
function validate_code($type, $code){
  if ($code === '') return 'A code is empty.';
  if (strlen($code) > 100) return 'A code is too long (100 characters max).';
  switch ($type) {
    case 'upca':  if (!ctype_digit($code) || strlen($code) !== 12 || !gs1_check($code)) return "UPC-A must be 12 digits with a valid check digit ($code). If it came from a scan, scan it again."; break;
    case 'ean13': if (!ctype_digit($code) || strlen($code) !== 13 || !gs1_check($code)) return "EAN-13 must be 13 digits with a valid check digit ($code)."; break;
    case 'ean8':  if (!ctype_digit($code) || strlen($code) !== 8 || !gs1_check($code)) return "EAN-8 must be 8 digits with a valid check digit ($code)."; break;
    case 'upce':  if (!ctype_digit($code) || strlen($code) !== 8) return "UPC-E must be 8 digits ($code)."; break;
    case 'itf':   if (!ctype_digit($code) || strlen($code) % 2) return "ITF needs an even number of digits ($code)."; break;
    case 'code39': if (!preg_match('/^[0-9A-Z \-.$\/+%]+$/', $code)) return "Code 39 allows only A-Z, 0-9, space and - . \$ / + % ($code)."; break;
    case 'codabar': if (!preg_match('/^[0-9\-$:\/.+]+$/', $code)) return "Codabar allows only digits and - \$ : / . + ($code)."; break;
  }
  return null;
}
function code_candidates($code){
  $c = trim($code); $out = [$c]; $u = strtoupper($c);
  if ($u !== $c) $out[] = $u;
  if (strlen($u) > 2 && $u[0] === '*' && substr($u, -1) === '*') $out[] = substr($u, 1, -1);
  if (ctype_digit($c)) { if (strlen($c) === 12) $out[] = '0'.$c; if (strlen($c) === 13 && $c[0] === '0') $out[] = substr($c, 1); }
  return array_values(array_unique($out));
}
function code_in_use($code, $except = 0){
  $r = q("SELECT i.id,i.name FROM items i WHERE i.id<>? AND (i.barcode=? OR i.sku=? OR EXISTS(SELECT 1 FROM item_codes c WHERE c.item_id=i.id AND c.code=?)) LIMIT 1", [$except, $code, $code, $code])->fetch();
  return $r ?: null;
}
function sync_item_codes($itemId, $codes){
  q("DELETE FROM item_codes WHERE item_id=?", [$itemId]);
  foreach ($codes as $c) q("INSERT INTO item_codes(item_id,code,code_type,is_primary) VALUES(?,?,?,?)", [$itemId, $c['code'], $c['type'], $c['primary'] ? 1 : 0]);
}
function codes_summary($rows){
  $o = []; foreach ($rows as $r) $o[] = (CODE_TYPES[$r['type']] ?? $r['type']).': '.$r['code'].(!empty($r['primary']) ? ' (primary)' : '');
  return implode('; ', $o);
}
function item_codes_summary($id){
  if (!$id) return '';
  $rows = q("SELECT code,code_type,is_primary FROM item_codes WHERE item_id=? ORDER BY is_primary DESC,id", [$id])->fetchAll();
  return codes_summary(array_map(fn($r)=>['code'=>$r['code'],'type'=>$r['code_type'],'primary'=>$r['is_primary']], $rows));
}
function label_fmt($choice, $type, $code){
  $want = $choice === 'auto' ? $type : $choice;
  if ($want === 'qr') return 'qr';
  if (!in_array($want, ['code128','code39','itf','upca','ean13'], true)) return 'code128';
  if (in_array($want, ['upca','ean13'], true) && ($want !== $type || validate_code($want, $code))) return 'code128';
  if (in_array($want, ['code39','itf'], true) && validate_code($want, $code)) return 'code128';
  return $want;
}
