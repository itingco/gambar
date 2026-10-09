<?php
namespace App\Services;

use RuntimeException;

class DriveClient
{
    private ?string $token = null;
    private int $expiry = 0;

    private function configFile(): string
    {
        $value = (string) config('drive_index.credentials');
        if ($value === '') throw new RuntimeException('DRIVE_SERVICE_ACCOUNT_JSON belum diset di .env');
        return preg_match('~^([A-Za-z]:[\\\\/]|/)~', $value) ? $value : base_path($value);
    }

    private function token(): string
    {
        if ($this->token && time() < $this->expiry - 60) return $this->token;
        if (!extension_loaded('curl') || !extension_loaded('openssl')) {
            throw new RuntimeException('Aktifkan extension curl dan openssl pada php.ini');
        }
        $file = $this->configFile();
        if (!is_file($file)) throw new RuntimeException('Credential tidak ditemukan: '.$file);
        $json = json_decode((string) file_get_contents($file), true);
        if (!is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
            throw new RuntimeException('Credential Service Account JSON tidak valid');
        }
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $now = time();
        $h = $b64(json_encode(['alg'=>'RS256','typ'=>'JWT']));
        $p = $b64(json_encode(['iss'=>$json['client_email'], 'scope'=>'https://www.googleapis.com/auth/drive.readonly', 'aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+3600]));
        if (!openssl_sign($h.'.'.$p, $signature, $json['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Gagal sign JWT');
        }
        $result = $this->request('POST', 'https://oauth2.googleapis.com/token', http_build_query([
            'grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion'=>$h.'.'.$p.'.'.$b64($signature),
        ]), ['Content-Type: application/x-www-form-urlencoded'], false);
        $data = json_decode($result, true);
        if (empty($data['access_token'])) throw new RuntimeException('Google OAuth tidak mengirim access_token');
        $this->token = $data['access_token'];
        $this->expiry = time() + (int) ($data['expires_in'] ?? 3600);
        return $this->token;
    }

    private function request(string $method, string $url, ?string $body = null, array $headers = [], bool $authorized = true, ?string $outputFile = null): string
    {
        $ch = curl_init($url);
        $headers[] = 'Accept: application/json';
        if ($authorized) $headers[] = 'Authorization: Bearer '.$this->token();
        $handle = $outputFile !== null ? fopen($outputFile, 'wb') : null;
        if ($outputFile !== null && !$handle) throw new RuntimeException('Tidak bisa membuat file sementara');
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_RETURNTRANSFER=>$handle === null, CURLOPT_CONNECTTIMEOUT=>15, CURLOPT_TIMEOUT=>120,
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_SSL_VERIFYPEER=>true,
        ]);
        if ($handle !== null) curl_setopt($ch, CURLOPT_FILE, $handle);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($handle !== null) fclose($handle);
        if ($response === false || $status < 200 || $status >= 300) {
            if ($outputFile !== null) @unlink($outputFile);
            $message = $outputFile === null ? substr((string) $response, 0, 500) : '';
            throw new RuntimeException('Google HTTP '.$status.': '.($err ?: $message));
        }
        return $handle === null ? (string) $response : '';
    }

    public function listFolder(string $folderId): \Generator
    {
        $page = null;
        do {
            $params = ['q'=>"'".str_replace("'", "\\'", $folderId)."' in parents and trashed = false",
                'fields'=>'nextPageToken,files(id,name,mimeType,size,modifiedTime,md5Checksum,webViewLink)',
                'pageSize'=>1000, 'supportsAllDrives'=>'true', 'includeItemsFromAllDrives'=>'true'];
            if ($page) $params['pageToken'] = $page;
            $json = json_decode($this->request('GET', 'https://www.googleapis.com/drive/v3/files?'.http_build_query($params)), true);
            if (!is_array($json)) throw new RuntimeException('Respons list Drive tidak valid');
            foreach ($json['files'] ?? [] as $item) yield $item;
            $page = $json['nextPageToken'] ?? null;
        } while ($page);
    }

    public function fetchToTemporaryFile(string $id): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) throw new RuntimeException('Invalid Drive file id');
        $temp = tempnam(sys_get_temp_dir(), 'sif_');
        if ($temp === false) throw new RuntimeException('Tidak bisa membuat temporary file');
        try {
            $this->request('GET', 'https://www.googleapis.com/drive/v3/files/'.rawurlencode($id).'?alt=media', null, [], true, $temp);
            return $temp;
        } catch (\Throwable $e) {
            @unlink($temp);
            throw $e;
        }
    }
}
