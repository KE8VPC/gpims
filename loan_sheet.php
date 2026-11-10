<?php require 'inc/bootstrap.php'; need_perm('checkout'); $title = 'Loan sheet';
$incF = (int)($_GET['incident'] ?? 0); $perF = (int)($_GET['person'] ?? 0); $holder = trim($_GET['holder'] ?? ''); $planned = !empty($_GET['planned']);
$f = []; if ($incF) $f['incident'] = $incF; if ($perF) $f['person'] = $perF; elseif ($holder !== '') $f['holder'] = $holder;
if (!$f) redirect('field.php');
$ev = $incF ? q("SELECT * FROM incidents WHERE id=?", [$incF])->fetch() : null; $per = $perF ? q("SELECT * FROM people WHERE id=?", [$perF])->fetch() : null;
$lines = [];
if ($planned) {
  foreach (res_list(['incident' => $incF, 'status' => 'open']) as $r) { if ($perF && (int)$r['person_id'] !== $perF) continue; if (!$perF && $holder !== '' && $r['borrower'] !== $holder) continue; $lines[] = ['item_id' => $r['item_id'], 'item' => $r['item'], 'qty' => $r['itype'] === 'asset' ? 1 : $r['qty'], 'holder' => $r['borrower'], 'out' => $r['starts_on'], 'due' => $r['ends_on'], 'person_id' => $r['person_id']]; }
} else {
  foreach (field_rows($f) as $r) $lines[] = ['item_id' => $r['item_id'], 'item' => $r['item'], 'qty' => $r['qty'], 'holder' => $r['holder'], 'out' => substr($r['since'], 0, 10), 'due' => $r['due'], 'person_id' => $r['person_id']];
}
$ser = []; if ($lines) foreach (q("SELECT id,serial,sku FROM items WHERE id IN (".implode(',', array_map('intval', array_column($lines, 'item_id'))).")")->fetchAll() as $x) $ser[$x['id']] = $x['serial'] ?: $x['sku'];
$names = array_unique(array_column($lines, 'holder')); $who = $per ? $per['name'] : ($holder !== '' ? $holder : ($names ? implode(', ', $names) : ''));
$phone = $per['phone'] ?? ''; if (!$phone && $lines && $lines[0]['person_id']) $phone = (string)q("SELECT phone FROM people WHERE id=?", [$lines[0]['person_id']])->fetchColumn();
$org = setting('org_name', $cfg['app_name']);
$terms = setting('loan_terms', "The borrower accepts responsibility for the equipment listed above while it is in their care, agrees to use it for its intended purpose, and will return it by the due date in the same condition, normal wear excepted. Lost or damaged equipment may be charged to the borrower's agency.");
$dues = array_filter(array_column($lines, 'due')); $lastDue = $dues ? max($dues) : '';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Loan sheet · <?=h($org)?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>body{background:#fff;color:#000}.sheet{max-width:820px;margin:1rem auto;padding:0 1rem}.box{display:inline-block;width:.9em;height:.9em;border:1px solid #000;vertical-align:-1px}.sig{border-bottom:1px solid #000;height:2.2rem}
@media print{.noprint{display:none!important}.sheet{margin:0;max-width:none}a{color:inherit;text-decoration:none}}</style></head><body><div class="sheet">
<div class="noprint d-flex gap-2 my-3"><button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button><a class="btn btn-outline-secondary" href="javascript:history.back()">Back</a>
<?php if($incF): ?><a class="btn btn-outline-secondary ms-auto" href="loan_sheet.php?incident=<?=$incF?><?=$planned?'':'&planned=1'?>"><?=$planned?'Show items out now':'Show reserved (planned) items'?></a><?php endif; ?></div>
<div class="d-flex justify-content-between align-items-end border-bottom border-2 border-dark pb-2 mb-3"><div><div class="fs-4 fw-bold"><?=h($org)?></div><div class="fs-5"><?=$planned?'Equipment reservation / release form':'Equipment loan / receipt form'?></div></div><div class="text-end small">Printed <?=h(date('M j, Y g:i a'))?><br>by <?=h(me()['name'])?></div></div>
<div class="row mb-3"><div class="col-6"><div class="small text-uppercase text-muted">Borrower</div><div class="fs-6 fw-semibold"><?=h($who ?: '—')?></div><?php if($phone): ?><div class="small"><?=h($phone)?></div><?php endif; ?></div>
<div class="col-6"><div class="small text-uppercase text-muted">Event / purpose</div><div class="fs-6 fw-semibold"><?=h($ev ? $ev['name'] : '—')?></div><?php if($ev && $ev['kind']): ?><div class="small"><?=h($ev['kind'])?><?=$ev['starts_on']?' · '.h($ev['starts_on']):''?></div><?php endif; ?><?php if($lastDue): ?><div class="small">Due back: <strong><?=h($lastDue)?></strong></div><?php endif; ?></div></div>
<?php if(!$lines): ?><div class="alert alert-info"><?=$planned?'No reserved items for this selection.':'Nothing is out for this selection.'?></div><?php else: ?>
<div class="table-responsive"><table class="table table-bordered align-middle"><thead><tr><th>Item</th><th>Serial / SKU</th><th class="text-end">Qty</th><th><?=$planned?'Out on':'Out since'?></th><th>Due back</th><th>Condition out</th><th>Returned</th></tr></thead><tbody>
<?php foreach($lines as $l): ?><tr><td><?=h($l['item'])?><?=count($names)>1?'<div class="small text-muted">'.h($l['holder']).'</div>':''?></td><td class="small"><?=h($ser[$l['item_id']] ?? '')?></td><td class="text-end"><?=h(fmt_q($l['qty']))?></td><td class="small"><?=h($l['out'])?></td><td class="small"><?=h($l['due'] ?: '')?></td><td class="small"><span class="box"></span> Good &nbsp;<span class="box"></span> Note:</td><td class="small"><span class="box"></span> Good<br><span class="box"></span> Damaged<br><span class="box"></span> Missing</td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<p class="small"><?=nl2br(h($terms))?></p>
<div class="row g-4 mt-2"><div class="col-6"><div class="sig"></div><div class="small">Released by (signature &amp; date)</div></div><div class="col-6"><div class="sig"></div><div class="small">Borrower (signature &amp; date)</div></div>
<div class="col-6"><div class="sig"></div><div class="small">Received back by (signature &amp; date)</div></div><div class="col-6"><div class="sig"></div><div class="small">Notes on return</div></div></div>
</div></body></html>
