<?php require 'inc/bootstrap.php'; need('viewer'); $title='Check history';
$view=(int)($_GET['view']??0); $clf=(int)($_GET['cl']??0);
if ($view) {
  $run=q("SELECT r.*,c.name cname,c.vehicle_id,c.id cid,u.name uname FROM checklist_runs r JOIN checklists c ON c.id=r.checklist_id LEFT JOIN users u ON u.id=r.user_id WHERE r.id=?",[$view])->fetch();
  if($run && !checklist_can_view(['vehicle_id'=>$run['vehicle_id']])){ http_response_code(403); exit('Access denied.'); }
  if($run){ $ri=q("SELECT * FROM checklist_run_items WHERE run_id=? ORDER BY id",[$view])->fetchAll(); $veh=checklist_vehicle(['vehicle_id'=>$run['vehicle_id']]);
    $phm=run_photos(array_column($ri,'id')); $refm=[];
    foreach(q("SELECT ci.label,ci.ref_photo,s.name sname,s.ref_photo sref FROM checklist_items ci LEFT JOIN checklist_sections s ON s.id=ci.section_id WHERE ci.checklist_id=?",[$run['cid']])->fetchAll() as $x) $refm[($x['sname']??'').'|'.$x['label']]=$x['ref_photo']?:$x['sref'];
    $nph=array_sum(array_map('count',$phm)); }
} else {
  $f=$clf?"WHERE r.checklist_id=".$clf:""; $runs=[];
  foreach(q("SELECT r.*,c.name cname,c.vehicle_id,v.name vname,u.name uname,(SELECT COUNT(*) FROM checklist_run_items ri WHERE ri.run_id=r.id AND ri.photo IS NOT NULL)+(SELECT COUNT(*) FROM check_photos cp JOIN checklist_run_items ri2 ON ri2.id=cp.run_item_id WHERE ri2.run_id=r.id) nphotos FROM checklist_runs r JOIN checklists c ON c.id=r.checklist_id LEFT JOIN vehicles v ON v.id=c.vehicle_id LEFT JOIN users u ON u.id=r.user_id $f ORDER BY r.id DESC LIMIT 200")->fetchAll() as $r) if(checklist_can_view($r)) $runs[]=$r;
}
include 'inc/header.php';
if($view && $run): $sect=null; ?>
<a href="<?=$veh?'vehicle.php?id='.$veh['id']:'checklist_runs.php'?>" class="small">&larr; <?=$veh?h($veh['name']):'All runs'?></a><h1 class="h4 mt-1"><?=h($run['cname'])?> <?=$run['issues']?'<span class="badge text-bg-danger fs-6">'.$run['issues'].' issue(s)</span>':'<span class="badge text-bg-success fs-6">All OK</span>'?></h1>
<p class="text-muted small"><?=h(date('M j, Y g:ia',strtotime($run['created_at'])))?> by <?=h($run['uname'])?> <?=$run['notes']?'· '.h($run['notes']):''?><?=$nph?' · <i class="bi bi-camera"></i> '.$nph.' photo'.($nph===1?'':'s'):''?></p>
<div class="card"><ul class="list-group list-group-flush">
<?php foreach($ri as $r): $c=['ok'=>'success','missing'=>'warning','issue'=>'danger'][$r['status']];
  if($r['section']!==$sect){ $sect=$r['section']; echo '<li class="list-group-item bg-body-tertiary fw-bold small">'.h($sect??'Other').'</li>'; }
  $val=''; if($r['kind']==='count'&&$r['actual']!==null) $val='Counted '.h($r['actual']).' of '.h(fmt_q($r['expected'])); elseif($r['kind']==='level'&&$r['actual']!==null) $val=h(LEVELS[(int)$r['actual']]??$r['actual']); elseif($r['kind']==='value'&&$r['actual']!=='') $val=h($r['actual']); ?>
<li class="list-group-item"><div class="d-flex justify-content-between gap-2"><div><?=h($r['label'])?><?php if($val): ?> <span class="text-muted small">· <?=$val?></span><?php endif; ?><?php if($r['item_id']): ?> <a class="small" href="item_view.php?id=<?=$r['item_id']?>"><i class="bi bi-link-45deg"></i></a><?php endif; ?></div><span class="badge text-bg-<?=$c?> align-self-start"><?=h(['ok'=>'OK','missing'=>'Short/missing','issue'=>'Not OK'][$r['status']])?></span></div>
<?php if($r['note']): ?><div class="small text-muted"><?=h($r['note'])?></div><?php endif; ?>
<?php $pp=$phm[$r['id']]??[]; $ref=$refm[($r['section']??'').'|'.$r['label']]??null; if($pp): ?><div class="d-flex gap-1 flex-wrap mt-1"><?php foreach($pp as $n=>$f): ?><a href="uploads/<?=h($f)?>" target="_blank" data-lb="uploads/<?=h($f)?>"<?=$ref?' data-ref="uploads/'.h($ref).'"':''?> data-cap="<?=h(($r['section']?$r['section'].': ':'').$r['label'])?>"><img src="uploads/<?=h($f)?>" alt="Issue photo <?=$n+1?>" class="rounded" style="height:90px;max-width:100%;object-fit:cover"></a><?php endforeach; ?><?php if($ref): ?><span class="small text-muted align-self-end">tap a photo to compare with how it should look</span><?php endif; ?></div><?php endif; ?></li>
<?php endforeach; ?></ul></div>
<?php else: ?><h1 class="h4 mb-3">Check history</h1><div class="card"><table class="table table-hover mb-0"><tbody><?php foreach($runs as $r): ?><tr><td><a href="?view=<?=$r['id']?>"><?=h($r['vname']?$r['vname'].' - ':'')?><?=h($r['cname'])?></a></td><td class="text-muted small"><?=h(date('M j g:ia',strtotime($r['created_at'])))?></td><td class="small"><?=h($r['uname'])?><?=$r['nphotos']?' <span class="text-muted" title="'.$r['nphotos'].' photo(s)"><i class="bi bi-camera"></i> '.$r['nphotos'].'</span>':''?></td><td><?=$r['issues']?'<span class="badge text-bg-danger">'.$r['issues'].' issue(s)</span>':'<span class="badge text-bg-success">All OK</span>'?></td></tr><?php endforeach; if(!$runs) echo '<tr><td class="text-muted p-3">No runs yet.</td></tr>'; ?></tbody></table></div><?php endif; ?>
<?php include 'inc/footer.php'; ?>
