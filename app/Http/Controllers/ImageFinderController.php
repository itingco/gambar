<?php
namespace App\Http\Controllers;

use App\Services\DriveClient;
use App\Services\DriveImageIndex;
use App\Services\RetailProductLookup;
use App\Services\ThumbnailCache;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ImageFinderController extends Controller
{
    public function index(DriveImageIndex $index)
    {
        return view('finder', ['count' => count($index->load())]);
    }

    public function search(Request $request, DriveImageIndex $index, RetailProductLookup $products, ThumbnailCache $thumbs)
    {
        $request->validate(['image' => 'required|image|mimes:jpeg,png,webp,gif|max:10240']);
        $file = $request->file('image');
        $ch = curl_init(rtrim(config('image_ai.url'), '/') . '/search');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['image' => new \CURLFile($file->getRealPath(), $file->getMimeType(), 'photo.jpg'), 'limit' => '5'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => config('image_ai.timeout', 45),
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $errno || $http !== 200) {
            return response()->json(['message' => 'Layanan AI belum tersedia. Jalankan Python AI service dan bangun indeks CLIP terlebih dahulu.'], 503);
        }
        $data = json_decode((string)$response, true);
        if (!is_array($data) || !is_array($data['results'] ?? null)) {
            return response()->json(['message'=>'Respons AI tidak valid'], 502);
        }
        $all = $index->load();
        $chosen = [];
        foreach ($data['results'] as $match) {
            $id = (string)($match['id'] ?? '');
            if (!isset($all[$id]) || !is_array($all[$id])) continue;
            $item = $all[$id];
            $thumbUrl = $thumbs->exists($id, $item) ? $thumbs->url($id, $item) : null;
            $chosen[] = [
                'id'=>$id, 'name'=>(string)($item['name']??''), 'path'=>(string)($item['path']??''),
                'score'=>round((float)($match['similarity']??0)*100, 1),
                'url'=>$thumbUrl ?? '/catalog/'.rawurlencode($id),
                'thumbnail_url'=>$thumbUrl,
                'original_url'=>'/catalog/'.rawurlencode($id),
            ];
        }
        $lookup = $products->findForResults($chosen);
        foreach ($chosen as &$row) {
            $row['item_code'] = $products->itemCode($row['name']);
            $row['product'] = $lookup['products'][$row['id']] ?? null;
        }
        unset($row);
        return response()->json([
            'results'=>$chosen, 'total'=>(int)($data['total']??0),
            'database_errors'=>$lookup['errors'], 'fallback'=>true,
            'ai_mode'=>true, 'threshold'=>null,
        ]);
    }

    public function image(string $id, DriveImageIndex $index, DriveClient $drive)
    {
        abort_unless(isset($index->load()[$id]), 404);
        try {
            $tmp = $drive->fetchToTemporaryFile($id);
            $info = @getimagesize($tmp);
            if (!$info || !in_array($info['mime']??'', ['image/jpeg','image/png','image/webp','image/gif'], true)) {
                @unlink($tmp); abort(415, 'Format gambar tidak didukung');
            }
            $bytes = file_get_contents($tmp); @unlink($tmp);
            return response($bytes, 200, ['Content-Type'=>$info['mime'], 'Cache-Control'=>'private, max-age=300', 'X-Content-Type-Options'=>'nosniff']);
        } catch (\Throwable $e) { report($e); abort(502, 'Gagal mengambil gambar dari Drive'); }
    }
}
