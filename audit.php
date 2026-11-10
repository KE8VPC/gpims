<?php require 'inc/bootstrap.php'; need('admin'); $title='Audit log';
$qs = trim($_GET['q'] ?? ''); $uid = (int)($_GET['user'] ?? 0); $act = trim($_GET['action'] ?? ''); $from = trim($_GET['from'] ?? ''); $to = trim($_GET['to'] ?? '');
$w = '1=1'; $p = [];
if ($qs !== '') { $w .= ' AND (a.details LIKE ? OR a.action LIKE ?)'; $p[] = '%'.addcslashes($qs, '%_\\').'%'; $p[] = '%'.addcslashes($qs, '%_\\').'%'; }
if ($uid) { $w .= ' AND a.user_id=?'; $p[] = $uid; }
if ($act !== '') { $w .= ' AND a.action=?'; $p[] = $act; }
if (valid_date($from)) { $w .= ' AND a.created_at>=?'; $p[] = $from.' 00:00:00'; }
if (valid_date($to)) { $w .= ' AND a.created_at<=?'; $p[] = $to.' 23:59:59'; }
$from_sql = "FROM audit_log a LEFT JOIN users u ON u.id=a.user_id WHERE $w";
if (($_GET['csv'] ?? '') === '1') {
  header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="audit-log-'.date('Y-m-d').'.csv"');
  $o = fopen('php://output', 'w'); fputcsv($o, ['When', 'User', 'Action', 'Details']);
  $safe = fn($v) => (is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'".$v : $v;
  foreach (q("SELECT a.created_at,u.username,a.action,a.details $from_sql ORDER BY a.id DESC LIMIT 20000", $p) as $r) fputcsv($o, [$r['created_at'], $safe((string)$r['username']), $r['action'], $safe((string)$r['details'])]);
  exit;
}
$per = 100; $total = (int)q("SELECT COUNT(*) $from_sql", $p)->fetchColumn(); $pages = max(1, (int)ceil($total / $per)); $pg = max(1, min($pages, (int)($_GET['page'] ?? 1)));
$rows = q("SELECT a.*,u.username $from_sql ORDER BY a.id DESC LIMIT $per OFFSET ".(($pg - 1) * $per), $p)->fetchAll();
$users = q("SELECT id,username FROM users ORDER BY username")->fetchAll(); $acts = q("SELECT DISTINCT action FROM audit_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$qsBase = http_build_query(array_filter(['q' => $qs, 'user' => $uid ?: '', 'action' => $act, 'from' => $from, 'to' => $to], fn($v) => $v !== '' && $v !== null));
$pl = fn($n) => 'audit.php?'.$qsBase.($qsBase ? '&' : '').'page='.$n;
include 'inc/header.php'; ?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"><h1 class="h3 mb-0">Audit log <small class="text-muted fs-6">(<?=number_format($total)?> entr<?=$total===1?'y':'ies'?>)</small></h1><a class="btn btn-sm btn-outline-secondary" href="audit.php?<?=h($qsBase)?><?=$qsBase?'&':''?>csv=1"><i class="bi bi-download"></i> CSV</a></div>
<form method="get" class="row g-2 mb-3"><div class="col-12 col-md-3"><input class="form-control form-control-sm" name="q" value="<?=h($qs)?>" placeholder="Search details…"></div>
<div class="col-6 col-md-2"><select class="form-select form-select-sm" name="user"><option value="">Any user</option><?php foreach($users as $u): ?><option value="<?=$u['id']?>"<?=$uid===(int)$u['id']?' selected':''?>><?=h($u['username'])?></option><?php endforeach; ?></select></div>
<div class="col-6 col-md-2"><select class="form-select form-select-sm" name="action"><option value="">Any action</option><?php foreach($acts as $x): ?><option<?=$act===$x?' selected':''?>><?=h($x)?></option><?php endforeach; ?></select></div>
<div class="col-6 col-md-2"><input class="form-control form-control-sm" type="date" name="from" value="<?=h($from)?>" title="From"></div><div class="col-6 col-md-2"><input class="form-control form-control-sm" type="date" name="to" value="<?=h($to)?>" title="To"></div>
<div class="col-12 col-md-1 d-flex gap-1"><button class="btn btn-sm btn-primary flex-grow-1">Go</button><?php if($qsBase): ?><a class="btn btn-sm btn-outline-secondary" href="audit.php" title="Clear">&times;</a><?php endif; ?></div></form>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>When</th><th>User</th><th>Action</th><th>Details</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr><td class="text-muted text-nowrap"><?=h($r['created_at'])?></td><td><?=h($r['username'])?></td><td><span class="badge text-bg-light border"><?=h($r['action'])?></span></td><td><?=h($r['details'])?></td></tr><?php endforeach; if(!$rows): ?><tr><td colspan="4" class="text-center text-muted py-4">No matching entries.</td></tr><?php endif; ?></tbody></table></div></div>
<?php if($pages>1): ?><nav class="mt-3 d-flex justify-content-center gap-2 align-items-center small"><a class="btn btn-sm btn-outline-secondary<?=$pg<=1?' disabled':''?>" href="<?=h($pl($pg-1))?>">&laquo; Newer</a><span>Page <?=$pg?> of <?=$pages?></span><a class="btn btn-sm btn-outline-secondary<?=$pg>=$pages?' disabled':''?>" href="<?=h($pl($pg+1))?>">Older &raquo;</a></nav><?php endif; ?>
<?php include 'inc/footer.php'; ?>
