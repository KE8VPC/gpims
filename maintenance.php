<?php require 'inc/bootstrap.php'; need('viewer'); if (me()['role']==='vendor') redirect('supply.php'); $title='Maintenance';
function m_back($d){ $b = $_POST['back'] ?? ''; return (preg_match('/^[a-z_]+\.php(\?[a-z0-9_=&]*)?$/i', $b)) ? $b : $d; }
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!can('maintenance_manage')) { http_response_code(403); exit('Access denied.'); }
  $a = $_POST['action'] ?? ''; $back = m_back('maintenance.php');
  if ($a === 'add') {
    $it = item_pick_resolve('item_q');
    if (!$it) flash('Pick an item from the list.', 'danger');
    else { [$ok, $m] = maint_add($it, $_POST['title'] ?? '', $_POST['every_days'] ?? 30, $_POST['first_due'] ?? '', $_POST['notes'] ?? ''); flash($m, $ok ? 'success' : 'danger'); }
  } elseif ($a === 'done') { [$ok, $m] = maint_complete((int)$_POST['id'], trim($_POST['note'] ?? ''), $_POST['done_on'] ?? null); flash($m, $ok ? 'success' : 'danger');
  } elseif ($a === 'delete') {
    $t = q("SELECT t.*,i.name iname,i.category_id,i.location_id FROM maintenance_tasks t JOIN items i ON i.id=t.item_id WHERE t.id=?", [(int)$_POST['id']])->fetch();
    if ($t && item_in_scope($t)) { q("DELETE FROM maintenance_tasks WHERE id=?", [$t['id']]); log_item_event($t['item_id'], 'maintenance_removed', $t['title']); audit('maint_del', "#{$t['item_id']} {$t['title']}"); flash('Task removed. Past completions are kept in the item history.'); }
  }
  redirect($back);
}
$itemF = (int)($_GET['item'] ?? 0); $view = $_GET['view'] ?? 'all';
$tasks = maint_tasks(['item' => $itemF ?: null, 'due' => $view === 'due' ? 1 : null]);
$recent = q("SELECT l.*,i.name item,u.name uname FROM maintenance_log l JOIN items i ON i.id=l.item_id LEFT JOIN users u ON u.id=l.user_id WHERE ".scope_sql('i').($itemF ? ' AND l.item_id='.$itemF : '')." ORDER BY l.id DESC LIMIT 15")->fetchAll();
$can = can('maintenance_manage'); $itemRow = $itemF ? item_by_id($itemF) : null;
include 'inc/header.php'; ?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"><h1 class="h3 mb-0">Maintenance<?=$itemRow?' <small class="text-muted fs-6">&middot; '.h($itemRow['name']).'</small>':''?></h1>
<div class="d-flex gap-2"><div class="btn-group btn-group-sm"><a class="btn btn-<?=$view==='due'?'outline-secondary':'secondary'?>" href="maintenance.php<?=$itemF?'?item='.$itemF:''?>">All tasks</a><a class="btn btn-<?=$view==='due'?'secondary':'outline-secondary'?>" href="maintenance.php?view=due<?=$itemF?'&item='.$itemF:''?>">Due soon</a></div>
<?php if($can): ?><button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#addBox"><i class="bi bi-plus-lg"></i> Add task</button><?php endif; ?></div></div>
<?php if($can): ?><div class="collapse<?=$itemRow&&!$tasks?' show':''?> mb-3" id="addBox"><form method="post" class="card card-body"><?=csrf_field()?><input type="hidden" name="action" value="add">
<div class="row g-2"><div class="col-md-4"><label class="form-label small mb-0">Item</label><?=item_pick('item_q', null, $itemF)?></div>
<div class="col-md-4"><label class="form-label small mb-0">Task</label><input class="form-control" name="title" placeholder="e.g. Swap battery, Oil change, Calibrate" maxlength="150" required></div>
<div class="col-6 col-md-2"><label class="form-label small mb-0">Repeats</label><select class="form-select" name="every_days"><?php foreach(MAINT_PRESETS as $d=>$l): ?><option value="<?=$d?>"><?=$l?></option><?php endforeach; ?></select></div>
<div class="col-6 col-md-2"><label class="form-label small mb-0">First due</label><input class="form-control" type="date" name="first_due"></div>
<div class="col-12"><input class="form-control" name="notes" placeholder="Notes (optional)" maxlength="255"></div>
<div class="col-12"><button class="btn btn-primary">Add task</button> <span class="form-text">Leave "First due" blank to start one interval from today. Each time you mark it done, the next due date moves forward.</span></div></div></form></div><?php endif; ?>
<?php if(!$tasks): ?><div class="text-muted"><?=$view==='due'?'Nothing is due soon.':'No maintenance tasks yet. Add one for anything that needs regular service: battery swaps, oil changes, calibration, inspections.'?></div>
<?php else: ?><div class="card"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Item</th><th>Task</th><th>Repeats</th><th>Status</th><th>Last done</th><th></th></tr></thead><tbody>
<?php foreach($tasks as $t): [$st,,$badge]=maint_state($t); ?><tr><td><a href="item_view.php?id=<?=$t['item_id']?>"><?=h($t['item'])?></a></td><td><?=h($t['title'])?><?=$t['notes']?'<div class="small text-muted">'.h($t['notes']).'</div>':''?></td><td class="small"><?=h(maint_label($t['every_days']))?></td><td><?=$badge?><div class="small text-muted"><?=h($t['next_due'])?></div></td><td class="small text-muted"><?=h($t['last_done'] ?: 'never')?></td>
<td class="text-end"><?php if($can): ?><form method="post" class="d-inline-flex gap-1 align-items-center"><?=csrf_field()?><input type="hidden" name="id" value="<?=$t['id']?>"><input type="hidden" name="back" value="maintenance.php<?=$view==='due'?'?view=due':''?>"><input class="form-control form-control-sm" name="note" placeholder="Note" style="width:9rem" maxlength="255"><button class="btn btn-sm btn-success" name="action" value="done">Done</button><button class="btn btn-sm btn-outline-danger" name="action" value="delete" data-confirm="Remove this recurring task?"><i class="bi bi-trash"></i></button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
<?php if($recent): ?><h2 class="h6 text-muted text-uppercase mt-4">Recently done</h2><div class="card"><table class="table table-sm small mb-0"><tbody><?php foreach($recent as $l): ?><tr><td class="text-muted"><?=h($l['done_on'])?></td><td><a href="item_view.php?id=<?=$l['item_id']?>"><?=h($l['item'])?></a></td><td><?=h($l['title'])?></td><td class="text-muted"><?=h($l['uname'])?></td><td class="text-muted"><?=h($l['note'])?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php include 'inc/footer.php'; ?>
