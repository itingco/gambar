<?php
namespace App\Console\Commands;

use App\Services\DriveClient;
use App\Services\DriveImageIndex;
use App\Services\ImageMatcher;
use Illuminate\Console\Command;

class SyncDriveImages extends Command
{
    protected $signature = 'images:sync-drive {--folder= : Override folder ID}';
    protected $description = 'Indeks Google Drive tanpa menyimpan gambar permanen (incremental)';

    public function handle(DriveClient $drive, DriveImageIndex $index, ImageMatcher $matcher): int
    {
        $folder = (string) ($this->option('folder') ?: config('drive_index.folder_id'));
        if (!$folder) { $this->error('DRIVE_FOLDER_ID belum diset'); return self::FAILURE; }
        if (!extension_loaded('gd')) { $this->error('Aktifkan ekstensi GD pada php.ini'); return self::FAILURE; }
        $old = $index->load(); $next = []; $seen = 0; $indexed = 0; $reused = 0; $failed = 0;
        $walk = function (string $id, string $prefix = '', int $depth = 0) use (&$walk, $drive, $matcher, $old, &$next, &$seen, &$indexed, &$reused, &$failed): void {
            if ($depth > 15) throw new \RuntimeException('Batas kedalaman folder terlampaui');
            foreach ($drive->listFolder($id) as $file) {
                $name = (string) ($file['name'] ?? '');
                if (($file['mimeType'] ?? '') === 'application/vnd.google-apps.folder') {
                    $walk($file['id'], $prefix.$name.'/', $depth+1); continue;
                }
                if (!preg_match('/\.(jpe?g|png|webp|gif)$/i', $name) || !str_starts_with((string)($file['mimeType']??''), 'image/')) continue;
                $seen++;
                $fid = (string) $file['id'];
                $stamp = ($file['md5Checksum'] ?? '') ?: (($file['modifiedTime'] ?? '').':'.($file['size'] ?? ''));
                if (isset($old[$fid]) && ($old[$fid]['stamp'] ?? '') === $stamp && !empty($old[$fid]['features'])) {
                    $next[$fid] = $old[$fid]; $next[$fid]['name'] = $name; $next[$fid]['path'] = $prefix.$name; $reused++; continue;
                }
                $tmp = null;
                try {
                    $tmp = $drive->fetchToTemporaryFile($fid);
                    $features = $matcher->features($tmp);
                    if (!$features) throw new \RuntimeException('File tidak dikenali GD');
                    $next[$fid] = ['name'=>$name,'path'=>$prefix.$name,'stamp'=>$stamp,'features'=>$features];
                    $indexed++;
                } catch (\Throwable $e) {
                    $failed++; $this->warn('Lewati '.$prefix.$name.': '.$e->getMessage());
                    if (isset($old[$fid])) $next[$fid] = $old[$fid];
                } finally { if ($tmp !== null) @unlink($tmp); }
                if ($seen % 100 === 0) $this->line('Diproses '.$seen.' | baru '.$indexed.' | lama '.$reused.' | gagal '.$failed);
            }
        };
        try {
            $walk($folder);
            if ($seen === 0 && count($old) > 0) throw new \RuntimeException('Drive mengembalikan 0 gambar; indeks lama dipertahankan');
            $index->save($next);
            $this->info('Selesai: '.$seen.' gambar ditemukan, '.$indexed.' diindeks, '.$reused.' tidak berubah, '.$failed.' gagal. Indeks total: '.count($next));
            $this->line('Tidak ada gambar katalog disimpan permanen di Laravel.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Sinkronisasi gagal: '.$e->getMessage().' | indeks lama tidak ditimpa');
            return self::FAILURE;
        }
    }
}
