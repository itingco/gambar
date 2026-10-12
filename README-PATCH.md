# Smart Image Finder v3.2 — Perbaikan Modal Galeri (Laravel 10)

Patch berisi **hanya lima file PHP/Blade yang berubah atau baru**. Tidak mengubah AI CLIP, SQL Server, pencarian foto, atau indeks Google Drive.

## Masalah sebelumnya
Thumbnail galeri ditampilkan dari `/gallery-thumbs`, tetapi klik gambar langsung menuju `/catalog/{fileId}`. Bila permintaan Drive gagal/502, modal tampak kosong.

## Solusi
- Modal menampilkan cache lokal segera, tidak menjadi kosong saat Drive gagal.
- Bila cache resolusi tinggi tersedia, gunakan `/gallery-previews/<hash>.webp` (maksimal 960px) tanpa akses Drive saat dibuka.
- Bila preview 960px belum dibuat, gunakan thumbnail 240px sementara, kemudian coba gambar asli melalui `/catalog` selama maksimal 8 detik.
- Jika gambar asli gagal, thumbnail tetap tampil dan ada tombol *Coba gambar asli*.
- Modal responsif untuk desktop dan ponsel, bisa ditutup dengan X, Esc, atau klik area luar.
- Tidak menyimpan file asli Drive secara permanen. Tidak membutuhkan migration/indexing ulang.

## Timpa file
Ekstrak ZIP dan timpa file ke `C:\xampp82\htdocs\smartimages` atau `/var/www/gambar`:

```
app/Console/Kernel.php
app/Console/Commands/BuildGalleryPreviews.php
app/Http/Controllers/GalleryController.php
app/Services/GalleryPreviewCache.php
resources/views/gallery.blade.php
```

**Catatan:** `app/Console/Kernel.php` dari paket mengikuti v3 (IndexImages, SyncDriveImages, BuildGalleryThumbnails, ExportAiManifest) dan menambah `BuildGalleryPreviews`. Jika Kernel lokal memiliki command custom lain, **tambahkan** `\App\Console\Commands\BuildGalleryPreviews::class,` ke array `$commands` yang sudah ada, jangan hilangkan command custom.

## Langkah uji (Windows)

```powershell
cd C:\xampp82\htdocs\smartimages
php artisan optimize:clear
php artisan list | findstr gallery-previews
php artisan images:gallery-previews --brand=smi --code=00072 --limit=1
php artisan serve --port=8085
```

Kunjungi `http://127.0.0.1:8085/gallery?brand=smi&q=00072`, klik foto `00072`. Jika berhasil, modal `00072` memakai preview WebP 960px langsung dari disk, tanpa menunggu Google Drive. Barang lain yang belum diproses tetap memakai thumbnail + fallback URL asli.

## Langkah uji (Ubuntu)

```bash
cd /var/www/gambar
php artisan optimize:clear
php artisan list | grep gallery-previews
mkdir -p public/gallery-previews
sudo chown it-pc:www-data public/gallery-previews
sudo chmod 2775 public/gallery-previews
php artisan images:gallery-previews --brand=smi --code=00072 --limit=1
```

Kemudian buka `https://gambar.ciptagroup.id/gallery?brand=smi&q=00072` dan refresh kuat (`Ctrl+Shift+R`).

> **Perhatikan**: cache di Windows tidak otomatis ada di Ubuntu. Jalankan generate di server tempat website ditayangkan. Semua request thumbnail/preview memakai path relatif, sehingga aman untuk HTTPS Caddy → Nginx.

Untuk seluruh katalog secara opsional:

```bash
php artisan images:gallery-previews --brand=ingco --limit=0
php artisan images:gallery-previews --brand=smi --limit=0
```

Preview 960px memakai disk lebih banyak daripada thumbnail 240px. Tidak wajib membuat semua; bisa bertahap hanya produk yang sering dibuka. Command dapat diulang dan melewati file yang sudah dibuat.

### Pemeriksaan jika preview masih gagal

- `php artisan route:list --path=gallery -v` — pastikan endpoint galeri aktif.
- `find public/gallery-previews -type f -name '*.webp' | wc -l` — jumlah gambar preview besar yang berhasil dibuat.
- `/catalog/...` masih dapat gagal bila credential Drive hilang, lambat, atau permission Drive berubah. Dengan patch ini modal tetap menggunakan cache lokal.
- Jika data JSON `/gallery/items?brand=smi&q=00072` tidak mengembalikan `preview_url`, preview besar untuk gambar tersebut belum dibuat.

Jangan mengunggah file JSON Service Account, `.env`, atau kredensial ke Git.
