<?php
// Backups: database dump, full zip (database + photos/documents), automatic daily copies, and restore.
const BACKUP_HEADER = '-- GPIMS backup';
function backup_dir(){
  $d = dirname(__DIR__).'/backups';
  if (!is_dir($d)) { @mkdir($d, 0755); }
  if (is_dir($d)) {
    if (!is_file("$d/.htaccess")) @file_put_contents("$d/.htaccess", "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
    if (!is_file("$d/index.html")) @file_put_contents("$d/index.html", '');
  }
  return $d;
}
function sql_dump($out){
  global $pdo;
  fwrite($out, BACKUP_HEADER.' '.date('c')."\n-- App ".APP_VERSION.' db '.(int)db_version()."\n-- Restore from Admin > Backup (or phpMyAdmin Import into an empty database).\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
  $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
  foreach ($tables as $t) { $t = $t[0]; $cr = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM); fwrite($out, "DROP TABLE IF EXISTS `$t`;\n".$cr[1].";\n\n");
    $st = $pdo->query("SELECT * FROM `$t`", PDO::FETCH_NUM); $batch = []; $cols = null;
    while ($row = $st->fetch(PDO::FETCH_NUM)) { if ($cols === null) { $cols = []; for ($i = 0; $i < $st->columnCount(); $i++) $cols[] = '`'.$st->getColumnMeta($i)['name'].'`'; }
      $batch[] = '('.implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row)).')';
      if (count($batch) >= 200) { fwrite($out, "INSERT INTO `$t` (".implode(',', $cols).") VALUES\n".implode(",\n", $batch).";\n"); $batch = []; } }
    if ($batch) fwrite($out, "INSERT INTO `$t` (".implode(',', $cols).") VALUES\n".implode(",\n", $batch).";\n"); fwrite($out, "\n"); }
  fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
}
// Write a database backup into the server's backups folder. Returns the file name or ''.
function backup_make_file($prefix = 'gpims-db'){
  @set_time_limit(300); $d = backup_dir(); if (!is_dir($d) || !is_writable($d)) return '';
  $gz = function_exists('gzopen'); $name = $prefix.'-'.date('Ymd-His').($gz ? '.sql.gz' : '.sql');
  $o = @fopen(($gz ? 'compress.zlib://' : '').$d.'/'.$name, 'wb'); if (!$o) return '';
  sql_dump($o); fclose($o); return $name;
}
function backup_files(){
  $d = backup_dir(); $o = [];
  foreach (glob($d.'/*.sql*') ?: [] as $f) if (preg_match('/\.sql(\.gz)?$/', $f)) $o[] = ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)];
  usort($o, fn($a, $b) => $b['time'] <=> $a['time']); return $o;
}
function backup_name_ok($n){ return (bool)preg_match('/^[A-Za-z0-9._-]{1,80}\.sql(\.gz)?$/', $n) && is_file(backup_dir().'/'.$n); }
function backup_auto_run(){
  if (setting('auto_backup', '0') !== '1') return;
  $today = date('Y-m-d'); if (setting('auto_backup_on', '') === $today) return; set_setting('auto_backup_on', $today);
  $n = backup_make_file('gpims-auto'); if (!$n) { set_setting('auto_backup_error', date('Y-m-d H:i').' could not write the backups folder'); return; }
  set_setting('auto_backup_error', ''); set_setting('last_backup', date('Y-m-d H:i').' (automatic)');
  $keep = max(1, min(60, (int)setting('backup_keep', 7) ?: 7)); $auto = array_values(array_filter(backup_files(), fn($f) => strpos($f['name'], 'gpims-auto-') === 0));
  foreach (array_slice($auto, $keep) as $f) @unlink(backup_dir().'/'.$f['name']);
}
function backup_tables_allowed(){
  static $t = null; if ($t !== null) return $t; $t = [];
  if (preg_match_all('/CREATE TABLE IF NOT EXISTS\s+`?(\w+)`?/i', (string)@file_get_contents(dirname(__DIR__).'/schema.sql'), $m)) $t = array_flip($m[1]);
  return $t;
}
// Read a GPIMS .sql / .sql.gz dump and run it. Only statements our own backup writes are accepted.
function restore_sql($path){
  global $pdo; @set_time_limit(600); @ini_set('memory_limit', '512M');
  $h = @fopen('compress.zlib://'.$path, 'rb'); if (!$h) return [false, 'Could not open the backup file.'];
  $first = (string)fgets($h); if (strpos($first, BACKUP_HEADER) !== 0) { fclose($h); return [false, 'That is not a GPIMS backup file.']; }
  $second = (string)fgets($h); if (preg_match('/db (\d+)/', $second, $m) && (int)$m[1] > APP_DB_VERSION) { fclose($h); return [false, 'This backup comes from a newer version of GPIMS (database update '.$m[1].'). Upload the newer program files first.']; }
  $allowed = backup_tables_allowed(); if (!$allowed) { fclose($h); return [false, 'schema.sql is missing from the site, so a restore cannot be checked.']; }
  rewind($h); $buf = ''; $n = 0; $tabs = 0; $err = null;
  $run = function($sql) use ($pdo, $allowed, &$n, &$tabs) {
    if (preg_match('/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO)\s+`(\w+)`/i', $sql, $m)) { if (!isset($allowed[$m[2]])) throw new RuntimeException('unknown table '.$m[2]); if (stripos($m[1], 'CREATE') === 0) $tabs++; }
    elseif (!preg_match('/^SET (NAMES utf8mb4|FOREIGN_KEY_CHECKS=[01]);?$/i', trim($sql))) throw new RuntimeException('unexpected statement');
    $pdo->exec($sql); $n++;
  };
  try {
    while (($line = fgets($h)) !== false) {
      if ($buf === '' && (trim($line) === '' || strncmp($line, '--', 2) === 0)) continue;
      $buf .= $line; if (substr(rtrim($line, "\r\n"), -1) === ';') { $run($buf); $buf = ''; }
    }
    if (trim($buf) !== '') $run($buf);
  } catch (Throwable $e) { $err = $e->getMessage(); }
  fclose($h); try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $e) {}
  if ($err !== null) return [false, 'The restore stopped part-way ('.$err.'). Restore the safety copy that was saved just before it started, or re-upload a good backup.'];
  if ($tabs < 5) return [false, 'The file did not look like a full GPIMS backup.'];
  return [true, "$tabs tables restored."];
}
function restore_uploads_from_zip($zipPath){
  if (!class_exists('ZipArchive')) return 0;
  $z = new ZipArchive(); if ($z->open($zipPath) !== true) return 0; $dir = dirname(__DIR__).'/uploads'; if (!is_dir($dir)) @mkdir($dir, 0755); $c = 0;
  $ok = ['jpg','jpeg','png','gif','webp','pdf','txt','csv','doc','docx','xls','xlsx','odt','ods'];
  for ($i = 0; $i < $z->numFiles; $i++) { $nm = $z->getNameIndex($i); if (strpos($nm, 'uploads/') !== 0) continue; $b = basename($nm);
    if (!preg_match('/^[A-Za-z0-9._-]{1,120}$/', $b) || !in_array(strtolower(pathinfo($b, PATHINFO_EXTENSION)), $ok, true)) continue;
    $s = $z->getStream($nm); if (!$s) continue; $o = @fopen("$dir/$b", 'wb'); if (!$o) { fclose($s); continue; } stream_copy_to_stream($s, $o); fclose($o); fclose($s); $c++; }
  $z->close(); return $c;
}
// Whole restore: safety copy first, then database, then (for a full zip) the photos and documents.
function restore_run($path, $isZip){
  $safe = backup_make_file('gpims-before-restore'); if (!$safe) return [false, 'Could not save a safety copy in the backups folder, so nothing was changed. Check that the folder is writable.'];
  $sql = $path; $tmp = null;
  if ($isZip) {
    if (!class_exists('ZipArchive')) return [false, 'This server cannot open zip files. Upload the .sql file instead.'];
    $z = new ZipArchive(); if ($z->open($path) !== true || $z->locateName('database.sql') === false) return [false, 'That zip does not contain a GPIMS database.sql.'];
    $tmp = tempnam(sys_get_temp_dir(), 'gpr'); $s = $z->getStream('database.sql'); $o = fopen($tmp, 'wb'); stream_copy_to_stream($s, $o); fclose($o); fclose($s); $z->close(); $sql = $tmp;
  }
  [$ok, $msg] = restore_sql($sql); if ($tmp) @unlink($tmp);
  if (!$ok) return [false, $msg.' Safety copy: '.$safe];
  $files = $isZip ? restore_uploads_from_zip($path) : 0;
  try { run_upgrade(); } catch (Throwable $e) {}
  return [true, $msg.($isZip ? " $files photo/document file".($files == 1 ? '' : 's').' restored.' : '').' A safety copy of the previous data was saved as '.$safe.'.'];
}
