<?php
namespace App\Console\Commands;

use App\Services\DriveClient;
use App\Services\DriveImageIndex;
use App\Services\ThumbnailCache;
use Illuminate\Console\Command;

class BuildGalleryThumbnails extends Command
{
    protected $signature = 'images:thumbnails {--brand=all : all, ingco, smi} {--limit=100 : Maksimum gambar BARU setiap eksekusi (0=semua)} {--force : Bangun ulang thumbnail yang sudah ada}';
    protected $description = 'Generate WebP kecil untuk galeri dari Drive; tidak menyimpan gambar asli';

    public function handle(DriveImageIndex $index, DriveClient $drive, ThumbnailCache $cache): int
    {
        $brand = strtolower((string) $this->option('brand'));
        if (!in_array($brand, ['all', 'ingco', 'smi'], true)) { $this->error('--brand harus all, ingco, atau smi'); return self::FAILURE; }
        $limit = (int) $this->option('limit');
        if ($limit < 0) { $this->error('--limit harus >= 0'); return self::FAILURE; }
        $force = (bool) $this->option('force');
        $done = 0; $skipped = 0; $failed = 0; $eligible = 0;
        foreach ($index->load() as $id => $item) {
            if (!is_array($item) || !preg_match('/^[A-Za-z0-9_-]{8,200}$/D', (string)$id)) continue;
            $path = trim(str_replace('\\', '/', (string)($item['path'] ?? '')), '/');
            $folder = strtolower(explode('/', $path)[0] ?? '');
            if (!in_array($folder, ['ingco','wadfow'], true)) continue;
            if ($brand !== 'all' && $folder !== ($brand === 'ingco' ? 'ingco' : 'wadfow')) continue;
            $eligible++;
            if (!$force && $cache->exists((string)$id, $item)) { $skipped++; continue; }
            if ($limit > 0 && $done + $failed >= $limit) break;
            try {
                $cache->generate((string)$id, $item, $drive, $force);
                $done++;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn('Gagal ' . basename($path) . ': ' . $e->getMessage());
            }
            if (($done + $failed) % 25 === 0) $this->line("Baru: {$done}, gagal: {$failed}, sudah ada: {$skipped}");
        }
        $this->info("Selesai. Dibuat: {$done} | Sudah ada: {$skipped} | Gagal: {$failed} | Dilewati batas: " . ($limit ?: 'tidak ada'));
        $this->line('Jalankan command ini lagi untuk melanjutkan. Tidak perlu sync ulang jika indeks tidak berubah.');
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
