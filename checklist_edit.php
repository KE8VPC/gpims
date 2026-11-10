<?php require 'inc/bootstrap.php'; need('viewer'); $title='Edit check';
$id=(int)($_GET['id']??0); $cl=q("SELECT * FROM checklists WHERE id=?",[$id])->fetch(); if(!$cl) redirect('checklists.php');
if(!checklist_can_edit($cl)){ http_response_code(403); exit('Access denied.'); }
$veh=checklist_vehicle($cl); $back=$veh?"vehicle.php?id={$veh['id']}":'checklists.php';
function renumber($table,$where,$params){ $r=q("SELECT id FROM $table WHERE $where ORDER BY sort,id",$params)->fetchAll(); foreach($r as $n=>$x) q("UPDATE $table SET sort=? WHERE id=?",[$n,$x['id']]); }
function resolve_item($txt){ $txt=trim($txt); if($txt==='') return null;
  if(preg_match('/#(\d+)\)?\s*$/',$txt,$m)) $it=q("SELECT * FROM items WHERE id=?",[(int)$m[1]])->fetch(); else $it=q("SELECT * FROM items WHERE name=? AND archived_at IS NULL LIMIT 1",[$txt])->fetch();
  return ($it && item_in_scope($it)) ? (int)$it['id'] : null; }
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $a=$_POST['action']??''; $anchor='';
  $sec=function($sid) use($id){ return q("SELECT * FROM checklist_sections WHERE id=? AND checklist_id=?",[(int)$sid,$id])->fetch(); };
  $line=function($lid) use($id){ return q("SELECT * FROM checklist_items WHERE id=? AND checklist_id=?",[(int)$lid,$id])->fetch(); };
  if ($a==='meta') {
    $name=trim($_POST['name']??''); $f=($_POST['freq']??'')==='custom'?(int)($_POST['freq_days']??0):(int)($_POST['freq']??0); if($f<0||$f>3650)$f=0;
    if($name==='') flash('Name is required.','danger');
    else { q("UPDATE checklists SET name=?,description=?,frequency_days=?,notify_complete=?,notify_fail=?,active=?,require_photo=? WHERE id=?",[mb_substr($name,0,150),mb_substr(trim($_POST['description']??''),0,255)?:null,$f?:null,implode(', ',parse_emails($_POST['notify_complete']??''))?:null,implode(', ',parse_emails($_POST['notify_fail']??''))?:null,isset($_POST['active'])?1:0,isset($_POST['require_photo'])?1:0,$id]); audit('checklist_save',$name); flash('Saved.'); }
  } elseif ($a==='add_section') {
    $n=trim($_POST['name']??''); if($n!==''){ $mx=(int)q("SELECT COALESCE(MAX(sort),-1)+1 FROM checklist_sections WHERE checklist_id=?",[$id])->fetchColumn(); q("INSERT INTO checklist_sections(checklist_id,name,sort) VALUES(?,?,?)",[$id,mb_substr($n,0,100),$mx]); $anchor='#s'.$pdo->lastInsertId(); }
  } elseif ($a==='rename_section' && ($s=$sec($_POST['sid']??0))) {
    $n=trim($_POST['name']??''); if($n!=='') q("UPDATE checklist_sections SET name=? WHERE id=?",[mb_substr($n,0,100),$s['id']]); $anchor='#s'.$s['id'];
  } elseif ($a==='del_section' && ($s=$sec($_POST['sid']??0))) {
    foreach(q("SELECT ref_photo FROM checklist_items WHERE section_id=? AND ref_photo IS NOT NULL",[$s['id']])->fetchAll() as $p) drop_upload($p['ref_photo']); drop_upload($s['ref_photo']);
    q("DELETE FROM checklist_items WHERE section_id=?",[$s['id']]); q("DELETE FROM checklist_sections WHERE id=?",[$s['id']]); flash('Compartment removed.');
  } elseif ($a==='move_section' && ($s=$sec($_POST['sid']??0))) {
    $ids=array_column(q("SELECT id FROM checklist_sections WHERE checklist_id=? ORDER BY sort,id",[$id])->fetchAll(),'id'); $k=array_search($s['id'],$ids); $d=($_POST['dir']??'')==='up'?-1:1;
    if($k!==false && isset($ids[$k+$d])){ [$ids[$k],$ids[$k+$d]]=[$ids[$k+$d],$ids[$k]]; foreach($ids as $n=>$x) q("UPDATE checklist_sections SET sort=? WHERE id=?",[$n,$x]); } $anchor='#s'.$s['id'];
  } elseif ($a==='sec_photo' && ($s=$sec($_POST['sid']??0))) {
    if(!empty($_POST['remove'])){ drop_upload($s['ref_photo']); q("UPDATE checklist_sections SET ref_photo=NULL WHERE id=?",[$s['id']]); }
    elseif($p=upload_image('photo')){ drop_upload($s['ref_photo']); q("UPDATE checklist_sections SET ref_photo=? WHERE id=?",[$p,$s['id']]); } else flash('No photo received. Try again.','warning');
    $anchor='#s'.$s['id'];
  } elseif ($a==='save_line') {
    $lid=(int)($_POST['lid']??0); $l=$lid?$line($lid):null; $sid=(int)($_POST['sid']??0); if($sid&&!$sec($sid)) $sid=0;
    $label=trim($_POST['label']??''); $kind=array_key_exists($_POST['kind']??'',CHECK_KINDS)?$_POST['kind']:'ok';
    $exp=($_POST['expect']??'')!==''?max(0,min(99999999,(float)$_POST['expect'])):null; $min=($_POST['min_level']??'')!==''?max(0,min(4,(int)$_POST['min_level'])):null;
    $iid=resolve_item($_POST['item']??'');
    if($kind!=='count') $exp=null; elseif($exp===null) $exp=1; if($kind!=='level') $min=null; if($kind==='value') $iid=null;
    if($label===''&&$iid&&$kind==='count'){ $label=q("SELECT name FROM items WHERE id=?",[$iid])->fetchColumn(); }
    if($label==='') flash('Give the line a label.','danger');
    elseif($l){ q("UPDATE checklist_items SET label=?,kind=?,expect_qty=?,min_level=?,item_id=? WHERE id=?",[mb_substr($label,0,255),$kind,$exp,$min,$iid,$l['id']]); $anchor='#l'.$l['id']; }
    else { $mx=(int)q("SELECT COALESCE(MAX(sort),-1)+1 FROM checklist_items WHERE checklist_id=?",[$id])->fetchColumn(); q("INSERT INTO checklist_items(checklist_id,label,sort,section_id,kind,expect_qty,min_level,item_id) VALUES(?,?,?,?,?,?,?,?)",[$id,mb_substr($label,0,255),$mx,$sid?:null,$kind,$exp,$min,$iid]); $anchor=$sid?'#s'.$sid:''; }
    if($label!=='' && ($_POST['item']??'')!=='' && !$iid) flash('Line saved, but no inventory item matched what you typed in "Linked item".','warning');
  } elseif ($a==='del_line' && ($l=$line($_POST['lid']??0))) { drop_upload($l['ref_photo']); q("DELETE FROM checklist_items WHERE id=?",[$l['id']]); $anchor=$l['section_id']?'#s'.$l['section_id']:'';
  } elseif ($a==='move_line' && ($l=$line($_POST['lid']??0))) {
    $ids=array_column(q("SELECT id FROM checklist_items WHERE checklist_id=? AND section_id <=> ? ORDER BY sort,id",[$id,$l['section_id']])->fetchAll(),'id'); $k=array_search($l['id'],$ids); $d=($_POST['dir']??'')==='up'?-1:1;
    if($k!==false && isset($ids[$k+$d])){ [$ids[$k],$ids[$k+$d]]=[$ids[$k+$d],$ids[$k]]; foreach($ids as $n=>$x) q("UPDATE checklist_items SET sort=? WHERE id=?",[$n,$x]); } $anchor='#l'.$l['id'];
  } elseif ($a==='line_photo' && ($l=$line($_POST['lid']??0))) {
    if(!empty($_POST['remove'])){ drop_upload($l['ref_photo']); q("UPDATE checklist_items SET ref_photo=NULL WHERE id=?",[$l['id']]); }
    elseif($p=upload_image('photo')){ drop_upload($l['ref_photo']); q("UPDATE checklist_items SET ref_photo=? WHERE id=?",[$p,$l['id']]); } else flash('No photo received. Try again.','warning');
    $anchor='#l'.$l['id'];
  }
  redirect("checklist_edit.php?id=$id$anchor");
}
$secs=q("SELECT * FROM checklist_sections WHERE checklist_id=? ORDER BY sort,id",[$id])->fetchAll();
$lines=[]; foreach(q("SELECT ci.*,i.name iname FROM checklist_items ci LEFT JOIN items i ON i.id=ci.item_id WHERE ci.checklist_id=? ORDER BY ci.sort,ci.id",[$id])->fetchAll() as $l) $lines[(int)$l['section_id']][]=$l;
$itemOpts=q("SELECT id,name FROM items WHERE archived_at IS NULL AND ".scope_sql('i')." ORDER BY name LIMIT 1500")->fetchAll();
$f=(int)$cl['frequency_days']; $preset=isset(FREQ_PRESETS[(string)$f])?(string)$f:'custom'; if(!$f)$preset='';
$line_form=function($sid,$l=null) use($id){ $kind=$l['kind']??'ok'; $uid=$l?$l['id']:'n'.$sid; ob_start(); ?>
<form method="post" class="row g-2 align-items-end"><?=csrf_field()?><input type="hidden" name="action" value="save_line"><input type="hidden" name="sid" value="<?=$sid?>"><input type="hidden" name="lid" value="<?=$l['id']??0?>">
<div class="col-12 col-md-4"><input class="form-control form-control-sm" name="label" maxlength="255" value="<?=h($l['label']??'')?>" placeholder="e.g. Absorbent pads 4x4"></div>
<div class="col-6 col-md-3"><select class="form-select form-select-sm kindsel" name="kind"><?php foreach(CHECK_KINDS as $k=>$lb): ?><option value="<?=$k?>"<?=$k===$kind?' selected':''?>><?=$lb?></option><?php endforeach; ?></select></div>
<div class="col-6 col-md-2 k-count"><input class="form-control form-control-sm" type="number" step="any" min="0" name="expect" value="<?=h(isset($l['expect_qty'])?fmt_q($l['expect_qty']):'')?>" placeholder="Expected qty"></div>
<div class="col-6 col-md-2 k-level"><select class="form-select form-select-sm" name="min_level"><option value="">No minimum</option><?php foreach(LEVELS as $n=>$lb): ?><option value="<?=$n?>"<?=isset($l['min_level'])&&(string)$l['min_level']===(string)$n?' selected':''?>>At least <?=$lb?></option><?php endforeach; ?></select></div>
<div class="col-12 col-md-5 k-item"><input class="form-control form-control-sm" name="item" list="itemList" value="<?=h(!empty($l['item_id'])?($l['iname']??'').' (#'.$l['item_id'].')':'')?>" placeholder="Linked inventory item (optional) - helps find where it went"></div>
<div class="col-auto"><button class="btn btn-sm btn-primary"><?=$l?'Save':'Add line'?></button></div></form>
<?php return ob_get_clean(); };
include 'inc/header.php'; ?>
<a href="<?=h($back)?>" class="small">&larr; <?=$veh?h($veh['name']):'Checklists'?></a>
<div class="d-flex justify-content-between align-items-center mt-1 mb-2"><h1 class="h4 mb-0"><?=h($cl['name'])?></h1><div class="d-flex gap-1"><?php if(array_sum(array_map('count',$lines))): ?><a class="btn btn-sm btn-primary" href="checklist_run.php?id=<?=$id?>">Run it</a><?php endif; ?><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#meta">Settings</button></div></div>
<div class="collapse<?=!$secs&&empty($lines)?' show':''?> mb-3" id="meta"><form method="post" class="card card-body"><?=csrf_field()?><input type="hidden" name="action" value="meta"><div class="row g-2">
<div class="col-md-6"><input class="form-control" name="name" value="<?=h($cl['name'])?>" maxlength="150" required></div><div class="col-md-6"><input class="form-control" name="description" value="<?=h($cl['description'])?>" placeholder="Description" maxlength="255"></div>
<div class="col-md-4"><label class="form-label small mb-0">How often?</label><select class="form-select" name="freq" id="freqSel"><?php foreach(FREQ_PRESETS as $k=>$lb): ?><option value="<?=$k?>"<?=$k===$preset?' selected':''?>><?=$lb?></option><?php endforeach; ?><option value="custom"<?=$preset==='custom'?' selected':''?>>Every N days…</option></select></div>
<div class="col-md-2"><label class="form-label small mb-0">Days</label><input class="form-control" type="number" min="1" max="3650" name="freq_days" id="freqDays" value="<?=$f?:''?>"></div>
<div class="col-md-6"><label class="form-label small mb-0">Email when completed</label><input class="form-control" name="notify_complete" value="<?=h($cl['notify_complete'])?>" placeholder="addresses, comma separated"></div>
<div class="col-md-6"><label class="form-label small mb-0">Email when issues are found or the check is overdue</label><input class="form-control" name="notify_fail" value="<?=h($cl['notify_fail'])?>" placeholder="addresses, comma separated"><?php if($veh&&$veh['owner']): ?><div class="form-text">The owner (<?=h($veh['owner'])?>) is also emailed when overdue.</div><?php endif; ?></div>
<div class="col-md-6 align-self-end"><label class="form-check"><input type="checkbox" class="form-check-input" name="active"<?=$cl['active']?' checked':''?>> <span class="form-check-label">Active</span></label><label class="form-check d-block"><input class="form-check-input" type="checkbox" name="require_photo" <?=!empty($cl['require_photo'])?'checked':''?>> <span class="form-check-label">Require a photo for every problem found</span></label></div>
<div class="col-12"><button class="btn btn-primary btn-sm">Save settings</button></div></div></form></div>
<datalist id="itemList"><?php foreach($itemOpts as $o): ?><option value="<?=h($o['name'])?> (#<?=$o['id']?>)"><?php endforeach; ?></datalist>

<?php $blocks=$secs; if(!empty($lines[0])) array_unshift($blocks,['id'=>0,'name'=>'Not in a compartment','ref_photo'=>null]);
foreach($blocks as $bi=>$s): $sid=(int)$s['id']; ?>
<div class="card mb-3" id="s<?=$sid?>"><div class="card-header"><div class="d-flex justify-content-between align-items-center gap-2">
<?php if($sid): ?><form method="post" class="d-flex gap-1 flex-grow-1"><?=csrf_field()?><input type="hidden" name="action" value="rename_section"><input type="hidden" name="sid" value="<?=$sid?>"><input class="form-control form-control-sm fw-semibold" name="name" value="<?=h($s['name'])?>" maxlength="100"><button class="btn btn-sm btn-outline-secondary" title="Rename"><i class="bi bi-check2"></i></button></form>
<div class="d-flex gap-1"><?php foreach(['up'=>'bi-arrow-up','down'=>'bi-arrow-down'] as $d=>$ic): ?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="move_section"><input type="hidden" name="sid" value="<?=$sid?>"><input type="hidden" name="dir" value="<?=$d?>"><button class="btn btn-sm btn-outline-secondary"><i class="bi <?=$ic?>"></i></button></form><?php endforeach; ?>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="del_section"><input type="hidden" name="sid" value="<?=$sid?>"><button class="btn btn-sm btn-outline-danger" data-confirm="Remove this compartment and all its lines?"><i class="bi bi-trash"></i></button></form></div>
<?php else: ?><strong><?=h($s['name'])?></strong><?php endif; ?></div>
<?php if($sid): ?><div class="mt-2 d-flex align-items-center gap-2 flex-wrap">
<?php if($s['ref_photo']): ?><a href="uploads/<?=h($s['ref_photo'])?>" target="_blank"><img src="uploads/<?=h($s['ref_photo'])?>" alt="" class="rounded" style="height:56px;width:84px;object-fit:cover"></a><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="d-flex gap-1 align-items-center flex-wrap"><?=csrf_field()?><input type="hidden" name="action" value="sec_photo"><input type="hidden" name="sid" value="<?=$sid?>">
<input type="file" name="photo" id="sc<?=$sid?>" accept="image/*" capture="environment" class="d-none" data-shrink data-auto><input type="file" name="photo" id="sa<?=$sid?>" accept="image/*" class="d-none" data-shrink data-auto>
<button type="button" class="btn btn-sm btn-outline-primary" data-pick="sc<?=$sid?>"><i class="bi bi-camera"></i> <?=$s['ref_photo']?'Retake':'Take'?> reference photo</button><button type="button" class="btn btn-sm btn-outline-secondary" data-pick="sa<?=$sid?>"><i class="bi bi-images"></i></button></form>
<?php if($s['ref_photo']): ?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="sec_photo"><input type="hidden" name="sid" value="<?=$sid?>"><input type="hidden" name="remove" value="1"><button class="btn btn-sm btn-link text-danger p-0">remove</button></form><?php endif; ?></div><?php endif; ?></div>
<ul class="list-group list-group-flush">
<?php foreach($lines[$sid]??[] as $l): ?><li class="list-group-item" id="l<?=$l['id']?>"><div class="d-flex justify-content-between align-items-start gap-2"><div class="flex-grow-1">
<span class="fw-semibold"><?=h($l['label'])?></span> <span class="badge text-bg-light border"><?php
 echo h(['ok'=>'OK / Not OK','count'=>'Count '.fmt_q($l['expect_qty']),'level'=>'Level'.($l['min_level']!==null?' ≥ '.LEVELS[(int)$l['min_level']]:''),'value'=>'Reading'][$l['kind']]); ?></span><?php if($l['item_id']): ?> <span class="small text-muted"><i class="bi bi-link-45deg"></i> <?=h($l['iname'])?></span><?php endif; ?>
<?php if($l['ref_photo']): ?> <a href="uploads/<?=h($l['ref_photo'])?>" target="_blank"><img src="uploads/<?=h($l['ref_photo'])?>" alt="" class="rounded align-middle" style="height:32px;width:44px;object-fit:cover"></a><?php endif; ?></div>
<div class="d-flex gap-1"><?php foreach(['up'=>'bi-arrow-up','down'=>'bi-arrow-down'] as $d=>$ic): ?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="move_line"><input type="hidden" name="lid" value="<?=$l['id']?>"><input type="hidden" name="dir" value="<?=$d?>"><button class="btn btn-sm btn-outline-secondary py-0"><i class="bi <?=$ic?>"></i></button></form><?php endforeach; ?>
<button class="btn btn-sm btn-outline-secondary py-0" data-bs-toggle="collapse" data-bs-target="#le<?=$l['id']?>"><i class="bi bi-pencil"></i></button>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="del_line"><input type="hidden" name="lid" value="<?=$l['id']?>"><button class="btn btn-sm btn-outline-danger py-0" data-confirm="Remove this line?"><i class="bi bi-x-lg"></i></button></form></div></div>
<div class="collapse mt-2" id="le<?=$l['id']?>"><?=$line_form($sid,$l)?>
<form method="post" enctype="multipart/form-data" class="d-flex gap-1 mt-2 align-items-center flex-wrap"><?=csrf_field()?><input type="hidden" name="action" value="line_photo"><input type="hidden" name="lid" value="<?=$l['id']?>">
<input type="file" name="photo" id="lc<?=$l['id']?>" accept="image/*" capture="environment" class="d-none" data-shrink data-auto><input type="file" name="photo" id="la<?=$l['id']?>" accept="image/*" class="d-none" data-shrink data-auto>
<button type="button" class="btn btn-sm btn-outline-primary" data-pick="lc<?=$l['id']?>"><i class="bi bi-camera"></i> Reference photo</button><button type="button" class="btn btn-sm btn-outline-secondary" data-pick="la<?=$l['id']?>"><i class="bi bi-images"></i></button>
<?php if($l['ref_photo']): ?><button class="btn btn-sm btn-link text-danger p-0" name="remove" value="1">remove photo</button><?php endif; ?></form></div></li>
<?php endforeach; ?>
<?php if($sid||!empty($lines[0])): ?><li class="list-group-item bg-body-tertiary"><?=$line_form($sid)?></li><?php endif; ?></ul></div>
<?php endforeach; ?>
<div class="card mb-3"><form method="post" class="card-body d-flex gap-2"><?=csrf_field()?><input type="hidden" name="action" value="add_section"><input class="form-control" name="name" maxlength="100" placeholder="<?=$secs?'Add another compartment':'Add the first compartment, e.g. Left Rear Bay 6, Crew Cab'?>" required><button class="btn btn-primary text-nowrap"><i class="bi bi-plus-lg"></i> Compartment</button></form></div>
<script>(function(){function sync(f){var k=f.querySelector('.kindsel').value;f.querySelectorAll('.k-count').forEach(function(e){e.style.display=k==='count'?'':'none';});f.querySelectorAll('.k-level').forEach(function(e){e.style.display=k==='level'?'':'none';});f.querySelectorAll('.k-item').forEach(function(e){e.style.display=(k==='value')?'none':'';});}
document.querySelectorAll('form').forEach(function(f){var s=f.querySelector('.kindsel');if(s){sync(f);s.addEventListener('change',function(){sync(f);});}});
var fs=document.getElementById('freqSel'),fd=document.getElementById('freqDays');if(fs&&fd){var u=function(){var v=fs.value;fd.disabled=(v===''||v!=='custom');if(v&&v!=='custom')fd.value=v;if(v==='')fd.value='';};fs.addEventListener('change',u);u();}})();</script>
<?php include 'inc/footer.php'; ?>
