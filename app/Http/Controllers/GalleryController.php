<?php

namespace App\Http\Controllers;

use App\Services\DriveImageIndex;
use App\Services\ThumbnailCache;
use App\Services\GalleryPreviewCache;
use App\Services\RetailProductLookup;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Throwable;

class GalleryController extends Controller
{
    public function index()
    {
        return view('gallery');
    }

    public function items(Request $request, DriveImageIndex $index, RetailProductLookup $products, ThumbnailCache $thumbnails, GalleryPreviewCache $previews)
    {
        $params = $request->validate([
            'q' => 'nullable|string|max:100',
            'brand' => 'required|in:ingco,smi',
            'page' => 'nullable|integer|min:1|max:100000',
        ]);
        $query = trim($params['q'] ?? '');
        $brand = $params['brand'];
        $targetFolder = $brand === 'ingco' ? 'Ingco' : 'Wadfow';
        $page = (int) ($params['page'] ?? 1);
        $perPage = 12;

        // Cache metadata katalog per folder; invalidate otomatis saat file indeks berubah.
        $indexPath = $index->path();
        $indexVersion = is_file($indexPath) ? ((string) filemtime($indexPath) . ':' . (string) filesize($indexPath)) : 'empty';
        $cacheKey = 'gallery:catalog:v26:' . sha1($brand . ':' . $indexVersion);
        $catalog = Cache::remember($cacheKey, now()->addMinutes(15), function () use ($index, $products, $targetFolder) {
            $entries = [];
            foreach ($index->load() as $id => $item) {
                if (!is_array($item)) continue;
                $path = str_replace('\\', '/', (string) ($item['path'] ?? ''));
                $name = (string) ($item['name'] ?? '');
                if ($name === '' || !preg_match('/^[A-Za-z0-9_-]{8,200}$/D', (string) $id)) continue;
                $folder = explode('/', trim($path, '/'))[0] ?? '';
                if (strcasecmp($folder, $targetFolder) !== 0) continue;
                $entries[] = ['id'=>(string)$id, 'name'=>$name, 'path'=>$path,
                    'code'=>$products->itemCode($name), 'brand'=>$targetFolder];
            }
            usort($entries, static fn($a,$b)=>strnatcasecmp($a['code'],$b['code']));
            return $entries;
        });
        $matchingNames = [];
        $searchWarning = null;
        // Untuk nama produk, cari daftar ItemCode yang cocok secara terbatas di SQL Server.
        if ($query !== '' && mb_strlen($query) >= 2) {
            foreach ([$brand === 'ingco' ? 'sqlsrv_ingco' : 'sqlsrv_smi' => $targetFolder] as $connection => $folder) {
                try {
                    $rows = DB::connection($connection)->select(
                        "SELECT TOP (250) ItemCode FROM IC_Items WHERE ItemName LIKE ? ESCAPE '~'",
                        ['%' . str_replace(['~', '%', '_', '['], ['~~', '~%', '~_', '~['], $query) . '%']
                    );
                    foreach ($rows as $row) $matchingNames[$folder][strtoupper(trim((string) $row->ItemCode))] = true;
                } catch (Throwable $e) {
                    report($e);
                    $searchWarning = 'Pencarian nama melalui SQL Server belum tersedia; pencarian kode file tetap berjalan.';
                }
            }
        }

        // Filter pada data cached, bukan decode dan sort keseluruhan JSON setiap request.
        $filtered = $query === '' ? $catalog : array_values(array_filter($catalog, static function ($item) use ($query, $matchingNames, $targetFolder) {
            return stripos($item['name'], $query) !== false
                || stripos($item['code'], $query) !== false
                || isset($matchingNames[$targetFolder][strtoupper($item['code'])]);
        }));
        $total = count($filtered);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $items = array_slice($filtered, ($page - 1) * $perPage, $perPage);
        $lookupInput = array_map(static fn ($item) => ['id'=>$item['id'], 'name'=>$item['name'], 'path'=>$item['path']], $items);
        // Hindari query SQL Server berulang saat user pindah halaman atau refresh.
        $priceKey = 'gallery:products:v26:' . sha1(json_encode(array_map(static fn($v)=>[$v['id'],$v['path']], $lookupInput)));
        $lookups = $lookupInput ? Cache::remember($priceKey, now()->addMinutes(5),
            static fn() => $products->findForResults($lookupInput)) : ['products'=>[], 'errors'=>[]];
        $rawIndex = $index->load();
        foreach ($items as &$item) {
            // URL relatif: aman di belakang Caddy HTTPS => Nginx HTTP.
            $indexedItem = $rawIndex[$item['id']] ?? null;
            $item['thumbnail_url'] = is_array($indexedItem) && $thumbnails->exists($item['id'], $indexedItem)
                ? $thumbnails->url($item['id'], $indexedItem) : null;
            // Modal: gunakan preview lokal resolusi lebih tinggi bila sudah dibuat.
            $item['preview_url'] = is_array($indexedItem) && $previews->exists($item['id'], $indexedItem)
                ? $previews->url($item['id'], $indexedItem) : null;
            // Gambar Drive asli hanya dicoba oleh browser setelah thumbnail lokal muncul.
            $item['url'] = '/catalog/' . rawurlencode($item['id']);
            $item['product'] = $lookups['products'][$item['id']] ?? null;
            unset($item['path']);
        }
        unset($item);

        return response()->json([
            'items'=>$items, 'total'=>$total, 'page'=>$page, 'pages'=>$pages,
            'per_page'=>$perPage, 'source'=>$brand, 'database_errors'=>array_keys($lookups['errors']),
            'search_warning'=>$searchWarning,
        ], 200, ['Cache-Control'=>'private, no-store']);
    }
}
