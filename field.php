<?php require 'inc/bootstrap.php'; need('viewer'); if (me()['role']==='vendor') redirect('supply.php'); field_post(); $title='Out now';
$group = in_array($_GET['by'] ?? '', ['incident','item'], true) ? $_GET['by'] : 'holder';
$rows = field_rows(); $fq = trim($_GET['q'] ?? '');
if ($fq !== '') $rows = array_values(array_filter($rows, fn($r) => stripos($r['holder'].' '.$r['item'].' '.($r['incident'] ?? ''), $fq) !== false));
$keyOf = fn($r) => $group === 'incident' ? ($r['incident'] ?: 'No event') : ($group === 'item' ? $r['item'] : $r['holder']);
$gk = []; foreach ($rows as $r) $gk[$keyOf($r)] = 1; ksort($gk, SORT_NATURAL | SORT_FLAG_CASE); $gk = array_keys($gk); $GP = 25;
$pages = max(1, (int)ceil(count($gk) / $GP)); $pg = max(1, min($pages, (int)($_GET['pg'] ?? 1))); $show = array_flip(array_slice($gk, ($pg - 1) * $GP, $GP));
$shown = $pages > 1 ? array_values(array_filter($rows, fn($r) => isset($show[$keyOf($r)]))) : $rows;
$lnk = fn($pgn) => 'field.php?'.http_build_query(array_filter(['by' => $group === 'holder' ? null : $group, 'q' => $fq ?: null, 'pg' => $pgn > 1 ? $pgn : null]));
include 'inc/header.php'; ?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"><h1 class="h3 mb-0">Out now <span class="badge text-bg-secondary fs-6"><?=count($rows)?></span></h1>
<div class="btn-group btn-group-sm" role="group" aria-label="Group by"><?php foreach(['holder'=>'By person','incident'=>'By event','item'=>'By item'] as $k=>$l): ?><a class="btn btn-outline-secondary<?=$group===$k?' active':''?>" href="field.php?by=<?=$k?>"><?=$l?></a><?php endforeach; ?></div></div>
<form class="mb-3" method="get"><input type="hidden" name="by" value="<?=h($group)?>"><div class="input-group" style="max-width:420px"><input class="form-control" name="q" value="<?=h($fq)?>" placeholder="Filter by person, item or event"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button></div></form>
<?=field_table($shown, $group)?>
<?php if($pages>1): ?><nav class="mt-3"><ul class="pagination pagination-sm justify-content-center flex-wrap"><li class="page-item<?=$pg<=1?' disabled':''?>"><a class="page-link" href="<?=h($lnk($pg-1))?>">&laquo; Prev</a></li><?php for($n=1;$n<=$pages;$n++): ?><li class="page-item<?=$n===$pg?' active':''?>"><a class="page-link" href="<?=h($lnk($n))?>"><?=$n?></a></li><?php endfor; ?><li class="page-item<?=$pg>=$pages?' disabled':''?>"><a class="page-link" href="<?=h($lnk($pg+1))?>">Next &raquo;</a></li></ul><div class="text-center small text-muted"><?=count($gk)?> groups, <?=$GP?> per page</div></nav><?php endif; ?>
<?php include 'inc/footer.php'; ?>
