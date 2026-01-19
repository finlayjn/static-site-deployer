<?php

namespace SSD;

use Symfony\Component\Mime\MimeTypes;

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

        // If local file exists, let Symfony / finfo try
        if (is_file($filePath)) {
            $mimeTypes = new MimeTypes();
            $guessed = $mimeTypes->guessMimeType($filePath);
            if ($guessed && $guessed !== 'text/plain') {
                return $guessed;
            }
        }

        // Final fallback
        return 'application/octet-stream';
    }
}