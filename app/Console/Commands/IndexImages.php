<?php
namespace App\Console\Commands;
use App\Services\ImageMatcher;
use Illuminate\Console\Command;
class IndexImages extends Command {
 protected $signature='images:index {--path= : Folder katalog eksternal untuk disalin}';protected $description='Index foto katalog berdasarkan fingerprint';
 public function handle(ImageMatcher $m):int {
  if($src=$this->option('path')){
   if(!is_dir($src)){$this->error('Folder tidak ditemukan');return 1;}
   $dest=$m->baseDir();$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src,\FilesystemIterator::SKIP_DOTS));
   $src=realpath($src);
   foreach($it as $f){if(!$f->isFile()||!in_array(strtolower($f->getExtension()),['jpg','jpeg','webp','png','gif']))continue;
    $rel=substr($f->getPathname(),strlen($src)+1);$out=$dest.DIRECTORY_SEPARATOR.$rel;
    if(!is_dir(dirname($out)))mkdir(dirname($out),0775,true);
    if(!is_file($out)||filesize($out)!==$f->getSize()||filemtime($out)<$f->getMTime())copy($f->getPathname(),$out);
   }
  }
  $result=$m->createIndex();$this->info('Berhasil index '.$result['count'].' gambar');return 0;
 }
}
