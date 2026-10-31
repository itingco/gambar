<?php
namespace App\Http\Controllers;

use App\Services\DriveClient;
use App\Services\DriveImageIndex;
use App\Services\RetailProductLookup;
use App\Services\SumopodClient;
use App\Services\ThumbnailCache;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class ImageFinderController extends Controller
{
    public function index(DriveImageIndex $index)
    {
        return view('finder', ['count' => count($index->load())]);
    }

    private function aiRequest(string $endpoint, $file, array $fields): array
    {
        $url = rtrim((string) config('image_ai.url'), '/') . $endpoint;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => array_merge(['image' => new \CURLFile(
                $file->getRealPath(), $file->getMimeType(), $file->getClientOriginalName()
            )], $fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => max(1, (int) config('image_ai.connect_timeout', 3)),
            CURLOPT_TIMEOUT => max(5, (int) config('image_ai.timeout', 45)),
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $errno || $http !== 200) {
            Log::warning('Image AI request failed', [
                'endpoint' => $endpoint, 'http' => $http, 'curl_errno' => $errno,
                'curl_error' => $error, 'response' => mb_substr((string) $response, 0, 500),
            ]);
            $message = 'Layanan pencarian gambar AI gagal.';
            if ($errno === 7 || $http === 0 && $errno === 0) {
                $message = 'Python AI service tidak dapat dihubungi di port 8779. Periksa systemctl status smartimages-ai dan indeks CLIP.';
            } elseif ($errno === 28) {
                $message = 'Pencarian AI melewati batas waktu. Coba mode cepat atau periksa beban CPU.';
            } elseif ($http === 422) {
                $message = 'Format atau ukuran gambar ditolak oleh AI.';
            } elseif ($http >= 500) {
                $message = 'Python AI service mengalami error saat memproses gambar. Periksa journalctl -u smartimages-ai.';
            }
            return ['ok' => false, 'status' => $http === 422 ? 422 : 503, 'message' => $message];
        }
        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            return ['ok' => false, 'status' => 502, 'message' => 'Respons JSON dari Python AI tidak valid.'];
        }
        return ['ok' => true, 'data' => $data];
    }

    public function search(Request $request, DriveImageIndex $index, RetailProductLookup $products, ThumbnailCache $thumbs, SumopodClient $sumopod)
    {
        $request->validate(['image' => 'required|image|mimes:jpeg,png,webp,gif|max:10240']);
        $file = $request->file('image');
        $candidateLimit = $sumopod->enabled() ? $sumopod->candidateLimit() : 5;
        // Single crop is faster on CPU; set IMAGE_AI_SEARCH_MODE=multi for old behavior.
        $mode = config('image_ai.search_mode', 'single');
        $mode = in_array($mode, ['single', 'multi'], true) ? $mode : 'single';
        $result = $this->aiRequest('/search', $file, ['limit' => (string) $candidateLimit, 'mode' => $mode]);
        if (!$result['ok']) return response()->json(['message' => $result['message']], $result['status']);
        $data = $result['data'];
        if (!is_array($data['results'] ?? null)) return response()->json(['message' => 'Respons hasil pencarian AI tidak valid.'], 502);

        $all = $index->load();
        $chosen = [];
        foreach ($data['results'] as $match) {
            $id = (string) ($match['id'] ?? '');
            if (!isset($all[$id]) || !is_array($all[$id])) continue;
            $item = $all[$id];
            $thumbUrl = $thumbs->exists($id, $item) ? $thumbs->url($id, $item) : null;
            $chosen[] = [
                'id' => $id,
                'name' => (string) ($item['name'] ?? ''),
                'path' => (string) ($item['path'] ?? ''),
                'score' => round((float) ($match['similarity'] ?? 0) * 100, 1),
                'url' => $thumbUrl ?? '/catalog/' . rawurlencode($id),
                'thumbnail_url' => $thumbUrl,
                'original_url' => '/catalog/' . rawurlencode($id),
            ];
        }
        $lookup = $products->findForResults($chosen);
        foreach ($chosen as &$row) {
            $row['item_code'] = $products->itemCode($row['name']);
            $row['product'] = $lookup['products'][$row['id']] ?? null;
        }
        unset($row);
        $verification = $sumopod->rerank($file, $chosen);
        $chosen = array_slice($verification['results'], 0, 5);
        return response()->json([
            'results' => $chosen,
            'total' => (int) ($data['total'] ?? 0),
            'database_errors' => $lookup['errors'],
            'fallback' => true,
            'ai_mode' => true,
            'ai_search_mode' => $mode,
            'ai_provider' => $verification['used'] ? 'clip+sumopod' : 'clip',
            'learning' => $data['feedback'] ?? null,
            'sumopod' => [
                'enabled' => $sumopod->enabled(), 'used' => $verification['used'],
                'model' => $verification['model'], 'description' => $verification['description'],
                'message' => $verification['error'],
            ],
            'threshold' => null,
        ]);
    }

    public function feedback(Request $request, DriveImageIndex $index)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,webp,gif|max:10240',
            'selected_id' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9_-]{8,200}$/'],
        ]);
        $selectedId = (string) $request->input('selected_id');
        $all = $index->load();
        if (!isset($all[$selectedId]) || !is_array($all[$selectedId])) {
            return response()->json(['message' => 'Gambar yang dipilih tidak ditemukan di indeks katalog.'], 422);
        }
        $result = $this->aiRequest('/feedback', $request->file('image'), ['selected_id' => $selectedId]);
        if (!$result['ok']) return response()->json(['message' => $result['message']], $result['status']);
        $data = $result['data'];
        if (($data['status'] ?? null) !== 'ok') {
            return response()->json(['message' => 'Respons feedback AI tidak valid.'], 502);
        }
        return response()->json([
            'message' => 'Pilihan gambar benar sudah dipelajari AI.',
            'selected_id' => $selectedId,
            'selected_name' => (string) ($all[$selectedId]['name'] ?? ''),
            'feedback_examples' => (int) ($data['feedback_examples'] ?? 0),
            'votes' => (int) ($data['votes'] ?? 1),
        ]);
    }

    public function image(string $id, DriveImageIndex $index, DriveClient $drive)
    {
        abort_unless(isset($index->load()[$id]), 404);
        try {
            $tmp = $drive->fetchToTemporaryFile($id);
            $info = @getimagesize($tmp);
            if (!$info || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
                @unlink($tmp);
                abort(415, 'Format gambar tidak didukung');
            }
            $bytes = file_get_contents($tmp);
            @unlink($tmp);
            return response($bytes, 200, [
                'Content-Type' => $info['mime'], 'Cache-Control' => 'private, max-age=300',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (\Throwable $e) {
            report($e);
            abort(502, 'Gagal mengambil gambar dari Drive');
        }
    }
}
