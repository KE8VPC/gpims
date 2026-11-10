<?php require 'inc/bootstrap.php'; need('viewer'); $title='Run check';
$id=(int)($_GET['id']??0); $cl=q("SELECT * FROM checklists WHERE id=? AND active=1",[$id])->fetch(); if(!$cl) redirect('checklists.php');
if(!checklist_can_run($cl)){ http_response_code(403); exit('Access denied.'); }
$veh=checklist_vehicle($cl);
$secs=q("SELECT * FROM checklist_sections WHERE checklist_id=? ORDER BY sort,id",[$id])->fetchAll(); $secById=[]; foreach($secs as $s) $secById[$s['id']]=$s;
$all=q("SELECT ci.*,i.name iname FROM checklist_items ci LEFT JOIN items i ON i.id=ci.item_id WHERE ci.checklist_id=? ORDER BY ci.sort,ci.id",[$id])->fetchAll();
$groups=[]; foreach($all as $l) $groups[(int)$l['section_id']][]=$l;
$order=[]; if(!empty($groups[0])) $order[]=0; foreach($secs as $s) if(!empty($groups[$s['id']])) $order[]=(int)$s['id'];
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $rows=[]; $issues=0; $needPhoto=[];
  foreach($order as $sid) foreach($groups[$sid] as $l) {
    $lid=(int)$l['id']; $note=trim($_POST['nt'][$lid]??''); $st='ok'; $actual=null; $auto='';
    switch($l['kind']){
      case 'count':
        $raw=trim($_POST['ct'][$lid]??''); $n=($raw!==''&&is_numeric($raw))?max(0,(float)$raw):null; $actual=$n===null?null:fmt_q($n);
        if($n===null){ $st='issue'; $auto='Count not entered'; }
        elseif($l['expect_qty']!==null && $n<(float)$l['expect_qty']-0.0001){ $st='missing'; $auto='Short by '.fmt_q((float)$l['expect_qty']-$n).' (expected '.fmt_q($l['expect_qty']).')'; $h=movement_hint($l['item_id']); if($h) $auto.='. '.$h; }
        elseif(!empty($_POST['fl'][$lid])) $st='issue';
        break;
      case 'level':
        $raw=$_POST['lv'][$lid]??''; if($raw===''||!ctype_digit((string)$raw)||(int)$raw>4){ $st='issue'; $auto='Level not checked'; }
        else { $actual=(string)(int)$raw; if($l['min_level']!==null && (int)$raw<(int)$l['min_level']){ $st='issue'; $auto='Level '.LEVELS[(int)$raw].', needs at least '.LEVELS[(int)$l['min_level']]; } elseif(!empty($_POST['fl'][$lid])) $st='issue'; }
        break;
      case 'value':
        $actual=mb_substr(trim($_POST['vl'][$lid]??''),0,60); if(!empty($_POST['fl'][$lid])) $st='issue'; break;
      default:
        $st=in_array($_POST['st'][$lid]??'ok',['ok','missing','issue'],true)?$_POST['st'][$lid]:'ok';
    }
    if($st!=='ok') $issues++;
    $full=trim($auto.($auto&&$note?' - ':'').$note);
    $ph=upload_images('ph',$lid);
    if(!empty($cl['require_photo']) && $st!=='ok' && !$ph) $needPhoto[]=$l['label'];
    $rows[]=['l'=>$l,'st'=>$st,'actual'=>$actual,'note'=>mb_substr($full,0,255),'photos'=>$ph];
  }
  if($needPhoto){ foreach($rows as $r) foreach($r['photos'] as $f) drop_upload($f); flash('This check needs a photo for every problem. Missing: '.implode(', ',array_slice($needPhoto,0,4)).(count($needPhoto)>4?'…':'').'. Your answers were not saved.','danger'); redirect("checklist_run.php?id=$id"); }
  q("INSERT INTO checklist_runs(checklist_id,user_id,notes,issues) VALUES(?,?,?,?)",[$id,me()['id'],mb_substr(trim($_POST['notes']??''),0,255),$issues]); $rid=(int)$pdo->lastInsertId();
  $failed=[];
  foreach($rows as $r){ $l=$r['l']; $sn=$l['section_id']?($secById[$l['section_id']]['name']??null):null;
    q("INSERT INTO checklist_run_items(run_id,label,status,note,kind,section,item_id,expected,actual,photo) VALUES(?,?,?,?,?,?,?,?,?,?)",[$rid,$l['label'],$r['st'],$r['note']!==''?$r['note']:null,$l['kind'],$sn,$l['item_id'],$l['kind']==='count'?$l['expect_qty']:null,$r['actual'],$r['photos'][0]??null]);
    $riid=(int)$pdo->lastInsertId(); foreach(array_slice($r['photos'],1) as $f) q("INSERT INTO check_photos(run_item_id,filename) VALUES(?,?)",[$riid,$f]);
    if($r['st']!=='ok') $failed[]=['label'=>($sn?$sn.': ':'').$l['label'],'status'=>$r['st'],'note'=>$r['note'],'photos'=>$r['photos']]; }
  q("UPDATE checklists SET overdue_notified_on=NULL WHERE id=?",[$id]);
  $ncl=$cl; if($veh) $ncl['name']=$veh['name'].' - '.$cl['name'];
  try { notify_checklist($ncl,$rid,$issues,$failed,trim($_POST['notes']??'')); } catch (Throwable $e) {}
  audit('checklist_run',"{$ncl['name']} issues=$issues"); flash("Check submitted ($issues issue".($issues===1?'':'s').").", $issues?'warning':'success'); redirect("checklist_runs.php?view=$rid");
}
include 'inc/header.php'; ?>
<?php if($veh): ?><a href="vehicle.php?id=<?=$veh['id']?>" class="small">&larr; <?=h($veh['name'])?></a><?php endif; ?>
<h1 class="h4 mt-1"><?=h($cl['name'])?></h1><?php if($cl['description']): ?><p class="text-muted small"><?=h($cl['description'])?></p><?php endif; ?>
<?php if(!$all): ?><div class="alert alert-warning">This check has no lines yet.</div><?php else: ?>
<form method="post" enctype="multipart/form-data" id="runForm" data-reqphoto="<?=!empty($cl['require_photo'])?1:0?>"><?=csrf_field()?>
<?php foreach($order as $sid): $s=$sid?$secById[$sid]:null; ?>
<div class="card mb-3"><div class="card-header d-flex align-items-center gap-2"><strong class="flex-grow-1"><?=h($s?$s['name']:'Other')?></strong>
<?php if($s&&$s['ref_photo']): ?><a href="uploads/<?=h($s['ref_photo'])?>" target="_blank" class="small text-nowrap" data-lb="uploads/<?=h($s['ref_photo'])?>" data-cap="How it should look: <?=h($s['name'])?>"><img src="uploads/<?=h($s['ref_photo'])?>" alt="How it should look" class="rounded me-1" style="height:40px;width:60px;object-fit:cover"> how it should look</a><?php endif; ?></div>
<ul class="list-group list-group-flush">
<?php foreach($groups[$sid] as $l): $lid=(int)$l['id']; ?><li class="list-group-item" data-lid="<?=$lid?>">
<div class="d-flex justify-content-between gap-2"><div class="fw-semibold"><?=h($l['label'])?><?php if($l['kind']==='count'): ?> <span class="badge text-bg-light border">expect <?=h(fmt_q($l['expect_qty']))?></span><?php elseif($l['kind']==='level'&&$l['min_level']!==null): ?> <span class="badge text-bg-light border">min <?=h(LEVELS[(int)$l['min_level']])?></span><?php endif; ?></div>
<?php if($l['ref_photo']): ?><a href="uploads/<?=h($l['ref_photo'])?>" target="_blank" data-lb="uploads/<?=h($l['ref_photo'])?>" data-cap="How it should look: <?=h($l['label'])?>"><img src="uploads/<?=h($l['ref_photo'])?>" alt="" class="rounded" style="height:36px;width:48px;object-fit:cover"></a><?php endif; ?></div>
<div class="mt-2">
<?php if($l['kind']==='count'): ?><div class="d-flex gap-2 align-items-center"><input type="number" inputmode="decimal" step="any" min="0" class="form-control" style="max-width:130px" name="ct[<?=$lid?>]" value="<?=h(fmt_q($l['expect_qty']))?>" aria-label="Count"><span class="small text-muted">counted</span>
<label class="form-check ms-2 mb-0"><input type="checkbox" class="form-check-input" name="fl[<?=$lid?>]" value="1"> <span class="form-check-label small">Problem</span></label></div>
<?php if($l['item_id']): $hint=movement_hint($l['item_id']); if($hint): ?><div class="small text-muted mt-1"><i class="bi bi-info-circle"></i> <?=h($hint)?></div><?php endif; endif; ?>
<?php elseif($l['kind']==='level'): ?><div class="btn-group w-100 flex-wrap"><?php foreach(LEVELS as $n=>$lb): ?><input type="radio" class="btn-check" name="lv[<?=$lid?>]" id="lv<?=$lid.$n?>" value="<?=$n?>" <?=$n===0?'required':''?>><label class="btn btn-outline-primary btn-sm" for="lv<?=$lid.$n?>"><?=h($lb)?></label><?php endforeach; ?></div>
<?php elseif($l['kind']==='value'): ?><div class="d-flex gap-2 align-items-center"><input class="form-control" name="vl[<?=$lid?>]" maxlength="60" placeholder="Reading / value"><label class="form-check mb-0 text-nowrap"><input type="checkbox" class="form-check-input" name="fl[<?=$lid?>]" value="1"> <span class="form-check-label small">Problem</span></label></div>
<?php else: ?><div class="btn-group"><?php foreach(['ok'=>['success','OK'],'missing'=>['warning','Missing'],'issue'=>['danger','Not OK']] as $v=>[$c,$lb]): ?><input type="radio" class="btn-check" name="st[<?=$lid?>]" id="s<?=$lid.$v?>" value="<?=$v?>" <?=$v==='ok'?'checked':''?>><label class="btn btn-outline-<?=$c?> btn-sm" for="s<?=$lid.$v?>"><?=$lb?></label><?php endforeach; ?></div><?php endif; ?>
</div>
<div class="d-flex gap-2 mt-2"><input class="form-control form-control-sm" name="nt[<?=$lid?>]" maxlength="200" placeholder="Note">
<button type="button" class="btn btn-sm btn-outline-secondary text-nowrap" data-addphoto="<?=$lid?>" title="Take a photo of a problem"><i class="bi bi-camera"></i><span class="d-none ms-1" id="pc<?=$lid?>"></span></button></div>
<div class="d-flex gap-1 flex-wrap mt-1" id="phs<?=$lid?>"></div>
</li><?php endforeach; ?></ul></div>
<?php endforeach; ?>
<input class="form-control mb-3" name="notes" maxlength="255" placeholder="Overall notes (optional)"><button class="btn btn-primary">Submit check</button> <a class="btn btn-link" href="<?=$veh?'vehicle.php?id='.$veh['id']:'checklists.php'?>">Cancel</a></form><?php endif; ?>
<?php include 'inc/footer.php'; ?>
