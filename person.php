<?php require 'inc/bootstrap.php'; need('viewer');
if (!(can('people_manage') || can('checkout'))) { http_response_code(403); exit('Access denied.'); }
field_post(); $id=(int)($_GET['id']??0); $p=q("SELECT p.*,u.username FROM people p LEFT JOIN users u ON u.id=p.user_id WHERE p.id=?",[$id])->fetch(); if(!$p) redirect('people.php');
$rows=q("SELECT c.*,i.name item,i.id iid,u.name issuer FROM checkouts c JOIN items i ON i.id=c.item_id LEFT JOIN users u ON u.id=c.user_id WHERE c.person_id=? AND ".scope_sql('i')." ORDER BY c.id DESC LIMIT 200",[$id])->fetchAll();
$out=array_filter($rows,fn($r)=>$r['in_at']===null); $title=$p['name']; $heldRows=field_rows(['person'=>$id]);
include 'inc/header.php'; ?>
<a href="people.php">&larr; People</a><h1 class="h3 mt-2 mb-0"><?=h($p['name'])?> <?=$p['active']?'':'<span class="badge text-bg-secondary fs-6">inactive</span>'?></h1>
<div class="text-muted mb-3"><?=h($p['department'])?> <?=$p['badge_code']?'· badge '.h($p['badge_code']):''?> <?=$p['username']?'· login '.h($p['username']):''?></div>
<div class="row g-4"><div class="col-lg-5"><h2 class="h6 text-muted text-uppercase d-flex justify-content-between align-items-center">Has out now (<?=count($heldRows)?>)<?php if($heldRows && can('checkout')): ?><a class="btn btn-sm btn-outline-secondary text-capitalize" href="loan_sheet.php?person=<?=$id?>"><i class="bi bi-printer"></i> Loan sheet</a><?php endif; ?></h2><?=field_table($heldRows,'holder',true)?></div>
<div class="col-lg-7"><div class="card"><div class="card-header fw-bold">History</div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Item</th><th>Out</th><th>Back</th><th>Issued by</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr><td><a href="item_view.php?id=<?=$r['iid']?>"><?=h($r['item'])?></a></td><td class="text-muted"><?=h(substr($r['out_at'],0,16))?></td><td class="text-muted"><?=$r['in_at']?h(substr($r['in_at'],0,16)):'<span class="badge text-bg-warning">out</span>'?></td><td><?=h($r['issuer'])?></td></tr><?php endforeach; if(!$rows) echo '<tr><td colspan="4" class="text-muted p-3">No history yet.</td></tr>'; ?></tbody></table></div></div></div></div>
<?php include 'inc/footer.php'; ?>
