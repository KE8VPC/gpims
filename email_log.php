<?php require 'inc/bootstrap.php'; need('admin'); $title='Email log';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (($_POST['action'] ?? '') === 'retry') { q("UPDATE email_queue SET status='pending',attempts=0 WHERE status='failed'"); flash('Failed messages will be retried.'); mail_queue_kick(); }
  if (($_POST['action'] ?? '') === 'purge') { q("DELETE FROM email_queue WHERE status<>'pending' AND created_at < (NOW() - INTERVAL 30 DAY)"); flash('Old entries removed.'); }
  redirect('email_log.php');
}
function mail_queue_kick(){ static $r=false; if(!$r){ $r=true; register_shutdown_function('mail_flush'); } }
$rows = q("SELECT * FROM email_queue ORDER BY id DESC LIMIT 200")->fetchAll();
$cnt = ['pending'=>0,'sent'=>0,'failed'=>0]; foreach (q("SELECT status,COUNT(*) n FROM email_queue GROUP BY status")->fetchAll() as $c) $cnt[$c['status']] = (int)$c['n'];
include 'inc/header.php'; ?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2"><h1 class="h3 mb-0">Email log</h1>
<form method="post" class="d-flex gap-2"><?=csrf_field()?><button class="btn btn-sm btn-outline-secondary" name="action" value="retry">Retry failed</button><button class="btn btn-sm btn-outline-secondary" name="action" value="purge">Clear entries older than 30 days</button></form></div>
<?php if(!mail_enabled()): ?><div class="alert alert-warning">Email is not configured. Set it up in <a href="settings.php">Settings</a>.</div><?php endif; ?>
<div class="small text-muted mb-2"><?=$cnt['sent']?> sent · <?=$cnt['pending']?> waiting · <span class="<?=$cnt['failed']?'text-danger fw-bold':''?>"><?=$cnt['failed']?> failed</span></div>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0 small"><thead><tr><th>When</th><th>To</th><th>Subject</th><th>Type</th><th>Status</th></tr></thead><tbody>
<?php foreach($rows as $r): $c=['sent'=>'success','pending'=>'warning','failed'=>'danger'][$r['status']]; ?><tr><td class="text-muted"><?=h(substr($r['created_at'],5,11))?></td><td><?=h($r['to_email'])?></td><td><?=h($r['subject'])?></td><td class="text-muted"><?=h($r['event'])?></td><td><span class="badge text-bg-<?=$c?>"><?=h($r['status'])?></span><?=$r['last_error']?'<div class="text-danger">'.h($r['last_error']).'</div>':''?></td></tr><?php endforeach; if(!$rows) echo '<tr><td colspan="5" class="text-muted p-3">Nothing sent yet.</td></tr>'; ?></tbody></table></div></div>
<?php include 'inc/footer.php'; ?>
