<?php require 'inc/bootstrap.php'; require 'inc/webauthn.php';
header('Content-Type: application/json'); header('Cache-Control: no-store');
function jout($a){ echo json_encode($a); exit; }
function body(){ $b = json_decode(file_get_contents('php://input'), true); return is_array($b) ? $b : []; }
$a = $_GET['a'] ?? '';
try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') jout(['ok'=>false, 'error'=>'POST required']);
  if (!passkeys_ok()) jout(['ok'=>false, 'error'=>'Passkeys are not available on this site (they need HTTPS and the OpenSSL extension, and must be enabled in Settings).']);
  [$rpId, $origin] = wa_rp(); $in = body();
  if ($a === 'reg_options') {
    if (!me()) jout(['ok'=>false, 'error'=>'Sign in first.']);
    $u = me(); $ex = [];
    foreach (q("SELECT credential_id FROM passkeys WHERE user_id=?", [$u['id']])->fetchAll() as $r) $ex[] = ['type'=>'public-key', 'id'=>$r['credential_id']];
    jout(['ok'=>true, 'options'=>[
      'challenge'=>wa_new_challenge('reg'), 'rp'=>['name'=>$cfg['app_name'], 'id'=>$rpId],
      'user'=>['id'=>wa_user_handle($u['id']), 'name'=>$u['username'], 'displayName'=>$u['name']],
      'pubKeyCredParams'=>[['type'=>'public-key', 'alg'=>-7], ['type'=>'public-key', 'alg'=>-257]],
      'authenticatorSelection'=>['residentKey'=>'required', 'requireResidentKey'=>true, 'userVerification'=>'required'],
      'attestation'=>'none', 'timeout'=>120000, 'excludeCredentials'=>$ex]]);
  }
  if ($a === 'reg_verify') {
    if (!me()) jout(['ok'=>false, 'error'=>'Sign in first.']);
    $chal = wa_take_challenge('reg'); $resp = $in['response'] ?? [];
    wa_check_client(b64u_dec($resp['clientDataJSON'] ?? ''), 'webauthn.create', $chal);
    $att = cbor_decode(b64u_dec($resp['attestationObject'] ?? '')); if (!is_array($att) || !isset($att['authData'])) throw new Exception('Bad attestation');
    $ad = parse_auth_data($att['authData']);
    if (!hash_equals(hash('sha256', $rpId, true), $ad['rpIdHash'])) throw new Exception('Relying party mismatch');
    if (!$ad['up'] || !$ad['uv'] || !$ad['at']) throw new Exception('The device did not verify you (fingerprint, face or screen lock is required).');
    $cid = b64u_enc($ad['credId']); if ($cid !== ($in['id'] ?? '')) throw new Exception('Credential id mismatch');
    [$pem, $alg] = cose_to_pem($ad['cose']);
    if (q("SELECT 1 FROM passkeys WHERE credential_id=?", [$cid])->fetch()) throw new Exception('This passkey is already registered.');
    $name = mb_substr(trim($in['name'] ?? ''), 0, 80) ?: 'Passkey';
    q("INSERT INTO passkeys(user_id,credential_id,public_key,alg,sign_count,name,transports) VALUES(?,?,?,?,?,?,?)", [me()['id'], $cid, $pem, $alg, $ad['signCount'], $name, mb_substr(implode(',', (array)($in['transports'] ?? [])), 0, 100)]);
    audit('passkey_add', $name); flash('Passkey added: '.$name); passkey_security_mail(me()['id'], 'added', $name);
    jout(['ok'=>true]);
  }
  if ($a === 'login_options') {
    jout(['ok'=>true, 'options'=>['challenge'=>wa_new_challenge('login'), 'rpId'=>$rpId, 'userVerification'=>'required', 'timeout'=>($in['auto'] ?? false) ? 300000 : 120000]]);
  }
  if ($a === 'login_verify') {
    $chal = wa_take_challenge('login'); $resp = $in['response'] ?? [];
    $row = q("SELECT p.*,u.id uid FROM passkeys p JOIN users u ON u.id=p.user_id WHERE p.credential_id=? AND u.active=1", [$in['id'] ?? ''])->fetch();
    if (!$row) throw new Exception('This passkey is not registered, or the account is inactive.');
    $cdj = b64u_dec($resp['clientDataJSON'] ?? ''); wa_check_client($cdj, 'webauthn.get', $chal);
    $adRaw = b64u_dec($resp['authenticatorData'] ?? ''); $ad = parse_auth_data($adRaw);
    if (!hash_equals(hash('sha256', $rpId, true), $ad['rpIdHash'])) throw new Exception('Relying party mismatch');
    if (!$ad['up'] || !$ad['uv']) throw new Exception('The device did not verify you.');
    $uh = $resp['userHandle'] ?? ''; if ($uh !== '' && !hash_equals(wa_user_handle($row['uid']), $uh)) throw new Exception('Account mismatch');
    $ok = openssl_verify($adRaw.hash('sha256', $cdj, true), b64u_dec($resp['signature'] ?? ''), $row['public_key'], OPENSSL_ALGO_SHA256);
    if ($ok !== 1) throw new Exception('Signature check failed');
    if (($ad['signCount'] > 0 || $row['sign_count'] > 0) && $ad['signCount'] <= $row['sign_count']) throw new Exception('Passkey counter went backwards (possible copied key).');
    q("UPDATE passkeys SET sign_count=?,last_used=NOW() WHERE id=?", [$ad['signCount'], $row['id']]);
    $u = q("SELECT * FROM users WHERE id=?", [$row['uid']])->fetch(); unset($u['pass_hash']);
    session_regenerate_id(true); $_SESSION['user'] = $u; $_SESSION['last'] = time();
    q("UPDATE users SET last_login=NOW() WHERE id=?", [$u['id']]); audit('login_passkey');
    jout(['ok'=>true, 'redirect'=>$u['role'] === 'vendor' ? 'supply.php' : 'index.php']);
  }
  jout(['ok'=>false, 'error'=>'Unknown action']);
} catch (Exception $e) { jout(['ok'=>false, 'error'=>$e->getMessage()]); }
