<?php
function item_snapshot($row){
  $cat = !empty($row['category_id']) ? cat_path($row['category_id']) : '';
  $loc = !empty($row['location_id']) ? q("SELECT name FROM locations WHERE id=?", [$row['location_id']])->fetchColumn() : '';
  $cf = []; $data = json_decode($row['custom_data'] ?? '[]', true) ?: [];
  if ($data) { $labels = []; foreach (q("SELECT id,label FROM custom_fields")->fetchAll() as $f) $labels[$f['id']] = $f['label']; foreach ($data as $fid => $v) if ($v !== '') $cf[] = ($labels[$fid] ?? "Field $fid").': '.$v; }
  return ['Name'=>$row['name'], 'SKU'=>$row['sku'], 'Barcode'=>$row['barcode'], 'Type'=>$row['type'], 'Category'=>$cat, 'Location'=>$loc,
          'Description'=>$row['description'], 'Unit'=>$row['unit'], 'Low-stock alert'=>($row['min_qty'] + 0), 'Serial'=>$row['serial'], 'Par level'=>$row['par_qty'] === null ? '' : ($row['par_qty'] + 0), 'Reorder quantity'=>$row['reorder_qty'] === null ? '' : ($row['reorder_qty'] + 0), 'Unit cost'=>$row['unit_cost'] === null ? '' : ($row['unit_cost'] + 0), 'Codes'=>($row['_codes'] ?? item_codes_summary((int)($row['id'] ?? 0))), 'Custom fields'=>implode('; ', $cf)];
}
function log_item_changes($id, $before, $after){
  foreach ($after as $f => $v) {
    $o = (string)($before[$f] ?? ''); $n = (string)$v;
    if ($o !== $n) q("INSERT INTO item_history(item_id,user_id,action,field,old_value,new_value) VALUES(?,?,?,?,?,?)", [$id, me()['id'] ?? null, 'edit', $f, mb_substr($o,0,500), mb_substr($n,0,500)]);
  }
}
function log_item_event($id, $action, $note = ''){
  q("INSERT INTO item_history(item_id,user_id,action,new_value) VALUES(?,?,?,?)", [$id, me()['id'] ?? null, $action, mb_substr($note,0,500)]);
}
