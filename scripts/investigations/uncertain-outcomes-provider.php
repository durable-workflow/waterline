<?php

declare(strict_types=1);

// Independent loopback-only synthetic provider. It deliberately has no deduplication.
header('Content-Type: application/json');
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/health') { echo '{"ok":true}'; return; }
$file = fopen(__DIR__.'/provider-oracle.json', 'c+');
if ($file === false || !flock($file, LOCK_EX)) throw new RuntimeException('Provider ledger unavailable.');
$delay = false;
try {
    $raw = stream_get_contents($file);
    $ledger = $raw ? json_decode($raw, true, flags: JSON_THROW_ON_ERROR) : [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/submit') {
        $input = json_decode(file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
        $key = $input['operation_key'] ?? null;
        $scenario = $input['scenario'] ?? null;
        if (!is_string($key) || !str_starts_with($key, 'operation-') || !in_array($scenario, ['lost-response','confirmed-failure','processing-failure'], true)) {
            http_response_code(400);
            $response = ['error' => 'invalid_synthetic_submission'];
        } else {
            $entry = $ledger[$key] ?? ['attempts' => 0, 'receipts' => []];
            ++$entry['attempts'];
            if ($scenario === 'confirmed-failure') {
                http_response_code(409);
                $response = ['accepted' => false, 'reason' => 'synthetic_confirmed_rejection'];
            } else {
                $response = ['receipt_id' => 'synthetic-'.$key.'-'.(count($entry['receipts']) + 1), 'operation_key' => $key, 'outcome' => 'accepted'];
                $entry['receipts'][] = $response;
                $delay = $scenario === 'lost-response';
            }
            $ledger[$key] = $entry;
            rewind($file);
            ftruncate($file, 0);
            fwrite($file, json_encode($ledger, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            fflush($file);
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && str_starts_with($path, '/operations/')) {
        $response = $ledger[rawurldecode(substr($path, strlen('/operations/')))] ?? ['attempts' => 0, 'receipts' => []];
    } else {
        http_response_code(404);
        $response = ['error' => 'not_found'];
    }
} finally {
    flock($file, LOCK_UN);
    fclose($file);
}
// Commit is durable before a real client timeout; no response headers/body are flushed yet.
if ($delay) usleep(600_000);
echo json_encode($response, JSON_THROW_ON_ERROR);
