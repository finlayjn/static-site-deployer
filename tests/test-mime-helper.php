<?php
/**
 * Standalone tests for SSD\MimeHelper (no WordPress required).
 *
 *   php tests/test-mime-helper.php
 */

require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/src/MimeHelper.php';

use SSD\MimeHelper;

ssd_assert('css maps to text/css', 'text/css', MimeHelper::getMimeType('/x/style.css'));
ssd_assert('js maps to text/javascript', 'text/javascript', MimeHelper::getMimeType('/x/app.js'));
ssd_assert('svg maps to image/svg+xml', 'image/svg+xml', MimeHelper::getMimeType('/x/logo.svg'));
ssd_assert('query string is ignored', 'text/css', MimeHelper::getMimeType('/x/style.css?ver=1'));
ssd_assert('unknown, missing file falls back', 'application/octet-stream', MimeHelper::getMimeType('/x/nope.unknownext'));

ssd_done();
