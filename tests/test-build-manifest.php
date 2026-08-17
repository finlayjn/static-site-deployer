<?php
/**
 * Standalone tests for SSD\CloudflareAssetsDeployer::buildManifest()
 * (no WordPress or network required).
 *
 *   php tests/test-build-manifest.php
 */

require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/src/MimeHelper.php';
require dirname(__DIR__) . '/src/FolderHelper.php';
require dirname(__DIR__) . '/src/CloudflareAssetsDeployer.php';

use SSD\CloudflareAssetsDeployer;

$dist = sys_get_temp_dir() . '/ssd-manifest-test-' . uniqid();
mkdir($dist . '/sub', 0777, true);
file_put_contents($dist . '/index.html', '<h1>hi</h1>');
file_put_contents($dist . '/sub/app.js', 'console.log(1)');

$deployer = new CloudflareAssetsDeployer('acct', 'worker', $dist, 'token');
$manifest = $deployer->buildManifest();

ssd_assert('manifest has both files', 2, count($manifest));
ssd_assert('paths are leading-slash relative', true, isset($manifest['/index.html'], $manifest['/sub/app.js']));

$entry = $manifest['/index.html'];
ssd_assert('hash is a 32-char prefix', 32, strlen($entry['hash']));
ssd_assert('hash matches sha256 prefix', substr(hash('sha256', '<h1>hi</h1>'), 0, 32), $entry['hash']);
ssd_assert('size is recorded', 11, $entry['size']);

\SSD\FolderHelper::delete_folder($dist);

ssd_done();
