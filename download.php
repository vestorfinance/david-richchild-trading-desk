<?php
require_once __DIR__ . '/auth.php';

$allowed = [
    'mq5' => ['path' => __DIR__ . '/TradingDeskEA.mq5', 'name' => 'TradingDeskEA.mq5', 'mime' => 'application/octet-stream'],
    'ex5' => ['path' => __DIR__ . '/TradingDeskEA.ex5', 'name' => 'TradingDeskEA.ex5', 'mime' => 'application/octet-stream'],
];

$type = $_GET['file'] ?? '';

if (!isset($allowed[$type])) {
    http_response_code(400);
    exit('Invalid file type.');
}

$file = $allowed[$type];

if (!is_file($file['path'])) {
    http_response_code(404);
    exit('File not available yet.');
}

header('Content-Type: ' . $file['mime']);
header('Content-Disposition: attachment; filename="' . $file['name'] . '"');
header('Content-Length: ' . filesize($file['path']));
header('Cache-Control: no-store');
readfile($file['path']);
