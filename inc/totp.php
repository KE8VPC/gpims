<?php // Time-based one-time passwords (RFC 6238): Google Authenticator, Microsoft Authenticator, Authy, 1Password, Aegis, etc.
function b32_enc($bin){ $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT); $o = ''; foreach (str_split($bits, 5) as $ch) $o .= $a[bindec(str_pad($ch, 5, '0'))]; return $o; }
function b32_dec($s){ $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s)); $bits = ''; foreach (str_split($s) as $c) $bits .= str_pad(decbin(strpos($a, $c)), 5, '0', STR_PAD_LEFT); $o = ''; foreach (str_split($bits, 8) as $b) if (strlen($b) === 8) $o .= chr(bindec($b)); return $o; }
function totp_new_secret(){ return b32_enc(random_bytes(20)); }
function totp_code($secret, $step){ $h = hash_hmac('sha1', pack('N2', 0, $step), b32_dec($secret), true); $o = ord($h[19]) & 15; $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o+1]) << 16) | (ord($h[$o+2]) << 8) | ord($h[$o+3]); return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT); }
// Returns the matched time step (so it can be remembered and never accepted twice) or 0.
function totp_check($secret, $code, $lastUsed = 0){ $code = preg_replace('/\D/', '', (string)$code); if (strlen($code) !== 6 || $secret === '') return 0; $now = (int)floor(time() / 30);
  for ($d = -1; $d <= 1; $d++) { $st = $now + $d; if ($st > (int)$lastUsed && hash_equals(totp_code($secret, $st), $code)) return $st; } return 0; }
function totp_uri($secret, $user){ $iss = $GLOBALS['cfg']['app_name'] ?? 'GPIMS'; return 'otpauth://totp/'.rawurlencode($iss.':'.$user).'?secret='.$secret.'&issuer='.rawurlencode($iss).'&algorithm=SHA1&digits=6&period=30'; }
function totp_recovery_make(){ $plain = $hash = []; for ($i = 0; $i < 8; $i++) { $c = strtolower(bin2hex(random_bytes(4))); $plain[] = substr($c, 0, 4).'-'.substr($c, 4); $hash[] = hash('sha256', $c); } return [$plain, $hash]; }
// Verifies a 6-digit code or a recovery code for a user row; updates replay/recovery state. Returns true when accepted.
function totp_user_verify($u, $input){
  $input = trim((string)$input); $digits = preg_replace('/\D/', '', $input);
  if (strlen($digits) === 6 && ctype_digit(str_replace(' ', '', $input))) { $st = totp_check($u['totp_secret'], $digits, (int)$u['totp_last']); if ($st) { q("UPDATE users SET totp_last=? WHERE id=? AND totp_last<?", [$st, $u['id'], $st]); return true; } return false; }
  $c = strtolower(preg_replace('/[^a-f0-9]/i', '', $input)); if (strlen($c) !== 8) return false; $h = hash('sha256', $c);
  $list = json_decode((string)$u['totp_recovery'], true) ?: []; $k = array_search($h, $list, true); if ($k === false) return false;
  unset($list[$k]); q("UPDATE users SET totp_recovery=? WHERE id=?", [json_encode(array_values($list)), $u['id']]); audit_as($u['id'], 'totp_recovery_used', count($list).' left'); return true;
}
function audit_as($uid, $action, $details = ''){ q("INSERT INTO audit_log(user_id,action,details) VALUES(?,?,?)", [$uid, $action, mb_substr($details, 0, 250)]); }
