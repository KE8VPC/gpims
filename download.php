<?php require 'inc/bootstrap.php'; need('viewer'); if(me()['role']==='vendor'){http_response_code(403);exit('Access denied.');}
$d=q("SELECT * FROM documents WHERE id=?",[(int)($_GET['id']??0)])->fetch(); if(!$d){ http_response_code(404); exit('Not found.'); }
if($d['item_id']){ $it=q("SELECT * FROM items WHERE id=?",[$d['item_id']])->fetch(); if(!$it||!item_in_scope($it)){ http_response_code(403); exit('Access denied.'); } }
elseif($d['vehicle_id']){ $v=vehicle_get($d['vehicle_id']); if(!$v||!vehicle_can_view($v)){ http_response_code(403); exit('Access denied.'); } }
$path=__DIR__.'/uploads/'.basename($d['filename']); if(!is_file($path)){ http_response_code(404); exit('The file is missing from the server.'); }
$ext=strtolower(pathinfo($d['orig_name'],PATHINFO_EXTENSION)); $mime=DOC_TYPES[$ext]??'application/octet-stream'; $inline=in_array($ext,['pdf','jpg','jpeg','png','webp'],true);
while(ob_get_level()) ob_end_clean();
header('Content-Type: '.$mime); header('X-Content-Type-Options: nosniff'); header('Content-Length: '.filesize($path)); header('Content-Security-Policy: sandbox');
header('Content-Disposition: '.($inline?'inline':'attachment').'; filename="'.str_replace(['"','\\',"\r","\n"],'_',$d['orig_name']).'"');
readfile($path);
