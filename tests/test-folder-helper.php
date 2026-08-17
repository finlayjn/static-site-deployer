<?php
/**
 * Standalone tests for SSD\FolderHelper (no WordPress required).
 *
 *   php tests/test-folder-helper.php
 */

require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/src/FolderHelper.php';

use SSD\FolderHelper;

$root = sys_get_temp_dir() . '/ssd-folder-test-' . uniqid();
mkdir($root . '/a/b', 0777, true);
file_put_contents($root . '/a/b/file.txt', 'hi');
file_put_contents($root . '/a/top.txt', 'hi');

ssd_assert('nested tree exists before delete', true, is_dir($root . '/a/b'));

FolderHelper::delete_folder($root);

ssd_assert('folder is removed recursively', false, file_exists($root));

// Deleting a non-existent folder is a no-op (no error).
FolderHelper::delete_folder($root . '/does-not-exist');
ssd_assert('deleting missing folder is safe', false, file_exists($root . '/does-not-exist'));

ssd_done();
