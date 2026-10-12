<?php
namespace App\Services;

use RuntimeException;

/** Cache medium-res WebP for gallery modal; originals always stay on Google Drive. */
class GalleryPreviewCache
{
    public function directory(): string
    {
        return public_path('gallery-previews');
    }

    public function filename(string $id, array $item): string
    {
        // Version by content stamp so Drive revisions don't serve stale previews.
        return hash('sha256', $id.':'.(string)($item['stamp'] ?? '').':modal960q78v1').'.webp';
    }

    public function path(string $id, array $item): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.$this->filename($id, $item);
    }

    public function url(string $id, array $item): string
    {
        return '/gallery-previews/'.$this->filename($id, $item);
    }

    public function exists(string $id, array $item): bool
    {
        return is_file($this->path($id, $item));
    }

    /** Generate a single 960-px preview using a temporary Drive download. */
    public function generate(string $id, array $item, DriveClient $drive, bool $force = false): bool
    {
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            throw new RuntimeException('Ekstensi GD + WebP PHP belum tersedia');
        }
        $file = $this->path($id, $item);
        if (!$force && is_file($file)) return false;
        if (!is_dir($this->directory()) && !mkdir($this->directory(), 0755, true) && !is_dir($this->directory())) {
            throw new RuntimeException('Tidak dapat membuat folder gallery-previews');
        }
        $temp = $drive->fetchToTemporaryFile($id);
        $output = $file.'.'.getmypid().'.tmp';
        try {
            $size = @getimagesize($temp);
            if (!$size || ($size[0]*$size[1]) > 50000000) {
                throw new RuntimeException('Resolusi gambar tidak valid/terlalu besar');
            }
            $src = match ($size['mime'] ?? '') {
                'image/jpeg' => @imagecreatefromjpeg($temp),
                'image/png' => @imagecreatefrompng($temp),
                'image/webp' => @imagecreatefromwebp($temp),
                'image/gif' => @imagecreatefromgif($temp),
                default => false,
            };
            if ($src === false) throw new RuntimeException('Format gambar tidak didukung');
            try {
                $width = imagesx($src); $height = imagesy($src);
                $scale = min(1.0, 960 / max($width, $height));
                $w = max(1, (int)round($width * $scale));
                $h = max(1, (int)round($height * $scale));
                $canvas = imagecreatetruecolor($w, $h);
                if ($canvas === false) throw new RuntimeException('Tidak dapat membuat canvas');
                try {
                    $white = imagecolorallocate($canvas, 255, 255, 255);
                    imagefill($canvas, 0, 0, $white);
                    if (!imagecopyresampled($canvas, $src, 0, 0, 0, 0, $w, $h, $width, $height)) {
                        throw new RuntimeException('Resize gagal');
                    }
                    if (!imagewebp($canvas, $output, 78)) throw new RuntimeException('Encode WebP gagal');
                } finally { imagedestroy($canvas); }
            } finally { imagedestroy($src); }
            // On Windows rename cannot overwrite existing file.
            if ($force && PHP_OS_FAMILY === 'Windows' && is_file($file)) @unlink($file);
            if (!rename($output, $file)) throw new RuntimeException('Gagal menyimpan hasil preview');
            @chmod($file, 0644);
            return true;
        } finally {
            @unlink($temp);
            @unlink($output);
        }
    }
}
