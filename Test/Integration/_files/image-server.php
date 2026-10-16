<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

// phpcs:ignoreFile -- router for PHP's built-in web server, started by ImageImportTest

declare(strict_types=1);

/**
 * /pic/<name>.png   a small PNG whose colour comes from the name, with an ETag (304 for If-None-Match)
 * /missing.png      404
 * /page.png         an HTML page with status 200
 * /photo.webp       a WebP header
 * /moved.png        302 to /pic/moved.png
 * Every request is logged as "<path> <status>" to the file in OPENIMPORT_IMAGE_LOG.
 */
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$log = static function (int $status) use ($path): void {
    file_put_contents((string) getenv('OPENIMPORT_IMAGE_LOG'), $path . ' ' . $status . "\n", FILE_APPEND);
};

if (preg_match('#^/pic/([a-z0-9-]+)\.png$#', $path, $match) === 1) {
    $hash = md5($match[1]);
    $image = imagecreatetruecolor(8, 8);
    imagefill($image, 0, 0, imagecolorallocate($image, hexdec(substr($hash, 0, 2)), hexdec(substr($hash, 2, 2)), hexdec(substr($hash, 4, 2))));
    ob_start();
    imagepng($image);
    $body = (string) ob_get_clean();
    $etag = '"' . md5($body) . '"';
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        header('ETag: ' . $etag);
        $log(304);
        return true;
    }
    header('Content-Type: application/octet-stream');
    header('ETag: ' . $etag);
    $log(200);
    echo $body;
    return true;
}
if ($path === '/moved.png') {
    http_response_code(302);
    header('Location: /pic/moved.png');
    $log(302);
    return true;
}
if ($path === '/page.png') {
    header('Content-Type: text/html');
    $log(200);
    echo '<!DOCTYPE html><html><body>Sorry</body></html>';
    return true;
}
if ($path === '/photo.webp') {
    header('Content-Type: image/webp');
    $log(200);
    echo 'RIFF' . "\x24\x00\x00\x00" . 'WEBPVP8 ' . str_repeat("\x00", 24);
    return true;
}
http_response_code(404);
$log(404);
echo 'Not found';
return true;
