<?php require 'inc/bootstrap.php'; need('viewer'); $title='Trash and undo';
$isAdmin = level() >= ROLES['admin'];
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $a = $_POST['action'] ?? ''; $it = ($i = (int)($_POST['item_id'] ?? 0)) ? q("SELECT * FROM items WHERE id=?", [$i])->fetch() : null;
  if ($a === 'undo_tx') { [$ok,$m] = tx_undo((int)($_POST['tx_id'] ?? 0)); flash($m, $ok ? 'success' : 'danger'); }
  elseif ($it && item_in_scope($it) && $it['deleted_at']) {
    if ($a === 'restore' && can('items_archive')) { item_untrash($it); flash('Restored "'.$it['name'].'".'); }
    elseif ($a === 'purge' && $isAdmin) { item_purge($it); flash('Deleted "'.$it['name'].'" permanently.'); }
  } elseif ($a === 'empty' && $isAdmin) {
    $n = 0; foreach (q("SELECT * FROM items i WHERE i.deleted_at IS NOT NULL AND ".scope_sql('i'))->fetchAll() as $t) { item_purge($t); $n++; } flash("Trash emptied ($n item".($n==1?'':'s').').');
  }
  redirect('trash.php');
}
$trash = q("SELECT i.*,l.name loc,u.name dby FROM items i LEFT JOIN locations l ON l.id=i.location_id LEFT JOIN users u ON u.id=i.deleted_by WHERE i.deleted_at IS NOT NULL AND ".scope_sql('i')." ORDER BY i.deleted_at DESC")->fetchAll();
$recent = undoable_recent(25); $days = trash_days();
include 'inc/header.php'; ?>
<h1 class="h4 mb-3">Trash and undo</h1>
<div class="card mb-4"><div class="card-header d-flex justify-content-between align-items-center"><span class="fw-bold"><i class="bi bi-trash"></i> Deleted items <span class="badge text-bg-secondary"><?=count($trash)?></span></span>
<?php if($isAdmin && $trash): ?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="empty"><button class="btn btn-sm btn-outline-danger" data-confirm="Permanently delete everything in the trash? This cannot be undone.">Empty trash</button></form><?php endif; ?></div>
<div class="table-responsive"><table class="table table-sm mb-0 align-middle"><tbody>
<?php foreach($trash as $t): $left=max(0,$days-(int)floor((time()-strtotime($t['deleted_at']))/86400)); ?><tr><td><a href="item_view.php?id=<?=$t['id']?>" class="fw-semibold"><?=h($t['name'])?></a><div class="small text-muted"><?=h($t['loc'])?> · <?=h($t['type'])?></div></td><td class="small text-muted">Deleted <?=h(substr($t['deleted_at'],0,16))?><?=$t['dby']?' by '.h($t['dby']):''?><br><?=$left?> day<?=$left==1?'':'s'?> left</td>
<td class="text-end text-nowrap"><?php if(can('items_archive')): ?><form method="post" class="d-inline"><?=csrf_field()?><input type="hidden" name="item_id" value="<?=$t['id']?>"><button class="btn btn-sm btn-outline-success" name="action" value="restore"><i class="bi bi-arrow-counterclockwise"></i> Restore</button></form><?php endif; ?>
<?php if($isAdmin): ?> <form method="post" class="d-inline"><?=csrf_field()?><input type="hidden" name="item_id" value="<?=$t['id']?>"><button class="btn btn-sm btn-outline-danger" name="action" value="purge" data-confirm="Permanently delete this item?">Delete forever</button></form><?php endif; ?></td></tr>
<?php endforeach; if(!$trash) echo '<tr><td class="text-muted p-3">The trash is empty. Deleted items wait here for '.$days.' days before they are removed for good.</td></tr>'; ?></tbody></table></div></div>
<div class="card"><div class="card-header fw-bold"><i class="bi bi-arrow-counterclockwise"></i> Recent stock changes you can undo</div>
<div class="table-responsive"><table class="table table-sm mb-0 align-middle"><tbody>
<?php foreach($recent as $t): ?><tr><td class="text-muted small"><?=h(substr($t['created_at'],0,16))?></td><td><a href="item_view.php?id=<?=$t['item_id']?>"><?=h($t['item'])?></a></td><td><?=h($t['type'])?> <?=h(fmt_q($t['qty']))?> <span class="text-muted">&rarr; <?=h(fmt_q($t['balance']))?></span></td><td class="small text-muted"><?=h($t['uname'])?> <?=h($t['note'])?></td>
<td class="text-end"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="undo_tx"><input type="hidden" name="tx_id" value="<?=$t['id']?>"><button class="btn btn-sm btn-outline-secondary py-0" data-confirm="Undo this change?">Undo</button></form></td></tr>
<?php endforeach; if(!$recent) echo '<tr><td class="text-muted p-3">Nothing to undo. Only the latest change to an item can be undone, for '.UNDO_WINDOW_DAYS.' days, by the person who made it or an admin.</td></tr>'; ?></tbody></table></div></div>
<?php include 'inc/footer.php'; ?>
