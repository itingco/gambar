# Smart Image Finder v2.7 — Thumbnail Cache (Laravel 10)

## Patch
Timpa hanya file `app/Services/ThumbnailCache.php`, `app/Console/Commands/BuildGalleryThumbnails.php`, `app/Http/Controllers/GalleryController.php`, dan `resources/views/gallery.blade.php`. Backup dulu file lama. Tidak mengubah rute, .env, database, atau indeks.

## Jalankan di Ubuntu
```bash
cd /var/www/gambar
php artisan optimize:clear
php artisan list | grep images:thumbnails
mkdir -p public/gallery-thumbs
sudo chown -R it-pc:www-data public/gallery-thumbs
sudo chmod 2775 public/gallery-thumbs
php artisan images:thumbnails --brand=ingco --limit=100
php artisan images:thumbnails --brand=smi --limit=100
```
Ulangi masing-masing command untuk batch berikutnya; `--limit=0` untuk memproses seluruh sisanya. File yang sudah ada akan di-skip. Untuk memperbarui thumbnail jika foto pada indeks berubah, jalankan `php artisan images:sync-drive` terlebih dahulu lalu command thumbnail; filename berisi hash versi `stamp`, sehingga tidak memerlukan penghapusan file lama untuk invalidasi. Sesekali bersihkan thumbnail lama setelah memastikan semua item versi baru sudah berhasil.

## Kinerja
Thumbnail disimpan di `public/gallery-thumbs/*.webp` sehingga Nginx melayani langsung sebagai file statis; tidak ada request ke Google Drive saat grid ditampilkan. `GET /catalog/{id}` tetap dipakai untuk modal gambar resolusi asli; ini masih membutuhkan Google Drive. Gambar yang belum diproses hanya menampilkan placeholder, tidak otomatis mengunduh berkas besar pada setiap buka galeri.

## Catatan
- Command memakai GD WebP dan credential Drive untuk mengunduh **sementara**, kemudian menghapus file sementara.
- 12 gambar per halaman dan lazy load tetap digunakan.
- Folder `public/gallery-thumbs` dapat diakses publik. Hindari penggunaan untuk gambar rahasia.
- Jangan sertakan JSON service-account dalam ZIP atau public.
- Jika `images:thumbnails` tidak ditemukan, lihat bahwa `app/Console/Kernel.php` memuat `$this->load(__DIR__.'/Commands');` atau command didaftarkan manual.
- Bila cache config atau route berubah, `php artisan optimize:clear` cukup.
- Lakukan tes di staging/lokal sebelum deploy produksi.
