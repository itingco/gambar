# SmartImages — Pemilihan database berdasarkan folder Drive

## Aturan

- `Ingco/HTBP020328.webp` → ItemCode `HTBP020328` → **AS_INGCO** saja.
- `Wadfow/03644.webp` → ItemCode `03644` → **AS_SMI** saja.
- Folder lain (selain top-level Ingco) → **AS_SMI** saja.
- Pencocokan nama folder `Ingco` tidak membedakan huruf besar/kecil.
- Jika ItemCode tidak ditemukan di database tujuan, tidak berpindah ke database lainnya.
- Nama barang dan harga RETAIL diambil dari database sesuai folder; filter kecocokan dan modal preview tetap sama.

## Instalasi

1. Backup file lama `app/Http/Controllers/ImageFinderController.php` dan `app/Services/RetailProductLookup.php`.
2. Ekstrak ZIP dan salin folder `app` ke `C:\xampp82\htdocs\smartimages`, pilih Replace.
3. Jalankan:

```powershell
php artisan optimize:clear
php artisan route:list
php artisan serve --port=8085
```

Tidak perlu migration atau indexing ulang. Pastikan `sqlsrv_ingco` dan `sqlsrv_smi` sudah dikonfigurasi di `config/database.php` serta driver `pdo_sqlsrv` aktif.

**Catatan:** indeks gambar `storage/app/drive-image-index.json` harus menyimpan `path` yang diawali nama subfolder (`Ingco/...` atau `Wadfow/...`). Format ini sudah dihasilkan oleh command indeks Drive versi sebelumnya.
