<?php
namespace App\Services;

use RuntimeException;

/** Stable versioned URLs; originals remain in Google Drive. */
class ThumbnailCache
{
    public function filename(string $id, array $item): string
    {
        $version = (string) ($item['stamp'] ?? '');
        return hash('sha256', $id . ':' . $version . ':w240q72v1') . '.webp';
    }

    public function directory(): string { return public_path('gallery-thumbs'); }

    public function path(string $id, array $item): string
    {
        return $this->directory() . DIRECTORY_SEPARATOR . $this->filename($id, $item);
    }

    public function url(string $id, array $item): string
    {
        return '/gallery-thumbs/' . $this->filename($id, $item);
    }

    public function exists(string $id, array $item): bool
    {
        return is_file($this->path($id, $item));
    }

    public function generate(string $id, array $item, DriveClient $drive, bool $force = false): bool
    {
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            throw new RuntimeException('PHP GD WebP extension tidak tersedia');
        }
        $target = $this->path($id, $item);
        if (!$force && is_file($target)) return false;
        if (!is_dir($this->directory()) && !mkdir($this->directory(), 0755, true) && !is_dir($this->directory())) {
            throw new RuntimeException('Gagal membuat public/gallery-thumbs');
        }
        $temp = $drive->fetchToTemporaryFile($id);
        $output = $target . '.' . getmypid() . '.tmp';
        try {
            $info = @getimagesize($temp);
            if (!$info || empty($info[0]) || empty($info[1])) throw new RuntimeException('Dimensi gambar tidak valid');
            $mime = $info['mime'] ?? '';
            $image = match ($mime) {
                'image/jpeg' => @imagecreatefromjpeg($temp),
                'image/png' => @imagecreatefrompng($temp),
                'image/webp' => @imagecreatefromwebp($temp),
                'image/gif' => @imagecreatefromgif($temp),
                default => false,
            };
            if (!$image) throw new RuntimeException('Gagal membuka gambar: ' . $mime);
            try {
                $w = imagesx($image); $h = imagesy($image);
                $scale = min(1, 240 / max($w, $h));
                $dw = max(1, (int) round($w * $scale));
                $dh = max(1, (int) round($h * $scale));
                $canvas = imagecreatetruecolor($dw, $dh);
                if (!$canvas) throw new RuntimeException('Gagal membuat thumbnail');
                try {
                    $white = imagecolorallocate($canvas, 255, 255, 255);
                    imagefill($canvas, 0, 0, $white);
                    if (!imagecopyresampled($canvas, $image, 0, 0, 0, 0, $dw, $dh, $w, $h)) throw new RuntimeException('Resize gagal');
                    if (!imagewebp($canvas, $output, 72)) throw new RuntimeException('Kompresi WebP gagal');
                } finally { imagedestroy($canvas); }
            } finally { imagedestroy($image); }
            if (!rename($output, $target)) throw new RuntimeException('Gagal menyimpan thumbnail');
            @chmod($target, 0644);
            return true;
        } finally {
            @unlink($temp); @unlink($output);
        }
    }
}
