<?php
// Bulk import of equipment lists with duplicate merging and a preview step.
// Supports two scraped formats: "Equipment List" (Record ID, Equipment, Serial, Ownership...) and "Inventory List" (Status, Inventory Name, Inventory ID, Crew Member, Station...).

function imp_norm($s){ return preg_replace('/[^a-z0-9]/', '', strtolower((string)$s)); }
function imp_clean($s){ return trim(preg_replace('/\s+/', ' ', (string)$s)); }
function imp_title($s){
  if (preg_match('/[a-z]/', $s) || !preg_match('/[A-Z]/', $s) || preg_match('/[A-Za-z]\d|\d[A-Za-z]/', $s)) return $s;
  $t = mb_convert_case(mb_strtolower($s), MB_CASE_TITLE);
  return preg_replace_callback('/\b(Aed|Eoc|Ema|Tv|Vcr|Dvd|Hp|Id|Cert|Efj|Led|Pc|Uv|Gps|Scba|Usb|Ac|Dc|Pdf|Cpu)\b/', fn($m) => strtoupper($m[1]), $t);
}

function imp_parse($path){
  $h = @fopen($path, 'r'); if (!$h) return [[], []];
  $hdr = fgetcsv($h); if (!$hdr) { fclose($h); return [[], []]; }
  $hdr[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$hdr[0]); $hdr = array_map('trim', $hdr);
  $rows = [];
  while (count($rows) < 20000 && ($r = fgetcsv($h)) !== false) {
    if (count($r) === 1 && trim((string)$r[0]) === '') continue;
    $r = array_pad($r, count($hdr), ''); $rows[] = array_combine($hdr, array_slice($r, 0, count($hdr)));
  }
  fclose($h); return [$hdr, $rows];
}
function imp_profile($hdr){
  if (in_array('Equipment', $hdr, true) && in_array('Ownership', $hdr, true) && in_array('Serial', $hdr, true)) return 'equip';
  if (in_array('Inventory Name', $hdr, true) && in_array('Inventory ID', $hdr, true)) return 'inv';
  if (in_array('Name', $hdr, true) && in_array('Type', $hdr, true) && in_array('Quantity', $hdr, true)) return 'gp';
  return null;
}
const IMP_PROFILE_LABEL = ['equip' => 'Equipment List', 'inv' => 'Inventory List', 'gp' => 'GPIMS template'];
const IMP_TEMPLATE_COLS = ['Name', 'Type', 'Category', 'Location', 'Quantity', 'Unit', 'Alert At', 'Serial', 'Tag', 'Unit Cost', 'Assigned To', 'Status', 'Notes'];

const IMP_TAG_RE = '/^[A-Za-z]{0,3}-?\d{2,7}[A-Za-z]?\d{0,4}$/';
function imp_is_tag($s){ return (bool)preg_match(IMP_TAG_RE, $s) && preg_match('/\d{3}/', $s); }
function imp_serial($s){
  $s = imp_clean($s); $n = imp_norm($s);
  if ($n === '' || in_array($n, ['na', 'none', 'unknown', 'unkown'], true) || preg_match('/^0+$/', $n)) return '';
  return $s;
}

// ----- groups, people, locations -----
const IMP_GROUPS = ['not assigned', 'office', 'ema', 'ema - emergency management', 'cer - cert', 'cer', 'cert', 'eoc - eoc', 'eoc', 'emarv', 'rehab', 'aid 1003', 'auglaize ema', 'er support', 'station 1', 'hazt1', 'masc2', '51 - st.marys township', 'mfd - minster fire department', 'sm - city of st. marys fire department', 'police dept', 'waynesfield', 'bocc', 'auglaize county', 'sheriff'];
const IMP_LOC_ALIAS = ['ema - emergency management' => 'EMA', 'cer - cert' => 'CERT', 'cer' => 'CERT', 'eoc - eoc' => 'EOC', '51 - st.marys township' => 'St. Marys Township', 'mfd - minster fire department' => 'Minster Fire Dept', 'sm - city of st. marys fire department' => 'St. Marys Fire Dept', 'st. marys eoc' => 'EOC', 'wapakoneta ema office' => 'EMA Office', 'ema office' => 'EMA Office', 'auglaize ema' => 'Auglaize EMA'];
function imp_is_group($t){ $l = strtolower(trim($t)); return in_array($l, IMP_GROUPS, true) || preg_match('/^\d{3,5}$/', $l) || strpos($l, ' - ') !== false || preg_match('/^[a-z]{2,5}\d$/', $l); }
function imp_loc_name($t){ $l = strtolower(trim($t)); return IMP_LOC_ALIAS[$l] ?? imp_clean($t); }
// "Last, First M" -> person display name; null when it cannot be read as a name
function imp_person($last, $first){
  $last = imp_clean(preg_replace('/\bEMA\b/i', '', $last)); $first = imp_clean(preg_replace('/\([^)]*\)/', '', $first));
  if ($last === '' || $first === '') return null;
  return imp_title($first.' '.$last);
}
function imp_owner($raw){
  $toks = array_values(array_filter(array_map('trim', explode(',', (string)$raw)), fn($t) => $t !== ''));
  $groups = []; $names = [];
  foreach ($toks as $t) { if (imp_is_group($t)) $groups[] = $t; else $names[] = $t; }
  $people = []; $loose = [];
  for ($i = 0; $i + 1 < count($names); $i += 2) { $p = imp_person($names[$i], $names[$i + 1]); if ($p) $people[] = $p; }
  if (count($names) % 2 === 1) $loose[] = $names[count($names) - 1];
  $locs = array_values(array_filter($groups, fn($g) => strtolower($g) !== 'not assigned'));
  $loc = '';
  if ($locs) {
    $loc = imp_loc_name(end($locs));
    $low = array_map('strtolower', $locs); if (in_array('office', $low, true) && in_array('ema', $low, true)) $loc = 'EMA Office';
  }
  return ['people' => $people, 'loc' => $loc, 'loose' => $loose];
}

// ----- name / category / type heuristics -----
const IMP_CONSUMABLE_RE = '/juice|gatorade|powerade|bottled water|purified water|^coffee$|\bcups\b|napkins|garbage bags|plastic bags|\brags\b|cleaning solution|towels|foam containers|gloves|face masks|n-95|diapers|wipes|toilet liners|absorbent|absorbant|\bpads\b|peat moss|\btape\b|\bboom\b|plastic roll|roll of plastic|tampons|hygene|\bfilters\b/i';
function imp_cat_guess($name, $loc = ''){
  if (stripos($loc, 'rehab') !== false) return 'Rehab';
  $rules = [
    '/charger/i' => 'Communication / Chargers', '/batter/i' => 'Communication / Batteries', '/pager|\bg1\b|unication/i' => 'Communication / Pagers',
    '/antenna/i' => 'Communication / Antennas', '/phone|voip|yealink|vtech|obihai|nortel|cisco/i' => 'Communication / Phones',
    '/radio|\befj\b|ef ?johnson|kenwood|motorola|\bxts|vertex|harris|baofeng|bofeng|yaesu|alinco|marcs|tk-?760|transc|repeater|zetron|ma-com|encoder/i' => 'Communication / Radios',
    '/hole punch|laminator|folder|cutter paper|paper cutter|shredder|heat ?seal|id printer|napkins|plates/i' => 'Office / Supplies',
    '/computer|laptop|monitor|keyboard|\bmouse|printer|scanner|hard drive|\bdvd|projector|media player|smart ?board|copier|\bfax|speaker|\btv\b|vizio|visio|roku|toshiba|\bonn\b|camera|cobracam|video|\bvcr|\btower\b|\bhp\b|dell|acer|samsung/i' => 'Office / IT & AV',
    '/chair|desk|cabinet|table|hutch|\brack\b|bulletin|filing|drawer|refrigerator|coffee|keurig|microwave|cart\b|magazine/i' => 'Office / Furniture & Appliances',
    '/generator|genset|power supply|compressor|\bcords?\b|extension|power unit|\bhose\b|light|spot ?light|sunspot|wobble|diesel|gas can/i' => 'Power & Lighting',
    '/\baed\b|first aid|stethoscope|bp cuff|bedpan|urinal|commode|iv stand|\bcots?\b|blanket|pillow|scrubs|gown|lab coat|diaper|\bkit\b|kits\b|shroud|body bag|mylar|lifter|stokes|back ?board|hygene|toilet|respirator|n-95|gloves|face masks|wipes|towels/i' => 'Medical & Shelter',
    '/absorb|\bboom\b|peat|decon|spreader|lukas|\bram\b|winch|log chain|tow strap|shovel|sledge|hydro wrench|guzzler|pump|blower|filters|purification|\btank\b|cascade|scba|fill station|cribbing|tarp|\bcones?\b|tape|plastic|\bpads\b|particle|\bmeter\b|thermal|tripod|extinguisher|vest|toolbox|tools/i' => 'Rescue & Hazmat',
  ];
  foreach ($rules as $re => $path) if (preg_match($re, $name)) return $path;
  return '';
}
function imp_inv_cat($raw){
  $raw = imp_clean($raw); if ($raw === '') return '';
  $code = preg_match('/^\[([A-Z]+)\]/', $raw, $m) ? $m[1] : ''; $txt = imp_clean(preg_replace('/^\[[A-Z]+\]/', '', $raw));
  if ($code === 'RADI') return 'Communication / Radios / Portable';
  if ($code === 'PAGE') return 'Communication / Pagers';
  if ($code === 'BATTERIES') return 'Communication / Batteries';
  if ($code === 'ELEC') return 'Power & Lighting';
  if ($code === 'REHB') return 'Rehab';
  return $txt;
}
function imp_status_from_name($name){
  $st = 'available';
  if (preg_match('/\b(destroyed|retired|scrap(ped)?)\b/i', $name)) $st = 'retired';
  elseif (preg_match('/\b(oos|out of service|broken)\b/i', $name)) $st = 'maintenance';
  $clean = imp_clean(preg_replace('/\s*-\s*(destroyed|retired|scrapped|oos|out of service|broken)\b/i', '', $name));
  return [$clean, $st];
}
function imp_finish($r, $guess){
  [$r['name'], $st] = imp_status_from_name($r['name']); if ($r['status'] === 'available') $r['status'] = $st;
  if ($r['name'] === '') $r['name'] = 'Unnamed item'.($r['src_id'] !== '' ? ' #'.$r['src_id'] : '').($r['tag'] ? ' '.$r['tag'] : '');
  if (preg_match(IMP_CONSUMABLE_RE, $r['name'])) $r['type'] = 'consumable';
  elseif ($r['qty'] > 1) $r['type'] = 'returnable';
  else $r['type'] = 'asset';
  if ($r['type'] === 'asset') $r['qty'] = 1;
  if ($r['type'] === 'consumable' && $r['qty'] < 1) $r['qty'] = 0;
  if ($r['cat'] === '' && $guess) $r['cat'] = imp_cat_guess($r['name'], $r['loc']);
  $r['keys'] = ['serial' => imp_norm($r['serial']), 'tag' => imp_norm($r['tag'])];
  return $r;
}
function imp_blank($src, $srcId){ return ['src' => $src, 'src_id' => (string)$srcId, 'name' => '', 'tag' => '', 'serial' => '', 'qty' => 1, 'cat' => '', 'loc' => '', 'people' => [], 'notes' => [], 'alt' => [], 'status' => 'available', 'type' => 'asset', 'vkeys' => []]; }

// ----- profile readers -----
function imp_canon_equip($row, $guess){
  $r = imp_blank('A', $row['Record ID'] ?? ''); $eq = imp_clean($row['Equipment'] ?? ''); $qty = null;
  if (preg_match('/^((?:[A-Za-z]{1,3}-?)?\d{3,7})\s*-\s*(.+)$/', $eq, $m) || preg_match('/^(\d{4,7})\s+([A-Za-z].+)$/', $eq, $m)) { $r['tag'] = $m[1]; $eq = imp_clean($m[2]); $tagged = true; }
  elseif ($eq !== '' && imp_is_tag($eq)) { $r['tag'] = $eq; $tagged = false; }
  else $tagged = false;
  if (preg_match('/^(.+?)\s+-\s+(.+)$/', $eq, $m)) {
    if (imp_norm($m[1]) === imp_norm($m[2])) $eq = $m[1];
    elseif (preg_match('/^\d{1,4}$/', $m[2])) { $qty = (int)$m[2]; $eq = $m[1]; }
    elseif (preg_match('/^(unknown|unkown|0+)$/i', $m[2])) $eq = $m[1];
  }
  $ser = imp_clean($row['Serial'] ?? '');
  if (preg_match('/^(\d{1,4})(\s*(total|pails|boxes))?$/i', $ser, $m) && ((int)$m[1] <= 999 || !empty($m[2]))) { if ($qty === null) $qty = (int)$m[1]; $ser = ''; }
  else $ser = imp_serial($ser);
  $r['serial'] = $ser; $r['qty'] = max(1, (int)$qty);
  $r['name'] = $tagged ? imp_title($eq).' '.$r['tag'] : (imp_is_tag($eq) ? $eq : imp_title($eq));
  if ($eq === '') $r['name'] = '';
  $o = imp_owner($row['Ownership'] ?? ''); $r['loc'] = $o['loc']; $r['people'] = $o['people'];
  if ($o['loose']) $r['notes'][] = 'Also listed under: '.implode(', ', $o['loose']);
  if (imp_clean($row['Ownership'] ?? '') !== '') $r['notes'][] = 'Ownership in Equipment List: '.imp_clean($row['Ownership']);
  $nm = imp_clean($row['Equipment'] ?? ''); $base = preg_replace('/^.*?\s*-\s*/', '', $nm); $pre = preg_replace('/\s+-\s+.*$/', '', $nm);
  $r['vkeys'] = array_values(array_unique(array_filter([imp_norm($pre), preg_match('/\d[rR]$/', $pre) ? imp_norm(substr($pre, 0, -1)) : ''])));
  return imp_finish($r, $guess);
}
// Reader for the GPIMS template (Admin > Import > download template).
function imp_canon_gp($row, $guess, $n){
  $g = fn($k) => imp_clean($row[$k] ?? '');
  $r = imp_blank('C', 'row '.$n); $r['name'] = $g('Name'); $r['serial'] = $g('Serial'); $r['tag'] = $g('Tag');
  $r['cat'] = trim(preg_replace('/\s*[\/>]\s*/', ' / ', $g('Category')), ' /'); $r['loc'] = $g('Location'); $r['unit'] = mb_substr($g('Unit'), 0, 20);
  $qty = str_replace(',', '', $g('Quantity')); $qty = is_numeric($qty) ? max(0, (float)$qty) : null;
  $mq = str_replace(',', '', $g('Alert At')); $r['min_qty'] = is_numeric($mq) ? max(0, (float)$mq) : 0;
  $uc = str_replace(['$', ','], '', $g('Unit Cost')); $r['unit_cost'] = is_numeric($uc) ? max(0, (float)$uc) : null;
  $t = strtolower($g('Type')); $map = ['asset' => 'asset', 'equipment' => 'asset', 'serialized' => 'asset', 'returnable' => 'returnable', 'counted' => 'returnable', 'counted gear' => 'returnable', 'consumable' => 'consumable', 'supply' => 'consumable', 'supplies' => 'consumable'];
  if (isset($map[$t])) $r['type'] = $map[$t];
  elseif (preg_match(IMP_CONSUMABLE_RE, $r['name'])) $r['type'] = 'consumable';
  elseif ($qty !== null && $qty > 1) $r['type'] = 'returnable'; else $r['type'] = 'asset';
  if ($r['type'] === 'asset') $r['qty'] = 1; else $r['qty'] = $qty === null ? ($r['type'] === 'consumable' ? 0 : 1) : $qty;
  $st = strtolower($g('Status')); $r['status'] = preg_match('/retire|disposed|destroy|gone/', $st) ? 'retired' : (preg_match('/maint|repair|service|broken|oos/', $st) ? 'maintenance' : 'available');
  $as = $g('Assigned To'); if ($as !== '') { if (preg_match('/^([^,]+),\s*(.+)$/', $as, $m)) { $p = imp_person($m[1], $m[2]); $as = $p ?: $as; } $r['people'][] = $as; }
  if ($g('Notes') !== '') $r['notes'][] = $g('Notes');
  if ($r['name'] === '') $r['name'] = 'Unnamed item #'.$r['src_id'];
  if ($r['cat'] === '' && $guess) $r['cat'] = imp_cat_guess($r['name'], $r['loc']);
  $r['keys'] = ['serial' => imp_norm($r['serial']), 'tag' => imp_norm($r['tag'])];
  return $r;
}
function imp_template_csv($examples){
  $o = fopen('php://temp', 'w+'); fputcsv($o, IMP_TEMPLATE_COLS);
  if ($examples) foreach ([
    ['Portable radio', 'asset', 'Communication / Radios / Portable', 'EMA Office', 1, '', '', 'SN123456', 'A-1001', 249.99, 'Smith, Jane', 'available', 'Example: one serialized item assigned to a person'],
    ['Safety vest', 'returnable', 'Rescue / Apparel', 'EOC', 12, 'ea', '', '', '', 18, '', 'available', 'Example: counted gear, no serial numbers'],
    ['Bottled water', 'consumable', 'Rehab / Supplies', 'Rehab Trailer', 48, 'bottles', 24, '', '', 0.35, '', 'available', 'Example: supply; alert when 24 or fewer'],
  ] as $row) fputcsv($o, $row);
  rewind($o); $c = stream_get_contents($o); fclose($o); return "\xEF\xBB\xBF".$c;
}
function imp_canon_inv($row, $guess, $containers){
  $r = imp_blank('B', $row['__no'] ?? ''); $n0 = imp_clean($row['Inventory Name'] ?? ''); $id0 = imp_clean($row['Inventory ID'] ?? '');
  $serialLike = fn($s) => (bool)preg_match('/^[A-Za-z]{1,3}\d{1,2}[A-Za-z]?\d{4,}[A-Za-z0-9]*$/', $s);
  $loc = ''; $name = $n0;
  if ($id0 !== '' && isset($containers[$id0])) { $loc = $id0; }
  elseif ($id0 !== '' && $serialLike($id0)) { $r['serial'] = $id0; }
  elseif ($id0 !== '' && preg_match('/^\d{4,7}$/', $id0)) { $r['tag'] = $id0; }
  elseif ($id0 !== '' && $n0 !== '' && imp_norm($id0) !== imp_norm($n0)) {
    if (preg_match('/^(?:[A-Za-z]+\s+)?([A-Za-z]{0,2}\d{3,7}[A-Za-z]?)$/', $n0, $m) && !preg_match('/\b(efj|cert)\b/i', $n0)) { $r['tag'] = $m[1]; $name = $id0; }
    else { $name = mb_strlen($id0) > mb_strlen($n0) ? $id0 : $n0; $alt = $name === $id0 ? $n0 : $id0; if ($alt !== '' && stripos($name, $alt) === false) $r['alt'][] = $alt; }
  }
  elseif ($n0 === '' && $id0 !== '' && !$serialLike($id0) && !isset($containers[$id0])) $name = $id0;
  elseif ($n0 !== '' && imp_is_tag($n0) && !preg_match('/\b(efj|cert)\b/i', $n0)) { $r['tag'] = $n0; }
  $r['name'] = $name;
  $stn = imp_clean($row['Station'] ?? ''); if ($loc === '' && $stn !== '') $loc = imp_loc_name($stn); $r['loc'] = $loc;
  $crew = imp_clean($row['Crew Member'] ?? '');
  if ($crew !== '' && preg_match('/^([^,]+),\s*(.+)$/', $crew, $m)) { $p = imp_person($m[1], $m[2]); if ($p) $r['people'][] = $p; else $r['notes'][] = 'Crew member: '.$crew; }
  elseif ($crew !== '') $r['notes'][] = 'Crew member: '.$crew;
  if (!empty($row['Truck'])) $r['notes'][] = 'Truck: '.imp_clean($row['Truck']);
  if (!empty($row['Compartment'])) $r['notes'][] = 'Compartment: '.imp_clean($row['Compartment']);
  if (!empty($row['Date of Manufacture'])) $r['notes'][] = 'Date of manufacture in Inventory List: '.substr(imp_clean($row['Date of Manufacture']), 0, 10);
  if ($stn !== '') $r['notes'][] = 'Station in Inventory List: '.$stn.($id0 !== '' && isset($containers[$id0]) ? ' / '.$id0 : '');
  $r['cat'] = imp_inv_cat($row['Inventory Type'] ?? '');
  $r['vkeys'] = array_values(array_unique(array_filter([imp_norm($n0), imp_norm($id0 !== '' && !$serialLike($id0) && !isset($containers[$id0]) ? $id0 : ''), imp_norm($name)])));
  $r = imp_finish($r, $guess);
  if ($r['cat'] === '' && $guess) $r['cat'] = imp_cat_guess($r['name'], $r['loc']);
  return $r;
}

// ----- merging -----
function imp_merge($x, $y){
  $alpha = fn($s) => preg_match_all('/[A-Za-z]/', $s);
  if ($alpha($y['name']) > $alpha($x['name']) && !preg_match('/^Unnamed item/', $y['name']) || preg_match('/^Unnamed item/', $x['name']) && !preg_match('/^Unnamed item/', $y['name'])) { $x['alt'][] = $x['name']; $x['name'] = $y['name']; } elseif (imp_norm($x['name']) !== imp_norm($y['name'])) $x['alt'][] = $y['name'];
  foreach (['tag', 'serial', 'loc', 'cat'] as $f) if ($x[$f] === '' && $y[$f] !== '') $x[$f] = $y[$f];
  $x['qty'] = max($x['qty'], $y['qty']); $x['people'] = array_values(array_unique(array_merge($x['people'], $y['people'])));
  $x['notes'] = array_values(array_unique(array_merge($x['notes'], $y['notes']))); $x['alt'] = array_values(array_unique(array_filter($x['alt'], fn($a) => imp_norm($a) !== imp_norm($x['name']) && !preg_match('/^Unnamed item/', $a))));
  $x['vkeys'] = array_values(array_unique(array_merge($x['vkeys'], $y['vkeys'])));
  foreach (['retired', 'maintenance'] as $s) if ($y['status'] === $s || $x['status'] === $s) { $x['status'] = $s; break; }
  if ($y['type'] === 'consumable' || $x['type'] === 'consumable') $x['type'] = 'consumable'; elseif ($x['qty'] > 1) $x['type'] = 'returnable';
  if ($x['type'] === 'asset') $x['qty'] = 1;
  $x['src'] = $x['src'] === $y['src'] ? $x['src'] : 'AB'; $x['src_ids'] = array_values(array_unique(array_merge($x['src_ids'] ?? [$x['src_id']], $y['src_ids'] ?? [$y['src_id']])));
  $x['keys'] = ['serial' => imp_norm($x['serial']), 'tag' => imp_norm($x['tag'])];
  return $x;
}

// Build the full plan from parsed files: [['profile'=>..,'label'=>..,'rows'=>[..]], ...]
function imp_plan($files, $guess = true){
  $recs = [];
  foreach ($files as $fi => $f) {
    $containers = [];
    if ($f['profile'] === 'inv') { $cnt = array_count_values(array_map(fn($r) => imp_clean($r['Inventory ID'] ?? ''), $f['rows'])); foreach ($cnt as $k => $v) if ($k !== '' && $v >= 3 && !preg_match('/^[A-Za-z]{1,3}\d{1,2}[A-Za-z]?\d{4,}/', $k)) {
        $nm = array_filter(array_map(fn($r) => (imp_clean($r['Inventory ID'] ?? '') === $k) ? imp_clean($r['Inventory Name'] ?? '') : null, $f['rows']), fn($x) => $x !== null && $x !== '');
        $tagLike = count(array_filter($nm, fn($x) => imp_is_tag($x) || preg_match('/^(?:[A-Za-z]+\s+)?[A-Za-z]{0,2}\d{3,7}[A-Za-z]?$/', $x)));
        if (count($nm) >= 3 && $tagLike * 2 < count($nm)) $containers[$k] = true;
      } }
    foreach ($f['rows'] as $ri => $row) {
      if ($f['profile'] === 'inv') $row['__no'] = 'row '.($ri + 2);
      $r = $f['profile'] === 'equip' ? imp_canon_equip($row, $guess) : ($f['profile'] === 'gp' ? imp_canon_gp($row, $guess, $ri + 2) : imp_canon_inv($row, $guess, $containers));
      $r['src_ids'] = [$r['src_id']]; $r['file'] = $fi; $r['label'] = $f['label']; $recs[] = $r;
    }
  }
  // existing items
  $ex = []; foreach (q("SELECT id,name,sku,serial FROM items")->fetchAll() as $e) { if ($e['serial']) $ex['s:'.imp_norm($e['serial'])] = $e; if ($e['sku']) $ex['t:'.imp_norm($e['sku'])] = $e; }
  $al = []; foreach (q("SELECT id,name,description FROM items WHERE description LIKE 'Also listed as: %'")->fetchAll() as $e) if (preg_match('/^Also listed as: ([^\n]*)/', $e['description'], $m)) foreach (explode(';', $m[1]) as $a) $al[imp_norm(trim($a))] = $e;
  foreach (q("SELECT id,name FROM items WHERE description LIKE '%Imported from%' AND ((serial IS NOT NULL AND serial<>'') OR (sku IS NOT NULL AND sku<>''))")->fetchAll() as $e) $al[imp_norm($e['name'])] = $e; // merged-in twin of an identified item
  $nm = []; foreach (q("SELECT id,name FROM items WHERE description LIKE '%Imported from%' AND (serial IS NULL OR serial='') AND (sku IS NULL OR sku='')")->fetchAll() as $e) $nm[imp_norm($e['name'])][] = $e;
  // union-find on auto-merge keys
  $n = count($recs); $par = range(0, max(0, $n - 1)); $find = function($i) use (&$par, &$find) { while ($par[$i] !== $i) { $par[$i] = $par[$par[$i]]; $i = $par[$i]; } return $i; };
  $bySer = []; $byTag = [];
  foreach ($recs as $i => $r) { if (strlen($r['keys']['serial']) >= 4) $bySer[$r['keys']['serial']][] = $i; if (strlen($r['keys']['tag']) >= 4) $byTag[$r['keys']['tag']][] = $i; }
  foreach ($bySer as $ids) for ($a = 0; $a < count($ids); $a++) for ($b = $a + 1; $b < count($ids); $b++) {
    $x = $recs[$ids[$a]]; $y = $recs[$ids[$b]];
    if ($x['keys']['tag'] !== '' && $y['keys']['tag'] !== '' && $x['keys']['tag'] !== $y['keys']['tag']) continue; // two different tags: different units sharing a typo'd serial
    if ($x['file'] === $y['file'] && $x['keys']['tag'] === '' && $y['keys']['tag'] === '' && imp_norm($x['name']) === imp_norm($y['name']) && !preg_match('/^Unnamed/', $x['name'])) continue; // identical untagged rows in one list: could be separate units
    $par[$find($ids[$b])] = $find($ids[$a]);
  }
  foreach ($byTag as $ids) for ($a = 0; $a < count($ids); $a++) for ($b = $a + 1; $b < count($ids); $b++) {
    $x = $recs[$ids[$a]]; $y = $recs[$ids[$b]];
    if ($x['file'] === $y['file']) continue;
    if ($x['keys']['serial'] !== '' && $y['keys']['serial'] !== '' && $x['keys']['serial'] !== $y['keys']['serial']) continue;
    $par[$find($ids[$b])] = $find($ids[$a]);
  }
  $groups = []; foreach ($recs as $i => $r) $groups[$find($i)][] = $i;
  $items = [];
  foreach ($groups as $ids) { $m = $recs[$ids[0]]; for ($k = 1; $k < count($ids); $k++) { $m = imp_merge($m, $recs[$ids[$k]]); $m['merged'] = ($m['merged'] ?? 1) + 1; } $m['merged'] = $m['merged'] ?? 1; $items[] = $m; }
  usort($items, fn($a, $b) => strcasecmp($a['name'], $b['name']));
  // already in GPIMS?
  foreach ($items as $k => $it) {
    $hit = ($it['keys']['serial'] !== '' && isset($ex['s:'.$it['keys']['serial']])) ? $ex['s:'.$it['keys']['serial']] : (($it['keys']['tag'] !== '' && isset($ex['t:'.$it['keys']['tag']])) ? $ex['t:'.$it['keys']['tag']] : null);
    if (!$hit && $it['keys']['serial'] === '' && $it['keys']['tag'] === '' && isset($al[imp_norm($it['name'])])) $hit = $al[imp_norm($it['name'])];
    if (!$hit && $it['keys']['serial'] === '' && $it['keys']['tag'] === '') { $nk = imp_norm($it['name']); if (!empty($nm[$nk])) $hit = array_shift($nm[$nk]); } // untagged repeat: match earlier import by name, one for one
    $items[$k]['exists'] = $hit ? ['id' => (int)$hit['id'], 'name' => $hit['name']] : null;
  }
  // name-based candidates: a B-only item vs an A-only item (EFJ 22 vs EFJ 22R etc.)
  $pairs = []; $idx = [];
  foreach ($items as $i => $it) if ($it['src'] === 'A' && !$it['exists']) foreach ($it['vkeys'] as $vk) if (strlen($vk) >= 4) $idx[$vk][] = $i;
  foreach ($items as $j => $it) if ($it['src'] === 'B' && !$it['exists']) {
    $cand = []; foreach ($it['vkeys'] as $vk) foreach ($idx[$vk] ?? [] as $i) {
      $a = $items[$i]; if ($a['keys']['serial'] !== '' && $it['keys']['serial'] !== '' && $a['keys']['serial'] !== $it['keys']['serial']) continue;
      if ($a['keys']['tag'] !== '' && $it['keys']['tag'] !== '' && $a['keys']['tag'] !== $it['keys']['tag']) continue;
      $cand[$i] = true;
    }
    foreach (array_keys($cand) as $i) $pairs[] = ['a' => $i, 'b' => $j, 'default' => count($cand) === 1 && preg_match('/\d/', $it['name']) && $items[$i]['type'] === $it['type']];
  }
  return ['items' => $items, 'pairs' => $pairs, 'files' => array_map(fn($f) => ['label' => $f['label'], 'profile' => $f['profile'], 'rows' => count($f['rows'])], $files), 'guess' => $guess, 'made' => time()];
}

// ----- apply -----
function imp_person_key($name){
  $p = preg_split('/\s+/', trim(preg_replace('/\([^)]*\)/', '', $name))); if (count($p) < 2) return null;
  $nick = ['mike' => 'michael', 'joe' => 'joseph', 'jim' => 'james', 'bob' => 'robert', 'bill' => 'william', 'chuck' => 'charles', 'rick' => 'richard', 'rich' => 'richard', 'nick' => 'nicholas', 'steve' => 'steven', 'tom' => 'thomas', 'dave' => 'david', 'dan' => 'daniel', 'matt' => 'matthew'];
  $last = imp_norm(end($p)); $given = array_map(fn($g) => $nick[strtolower($g)] ?? strtolower($g), array_slice($p, 0, -1));
  return [$last, $given];
}
function imp_given_compat($a, $b){
  $pre = fn($x, $y) => min(strlen($x), strlen($y)) >= 3 && (strpos($x, $y) === 0 || strpos($y, $x) === 0);
  if ($a[0] === $b[0] || $pre($a[0], $b[0])) return true;
  foreach ($b as $g) if (strlen($g) >= 3 && ($g === $a[0] || $pre($g, $a[0]))) return true;
  foreach ($a as $g) if (strlen($g) >= 3 && ($g === $b[0] || $pre($g, $b[0]))) return true;
  return false;
}
function imp_person_find($name, &$reg){
  $k = imp_person_key($name); if (!$k) return null;
  foreach ($reg as $id => $e) if ($e['k'][0] === $k[0] && imp_given_compat($e['k'][1], $k[1])) return $id;
  return null;
}
function imp_apply($plan, $mergePairs){
  global $pdo;
  $items = $plan['items'];
  // apply confirmed pair merges (b into a)
  $gone = [];
  foreach ($plan['pairs'] as $pi => $p) if (in_array((string)$pi, $mergePairs, true) && !isset($gone[$p['a']]) && !isset($gone[$p['b']])) { $items[$p['a']] = imp_merge($items[$p['a']], $items[$p['b']]); $items[$p['a']]['merged'] = ($items[$p['a']]['merged'] ?? 1) + ($items[$p['b']]['merged'] ?? 1); $gone[$p['b']] = true; }
  $cats = []; foreach (q("SELECT id,name,parent_id FROM categories")->fetchAll() as $c) $cats[strtolower($c['name']).'|'.(int)$c['parent_id']] = (int)$c['id'];
  $locs = []; foreach (q("SELECT id,name FROM locations")->fetchAll() as $l) $locs[strtolower($l['name'])] = (int)$l['id'];
  $reg = []; foreach (q("SELECT id,name FROM people")->fetchAll() as $p) { $k = imp_person_key($p['name']); if ($k) $reg[(int)$p['id']] = ['k' => $k, 'name' => $p['name']]; }
  $stat = ['items' => 0, 'skipped' => 0, 'cats' => 0, 'locs' => 0, 'people' => 0, 'checkouts' => 0];
  $uid = me()['id'] ?? null;
  $pdo->beginTransaction(); $made = ['cats' => [], 'locs' => [], 'people' => []];
  try {
    q("INSERT INTO import_batches(user_id) VALUES(?)", [$uid]); $batch = (int)$pdo->lastInsertId();
    foreach ($items as $i => $it) {
      if (isset($gone[$i])) continue; if ($it['exists']) { $stat['skipped']++; continue; }
      $catId = null;
      if ($it['cat'] !== '') { $parent = 0; foreach (array_map('trim', explode('/', $it['cat'])) as $seg) { if ($seg === '') continue; $key = strtolower($seg).'|'.$parent; if (!isset($cats[$key])) { q("INSERT INTO categories(name,parent_id) VALUES(?,?)", [$seg, $parent ?: null]); $cats[$key] = (int)$pdo->lastInsertId(); $made['cats'][] = $cats[$key]; $stat['cats']++; } $parent = $cats[$key]; } $catId = $parent ?: null; }
      $locId = null; if ($it['loc'] !== '') { $lk = strtolower($it['loc']); if (!isset($locs[$lk])) { q("INSERT INTO locations(name) VALUES(?)", [$it['loc']]); $locs[$lk] = (int)$pdo->lastInsertId(); $made['locs'][] = $locs[$lk]; $stat['locs']++; } $locId = $locs[$lk]; }
      $desc = []; if ($it['alt']) $desc[] = 'Also listed as: '.implode('; ', $it['alt']);
      foreach ($it['notes'] as $n) $desc[] = $n; $srcs = implode(', ', $it['src_ids'] ?? []);
      $desc[] = 'Imported from '.($it['src'] === 'AB' ? 'both lists' : ($it['src'] === 'A' ? 'Equipment List' : ($it['src'] === 'C' ? 'your CSV template' : 'Inventory List'))).($srcs ? ' ('.$srcs.')' : '').($it['merged'] > 1 ? '; '.$it['merged'].' entries merged' : '').'.';
      if ($it['type'] === 'consumable' && !$it['qty'] && $it['src'] !== 'C') $desc[] = 'Quantity was not in the source list. Do a recount.';
      $status = $it['status'];
      q("INSERT INTO items(name,sku,type,category_id,location_id,description,qty,serial,status,import_batch,unit,min_qty,unit_cost) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)", [mb_substr($it['name'], 0, 150), $it['tag'] !== '' ? mb_substr($it['tag'], 0, 80) : null, $it['type'], $catId, $locId, mb_substr(implode("\n", $desc), 0, 4000), $it['type'] === 'asset' ? 1 : $it['qty'], $it['serial'] !== '' ? mb_substr($it['serial'], 0, 100) : null, $status === 'retired' ? 'retired' : ($status === 'maintenance' ? 'maintenance' : 'available'), $batch, ($it['unit'] ?? '') !== '' ? $it['unit'] : null, $it['type'] === 'asset' ? 0 : ($it['min_qty'] ?? 0), $it['unit_cost'] ?? null]);
      $nid = (int)$pdo->lastInsertId(); $stat['items']++;
      log_item_event($nid, 'created', 'Imported');
      if ($it['people'] && $it['type'] === 'asset' && $status === 'available') {
        $pn = $it['people'][0]; $pid = imp_person_find($pn, $reg);
        if (!$pid) { q("INSERT INTO people(name,active) VALUES(?,1)", [$pn]); $pid = (int)$pdo->lastInsertId(); $made['people'][] = $pid; $k = imp_person_key($pn); if ($k) $reg[$pid] = ['k' => $k, 'name' => $pn]; $stat['people']++; }
        $pname = $reg[$pid]['name'] ?? $pn;
        q("INSERT INTO checkouts(item_id,borrower,person_id,user_id,note) VALUES(?,?,?,?,?)", [$nid, $pname, $pid, $uid, 'Assigned in imported list']);
        q("UPDATE items SET status='checked_out' WHERE id=?", [$nid]); tx_log($nid, 'checkout', 1, null, 'To '.$pname.' - imported assignment'); $stat['checkouts']++;
        if (count($it['people']) > 1) q("UPDATE items SET description=CONCAT(description,?) WHERE id=?", ["\nOther people listed: ".implode(', ', array_slice($it['people'], 1)), $nid]);
      } elseif ($it['people']) q("UPDATE items SET description=CONCAT(description,?) WHERE id=?", ["\nPeople listed: ".implode(', ', $it['people']), $nid]);
    }
    q("UPDATE import_batches SET summary=?,made=? WHERE id=?", [$stat['items'].' items, '.$stat['checkouts'].' assignments', json_encode($made), $batch]);
    $pdo->commit();
  } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
  audit('import', json_encode($stat)); $stat['batch'] = $batch;
  return $stat;
}
// Undo an import: removes its items unless somebody has used them since; removes categories, locations and people it created if nothing else uses them.
function imp_undo($batch){
  global $pdo; $b = q("SELECT * FROM import_batches WHERE id=? AND undone_at IS NULL", [(int)$batch])->fetch(); if (!$b) return null;
  $made = json_decode((string)$b['made'], true) ?: ['cats' => [], 'locs' => [], 'people' => []]; $st = ['removed' => 0, 'kept' => 0, 'cats' => 0, 'locs' => 0, 'people' => 0, 'kept_names' => []];
  $pdo->beginTransaction();
  try {
    foreach (q("SELECT id,name FROM items WHERE import_batch=?", [$b['id']])->fetchAll() as $it) {
      $id = (int)$it['id'];
      $used = (int)q("SELECT (SELECT COUNT(*) FROM item_history WHERE item_id=? AND action NOT IN ('created')) + (SELECT COUNT(*) FROM transactions WHERE item_id=? AND (note IS NULL OR note NOT LIKE '%imported assignment')) + (SELECT COUNT(*) FROM checkouts WHERE item_id=? AND in_at IS NOT NULL) + (SELECT COUNT(*) FROM reservations WHERE item_id=?) + (SELECT COUNT(*) FROM maintenance_tasks WHERE item_id=?) + (SELECT COUNT(*) FROM documents WHERE item_id=?) + (SELECT COUNT(*) FROM kit_items WHERE item_id=?) + (SELECT COUNT(*) FROM issues WHERE item_id=?)", array_fill(0, 8, $id))->fetchColumn();
      if ($used) { $st['kept']++; if (count($st['kept_names']) < 8) $st['kept_names'][] = $it['name']; q("UPDATE items SET import_batch=NULL WHERE id=?", [$id]); continue; }
      q("DELETE FROM items WHERE id=?", [$id]); $st['removed']++;
    }
    foreach ($made['people'] as $pid) if (!q("SELECT 1 FROM checkouts WHERE person_id=? LIMIT 1", [(int)$pid])->fetchColumn() && !q("SELECT 1 FROM issues WHERE person_id=? LIMIT 1", [(int)$pid])->fetchColumn() && !q("SELECT 1 FROM reservations WHERE person_id=? LIMIT 1", [(int)$pid])->fetchColumn() && !q("SELECT 1 FROM people WHERE id=? AND user_id IS NOT NULL", [(int)$pid])->fetchColumn()) { q("DELETE FROM people WHERE id=?", [(int)$pid]); $st['people']++; }
    foreach (array_reverse($made['cats']) as $cid) if (!q("SELECT 1 FROM items WHERE category_id=? LIMIT 1", [(int)$cid])->fetchColumn() && !q("SELECT 1 FROM categories WHERE parent_id=? LIMIT 1", [(int)$cid])->fetchColumn()) { q("DELETE FROM categories WHERE id=?", [(int)$cid]); $st['cats']++; }
    foreach (array_reverse($made['locs']) as $lid) if (!q("SELECT 1 FROM items WHERE location_id=? LIMIT 1", [(int)$lid])->fetchColumn() && !q("SELECT 1 FROM locations WHERE parent_id=? LIMIT 1", [(int)$lid])->fetchColumn()) { q("DELETE FROM locations WHERE id=?", [(int)$lid]); $st['locs']++; }
    q("UPDATE import_batches SET undone_at=NOW() WHERE id=?", [$b['id']]);
    $pdo->commit();
  } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
  audit('import_undo', json_encode(['batch' => (int)$b['id']] + array_diff_key($st, ['kept_names' => 1])));
  return $st;
}
