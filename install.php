<?php
session_start();
require __DIR__.'/inc/version.php';
if (file_exists(__DIR__.'/config.php')) { exit('Already installed. Delete install.php from the server.'); }
$err = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  try {
    $pdo = new PDO("mysql:host={$_POST['host']};dbname={$_POST['name']};charset=utf8mb4", $_POST['user'], $_POST['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if (strlen($_POST['admin_pass']) < 8) throw new Exception('Admin password must be at least 8 characters.');
    foreach (array_filter(array_map('trim', explode(";\n", file_get_contents(__DIR__.'/schema.sql')))) as $sql) $pdo->exec($sql);
    $pdo->prepare("INSERT INTO users(username,name,pass_hash,role) VALUES(?,?,?,'admin')")->execute([trim($_POST['admin_user']), trim($_POST['admin_name']), password_hash($_POST['admin_pass'], PASSWORD_DEFAULT)]);
    $pdo->prepare("INSERT INTO settings(k,v) VALUES('db_version',?),('org_name',?)")->execute([APP_DB_VERSION, trim($_POST['app_name'])?:'GPIMS']);
    $pdo->exec("INSERT INTO categories(name) VALUES('General')");
    $pdo->exec("INSERT INTO locations(name) VALUES('Main Storage')");
    $c = "<?php\nreturn ".var_export(['host'=>$_POST['host'],'name'=>$_POST['name'],'user'=>$_POST['user'],'pass'=>$_POST['pass'],'app_name'=>trim($_POST['app_name'])?:'GPIMS'], true).";\n";
    if (file_put_contents(__DIR__.'/config.php', $c) === false) throw new Exception('Could not write config.php - check folder permissions.');
    header('Location: login.php'); exit;
  } catch (Exception $e) { $err = $e->getMessage(); }
}
$v = fn($k,$d='') => htmlspecialchars($_POST[$k] ?? $d);
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GPIMS Installer</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><div class="container py-5" style="max-width:640px">
<h1 class="h3 mb-3">GPIMS Installer</h1><p class="text-muted">Create an empty MySQL database in your hosting control panel first, then enter its details below.</p>
<?php if($err): ?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif; ?>
<form method="post" class="card card-body gap-2">
<h2 class="h6">Database</h2>
<input class="form-control" name="host" placeholder="DB host" value="<?=$v('host','localhost')?>" required>
<input class="form-control" name="name" placeholder="DB name" value="<?=$v('name')?>" required>
<input class="form-control" name="user" placeholder="DB user" value="<?=$v('user')?>" required>
<input class="form-control" type="password" name="pass" placeholder="DB password">
<h2 class="h6 mt-3">Organization (changeable later in Settings)</h2>
<input class="form-control" name="app_name" placeholder="Organization name" value="<?=$v('app_name','GPIMS')?>">
<h2 class="h6 mt-3">Administrator account</h2>
<input class="form-control" name="admin_name" placeholder="Full name" value="<?=$v('admin_name')?>" required>
<input class="form-control" name="admin_user" placeholder="Username" value="<?=$v('admin_user')?>" required>
<input class="form-control" type="password" name="admin_pass" placeholder="Password (8+ chars)" required>
<button class="btn btn-primary mt-3">Install</button></form></div></body></html>
