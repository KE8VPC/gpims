<?php
function item_code($it){ return !empty($it['barcode']) ? $it['barcode'] : 'GPIMS-'.$it['id']; }
function save_photo($tmp, $dir){
  $info = @getimagesize($tmp); if (!$info) return null;
  $t = $info[2];
  if (!function_exists('imagecreatetruecolor')) {
    $map = [IMAGETYPE_JPEG=>'jpg', IMAGETYPE_PNG=>'png']; if (!isset($map[$t])) return null;
    $n = bin2hex(random_bytes(12)).'.'.$map[$t]; return move_uploaded_file($tmp, "$dir/$n") ? $n : null;
  }
  switch ($t) {
    case IMAGETYPE_JPEG: $im = @imagecreatefromjpeg($tmp); break;
    case IMAGETYPE_PNG:  $im = @imagecreatefrompng($tmp); break;
    case IMAGETYPE_WEBP: $im = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false; break;
    default: return null;
  }
  if (!$im) return null;
  if ($t === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
    $ex = @exif_read_data($tmp); $o = $ex['Orientation'] ?? 1; $deg = [3=>180, 6=>-90, 8=>90][$o] ?? 0;
    if ($deg) { $r = imagerotate($im, $deg, 0); if ($r) $im = $r; }
  }
  $w = imagesx($im); $h = imagesy($im); $max = 1600; $s = max($w,$h) > $max ? $max / max($w,$h) : 1;
  $nw = (int)round($w*$s); $nh = (int)round($h*$s);
  $d = imagecreatetruecolor($nw, $nh); imagefill($d, 0, 0, imagecolorallocate($d, 255, 255, 255));
  imagecopyresampled($d, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
  $n = bin2hex(random_bytes(12)).'.jpg';
  return imagejpeg($d, "$dir/$n", 82) ? $n : null;
}
