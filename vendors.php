<?php require 'inc/bootstrap.php'; need_perm('vendors_manage'); $title='Vendors';
$edit = !empty($_GET['edit']) ? q("SELECT * FROM vendors WHERE id=?",[(int)$_GET['edit']])->fetch() : null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $id=(int)($_POST['id']??0); $name=trim($_POST['name']);
  if ($name==='') flash('Vendor name is required.','danger');
  else {
    $v=[$name,trim($_POST['contact_name']),trim($_POST['phone']),trim($_POST['email']),trim($_POST['website']),trim($_POST['account_no']),trim($_POST['address']),trim($_POST['notes']),isset($_POST['active'])?1:0];
    if ($id) { q("UPDATE vendors SET name=?,contact_name=?,phone=?,email=?,website=?,account_no=?,address=?,notes=?,active=? WHERE id=?",array_merge($v,[$id])); audit('vendor_update',"#$id $name"); }
    else { q("INSERT INTO vendors(name,contact_name,phone,email,website,account_no,address,notes,active) VALUES(?,?,?,?,?,?,?,?,?)",$v); audit('vendor_add',$name); }
    flash('Vendor saved.'); redirect('vendors.php');
  }
  $edit=['id'=>$id,'name'=>$name,'contact_name'=>$_POST['contact_name'],'phone'=>$_POST['phone'],'email'=>$_POST['email'],'website'=>$_POST['website'],'account_no'=>$_POST['account_no'],'address'=>$_POST['address'],'notes'=>$_POST['notes'],'active'=>isset($_POST['active'])?1:0];
}
$all=!empty($_GET['all']);
$vendors=q("SELECT v.*,(SELECT COUNT(*) FROM item_vendors iv WHERE iv.vendor_id=v.id) n FROM vendors v ".($all?'':'WHERE v.active=1 ')."ORDER BY v.name")->fetchAll();
include 'inc/header.php'; ?>
<h1 class="h3 mb-3">Vendors</h1>
<div class="row g-4"><div class="col-lg-7">
<div class="d-flex justify-content-between mb-2"><input class="form-control w-auto" id="quickFilter" placeholder="Filter…"><a href="?<?=$all?'':'all=1'?>" class="btn btn-sm btn-outline-secondary"><?=$all?'Hide inactive':'Show inactive'?></a></div>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0" id="filterTable"><thead><tr><th>Vendor</th><th>Contact</th><th>Phone / email</th><th>Items</th><th></th></tr></thead><tbody>
<?php foreach($vendors as $v): ?><tr class="<?=$v['active']?'':'text-muted'?>"><td><strong><?=h($v['name'])?></strong><?=$v['active']?'':' <span class="badge text-bg-secondary">inactive</span>'?><div class="small text-muted"><?=h($v['account_no'] ? 'Acct '.$v['account_no'] : '')?></div></td><td><?=h($v['contact_name'])?></td>
<td class="small"><?php if($v['phone']): ?><a href="tel:<?=h(preg_replace('/[^0-9+]/','',$v['phone']))?>"><?=h($v['phone'])?></a><br><?php endif; ?><?php if($v['email']): ?><a href="mailto:<?=h($v['email'])?>"><?=h($v['email'])?></a><?php endif; ?></td><td><?=$v['n']?></td><td><a href="?edit=<?=$v['id']?>">Edit</a></td></tr><?php endforeach; if(!$vendors) echo '<tr><td colspan="5" class="text-muted p-3">No vendors yet.</td></tr>'; ?></tbody></table></div></div></div>
<div class="col-lg-5"><div class="card"><div class="card-header fw-bold"><?=$edit?'Edit vendor':'Add vendor'?></div><form method="post" class="card-body"><?=csrf_field()?><input type="hidden" name="id" value="<?=h($edit['id']??0)?>">
<?php foreach(['name'=>'Company name *','contact_name'=>'Contact person','phone'=>'Phone','email'=>'Email','website'=>'Website','account_no'=>'Our account number','address'=>'Address'] as $k=>$l): ?><input class="form-control mb-2" name="<?=$k?>" placeholder="<?=h($l)?>" value="<?=h($edit[$k]??'')?>" <?=$k==='name'?'required':''?>><?php endforeach; ?>
<textarea class="form-control mb-2" name="notes" rows="2" placeholder="Notes"><?=h($edit['notes']??'')?></textarea>
<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="active" id="act" <?=($edit['active']??1)?'checked':''?>><label class="form-check-label" for="act">Active</label></div>
<button class="btn btn-primary">Save</button> <?php if($edit): ?><a class="btn btn-link" href="vendors.php">Cancel</a><?php endif; ?></form></div></div></div>
<?php include 'inc/footer.php'; ?>
