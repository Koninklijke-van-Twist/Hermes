<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
if (function_exists('xdebug_disable')) {
    xdebug_disable();
}

require __DIR__ . "/auth.php";
require __DIR__ . "/logincheck.php";

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$cacheDir = __DIR__ . "/cache/odata";
$totalBytes = 0;

if (is_dir($cacheDir)) {
    $iterator = new FilesystemIterator($cacheDir, FilesystemIterator::SKIP_DOTS);
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }
        $totalBytes += (int) $fileInfo->getSize();
    }
}

echo json_encode([
    'bytes' => $totalBytes,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
