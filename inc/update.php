<?php // In-app updater: checks GitHub releases, backs up, installs the release zip over the app files, runs the DB upgrade.
const UPD_KEEP = ['config.php', 'install.php', 'uploads/', 'backups/', '.git/', '.github/'];
function upd_repo(){ $r = trim((string)setting('update_repo', 'KE8VPC/gpims')); return preg_match('#^[A-Za-z0-9_.-]{1,60}/[A-Za-z0-9_.-]{1,80}$#', $r) ? $r : 'KE8VPC/gpims'; }
function upd_api_base(){ return defined('UPD_API_BASE') ? UPD_API_BASE : 'https://api.github.com'; }
function upd_http($url, $toFile = null, $timeout = 120){
  if (!preg_match('#^https://(api\.github\.com|codeload\.github\.com|github\.com)/#', $url) && !(defined('UPD_API_BASE') && strpos($url, UPD_API_BASE) === 0)) throw new Exception('Update address not allowed.');
  if (!function_exists('curl_init')) { $c = stream_context_create(['http' => ['header' => "User-Agent: GPIMS-updater\r\n", 'timeout' => min(60, $timeout)]]); $d = @file_get_contents($url, false, $c); if ($d === false) throw new Exception('Could not reach GitHub.'); if ($toFile) { file_put_contents($toFile, $d); return ''; } return $d; }
  $ch = curl_init($url); $fh = $toFile ? fopen($toFile, 'wb') : null;
  curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => $timeout, CURLOPT_USERAGENT => 'GPIMS-updater', CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'], CURLOPT_RETURNTRANSFER => !$fh]);
  if (!defined('UPD_API_BASE')) curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
  if ($fh) curl_setopt($ch, CURLOPT_FILE, $fh);
  $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch); if ($fh) fclose($fh);
  if ($r === false || $code >= 400) throw new Exception('GitHub answered '.($code ?: $err ?: 'no response').'.');
  return $fh ? '' : $r;
}
function upd_norm($t){ return ltrim(trim((string)$t), 'vV'); }
function upd_newer($remote, $local){ $r = upd_norm($remote); $l = upd_norm($local); if (!preg_match('/^\d+\.\d+\.\d+$/', $l)) return true; return version_compare($r, $l, '>'); }
function upd_type($notes){ return preg_match('/^\s*(release\s*)?type\s*:\s*minor\b/im', (string)$notes) ? 'minor' : 'major'; }
function upd_check($timeout = 120){
  $j = json_decode(upd_http(upd_api_base().'/repos/'.upd_repo().'/releases/latest', null, $timeout), true);
  if (!is_array($j) || empty($j['tag_name'])) throw new Exception('No release found for '.upd_repo().'.');
  $asset = ''; foreach ($j['assets'] ?? [] as $a) if (preg_match('/\.zip$/i', $a['name'] ?? '') && !empty($a['browser_download_url'])) { $asset = $a['browser_download_url']; break; }
  $info = ['tag' => $j['tag_name'], 'name' => $j['name'] ?? $j['tag_name'], 'notes' => (string)($j['body'] ?? ''), 'zip' => $asset ?: ($j['zipball_url'] ?? ''), 'newer' => upd_newer($j['tag_name'], APP_VERSION), 'checked' => date('Y-m-d H:i')];
  $info['type'] = upd_type($info['notes']);
  set_setting('update_last_check', json_encode(['tag' => $info['tag'], 'name' => $info['name'], 'newer' => $info['newer'], 'type' => $info['type'], 'at' => $info['checked'], 'notes' => mb_substr($info['notes'], 0, 600)])); return $info;
}
function upd_skip($rel){ foreach (UPD_KEEP as $k) { if (substr($k, -1) === '/' ? strpos($rel.'/', $k) === 0 || strpos($rel, $k) === 0 : $rel === $k) return true; } return (bool)preg_match('/\.zip$/i', $rel); }
// Installs the zip at $zipPath. Returns list of messages. Never deletes files; only adds/replaces.
function upd_install($zipPath){
  if (!class_exists('ZipArchive')) throw new Exception('This server has no zip support (ZipArchive), so updates must be uploaded by hand.');
  $z = new ZipArchive(); if ($z->open($zipPath) !== true) throw new Exception('The downloaded file is not a valid zip.');
  $names = []; for ($i = 0; $i < $z->numFiles; $i++) $names[] = $z->getNameIndex($i);
  $prefix = ''; if ($names) { $f = $names[0]; $p = strpos($f, '/'); if ($p !== false) { $cand = substr($f, 0, $p + 1); $all = true; foreach ($names as $n) if (strpos($n, $cand) !== 0) { $all = false; break; } if ($all) $prefix = $cand; } }
  if (!in_array($prefix.'inc/version.php', $names, true) || !in_array($prefix.'inc/bootstrap.php', $names, true)) { $z->close(); throw new Exception('That zip does not look like a GPIMS release.'); }
  $root = realpath(__DIR__.'/..'); $written = 0; $skipped = 0; $plan = [];
  for ($i = 0; $i < $z->numFiles; $i++) {
    $n = $z->getNameIndex($i); $rel = substr($n, strlen($prefix)); if ($rel === '' || substr($n, -1) === '/') continue;
    if (strpos($rel, '..') !== false || $rel[0] === '/' || strpos($rel, "\0") !== false || preg_match('#(^|/)(gpims|hub)/#i', $rel)) { $skipped++; continue; }
    if (upd_skip($rel)) { $skipped++; continue; }
    $plan[] = [$i, $rel];
  }
  $b = backup_make_file('gpims-preupdate'); if ($b === '') { $z->close(); throw new Exception('Could not write the database backup, so nothing was changed.'); }
  $msgs = ['Database backup saved: '.$b];
  // write app files first except version.php, which goes last so a half-finished install is visible and re-runnable
  usort($plan, fn($a, $c) => ($a[1] === 'inc/version.php') <=> ($c[1] === 'inc/version.php'));
  foreach ($plan as [$i, $rel]) {
    $dest = $root.'/'.$rel; $dir = dirname($dest);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { $z->close(); throw new Exception('Could not create folder '.dirname($rel).'. Files written so far: '.$written.'.'); }
    $data = $z->getFromIndex($i); if ($data === false) { $z->close(); throw new Exception('Could not read '.$rel.' from the zip.'); }
    $tmp = $dest.'.upd'.getmypid(); if (file_put_contents($tmp, $data) === false || !@rename($tmp, $dest)) { @unlink($tmp); $z->close(); throw new Exception('Could not write '.$rel.'. Files written so far: '.$written.'.'); }
    $written++;
  }
  $z->close(); if (function_exists('opcache_reset')) @opcache_reset();
  $msgs[] = $written.' files updated, '.$skipped.' protected or skipped (config, uploads, backups are never touched).';
  return $msgs;
}

// Quiet once-a-day check, called from the dashboard for admins. Never throws; a failure is retried after 6 hours.
function upd_auto_check(){
  if (setting('update_notice', 'all') === 'off') return;
  $due = (string)setting('update_next_check', ''); if ($due !== '' && $due > date('Y-m-d H:i:s')) return;
  try { upd_check(4); set_setting('update_next_check', date('Y-m-d H:i:s', time() + 86400)); }
  catch (Throwable $e) { set_setting('update_next_check', date('Y-m-d H:i:s', time() + 21600)); }
}
// The cached result if it should be announced to this admin, else null. Choice: all, major, off.
function upd_notice(){
  $mode = setting('update_notice', 'all'); if ($mode === 'off') return null;
  $l = json_decode((string)setting('update_last_check', ''), true); if (!is_array($l) || empty($l['newer']) || empty($l['tag'])) return null;
  if (!upd_newer($l['tag'], APP_VERSION)) return null;
  if ($mode === 'major' && ($l['type'] ?? 'major') !== 'major') return null;
  if (setting('update_dismissed', '') === $l['tag']) return null;
  return $l;
}
// install.php is only needed for the first install; remove it once the app is running.
function upd_remove_installer(){ $f = realpath(__DIR__.'/..').'/install.php'; return is_file($f) ? @unlink($f) : false; }
