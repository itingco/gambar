<?php
namespace App\Console\Commands;

use App\Services\DriveClient;
use App\Services\DriveImageIndex;
use App\Services\GalleryPreviewCache;
use Illuminate\Console\Command;

class BuildGalleryPreviews extends Command
{
    protected $signature = 'images:gallery-previews {--brand=all : ingco, smi, all} {--limit=25 : Maksimum gambar baru per run (0=semua)} {--code= : Hanya ItemCode tertentu (mis. 00072)} {--force : Generate ulang gambar yang sudah di-cache}';
    protected $description = 'Generate cache gambar modal resolusi 960px dari Google Drive';

    public function handle(DriveImageIndex $index, DriveClient $drive, GalleryPreviewCache $cache): int
    {
        $brand = strtolower((string)$this->option('brand'));
        $limit = (int)$this->option('limit');
        if (!in_array($brand, ['all','ingco','smi'], true) || $limit < 0) {
            $this->error('Gunakan --brand=all|ingco|smi dan --limit>=0');
            return self::FAILURE;
        }
        $force = (bool)$this->option('force');
        $code = strtoupper(trim((string)$this->option('code')));
        $done=0; $skipped=0; $failed=0;
        foreach ($index->load() as $id => $item) {
            if (!is_array($item) || !preg_match('/^[A-Za-z0-9_-]{8,200}$/D', (string)$id)) continue;
            $path = trim(str_replace('\\', '/', (string)($item['path'] ?? '')), '/');
            $folder = strtolower(explode('/', $path)[0] ?? '');
            if (!in_array($folder, ['ingco', 'wadfow'], true)) continue;
            if ($code !== '' && strtoupper(pathinfo((string)($item['name'] ?? basename($path)), PATHINFO_FILENAME)) !== $code) continue;
            if ($brand !== 'all' && $folder !== ($brand === 'ingco' ? 'ingco' : 'wadfow')) continue;
            if (!$force && $cache->exists((string)$id, $item)) { $skipped++; continue; }
            if ($limit > 0 && ($done+$failed) >= $limit) break;
            try {
                $cache->generate((string)$id, $item, $drive, $force);
                $done++;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn('Gagal '.basename($path).': '.$e->getMessage());
            }
            if (($done+$failed) % 25 === 0) $this->line("Preview dibuat: {$done} | gagal: {$failed} | sudah ada: {$skipped}");
        }
        $this->info("Selesai. Dibuat: {$done} | Sudah ada: {$skipped} | Gagal: {$failed}");
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
