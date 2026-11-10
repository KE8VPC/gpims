<?php require 'inc/bootstrap.php'; need('viewer'); $title='Scan';
$code = trim($_GET['code'] ?? ''); $rfmt = trim($_GET['fmt'] ?? '');
if ($code !== '') {
  $m = preg_match('/^GPIMS-(\d+)$/i', $code, $mm) ? (int)$mm[1] : 0;
  $cand = code_candidates($code); $in = implode(',', array_fill(0, count($cand), '?'));
  $it = q("SELECT i.id FROM items i WHERE (i.barcode IN ($in) OR i.sku IN ($in) OR i.serial IN ($in) OR i.id=? OR EXISTS(SELECT 1 FROM item_codes c WHERE c.item_id=i.id AND c.code IN ($in))) AND ".scope_sql('i')." ORDER BY (i.barcode IN ($in)) DESC LIMIT 1", array_merge($cand,$cand,$cand,[$m],$cand,$cand))->fetch();
  if (!$it && (can('checkout') || can('people_manage'))) { $pp = q("SELECT id FROM people WHERE badge_code IN ($in) LIMIT 1", $cand)->fetch(); if ($pp) redirect('person.php?id='.(int)$pp['id']); }
  $add = (($_GET['mode'] ?? '') === 'add') && can('items_add');
  if ($it) { if ($add) flash('That code is already in the inventory, so here is the existing item.', 'info'); redirect('item_view.php?id='.$it['id']); }
  if ($add) redirect('item_edit.php?barcode='.urlencode($code).'&ctype='.urlencode(fmt_to_type($rfmt)));
}
include 'inc/header.php'; ?>
<div class="text-center" style="max-width:480px;margin:2rem auto">
<h1 class="h3 mb-3">Scan an item</h1>
<?php if ($code !== ''): ?><div class="alert alert-warning">No item matches <strong><?=h($code)?></strong><?=$rfmt?' (read as '.h(str_replace('_',' ',strtolower($rfmt))).')':''?>.
<?php if (can('items_add')): ?><div class="mt-2"><a class="btn btn-sm btn-primary" href="item_edit.php?barcode=<?=urlencode($code)?>&ctype=<?=urlencode(fmt_to_type($rfmt))?>">Create item with this code</a></div><?php endif; ?></div><?php endif; ?>
<p class="text-muted">Scan a QR code or barcode to jump straight to the item. Matches the barcode, SKU, serial number or a GPIMS label. Scan a person's badge to see what they have out and collect it.</p>
<button class="btn btn-primary btn-lg" id="scanBtn" data-scan-go><i class="bi bi-upc-scan"></i> Open scanner</button>
<p class="small text-muted mt-3">Camera scanning needs a secure (https) site. You can always type the code instead.</p></div>
<?php include 'inc/footer.php'; ?>
<?php if ($code === ''): ?><script>window.addEventListener('load',function(){document.getElementById('scanBtn').click();});</script><?php endif; ?>
