<?php require 'inc/bootstrap.php';
header('Content-Type: application/manifest+json'); header('Cache-Control: no-cache');
$n = $cfg['app_name'];
echo json_encode(['name'=>$n, 'short_name'=>mb_substr($n, 0, 12), 'description'=>'Inventory, assets and equipment checklists', 'start_url'=>'index.php', 'scope'=>'./', 'display'=>'standalone', 'orientation'=>'portrait-primary',
  'background_color'=>'#ffffff', 'theme_color'=>'#0d6efd',
  'icons'=>[['src'=>'assets/icon-192.png','sizes'=>'192x192','type'=>'image/png','purpose'=>'any'],['src'=>'assets/icon-512.png','sizes'=>'512x512','type'=>'image/png','purpose'=>'any'],['src'=>'assets/icon-maskable-512.png','sizes'=>'512x512','type'=>'image/png','purpose'=>'maskable']]], JSON_UNESCAPED_SLASHES);
