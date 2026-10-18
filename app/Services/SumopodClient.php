<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SumopodClient
{
    public function enabled(): bool
    {
        return (bool) config('image_ai.sumopod.enabled', false)
            && trim((string) config('image_ai.sumopod.api_key', '')) !== '';
    }

    public function model(): string
    {
        return trim((string) config('image_ai.sumopod.model', 'gpt-4o-mini'));
    }

    public function candidateLimit(): int
    {
        return max(5, min(20, (int) config('image_ai.sumopod.candidates', 8)));
    }

    /**
     * CLIP remains the retrieval engine. Sumopod receives the uploaded photo plus
     * the strongest CLIP candidates and can reorder them using product metadata.
     * If the provider/model does not accept image input, this method fails closed
     * and the original CLIP order is returned unchanged.
     *
     * @param array<int,array<string,mixed>> $results
     * @return array{results:array,used:bool,model:string,description:?string,error:?string}
     */
    public function rerank(UploadedFile $image, array $results): array
    {
        $fallback = [
            'results' => $results,
            'used' => false,
            'model' => $this->model(),
            'description' => null,
            'error' => null,
        ];

        if (!$this->enabled() || count($results) < 2) {
            return $fallback;
        }

        try {
            $candidateText = [];
            foreach ($results as $i => $row) {
                $candidateText[] = sprintf(
                    "%d. id=%s | kode=%s | file=%s | nama_barang=%s | skor_clip=%s%%",
                    $i + 1,
                    (string) ($row['id'] ?? ''),
                    (string) ($row['item_code'] ?? '-'),
                    (string) ($row['name'] ?? '-'),
                    (string) ($row['product']['item_name'] ?? '-'),
                    (string) ($row['score'] ?? '-')
                );
            }

            $prompt = "Anda adalah verifier pencarian produk alat teknik INGCO/WADFOW.\n"
                . "Lihat foto yang diunggah, lalu nilai kandidat hasil CLIP di bawah. "
                . "Gunakan bentuk alat, kategori, warna/kemasan, tulisan yang terlihat, kode/model, dan nama barang. "
                . "Jangan membuat ID baru. Urutkan hanya ID yang tersedia dari paling mungkin ke paling tidak mungkin.\n\n"
                . implode("\n", $candidateText)
                . "\n\nBalas JSON murni tanpa markdown dengan format: "
                . '{"description":"deskripsi singkat objek pada foto","ranking":["id1","id2","id3"]}';

            $content = $this->chat([
                [
                    'role' => 'system',
                    'content' => 'Jawab deterministik dan ringkas. Output harus JSON valid.',
                ],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        ['type' => 'image_url', 'image_url' => ['url' => $this->imageDataUrl($image)]],
                    ],
                ],
            ], 500);

            $parsed = $this->parseJsonObject($content);
            $ranking = is_array($parsed['ranking'] ?? null) ? $parsed['ranking'] : [];
            $byId = [];
            foreach ($results as $row) {
                $id = (string) ($row['id'] ?? '');
                if ($id !== '') {
                    $byId[$id] = $row;
                }
            }

            $ordered = [];
            $seen = [];
            foreach ($ranking as $id) {
                $id = (string) $id;
                if (isset($byId[$id]) && !isset($seen[$id])) {
                    $ordered[] = $byId[$id];
                    $seen[$id] = true;
                }
            }

            // Require at least two valid IDs before trusting the model's order.
            if (count($ordered) < 2) {
                throw new RuntimeException('Sumopod tidak mengembalikan ranking kandidat yang valid.');
            }

            foreach ($results as $row) {
                $id = (string) ($row['id'] ?? '');
                if ($id !== '' && !isset($seen[$id])) {
                    $ordered[] = $row;
                }
            }

            return [
                'results' => $ordered,
                'used' => true,
                'model' => $this->model(),
                'description' => isset($parsed['description']) ? (string) $parsed['description'] : null,
                'error' => null,
            ];
        } catch (Throwable $e) {
            Log::warning('Sumopod rerank failed; using CLIP order', [
                'model' => $this->model(),
                'error' => $e->getMessage(),
            ]);
            $fallback['error'] = 'Verifikasi Sumopod gagal; hasil tetap memakai ranking CLIP.';
            return $fallback;
        }
    }

    public function testConnection(): string
    {
        if (!$this->enabled()) {
            throw new RuntimeException('SUMOPOD_ENABLED=true dan SUMOPOD_API_KEY wajib diisi.');
        }

        return $this->chat([
            ['role' => 'user', 'content' => 'Balas tepat dengan teks: SUMOPOD_OK'],
        ], 30);
    }

    /** @return array<int,string> */
    public function availableModels(): array
    {
        $data = $this->requestJson('GET', '/models');
        $ids = [];
        foreach (($data['data'] ?? []) as $row) {
            if (is_array($row) && isset($row['id'])) {
                $ids[] = (string) $row['id'];
            }
        }
        sort($ids, SORT_NATURAL | SORT_FLAG_CASE);
        return array_values(array_unique($ids));
    }

    private function chat(array $messages, int $maxTokens): string
    {
        $data = $this->requestJson('POST', '/chat/completions', [
            'model' => $this->model(),
            'messages' => $messages,
            'temperature' => 0,
            'max_tokens' => $maxTokens,
        ]);

        $content = $data['choices'][0]['message']['content'] ?? null;
        if (is_string($content)) {
            return trim($content);
        }
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                if (is_array($part) && isset($part['text'])) {
                    $parts[] = (string) $part['text'];
                }
            }
            if ($parts) {
                return trim(implode("\n", $parts));
            }
        }
        throw new RuntimeException('Respons Sumopod tidak memiliki choices[0].message.content.');
    }

    private function requestJson(string $method, string $path, ?array $payload = null): array
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('PHP curl extension belum aktif.');
        }

        $baseUrl = rtrim((string) config('image_ai.sumopod.base_url', 'https://ai.sumopod.com/v1'), '/');
        $apiKey = trim((string) config('image_ai.sumopod.api_key', ''));
        if ($apiKey === '') {
            throw new RuntimeException('SUMOPOD_API_KEY belum diisi.');
        }

        $ch = curl_init($baseUrl . '/' . ltrim($path, '/'));
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];
        $options = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => max(10, (int) config('image_ai.sumopod.timeout', 45)),
            CURLOPT_SSL_VERIFYPEER => true,
        ];

        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new RuntimeException('Gagal membuat payload JSON Sumopod.');
            }
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $headers;
            $options[CURLOPT_POSTFIELDS] = $json;
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Sumopod network error: ' . ($error ?: 'unknown error'));
        }
        $data = json_decode((string) $response, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($data)
                ? (string) ($data['error']['message'] ?? $data['message'] ?? 'HTTP ' . $status)
                : 'HTTP ' . $status;
            throw new RuntimeException('Sumopod HTTP ' . $status . ': ' . mb_substr($message, 0, 300));
        }
        if (!is_array($data)) {
            throw new RuntimeException('Respons Sumopod bukan JSON yang valid.');
        }
        return $data;
    }

    private function imageDataUrl(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        if (!$path || !is_file($path)) {
            throw new RuntimeException('File upload tidak dapat dibaca untuk Sumopod.');
        }

        $bytes = (string) file_get_contents($path);
        $mime = (string) ($file->getMimeType() ?: 'image/jpeg');

        // Reduce token/body size while preserving enough visual detail for verification.
        if (extension_loaded('gd')) {
            $src = @imagecreatefromstring($bytes);
            if ($src) {
                try {
                    $w = imagesx($src);
                    $h = imagesy($src);
                    $scale = min(1, 1280 / max($w, $h));
                    $dw = max(1, (int) round($w * $scale));
                    $dh = max(1, (int) round($h * $scale));
                    $dst = imagecreatetruecolor($dw, $dh);
                    if ($dst) {
                        try {
                            $white = imagecolorallocate($dst, 255, 255, 255);
                            imagefill($dst, 0, 0, $white);
                            imagecopyresampled($dst, $src, 0, 0, 0, 0, $dw, $dh, $w, $h);
                            ob_start();
                            imagejpeg($dst, null, 82);
                            $compressed = ob_get_clean();
                            if (is_string($compressed) && $compressed !== '') {
                                $bytes = $compressed;
                                $mime = 'image/jpeg';
                            }
                        } finally {
                            imagedestroy($dst);
                        }
                    }
                } finally {
                    imagedestroy($src);
                }
            }
        }

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    private function parseJsonObject(string $text): array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        $first = strpos($text, '{');
        $last = strrpos($text, '}');
        if ($first !== false && $last !== false && $last >= $first) {
            $text = substr($text, $first, $last - $first + 1);
        }
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Output Sumopod bukan JSON sesuai format rerank.');
        }
        return $decoded;
    }
}
