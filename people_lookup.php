<?php require 'inc/bootstrap.php'; need('viewer'); header('Content-Type: application/json');
$code = trim($_GET['code'] ?? ''); $c = code_candidates($code);
$p = $code === '' ? null : q("SELECT id,name,active FROM people WHERE badge_code IN (".implode(',', array_fill(0, count($c), '?')).") LIMIT 1", $c)->fetch();
if (!$p) echo json_encode(['ok'=>false,'reason'=>'not_found']);
elseif (!$p['active']) echo json_encode(['ok'=>false,'reason'=>'inactive','name'=>$p['name']]);
else echo json_encode(['ok'=>true,'id'=>(int)$p['id'],'name'=>$p['name']]);
