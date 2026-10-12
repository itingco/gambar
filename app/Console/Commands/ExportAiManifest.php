<?php
namespace App\Console\Commands;

use App\Services\DriveImageIndex;
use App\Services\ThumbnailCache;
use Illuminate\Console\Command;

class ExportAiManifest extends Command
{
    protected $signature = 'images:ai-manifest';
    protected $description = 'Ekspor daftar thumbnail ke manifest CLIP; tidak mengunduh foto';

    public function handle(DriveImageIndex $index, ThumbnailCache $thumbs): int
    {
        $path = storage_path('app/ai-manifest.json');
        $rows = [];
        $missing = 0;
        foreach ($index->load() as $id => $item) {
            if (!is_array($item)) continue;
            if (!$thumbs->exists((string) $id, $item)) { $missing++; continue; }
            $rows[] = ['id'=>(string)$id, 'path'=>$thumbs->path((string)$id, $item), 'stamp'=>(string)($item['stamp']??'')];
        }
        file_put_contents($path.'.tmp', json_encode($rows, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        rename($path.'.tmp', $path);
        $this->info('Manifest dibuat: '.count($rows).' gambar; belum punya thumbnail: '.$missing);
        $this->line($path);
        return self::SUCCESS;
    }
}
