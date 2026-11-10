<?php $page = basename($_SERVER['SCRIPT_NAME']); $nav = function($f,$l,$min='viewer') use ($page){ if(level()<ROLES[$min]) return ''; return '<li class="nav-item"><a class="nav-link'.($page===$f?' active':'').'" href="'.$f.'">'.$l.'</a></li>'; }; ?>
<!doctype html><html lang="en" data-bs-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($title ?? 'GPIMS')?> · <?=h($cfg['app_name'])?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="assets/app.css?v=<?=APP_VERSION?>" rel="stylesheet">
<link rel="manifest" href="manifest.php"><meta name="theme-color" content="#0d6efd"><link rel="apple-touch-icon" href="assets/icon-180.png"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="<?=h($cfg['app_name'])?>">
<script>window.GP_CSRF=<?=json_encode(csrf())?>;window.GP_STOCK=<?=(me() && can('stock_move')) ? 1 : 0?>;</script></head><body>
<?php
$isV = me() && me()['role']==='vendor';
$mg = []; foreach ([['categories.php','Categories','bi-tags','categories_manage'],['locations.php','Locations','bi-geo-alt','locations_manage'],['people.php','People','bi-person-badge','people_manage'],['vendors.php','Vendors','bi-truck','vendors_manage'],['labels.php','Labels','bi-qr-code','labels_print'],['incidents.php','Events / incidents','bi-lightning-charge','checkout'],['reservations.php','Reservations','bi-calendar-event','checkout'],['maintenance.php','Maintenance','bi-wrench-adjustable','maintenance_manage'],['kits.php','Kits','bi-bag','checkout'],['recount.php','Recount','bi-clipboard-check','stock_move'],['trash.php','Trash and undo','bi-trash','stock_move'],['offline_sync.php','Offline scans','bi-cloud-upload','stock_move'],['reports.php','Reports','bi-graph-up','reports_view']] as $m) if (me() && can($m[3])) $mg[] = $m;
$mgPages = array_column($mg, 0); $adPages = ['users.php','user_access.php','audit.php','email_log.php','settings.php','health.php','upgrade.php','backup.php','import.php'];
$ac = (!$isV && level()>=ROLES['viewer']) ? attention_count() : 0; $fc = (!$isV && level()>=ROLES['viewer']) ? field_count() : 0;
$lnk = fn($f,$l,$icon,$extra='') => '<li class="nav-item"><a class="nav-link py-1'.($page===$f?' active':'').'" href="'.$f.'"><i class="bi '.$icon.'"></i> '.$l.$extra.'</a></li>';
?>
<nav class="navbar navbar-expand-lg bg-body-tertiary border-bottom mb-3 py-1"><div class="container-fluid">
<a class="navbar-brand fw-bold fs-6 me-3" href="<?=$isV?'supply.php':'index.php'?>"><i class="bi bi-box-seam"></i> <?=h($cfg['app_name'])?></a>
<button class="navbar-toggler py-0 px-2" data-bs-toggle="collapse" data-bs-target="#nv"><span class="navbar-toggler-icon"></span></button>
<div class="collapse navbar-collapse" id="nv"><ul class="navbar-nav me-auto">
<?php if($isV): echo $lnk('supply.php','Supply','bi-box-seam'); elseif(me() && level()>=ROLES['viewer']):
  echo $lnk('index.php','Home','bi-speedometer2'), $lnk('items.php','Inventory','bi-boxes'), $lnk('field.php','Out now','bi-box-arrow-up-right', $fc ? ' <span class="badge text-bg-secondary">'.$fc.'</span>' : ''), $lnk('attention.php','Attention','bi-exclamation-triangle', $ac ? ' <span class="badge text-bg-danger">'.$ac.'</span>' : '');
endif; ?>
<?php if(!$isV && me() && level()>=ROLES['viewer']): $ckPages=['checklists.php','checklist_edit.php','checklist_run.php','checklist_runs.php','vehicles.php','vehicle.php']; ?><li class="nav-item dropdown"><a class="nav-link py-1 dropdown-toggle<?=in_array($page,$ckPages)?' active':''?>" href="#" data-bs-toggle="dropdown"><i class="bi bi-check2-square"></i> Checklists</a><ul class="dropdown-menu">
<li><a class="dropdown-item" href="checklists.php"><i class="bi bi-list-check"></i> All checklists</a></li>
<li><a class="dropdown-item" href="vehicles.php"><i class="bi bi-truck"></i> Vehicles</a></li>
<li><hr class="dropdown-divider"></li>
<li><a class="dropdown-item" href="checklist_runs.php"><i class="bi bi-clock-history"></i> History</a></li></ul></li><?php endif; ?>
<?php if($mg): ?><li class="nav-item dropdown"><a class="nav-link py-1 dropdown-toggle<?=in_array($page,$mgPages)?' active':''?>" href="#" data-bs-toggle="dropdown"><i class="bi bi-sliders"></i> Manage</a><ul class="dropdown-menu"><?php foreach($mg as $m): ?><li><a class="dropdown-item" href="<?=$m[0]?>"><i class="bi <?=$m[2]?>"></i> <?=$m[1]?></a></li><?php endforeach; ?></ul></li><?php endif; ?>
<?php if(level()>=ROLES['admin']): ?><li class="nav-item dropdown"><a class="nav-link py-1 dropdown-toggle<?=in_array($page,$adPages)?' active':''?>" href="#" data-bs-toggle="dropdown"><i class="bi bi-shield-lock"></i> Admin</a><ul class="dropdown-menu">
<li><a class="dropdown-item" href="users.php"><i class="bi bi-people"></i> Users</a></li>
<li><a class="dropdown-item" href="audit.php"><i class="bi bi-journal-text"></i> Audit log</a></li>
<li><a class="dropdown-item" href="email_log.php"><i class="bi bi-envelope"></i> Email log</a></li>
<li><a class="dropdown-item" href="settings.php"><i class="bi bi-gear"></i> Settings</a></li>
<li><a class="dropdown-item" href="import.php"><i class="bi bi-upload"></i> Import</a></li>
<li><a class="dropdown-item" href="backup.php"><i class="bi bi-cloud-arrow-down"></i> Backup</a></li>
<li><hr class="dropdown-divider"></li>
<li><a class="dropdown-item" href="health.php"><i class="bi bi-heart-pulse"></i> System health</a></li>
<li><a class="dropdown-item" href="upgrade.php"><i class="bi bi-arrow-up-circle"></i> Upgrade</a></li></ul></li><?php endif; ?>
</ul><div class="d-flex align-items-center gap-1">
<button class="btn btn-sm btn-outline-secondary" id="themeBtn" title="Dark mode"><i class="bi bi-moon-stars"></i></button>
<?php if(me()): ?><div class="dropdown"><button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-person-circle"></i> <?=h(me()['name'])?></button><ul class="dropdown-menu dropdown-menu-end"><li><span class="dropdown-item-text small text-muted"><?=h(ROLE_LABELS[me()['role']] ?? me()['role'])?></span></li><li><a class="dropdown-item" href="account.php"><i class="bi bi-key"></i> Account</a></li><li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign out</a></li></ul></div><?php endif; ?>
</div></div></nav>
<main class="container-fluid px-4">
<?php foreach (flash() as [$t,$m]): ?><div class="alert alert-<?=h($t)?> alert-dismissible"><?=h($m)?><button class="btn-close" data-bs-dismiss="alert"></button></div><?php endforeach; ?>
