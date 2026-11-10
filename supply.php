<?php require 'inc/bootstrap.php'; need('vendor'); $title='Supply';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $iid=(int)$_POST['item_id']; $note=trim($_POST['note']); $it=q("SELECT * FROM items WHERE id=?",[$iid])->fetch();
  if ($it && item_in_scope($it) && $it['type']!=='asset' && $it['min_qty']>0 && $it['qty']<=$it['min_qty']) {
    if ($note==='') flash('Please add a short note, for example "will order 5 Friday, delivery Monday".','danger');
    else { q("INSERT INTO alert_notes(item_id,user_id,kind,note) VALUES(?,?,?,?)",[$iid,me()['id'],'ack',mb_substr($note,0,250)]); audit('alert_ack',"#$iid"); flash('Acknowledged. Thank you.'); }
  }
  redirect('supply.php'.(!empty($_GET['category_id'])?'?category_id='.(int)$_GET['category_id']:''));
}
$cats = cats_select();
$w = "i.archived_at IS NULL AND i.status<>'retired' AND ".scope_sql('i'); $p = [];
if (($_GET['category_id'] ?? '') !== '') { $w .= ' AND i.category_id IN ('.implode(',', cat_descendants((int)$_GET['category_id'])).')'; }
$items = q("SELECT i.*,c.name cat FROM items i LEFT JOIN categories c ON c.id=i.category_id WHERE $w ORDER BY c.name,i.name", $p)->fetchAll();
$state = function($i){ if ($i['type']==='asset') return ['asset',null]; if ($i['min_qty']>0 && $i['qty']<=0) return ['out','danger']; if ($i['min_qty']>0 && $i['qty']<=$i['min_qty']) return ['low','danger']; return ['ok','success']; };
$low = 0; $lowIds = []; foreach ($items as $i) if (in_array($state($i)[0], ['low','out'], true)) { $low++; $lowIds[] = $i['id']; }
$nm = open_notes_map($lowIds);
include 'inc/header.php'; ?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2"><h1 class="h3 mb-0">Supply status</h1>
<form class="d-flex gap-2"><select class="form-select form-select-sm" name="category_id" onchange="this.form.submit()"><option value="">All categories</option><?php foreach($cats as $c): ?><option value="<?=$c['id']?>" <?=(($_GET['category_id']??'')==$c['id'])?'selected':''?>><?=h($c['name'])?></option><?php endforeach; ?></select></form></div>
<?php if($low): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill"></i> <strong><?=$low?></strong> item(s) are low or out of stock.</div><?php else: ?><div class="alert alert-success">Everything shown is stocked above its minimum.</div><?php endif; ?>
<div class="card"><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Item</th><th>Category</th><th>On hand</th><th>Minimum</th><th>Status</th></tr></thead><tbody>
<?php foreach($items as $i): [$s,$c] = $state($i); $n=$nm[$i['id']]??[]; $acked=false; foreach($n as $x) if($x['kind']==='ack') $acked=true; ?><tr class="<?=$c==='danger'?($acked?'table-warning':'table-danger'):''?>"><td><?=h($i['name'])?>
<?php foreach(array_slice($n,0,2) as $x): ?><div class="small text-muted"><?=$x['kind']==='ack'?'Acknowledged':'Note'?> <?=h(substr($x['created_at'],5,11))?> by <?=h($x['uname'])?>: <?=h($x['note'])?></div><?php endforeach; ?>
<?php if($c==='danger' && !$acked): ?><form method="post" class="d-flex gap-1 mt-1"><?=csrf_field()?><input type="hidden" name="item_id" value="<?=$i['id']?>"><input class="form-control form-control-sm" name="note" placeholder="Note, e.g. will order 5 Friday, delivery Monday" required><button class="btn btn-sm btn-danger text-nowrap">Acknowledge</button></form><?php endif; ?></td><td><?=h(cat_path($i['category_id']))?></td>
<td><?=$i['type']!=='asset' ? h($i['qty']+0).' '.h($i['unit']) : '—'?></td><td><?=$i['type']!=='asset' && $i['min_qty']>0 ? h($i['min_qty']+0) : '—'?></td>
<td><?php if($s==='low'): ?><span class="badge text-bg-danger"><i class="bi bi-exclamation-triangle-fill"></i> LOW</span><?php elseif($s==='out'): ?><span class="badge text-bg-danger"><i class="bi bi-x-octagon-fill"></i> OUT</span><?php elseif($s==='ok'): ?><span class="badge text-bg-success">OK</span><?php else: echo status_badge($i['status']); endif; ?><?=$acked?' <span class="badge text-bg-info">Acknowledged</span>':''?></td></tr><?php endforeach; if(!$items) echo '<tr><td colspan="5" class="text-center text-muted py-4">Nothing to show.</td></tr>'; ?></tbody></table></div></div>
<?php include 'inc/footer.php'; ?>
