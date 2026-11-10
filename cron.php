<?php // Scheduled-task runner. Open cron.php?key=... from a cron job; no sign-in needed.
require 'inc/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
$k = (string)setting('cron_key', '');
if ($k === '' || !hash_equals($k, (string)($_GET['key'] ?? ''))) { http_response_code(403); exit("Forbidden\n"); }
if ($db_outdated) { http_response_code(503); exit("Database needs the upgrade page\n"); }
@set_time_limit(120);
if (!mail_enabled()) exit("Email is off. Nothing to do.\n");
$out = [];
foreach (['check_overdue_notify', 'mail_flush'] as $fn) { try { $fn(); $out[] = "$fn ok"; } catch (Throwable $e) { $out[] = "$fn failed"; } }
set_setting('cron_last_run', date('Y-m-d H:i:s'));
echo implode("\n", $out), "\n";
