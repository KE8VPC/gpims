<?php require 'inc/bootstrap.php'; need('admin'); $title='Users';
$roles = array_keys(ROLE_LABELS);
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $a = $_POST['action'] ?? '';
  if ($a==='add') {
    $role = in_array($_POST['role'], $roles, true) ? $_POST['role'] : 'user';
    $invite = !empty($_POST['invite']) && valid_email(trim($_POST['email'])) && mail_enabled();
    if ((strlen($_POST['password'])<8 && !($invite && $_POST['password']==='')) || trim($_POST['username'])==='') flash('Username and an 8+ character password are required (the password can be blank when emailing an invitation).','danger');
    elseif (q("SELECT 1 FROM users WHERE username=?",[trim($_POST['username'])])->fetch()) flash('Username already exists.','danger');
    else {
      q("INSERT INTO users(username,name,email,pass_hash,role,scope_all) VALUES(?,?,?,?,?,?)",[trim($_POST['username']),trim($_POST['name']),trim($_POST['email']),password_hash($_POST['password']!=='' ? $_POST['password'] : bin2hex(random_bytes(16)),PASSWORD_DEFAULT),$role,$role==='vendor'?0:1]);
      $nid = $pdo->lastInsertId(); audit('user_add',$_POST['username']);
      if ($invite) { $nu=q("SELECT * FROM users WHERE id=?",[$nid])->fetch(); send_account_email($nu,true); flash('Invitation emailed to '.$nu['email'].'.'); }
      if ($role==='vendor') { flash('Vendor created. Choose which categories they can see.'); redirect("user_access.php?id=$nid"); }
      flash('User created.');
    }
  } elseif ($a==='update' && (int)$_POST['id']!==me()['id']) {
    $role = in_array($_POST['role'], $roles, true) ? $_POST['role'] : 'user';
    q("UPDATE users SET role=?,active=? WHERE id=?",[$role,isset($_POST['active'])?1:0,(int)$_POST['id']]);
    if (strlen($_POST['password'])>=8) q("UPDATE users SET pass_hash=? WHERE id=?",[password_hash($_POST['password'],PASSWORD_DEFAULT),(int)$_POST['id']]);
    audit('user_update',"#".(int)$_POST['id']); flash('User updated.');
  }
  redirect('users.php');
}
$users=q("SELECT u.*,(SELECT COUNT(*) FROM user_access a WHERE a.user_id=u.id) rules FROM users u ORDER BY username")->fetchAll();
include 'inc/header.php'; ?>
<h1 class="h3 mb-3">Users</h1>
<p class="text-muted small"><b>Admin</b> everything · <b>User</b> adds and edits items, moves stock, checks equipment out, runs checklists · <b>Viewer</b> read only · <b>Vendor</b> sees only the Supply page for the categories you choose. Use <b>Access</b> to fine-tune what each person can do and see.</p>
<form method="post" class="row g-2 mb-4"><?=csrf_field()?><input type="hidden" name="action" value="add">
<div class="col-md-2"><input class="form-control" name="username" placeholder="Username" required></div><div class="col-md-2"><input class="form-control" name="name" placeholder="Full name" required></div><div class="col-md-2"><input class="form-control" name="email" placeholder="Email"></div><div class="col-md-2"><input class="form-control" type="password" name="password" placeholder="Password (8+)" required></div>
<div class="col-md-2"><select class="form-select" name="role"><?php foreach($roles as $r): ?><option value="<?=$r?>" <?=$r==='user'?'selected':''?>><?=h(ROLE_LABELS[$r])?></option><?php endforeach; ?></select></div><div class="col-md-2"><button class="btn btn-primary">Add user</button></div>
<?php if(mail_enabled()): ?><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="invite" id="inv"><label class="form-check-label small" for="inv">Email them an invitation to set their own password (needs an email address; the password above can be left blank)</label></div></div><?php endif; ?></form>
<div class="card"><div class="table-responsive"><table class="table mb-0"><thead><tr><th>User</th><th>Last login</th><th>Role</th><th>Active</th><th>New password</th><th></th></tr></thead><tbody>
<?php foreach($users as $u): $self=$u['id']==me()['id']; ?><tr><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?=$u['id']?>">
<td><strong><?=h($u['username'])?></strong><div class="small text-muted"><?=h($u['name'])?></div></td><td class="text-muted small"><?=h($u['last_login'] ?? 'never')?></td>
<td><select class="form-select form-select-sm" name="role" <?=$self?'disabled':''?>><?php foreach($roles as $r): ?><option value="<?=$r?>" <?=$u['role']===$r?'selected':''?>><?=h(ROLE_LABELS[$r])?></option><?php endforeach; ?></select>
<?php if(!$u['scope_all'] && $u['role']!=='admin'): ?><div class="small text-muted"><?=$u['rules']?> access rule(s)</div><?php endif; ?></td>
<td><input class="form-check-input" type="checkbox" name="active" <?=$u['active']?'checked':''?> <?=$self?'disabled':''?>></td><td><input class="form-control form-control-sm" type="password" name="password" placeholder="leave blank to keep"></td>
<td class="text-nowrap"><?php if(!$self): ?><button class="btn btn-sm btn-outline-primary">Save</button> <?php endif; ?><a class="btn btn-sm btn-outline-secondary" href="user_access.php?id=<?=$u['id']?>">Access</a></td></form></tr><?php endforeach; ?></tbody></table></div></div>
<?php include 'inc/footer.php'; ?>
