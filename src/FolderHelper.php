<?php

namespace SSD;

class FolderHelper
{
    public static function delete_folder($dir)
    {
        if (!is_dir($dir)) return;
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = "$dir/$item";
            is_dir($path) ? self::delete_folder($path) : unlink($path);
        }
        rmdir($dir);
    }
}