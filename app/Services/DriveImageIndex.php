<?php
namespace App\Services;

class DriveImageIndex
{
    public function path(): string { return storage_path('app/drive-image-index.json'); }
    public function load(): array
    {
        if (!is_file($this->path())) return [];
        $data = json_decode((string) file_get_contents($this->path()), true);
        return is_array($data) ? $data : [];
    }
    public function save(array $items): void
    {
        $tmp = $this->path().'.'.getmypid().'.tmp';
        if (file_put_contents($tmp, json_encode($items, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) === false) {
            throw new \RuntimeException('Gagal menulis indeks');
        }
        if (PHP_OS_FAMILY === 'Windows' && is_file($this->path())) @unlink($this->path());
        if (!rename($tmp, $this->path())) throw new \RuntimeException('Gagal menyimpan indeks');
    }
}
