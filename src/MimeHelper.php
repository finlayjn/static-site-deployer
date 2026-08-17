<?php

namespace SSD;

class MimeHelper
{
    protected static array $extMap = [
        'js'    => 'text/javascript',        // or 'application/javascript'
        'mjs'   => 'text/javascript',
        'css'   => 'text/css',
        'json'  => 'application/json',
        'map'   => 'application/json',
        'svg'   => 'image/svg+xml',
        'html'  => 'text/html',
        'htm'   => 'text/html',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'otf'   => 'font/otf',
        'eot'   => 'application/vnd.ms-fontobject',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'png'   => 'image/png',
        'gif'   => 'image/gif',
        'webp'  => 'image/webp',
        'avif'  => 'image/avif',
        'ico'   => 'image/x-icon',
        'xml'   => 'application/xml',
        'txt'   => 'text/plain',
    ];

    public static function getMimeType(string $filePath): string
    {
        // Strip query and fragment robustly
        $pathOnly = parse_url($filePath, PHP_URL_PATH) ?? $filePath;
        $ext = strtolower(pathinfo($pathOnly, PATHINFO_EXTENSION));

        if ($ext && isset(self::$extMap[$ext])) {
            return self::$extMap[$ext];
        }

        // Fall back to PHP's fileinfo for existing files with an unrecognized
        // extension. Common web-asset types are handled by the map above.
        if (is_file($filePath) && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if (false !== $finfo) {
                $guessed = finfo_file($finfo, $filePath);
                finfo_close($finfo);
                if (is_string($guessed) && '' !== $guessed
                    && 'application/x-empty' !== $guessed && 'text/plain' !== $guessed) {
                    return $guessed;
                }
            }
        }

        // Final fallback
        return 'application/octet-stream';
    }
}