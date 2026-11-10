<?php require 'inc/bootstrap.php'; need('viewer'); if (me()['role']==='vendor') redirect('supply.php');
$id = (int)($_GET['id'] ?? 0); $it = item_by_id($id); if (!$it) redirect('items.php');
$title = $it['name'].' history'; $kind = $_GET['kind'] ?? ''; if (!isset(TL_KINDS[$kind])) $kind = '';
$all = item_timeline($id); $cnt = array_count_values(array_column($all, 'kind'));
$ev = $kind ? array_values(array_filter($all, fn($e) => $e['kind'] === $kind)) : $all;
include 'inc/header.php'; ?>
<a href="item_view.php?id=<?=$id?>">&larr; <?=h($it['name'])?></a>
<h1 class="h3 mt-2 mb-1">History <small class="text-muted fs-6">&middot; <?=h($it['name'])?></small></h1>
<div class="text-muted small mb-3">Everything recorded for this item: stock moves, loans, edits, maintenance, checks, recounts, reservations and documents. Added <?=h(substr($it['created_at'],0,10))?>.</div>
<div class="d-flex flex-wrap gap-1 mb-3"><a class="btn btn-sm btn-<?=$kind===''?'secondary':'outline-secondary'?>" href="item_timeline.php?id=<?=$id?>">All <span class="badge text-bg-light"><?=count($all)?></span></a>
<?php foreach(TL_KINDS as $k=>$l): if(empty($cnt[$k])) continue; ?><a class="btn btn-sm btn-<?=$kind===$k?'secondary':'outline-secondary'?>" href="item_timeline.php?id=<?=$id?>&kind=<?=$k?>"><?=h($l)?> <span class="badge text-bg-light"><?=$cnt[$k]?></span></a><?php endforeach; ?></div>
<?php if(!$ev): ?><div class="text-muted">Nothing recorded yet.</div><?php else: $day = ''; ?><div class="card"><div class="list-group list-group-flush">
<?php foreach($ev as $e): $d = substr($e['ts'], 0, 10); if ($d !== $day): $day = $d; ?><div class="list-group-item bg-body-tertiary py-1 small fw-bold text-muted"><?=h(date('D, M j, Y', strtotime($d)))?></div><?php endif; ?>
<div class="list-group-item d-flex gap-2"><i class="bi <?=h($e['icon'])?> text-muted mt-1"></i><div class="flex-grow-1"><div><?=h($e['text'])?></div><?php if($e['detail']!==''): ?><div class="small text-muted"><?=h($e['detail'])?></div><?php endif; ?></div><div class="small text-muted text-end text-nowrap"><?=h(substr($e['ts'],11,5))?><?=$e['user']?'<br>'.h($e['user']):''?></div></div>
<?php endforeach; ?></div></div><?php endif; ?>
<?php include 'inc/footer.php'; ?>
