<?php
function b64u_enc($s){ return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec($s){ return base64_decode(strtr((string)$s, '-_', '+/').str_repeat('=', (4 - strlen((string)$s) % 4) % 4)); }
function cbor_decode($d, &$o = 0){
  if ($o >= strlen($d)) throw new Exception('CBOR: unexpected end');
  $ib = ord($d[$o++]); $mt = $ib >> 5; $ai = $ib & 31;
  if ($ai < 24) $v = $ai;
  elseif ($ai === 24) $v = ord($d[$o++]);
  elseif ($ai === 25) { $v = unpack('n', substr($d, $o, 2))[1]; $o += 2; }
  elseif ($ai === 26) { $v = unpack('N', substr($d, $o, 4))[1]; $o += 4; }
  elseif ($ai === 27) { $hi = unpack('N', substr($d, $o, 4))[1]; $lo = unpack('N', substr($d, $o + 4, 4))[1]; $o += 8; $v = $hi * 4294967296 + $lo; }
  else throw new Exception('CBOR: unsupported length');
  switch ($mt) {
    case 0: return $v;
    case 1: return -1 - $v;
    case 2: case 3: $r = substr($d, $o, $v); $o += $v; return $r;
    case 4: $a = []; for ($i = 0; $i < $v; $i++) $a[] = cbor_decode($d, $o); return $a;
    case 5: $m = []; for ($i = 0; $i < $v; $i++) { $k = cbor_decode($d, $o); $m[$k] = cbor_decode($d, $o); } return $m;
    case 6: return cbor_decode($d, $o);
    case 7: if ($v === 20) return false; if ($v === 21) return true; if ($v === 22 || $v === 23) return null; throw new Exception('CBOR: unsupported simple value');
  }
  throw new Exception('CBOR: bad type');
}
function der_len($n){ if ($n < 128) return chr($n); $b = ltrim(pack('N', $n), "\0"); return chr(0x80 | strlen($b)).$b; }
function der_seq($c){ return "\x30".der_len(strlen($c)).$c; }
function der_int($b){ $b = ltrim($b, "\0"); if ($b === '' || (ord($b[0]) & 0x80)) $b = "\0".$b; return "\x02".der_len(strlen($b)).$b; }
function der_bits($b){ return "\x03".der_len(strlen($b) + 1)."\0".$b; }
function der_to_pem($der){ return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n"; }
function cose_to_pem($k){
  $kty = $k[1] ?? null;
  if ($kty === 2) {
    if (($k[-1] ?? null) !== 1) throw new Exception('Unsupported curve');
    $x = $k[-2] ?? ''; $y = $k[-3] ?? '';
    if (strlen($x) !== 32 || strlen($y) !== 32) throw new Exception('Bad EC key');
    return [der_to_pem(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200')."\x04".$x.$y), -7];
  }
  if ($kty === 3) {
    $n = $k[-1] ?? ''; $e = $k[-2] ?? ''; if ($n === '' || $e === '') throw new Exception('Bad RSA key');
    $rsa = der_seq(der_int($n).der_int($e));
    return [der_to_pem(der_seq(hex2bin('300d06092a864886f70d0101010500').der_bits($rsa))), -257];
  }
  throw new Exception('Unsupported key type');
}
function parse_auth_data($ad){
  if (strlen($ad) < 37) throw new Exception('Bad authenticator data');
  $r = ['rpIdHash'=>substr($ad, 0, 32), 'flags'=>ord($ad[32]), 'signCount'=>unpack('N', substr($ad, 33, 4))[1]];
  $r['up'] = ($r['flags'] & 1) > 0; $r['uv'] = ($r['flags'] & 4) > 0; $r['at'] = ($r['flags'] & 64) > 0;
  if ($r['at']) {
    $o = 37 + 16; $len = unpack('n', substr($ad, $o, 2))[1]; $o += 2;
    $r['credId'] = substr($ad, $o, $len); $o += $len;
    $r['cose'] = cbor_decode($ad, $o);
  }
  return $r;
}
function wa_rp(){
  global $https; $host = $_SERVER['HTTP_HOST'] ?? '';
  return [preg_replace('/:\d+$/', '', $host), ($https ? 'https' : 'http').'://'.$host];
}
function wa_user_handle($uid){ global $cfg; return b64u_enc(hash('sha256', 'gpims-user-'.$uid.'|'.($cfg['name'] ?? ''), true)); }
function passkeys_ok(){
  global $https, $db_outdated; [$rp] = wa_rp();
  return !$db_outdated && extension_loaded('openssl') && setting('passkeys_enabled', '1') === '1' && ($https || $rp === 'localhost');
}
function wa_new_challenge($kind){ $c = b64u_enc(random_bytes(32)); $_SESSION['wa'] = ['c'=>$c, 't'=>time(), 'k'=>$kind]; return $c; }
function wa_take_challenge($kind){
  $w = $_SESSION['wa'] ?? null; unset($_SESSION['wa']);
  if (!$w || $w['k'] !== $kind || time() - $w['t'] > 300) throw new Exception('Challenge expired. Try again.');
  return $w['c'];
}
function wa_check_client($json, $type, $chal){
  $c = json_decode($json, true); [$rp, $origin] = wa_rp();
  if (!is_array($c) || ($c['type'] ?? '') !== $type) throw new Exception('Wrong request type');
  if (!hash_equals($chal, (string)($c['challenge'] ?? ''))) throw new Exception('Challenge mismatch');
  if (($c['origin'] ?? '') !== $origin) throw new Exception('Origin mismatch');
  if (!empty($c['crossOrigin'])) throw new Exception('Cross-origin request refused');
}

function passkey_kind($transports){
  $t = array_filter(explode(',', (string)$transports));
  if (in_array('internal', $t, true) && !in_array('usb', $t, true)) return 'This device';
  if (in_array('hybrid', $t, true)) return 'Phone or tablet';
  if (array_intersect(['usb', 'nfc', 'ble'], $t)) return 'Security key';
  return 'Passkey';
}
function passkey_security_mail($uid, $what, $name){
  try {
    $u = q("SELECT name,email FROM users WHERE id=? AND active=1", [$uid])->fetch(); if (!$u || !valid_email($u['email'] ?? '') || !mail_enabled()) return;
    mail_queue($u['email'], 'Security notice: passkey '.$what, mail_wrap('Passkey '.$what, '<p>A passkey named <strong>'.h($name).'</strong> was '.$what.' on your account'.(' at '.date('M j, g:ia')).'.</p><p>If this was not you, change your password on the <a href="'.h(base_url().'account.php').'">Account page</a> and tell an administrator.</p>'), 'passkey');
  } catch (Throwable $e) {}
}
