<?php

namespace SSD;

class CloudflareAssetsDeployer
{
    private string $accountId;
    private string $scriptName;
    private string $distPath;
    private string $apiToken;

    public function __construct(string $accountId, string $scriptName, string $distPath, string $apiToken)
    {
        $this->accountId = $accountId;
        $this->scriptName = $scriptName;
        $this->distPath = rtrim($distPath, '/');
        $this->apiToken = $apiToken;
    }

    public function buildManifest(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->distPath));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $realPath = $file->getRealPath();
                $relativePath = '/' . str_replace('\\', '/', substr($realPath, strlen($this->distPath) + 1));
                $hash = substr(hash_file('sha256', $realPath), 0, 32);
                $size = filesize($realPath);
                $files[$relativePath] = [
                    'hash' => $hash,
                    'size' => $size
                ];
            }
        }
        return $files;
    }

    private function cfApiRequest(string $method, string $url, $body = null, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        $defaultHeaders = [
            'Authorization: Bearer ' . $this->apiToken,
            'Content-Type: application/json'
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($defaultHeaders, $headers));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if ($body !== null) {
            if (is_array($body)) {
                $body = json_encode($body);
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        if ($response === false) {
            throw new \Exception("cURL error: " . curl_error($ch));
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = json_decode($response, true);
        if ($httpCode >= 400) {
            throw new \Exception("CF API error (HTTP $httpCode): " . $response);
        }
        return $decoded;
    }

    public function startUploadSession(array $manifest): array
    {
        $url = "https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/workers/scripts/{$this->scriptName}/assets-upload-session";
        $body = ['manifest' => $manifest];
        return $this->cfApiRequest('POST', $url, $body);
    }

    public function uploadFilesBatch(string $jwt, array $fileHashes, array $manifest): string
    {
        $url = "https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/workers/assets/upload?base64=true";
        $boundary = '----CloudflareBoundary' . md5(uniqid('', true));
        $headers = [
            'Authorization: Bearer ' . $jwt,
            'Content-Type: multipart/form-data; boundary=' . $boundary,
        ];

        $body = '';
        foreach ($fileHashes as $hash) {
            $filePath = null;
            foreach ($manifest as $path => $meta) {
                if ($meta['hash'] === $hash) {
                    $filePath = $this->distPath . $path;
                    break;
                }
            }
            if (!$filePath || !file_exists($filePath)) {
                throw new \Exception("File $filePath (hash: {$hash}) not found in dist folder.");
            }
            $contents = file_get_contents($filePath);
            $base64 = base64_encode($contents);

            $mimeType = MimeHelper::getMimeType($filePath);

            $body .= "--{$boundary}\r\n";
            $body .= 'Content-Disposition: form-data; name="' . $hash . "\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= "Content-Type: {$mimeType}\r\n\r\n";
            $body .= $base64 . "\r\n";
        }
        $body .= "--{$boundary}--\r\n";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        if ($response === false) {
            throw new \Exception("cURL error uploading files: " . curl_error($ch));
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode >= 400) {
            throw new \Exception("CF API error uploading files (HTTP $httpCode): " . $response);
        }
        $decoded = json_decode($response, true);

        if (isset($decoded['result']['jwt'])) {
            return $decoded['result']['jwt'];
        } elseif (!empty($decoded['success'])) {
            return $jwt;
        } else {
            throw new \Exception("Upload response missing JWT token. Raw response: " . $response);
        }
    }

    public function uploadAssets(): void
    {
        $manifest = $this->buildManifest();
        $sessionResponse = $this->startUploadSession($manifest);
        $jwt = $sessionResponse['result']['jwt'] ?? null;
        $buckets = $sessionResponse['result']['buckets'] ?? [];

        if (!$jwt) {
            throw new \Exception("No JWT token returned from upload session.");
        }

        $completionToken = $jwt;
        foreach ($buckets as $bucket) {
            $completionToken = $this->uploadFilesBatch($completionToken, $bucket, $manifest);
        }

        // NEW: Deploy the assets to the Worker
        $this->deployAssets($completionToken);
    }

    public function deployAssets(string $completionToken): void
    {
        $url = "https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/workers/scripts/{$this->scriptName}";
        $metadata = [
            'assets' => [
                'jwt' => $completionToken,
                'config' => [
                    'html_handling' => 'auto-trailing-slash',
                    'not_found_handling' => '404-page'
                ]
            ],
            'compatibility_date' => date('Y-m-d'),
        ];

        $boundary = '----CloudflareBoundary' . md5(uniqid('', true));
        $headers = [
            'Authorization: Bearer ' . $this->apiToken,
            'Content-Type: multipart/form-data; boundary=' . $boundary,
        ];

        $body = "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"metadata\"\r\n\r\n";
        $body .= json_encode($metadata) . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        if ($response === false) {
            throw new \Exception("cURL error deploying assets: " . curl_error($ch));
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode >= 400) {
            throw new \Exception("CF API error deploying assets (HTTP $httpCode): " . $response);
        }
    }
}
