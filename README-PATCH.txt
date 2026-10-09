SMART IMAGE FINDER - FIX ROUTES LARAVEL 10

Penyebab: paket sebelumnya tidak mendaftarkan App\Providers\RouteServiceProvider dalam config/app.php.
Hasil: php artisan route:list mengatakan aplikasi tidak memiliki routes.

1. Extract ZIP ini ke C:\xampp82\htdocs\smartimages (bukan membuat subfolder patch).
2. Pilih Replace / Timpa file jika diminta. Dua file yang ditambah/diubah:
   - app/Providers/RouteServiceProvider.php (baru)
   - config/app.php (timpa)
3. Jalankan:
   php artisan optimize:clear
   php artisan route:list
   php artisan serve --port=8085
4. Buka http://127.0.0.1:8085

Patch tidak mengubah database, routes/web.php, controller atau vendor.
Hasil yang diharapkan: GET /, POST /search, GET /catalog/{id}.
