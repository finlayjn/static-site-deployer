<?php

namespace SSD;

use Symfony\Component\Mime\MimeTypes;

class MimeHelper
{
    public static function getMimeType(string $filePath): string
    {
        $cleanPath = preg_replace('/\?.*$/', '', $filePath);
        $mimeTypes = new MimeTypes();
        $mime = $mimeTypes->guessMimeType($cleanPath);

        // Fallback for common web types
        if (!$mime || $mime === 'text/plain') {
            $ext = strtolower(pathinfo($cleanPath, PATHINFO_EXTENSION));
            if ($ext === 'css') return 'text/css';
            if ($ext === 'js') return 'application/javascript';
            if ($ext === 'json') return 'application/json';
            if ($ext === 'svg') return 'image/svg+xml';
            if ($ext === 'html' || $ext === 'htm') return 'text/html';
        }
        return $mime ?: 'application/octet-stream';
    }
}