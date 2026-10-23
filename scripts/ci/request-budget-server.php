<?php

declare(strict_types=1);

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/ready') { echo 'ready'; return; }
$headers = getallheaders();
if (($headers['Authorization'] ?? '') !== 'Bearer smoke-token' || ($headers['X-Namespace'] ?? '') !== 'smoke') {
    http_response_code(403); echo '{"reason":"authorization_failed"}'; return;
}
$mode = json_decode(file_get_contents(__DIR__.'/mode.json'), true, flags: JSON_THROW_ON_ERROR);
if ($mode['mode'] === 'write-stall' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    file_put_contents(__DIR__.'/writes.jsonl', json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'path' => $path], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    usleep(2_000_000);
    header('Content-Type: application/json'); echo '{"accepted":true}'; return;
}
if ($mode['mode'] === 'backend-stall' && $path === '/api/workflows') usleep(2_000_000);
require __DIR__.'/protocol-server.php';
