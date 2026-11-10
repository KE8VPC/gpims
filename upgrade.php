<?php require 'inc/bootstrap.php'; need('admin'); $title = 'Upgrade'; $log = []; $err = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  try { $log = run_upgrade(); audit('db_upgrade', 'to v'.db_version()); }
  catch (Exception $e) { $err = $e->getMessage(); }
}
$cur = db_version(); $pending = [];
foreach (migrations() as $v => $m) if ($v > $cur) $pending[$v] = $m[0];
include 'inc/header.php'; ?>
<h1 class="h3 mb-3">Upgrade</h1>
<div class="card mb-3" style="max-width:640px"><div class="card-body">
<div class="d-flex justify-content-between"><span>Application version</span><strong><?=h(APP_VERSION)?></strong></div>
<div class="d-flex justify-content-between"><span>Database version</span><strong><?=h($cur)?> <span class="text-muted fw-normal">(needs <?=APP_DB_VERSION?>)</span></strong></div></div></div>
<?php if ($err): ?><div class="alert alert-danger" style="max-width:640px">Update failed: <?=h($err)?><br><small>Nothing further was applied. Send me this message and I will fix it.</small></div><?php endif; ?>
<?php foreach ($log as $l): ?><div class="alert alert-success py-2" style="max-width:640px"><?=h($l)?></div><?php endforeach; ?>
<?php if ($pending): ?>
<div class="alert alert-warning" style="max-width:640px"><strong>Updates waiting:</strong><ul class="mb-0"><?php foreach ($pending as $v => $n): ?><li><?=h($n)?></li><?php endforeach; ?></ul></div>
<form method="post"><?=csrf_field()?><button class="btn btn-primary">Apply updates</button></form>
<?php else: ?><div class="alert alert-success" style="max-width:640px">The database is up to date.</div><a class="btn btn-outline-primary" href="health.php">Run system health check</a> <a class="btn btn-link" href="index.php">Dashboard</a><?php endif; ?>
<?php include 'inc/footer.php'; ?>
