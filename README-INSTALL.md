# Patch Smart Image Finder — 31 Oktober 2026

Basis: `itingco/gambar`, branch `main`. Paket ini berisi **hanya file baru/berubah**.

## Perubahan

- `app/Http/Controllers/ImageFinderController.php`: mode pencarian CPU cepat (single crop) sebagai default; membedakan error koneksi, timeout, respons 422, dan server 500; log detail ke Laravel, tanpa mengungkap informasi internal ke browser.
- `config/image_ai.php`: opsi `IMAGE_AI_SEARCH_MODE`, `IMAGE_AI_CONNECT_TIMEOUT`, dan timeout yang tetap kompatibel.
- `deploy/smartimages-ai.service.example`: contoh systemd termasuk path feedback, agar pembelajaran user tetap persisten.
- `scripts/check-ai.sh`: pemeriksaan dependency, indeks, port, health, dan log systemd.

## Instalasi di server Ubuntu

1. Backup file lama. Salin **isi folder patch** sesuai struktur `/var/www/gambar/`. Jangan menghapus direktori lain.
2. Jalankan:

```bash
cd /var/www/gambar
php artisan optimize:clear
bash scripts/check-ai.sh
```

3. Untuk konfigurasi `.env`, tambahkan atau ubah baris berikut **secara manual** (file `.env` tidak disertakan dan jangan ditimpa):

```dotenv
IMAGE_AI_URL=http://127.0.0.1:8779
IMAGE_AI_SEARCH_MODE=single
IMAGE_AI_TIMEOUT=45
IMAGE_AI_CONNECT_TIMEOUT=3
SUMOPOD_ENABLED=false
```

`SUMOPOD_ENABLED=false` adalah **pengujian performa**: menghindari tambahan latency verifikasi API eksternal. Jika Anda memang menggunakan verifikasi Sumopod, hidupkan lagi setelah uji.

4. Pastikan `.venv-ai` dan indeks tersedia sebelum menjalankan service. Jika belum ada dependensi:

```bash
cd /var/www/gambar
python3 -m venv .venv-ai
.venv-ai/bin/pip install -r ai/requirements.txt
# Install torch/torchvision CPU sesuai panduan distro dan versi Python Anda.
```

5. Bila indeks belum tersedia, buat thumbnail/manifest dan build sesuai dokumentasi project:

```bash
cd /var/www/gambar
php artisan images:ai-manifest
.venv-ai/bin/python ai/server.py --build --manifest storage/app/ai-manifest.json --index storage/app/ai-clip-index.npz
```

**Perhatian:** Build indeks untuk katalog besar memerlukan waktu lama. Jangan jalankan ulang jika `storage/app/ai-clip-index.npz` sudah valid. Indeks harus menggunakan MODEL dan PRETRAINED yang sama dengan saat inferensi, yakni default `ViT-B-32` dan `laion2b_s34b_b79k`.

6. Contoh unit file systemd diasumsikan user server `it-pc` dan direktori `/var/www/gambar`. Sesuaikan dulu bila berbeda:

```bash
sudo cp deploy/smartimages-ai.service.example /etc/systemd/system/smartimages-ai.service
sudo systemctl daemon-reload
sudo systemctl enable --now smartimages-ai
sudo systemctl restart smartimages-ai
sudo systemctl status smartimages-ai --no-pager
curl --max-time 10 http://127.0.0.1:8779/health
```

Jika `status` menampilkan gagal, lihat:

```bash
sudo journalctl -u smartimages-ai -n 100 --no-pager
```

7. Bersihkan konfigurasi Laravel setelah mengubah `.env`, kemudian uji upload foto:

```bash
php artisan optimize:clear
```

## Mode kecepatan vs akurasi

- `IMAGE_AI_SEARCH_MODE=single`: satu embedding foto, lebih ringan di CPU. Keakuratan foto dengan background ramai *mungkin* menurun.
- `IMAGE_AI_SEARCH_MODE=multi`: lima crop per foto, biasanya lebih baik untuk foto barang dengan background ramai, tetapi CPU lebih berat.
- Model **tidak diubah** agar indeks `.npz` lama tetap kompatibel. Bila memilih model baru, indeks wajib rebuild.

## Masalah selain AI inference

Apabila Python `/health` sudah OK tetapi halaman masih lambat, ukur tahap `RetailProductLookup::findForResults()` (database), `SumopodClient::rerank()` (API eksternal), dan waktu request PHP. Patch ini belum mengubah implementasi kedua service tersebut agar perilaku bisnis dan pencocokan kode item tetap aman.

## Validasi

`php -l` pada controller dan config telah lolos. Pengujian end-to-end, koneksi DB asli, indeks, dan performa server produksi harus dilakukan pada lingkungan Anda.
