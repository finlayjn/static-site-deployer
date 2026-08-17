<?php

namespace SSD;

/**
 * Uploads a directory of static files to a Cloudflare Worker's assets and
 * deploys it, using the Workers assets upload-session API.
 *
 * Uses the WordPress HTTP API so it works without the cURL PHP extension and
 * honors site proxy settings.
 */
class CloudflareAssetsDeployer
{
    const API_BASE = 'https://api.cloudflare.com/client/v4';
    const TIMEOUT = 60;

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

    /**
     * Builds the Cloudflare upload manifest keyed by leading-slash path.
     *
     * @return array<string,array{hash:string,size:int}>
     */
    public function buildManifest(): array
    {
        if (!is_dir($this->distPath)) {
            throw new \RuntimeException("Export directory not found: {$this->distPath}");
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->distPath, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            // Use the iterator path (not getRealPath) so the prefix strip stays
            // consistent with $distPath even when it contains symlinks.
            $path = $file->getPathname();
            $relativePath = '/' . str_replace('\\', '/', substr($path, strlen($this->distPath) + 1));
            $files[$relativePath] = [
                'hash' => substr(hash_file('sha256', $path), 0, 32),
                'size' => filesize($path),
            ];
        }

        if (empty($files)) {
            throw new \RuntimeException("No files found to deploy in {$this->distPath}");
        }

        return $files;
    }

    /**
     * Performs an HTTP request via the WordPress HTTP API.
     *
     * @param string               $method
     * @param string               $url
     * @param array<string,string> $headers
     * @param string|null          $body
     * @return array<mixed>
     */
    private function request(string $method, string $url, array $headers, ?string $body = null): array
    {
        $args = [
            'method'  => $method,
            'headers' => $headers,
            'timeout' => self::TIMEOUT,
        ];
        if (null !== $body) {
            $args['body'] = $body;
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            throw new \RuntimeException('HTTP error: ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = (string) wp_remote_retrieve_body($response);
        if ($code >= 400) {
            throw new \RuntimeException("Cloudflare API error (HTTP {$code}): {$raw}");
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,array{hash:string,size:int}> $manifest
     * @return array<mixed>
     */
    public function startUploadSession(array $manifest): array
    {
        $url = self::API_BASE . "/accounts/{$this->accountId}/workers/scripts/{$this->scriptName}/assets-upload-session";
        return $this->request(
            'POST',
            $url,
            [
                'Authorization' => 'Bearer ' . $this->apiToken,
                'Content-Type'  => 'application/json',
            ],
            (string) wp_json_encode(['manifest' => $manifest])
        );
    }

    /**
     * Uploads one batch (bucket) of files by hash.
     *
     * @param string                                     $jwt
     * @param string[]                                   $fileHashes
     * @param array<string,array{hash:string,size:int}>  $manifest
     * @return string The completion/continuation token.
     */
    public function uploadFilesBatch(string $jwt, array $fileHashes, array $manifest): string
    {
        $url = self::API_BASE . "/accounts/{$this->accountId}/workers/assets/upload?base64=true";
        $boundary = '----CloudflareBoundary' . md5(uniqid('', true));

        $body = '';
        foreach ($fileHashes as $hash) {
            $filePath = $this->pathForHash($hash, $manifest);
            if (null === $filePath || !file_exists($filePath)) {
                throw new \RuntimeException("File for hash {$hash} not found in export directory.");
            }
            $base64 = base64_encode((string) file_get_contents($filePath));
            $mimeType = MimeHelper::getMimeType($filePath);

            $body .= "--{$boundary}\r\n";
            $body .= 'Content-Disposition: form-data; name="' . $hash . "\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= "Content-Type: {$mimeType}\r\n\r\n";
            $body .= $base64 . "\r\n";
        }
        $body .= "--{$boundary}--\r\n";

        $decoded = $this->request(
            'POST',
            $url,
            [
                'Authorization' => 'Bearer ' . $jwt,
                'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
            ],
            $body
        );

        if (isset($decoded['result']['jwt'])) {
            return $decoded['result']['jwt'];
        }
        if (!empty($decoded['success'])) {
            return $jwt;
        }
        throw new \RuntimeException('Upload response missing continuation token.');
    }

    /**
     * Runs the full upload session and deploys the assets.
     */
    public function uploadAssets(): void
    {
        $manifest = $this->buildManifest();
        $session  = $this->startUploadSession($manifest);

        $jwt = $session['result']['jwt'] ?? null;
        $buckets = $session['result']['buckets'] ?? [];

        // A missing JWT with no buckets means every asset is already uploaded.
        if (null === $jwt) {
            if (empty($buckets)) {
                $this->deployAssets('');
                return;
            }
            throw new \RuntimeException('No upload token returned from Cloudflare.');
        }

        $completionToken = $jwt;
        foreach ($buckets as $bucket) {
            $completionToken = $this->uploadFilesBatch($completionToken, $bucket, $manifest);
        }

        $this->deployAssets($completionToken);
    }

    /**
     * Deploys the uploaded assets to the Worker.
     *
     * @param string $completionToken
     */
    public function deployAssets(string $completionToken): void
    {
        $url = self::API_BASE . "/accounts/{$this->accountId}/workers/scripts/{$this->scriptName}";

        $assets = [
            'config' => [
                'html_handling'      => 'auto-trailing-slash',
                'not_found_handling' => '404-page',
            ],
        ];
        if ('' !== $completionToken) {
            $assets['jwt'] = $completionToken;
        }

        $metadata = [
            'assets'             => $assets,
            'compatibility_date' => gmdate('Y-m-d'),
        ];

        $boundary = '----CloudflareBoundary' . md5(uniqid('', true));
        $body  = "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"metadata\"\r\n\r\n";
        $body .= wp_json_encode($metadata) . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $this->request(
            'PUT',
            $url,
            [
                'Authorization' => 'Bearer ' . $this->apiToken,
                'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
            ],
            $body
        );
    }

    /**
     * Resolves the on-disk path for a manifest hash.
     *
     * @param string                                     $hash
     * @param array<string,array{hash:string,size:int}>  $manifest
     * @return string|null
     */
    private function pathForHash(string $hash, array $manifest): ?string
    {
        foreach ($manifest as $path => $meta) {
            if ($meta['hash'] === $hash) {
                return $this->distPath . $path;
            }
        }
        return null;
    }
}
