<?php require 'inc/bootstrap.php'; need('viewer'); if (me()['role']==='vendor') redirect('supply.php'); $title='Events / incidents';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!can('incidents_manage')) { http_response_code(403); exit('Access denied.'); }
  $a = $_POST['action'] ?? ''; $id = (int)($_POST['id'] ?? 0);
  if ($a === 'add') {
    $name = mb_substr(trim($_POST['name'] ?? ''), 0, 120);
    if ($name === '') flash('Give the event or incident a name.', 'danger');
    else {
      $d = trim($_POST['starts_on'] ?? ''); $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : date('Y-m-d');
      q("INSERT INTO incidents(name,kind,starts_on,notes,created_by) VALUES(?,?,?,?,?)", [$name, mb_substr(trim($_POST['kind'] ?? ''), 0, 40) ?: null, $d, mb_substr(trim($_POST['notes'] ?? ''), 0, 500) ?: null, me()['id']]);
      $nid = (int)$pdo->lastInsertId(); audit('incident_add', "#$nid $name"); flash('Event started.'); redirect("incident.php?id=$nid");
    }
  } elseif ($a === 'close' || $a === 'reopen') {
    $r = q("SELECT * FROM incidents WHERE id=?", [$id])->fetch();
    if ($r) {
      if ($a === 'close') { q("UPDATE incidents SET status='closed',closed_at=NOW(),closed_by=? WHERE id=?", [me()['id'], $id]); $n = count(field_rows(['incident'=>$id])); audit('incident_close', "#$id {$r['name']}"); flash($n ? "Event closed. $n item(s) are still out; they stay on the Field page until returned." : 'Event closed.', $n ? 'warning' : 'success'); }
      else { q("UPDATE incidents SET status='open',closed_at=NULL,closed_by=NULL WHERE id=?", [$id]); audit('incident_reopen', "#$id {$r['name']}"); flash('Event reopened.'); }
    }
  } elseif ($a === 'delete' && level() >= ROLES['admin']) {
    $used = (int)q("SELECT (SELECT COUNT(*) FROM transactions WHERE incident_id=?)+(SELECT COUNT(*) FROM issues WHERE incident_id=?)+(SELECT COUNT(*) FROM checkouts WHERE incident_id=?)", [$id,$id,$id])->fetchColumn();
    if ($used) flash('This event has records attached and cannot be deleted. Close it instead.', 'danger'); else { q("DELETE FROM incidents WHERE id=?", [$id]); audit('incident_delete', "#$id"); flash('Event deleted.'); }
  }
  redirect('incidents.php');
}
$rows = q("SELECT n.*,(SELECT COUNT(*) FROM checkouts c WHERE c.incident_id=n.id AND c.in_at IS NULL) a_out,(SELECT COUNT(*) FROM issues l WHERE l.incident_id=n.id AND l.closed_at IS NULL AND ".OUT_EXPR." > 0.0001) l_out FROM incidents n ORDER BY n.status='closed', n.id DESC LIMIT 200")->fetchAll();
include 'inc/header.php'; ?>
<h1 class="h3 mb-3">Events / incidents</h1>
<?php if(can('incidents_manage')): ?><details class="card card-body mb-3" <?=$rows?'':'open'?>><summary class="fw-bold" style="cursor:pointer"><i class="bi bi-plus-circle"></i> Start an event or incident</summary>
<form method="post" class="row g-2 mt-1"><?=csrf_field()?><input type="hidden" name="action" value="add">
<div class="col-md-5"><input class="form-control" name="name" placeholder="Name, e.g. Fair parking detail, Elm St fire, Sheriff sign loan" maxlength="120" required></div>
<div class="col-md-3"><input class="form-control" name="kind" list="kinds" placeholder="Type (optional)" maxlength="40"><datalist id="kinds"><option>Structure fire</option><option>Traffic control</option><option>Community event</option><option>Equipment loan / rental</option><option>Training</option><option>Weather</option><option>Search</option><option>Other</option></datalist></div>
<div class="col-md-2"><input class="form-control" type="date" name="starts_on" value="<?=h(date('Y-m-d'))?>"></div>
<div class="col-md-2"><button class="btn btn-primary w-100">Start</button></div>
<div class="col-12"><input class="form-control" name="notes" placeholder="Notes (optional)" maxlength="500"></div></form></details><?php endif; ?>
<?php if(!$rows): ?><div class="text-muted">No events yet. Start one to plan a loan or a deployment, or when you issue gear for an incident, so you can see everything that went out and collect it when it ends.</div><?php else: ?>
<div class="card"><div class="list-group list-group-flush">
<?php foreach($rows as $r): $out = $r['a_out'] + $r['l_out']; ?><div class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2">
<div><a class="fw-bold" href="incident.php?id=<?=$r['id']?>"><?=h($r['name'])?></a> <?=$r['status']==='open'?'<span class="badge text-bg-success">open</span>':'<span class="badge text-bg-secondary">closed</span>'?>
<div class="small text-muted"><?=h($r['kind'])?><?=$r['kind']&&$r['starts_on']?' · ':''?><?=h($r['starts_on'])?></div></div>
<div class="d-flex align-items-center gap-2"><?=$out?'<span class="badge text-bg-warning">'.$out.' still out</span>':'<span class="small text-muted">nothing out</span>'?>
<?php if(can('incidents_manage')): ?><form method="post" class="d-inline"><?=csrf_field()?><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="action" value="<?=$r['status']==='open'?'close':'reopen'?>"><button class="btn btn-sm btn-outline-secondary" <?=$r['status']==='open'?'data-confirm="Close this event?"':''?>><?=$r['status']==='open'?'Close':'Reopen'?></button></form><?php endif; ?></div></div>
<?php endforeach; ?></div></div><?php endif; ?>
<?php include 'inc/footer.php'; ?>
