<?php
namespace App\Services;
use RuntimeException;
class ImageMatcher {
 public function baseDir():string{return storage_path('app/catalog');}
 public function indexPath():string{return storage_path('app/image-index.json');}
 public function features(string $file):?array {
  if(!extension_loaded('gd'))throw new RuntimeException('Aktifkan PHP GD extension di php.ini');
  $bytes=@file_get_contents($file);if($bytes===false)return null;
  $src=@imagecreatefromstring($bytes);if(!$src)return null;
  $w=imagesx($src);$h=imagesy($src);if($w<2||$h<2){imagedestroy($src);return null;}
  $small=imagecreatetruecolor(9,8);imagecopyresampled($small,$src,0,0,0,0,9,8,$w,$h);
  $hash='';$hist=array_fill(0,24,0);
  for($y=0;$y<8;$y++)for($x=0;$x<9;$x++){
   $rgb=imagecolorat($small,$x,$y);$r=($rgb>>16)&255;$g=($rgb>>8)&255;$b=$rgb&255;
   if($x<8){$n=imagecolorat($small,$x+1,$y);$lum=(299*$r+587*$g+114*$b);$lum2=(299*(($n>>16)&255)+587*(($n>>8)&255)+114*($n&255));$hash.=($lum>$lum2?'1':'0');}
   if($x<8){$hist[intdiv($r,32)]++;$hist[8+intdiv($g,32)]++;$hist[16+intdiv($b,32)]++;}
  }
  imagedestroy($src);imagedestroy($small);
  return ['hash'=>$hash,'hist'=>$hist];
 }
 public function score(array $a,array $b):float {
  $diff=0;for($i=0;$i<64;$i++)if($a['hash'][$i]!==$b['hash'][$i])$diff++;
  $h=1-$diff/64;$distance=0;
  for($i=0;$i<24;$i++)$distance+=abs($a['hist'][$i]-$b['hist'][$i]);
  $color=1-$distance/(2*64*3);
  return max(0,min(100,round(100*(0.75*$h+0.25*$color),1)));
 }
 public function load():array {if(!is_file($this->indexPath()))return []; $v=json_decode(file_get_contents($this->indexPath()),true);return is_array($v)?$v:[];}
 public function createIndex():array {
  $dir=$this->baseDir();if(!is_dir($dir))mkdir($dir,0775,true);
  $old=$this->load();$out=[];$cnt=0;
  $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS));
  foreach($it as $file){if(!$file->isFile()||!in_array(strtolower($file->getExtension()),['jpg','jpeg','png','webp','gif']))continue;
   $absolute=$file->getPathname();$relative=str_replace('\\','/',substr($absolute,strlen($dir)+1));
   $id=sha1($relative);$mtime=$file->getMTime();$size=$file->getSize();
   $features=($old[$id]['mtime']??null)===$mtime && ($old[$id]['size']??null)===$size ? $old[$id]['features'] : $this->features($absolute);
   if(!$features)continue;
   $out[$id]=['name'=>$file->getFilename(),'path'=>$relative,'mtime'=>$mtime,'size'=>$size,'features'=>$features];$cnt++;
  }
  $tmp=$this->indexPath().'.tmp';file_put_contents($tmp,json_encode($out,JSON_UNESCAPED_SLASHES));rename($tmp,$this->indexPath());return ['count'=>$cnt];
 }
}
