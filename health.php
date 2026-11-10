<?php require 'inc/bootstrap.php'; need('admin'); $title = 'System health';
$rows = [];
function chk($label, $status, $detail = ''){ global $rows; $rows[] = [$label, $status, $detail]; }
function bytes($s){ $s = trim((string)$s); if ($s === '') return 0; $n = (float)$s; switch (strtolower(substr($s, -1))) { case 'g': $n *= 1024; case 'm': $n *= 1024; case 'k': $n *= 1024; } return (int)$n; }
chk('PHP version', version_compare(PHP_VERSION, '7.4', '>=') ? 'ok' : 'fail', PHP_VERSION.' (7.4 or newer needed)');
foreach (['pdo_mysql'=>'fail','mbstring'=>'fail','json'=>'fail','gd'=>'warn','exif'=>'warn','openssl'=>'warn'] as $ext => $lvl) {
  $has = extension_loaded($ext);
  $note = ['gd'=>'Needed to resize photos. Without it photos are stored as uploaded.','exif'=>'Used to rotate phone photos correctly.','openssl'=>'Needed for passkey sign-in.'][$ext] ?? 'Required.';
  chk("PHP extension: $ext", $has ? 'ok' : $lvl, $has ? 'Loaded' : "Missing. $note");
}
try { $ver = q("SELECT VERSION()")->fetchColumn(); chk('Database connection', 'ok', 'MySQL/MariaDB '.$ver); }
catch (Exception $e) { chk('Database connection', 'fail', $e->getMessage()); }
$dbv = db_version();
chk('Database version', $dbv >= APP_DB_VERSION ? 'ok' : 'fail', "Database is at $dbv, application expects ".APP_DB_VERSION.($dbv < APP_DB_VERSION ? '. Open Admin > Upgrade.' : ''));
$need = ['users','categories','custom_fields','locations','items','transactions','checkouts','checklists','checklist_items','checklist_runs','checklist_run_items','audit_log','item_photos','settings','login_attempts','item_history','people','user_access','item_codes','vendors','item_vendors','item_lots','alert_notes','passkeys','email_queue','password_resets','incidents','issues','issue_events','vehicles','vehicle_users','checklist_sections','kits','kit_items','documents','recounts','recount_lines','reservations','maintenance_tasks','maintenance_log'];
$missing = []; foreach ($need as $t) { try { if (!tbl_exists($t)) $missing[] = $t; } catch (Exception $e) { $missing[] = $t; } }
chk('Database tables', $missing ? 'fail' : 'ok', $missing ? 'Missing: '.implode(', ', $missing) : count($need).' tables present');
$cols = []; foreach ([['items','barcode'],['items','archived_at'],['users','last_login'],['users','perms'],['users','scope_all'],['locations','parent_id'],['checkouts','person_id'],['items','par_qty'],['transactions','incident_id'],['checkouts','incident_id'],['checklists','vehicle_id'],['checklist_items','section_id'],['checklist_run_items','actual'],['people','phone'],['checkouts','overdue_notified_on'],['categories','parent_id']] as [$t,$c]) { try { if (!col_exists($t,$c)) $cols[] = "$t.$c"; } catch (Exception $e) { $cols[] = "$t.$c"; } }
chk('Required columns', $cols ? 'fail' : 'ok', $cols ? 'Missing: '.implode(', ', $cols) : 'All present');
$up = __DIR__.'/uploads';
if (!is_dir($up)) chk('Uploads folder', 'fail', 'Folder is missing.');
else { $t = $up.'/.healthcheck'; $w = @file_put_contents($t, 'x') !== false; if ($w) @unlink($t); chk('Uploads folder', $w ? 'ok' : 'fail', $w ? 'Exists and is writable' : 'Not writable. Set permissions to 755 or 775.'); }
chk('install.php removed', file_exists(__DIR__.'/install.php') ? 'fail' : 'ok', file_exists(__DIR__.'/install.php') ? 'Delete install.php from the server.' : 'Not present');
$cp = @fileperms(__DIR__.'/config.php'); chk('config.php permissions', ($cp && ($cp & 0002)) ? 'warn' : 'ok', $cp ? substr(sprintf('%o', $cp), -4).(($cp & 0002) ? ' (world-writable, tighten to 640 or 644)' : '') : 'Unreadable');
chk('HTTPS', $https ? 'ok' : 'warn', $https ? 'Connection is secure' : 'Not using HTTPS. Camera scanning will not work and passwords travel unprotected.');
$uf = bytes(ini_get('upload_max_filesize')); $pm = bytes(ini_get('post_max_size'));
chk('Upload limits', ($uf >= 8*1048576 && $pm >= 8*1048576) ? 'ok' : 'warn', 'upload_max_filesize '.ini_get('upload_max_filesize').', post_max_size '.ini_get('post_max_size').' (photos can be several MB)');
$df = @disk_free_space(__DIR__); if ($df !== false) chk('Disk space', $df > 500*1048576 ? 'ok' : 'warn', round($df/1073741824, 1).' GB free');
try { $dbn = q("SELECT NOW() n, @@session.time_zone tz")->fetch(); $drift = abs(strtotime($dbn['n']) - time());
  chk('Time zone', $drift > 120 ? 'warn' : 'ok', 'App: '.date_default_timezone_get().' '.date('Y-m-d H:i').' · Database session: '.$dbn['tz'].' '.$dbn['n'].($drift > 120 ? ' (clocks disagree)' : '').'. Change it in Admin > Settings.'); }
catch (Exception $e) { chk('Time zone', 'info', date_default_timezone_get().' '.date('Y-m-d H:i')); }
$lb = setting('last_backup', ''); $age = $lb ? (time() - strtotime(substr($lb, 0, 16))) / 86400 : null;
chk('Backup', $age === null ? 'warn' : ($age > 30 ? 'warn' : 'ok'), $lb ? 'Last backup '.$lb.($age > 30 ? ' (over a month ago)' : '').'. See Admin > Backup.' : 'No backup has been made yet. See Admin > Backup.');
$bd = backup_dir(); chk('Backups folder', is_dir($bd) && is_writable($bd) ? 'ok' : 'warn', is_dir($bd) && is_writable($bd) ? 'Writable (automatic and safety copies can be saved)' : 'Cannot write to the backups folder. Automatic backups and the safety copy before a restore need it (permissions 755 or 775).');
chk('Application version', 'info', 'GPIMS '.APP_VERSION);
try { $ef = (int)q("SELECT COUNT(*) FROM email_queue WHERE status='failed'")->fetchColumn(); chk('Email', !mail_enabled() ? 'info' : ($ef ? 'warn' : 'ok'), !mail_enabled() ? 'Not set up (Admin > Settings).' : strtoupper(setting('mail_method')).' from '.setting('mail_from_email').($ef ? " · $ef failed message(s), see the Email log" : '')); if (mail_enabled() && setting('site_url') === '') chk('Site address for email links', 'warn', 'Not set. Links in emails use the address the request came from. Set it in Settings.'); } catch (Exception $e) {}
$cnt = ['ok'=>0,'warn'=>0,'fail'=>0]; foreach ($rows as $r) if (isset($cnt[$r[1]])) $cnt[$r[1]]++;
$cls = ['ok'=>'success','warn'=>'warning','fail'=>'danger','info'=>'secondary']; $lbl = ['ok'=>'OK','warn'=>'Warning','fail'=>'Problem','info'=>'Info'];
include 'inc/header.php'; ?>
<div class="d-flex justify-content-between align-items-center mb-3"><h1 class="h3 mb-0">System health</h1><a class="btn btn-outline-secondary btn-sm" href="health.php"><i class="bi bi-arrow-clockwise"></i> Re-run</a></div>
<div class="alert alert-<?=$cnt['fail']?'danger':($cnt['warn']?'warning':'success')?>"><?=$cnt['fail'] ? $cnt['fail'].' problem(s) need attention.' : ($cnt['warn'] ? 'Working, with '.$cnt['warn'].' warning(s).' : 'Everything looks good.')?></div>
<div class="card"><table class="table mb-0"><tbody><?php foreach ($rows as [$l,$s,$d]): ?><tr><td style="width:240px"><?=h($l)?></td><td style="width:110px"><span class="badge text-bg-<?=$cls[$s]?>"><?=$lbl[$s]?></span></td><td class="text-muted"><?=h($d)?></td></tr><?php endforeach; ?></tbody></table></div>
<?php include 'inc/footer.php'; ?>
