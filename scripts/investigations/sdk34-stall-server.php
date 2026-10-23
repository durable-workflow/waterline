<?php

declare(strict_types=1);

if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/health') { echo 'ready'; return; }
file_put_contents(__DIR__.'/stall-'.$_SERVER['SERVER_PORT'].'.jsonl', json_encode([
    'at' => gmdate(DATE_ATOM), 'method' => $_SERVER['REQUEST_METHOD'],
    'path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    'namespace' => $_SERVER['HTTP_X_NAMESPACE'] ?? null,
], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
// The caller must time out before this synthetic server sends headers or body.
sleep(70);
header('Content-Type: application/json');
echo '{"unexpected_response":true}';
