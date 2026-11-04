<?php

declare(strict_types=1);

require_once '/packet/state.php';

final class NativeBudgetFixtureException extends RuntimeException {}

if (PHP_SAPI !== 'cli') {
    $path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $mode = sourceFixtureState();
    if (($mode['schedule_write_stall'] ?? false) && $_SERVER['REQUEST_METHOD'] === 'POST'
        && $path === '/waterline/api/v2/schedules/budget-schedule/pause') {
        file_put_contents('/state/schedule-writes.jsonl', json_encode([
            'method' => 'POST', 'path' => $path, 'schedule_id' => 'budget-schedule', 'status' => 'paused',
        ], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
        usleep(3_000_000);
        header('Content-Type: application/json');
        echo json_encode(['schedule_id' => 'budget-schedule', 'status' => 'paused'], JSON_THROW_ON_ERROR);
        return;
    }
    // View wiring uses controlled HTTP responses. Run-list cases continue to
    // the real package/image and its actual SDK/protocol backend.
    if (isset($mode['browser_path'], $mode['browser_response']) && $path === $mode['browser_path']) {
        if ($mode['mode'] === 'browser-stall') {
            usleep(3_000_000);
        }
        header('Content-Type: application/json');
        echo json_encode($mode['browser_response'], JSON_THROW_ON_ERROR);
        return;
    }
    if ($mode['mode'] === 'browser-stall' && $path === '/waterline/api/flows/running') {
        usleep((int) ($mode['browser_stall_microseconds'] ?? 3_000_000));
    }

    $nativePort = '8082';
    if ($nativePort !== false) {
        $port = filter_var($nativePort, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false || !str_starts_with($_SERVER['REQUEST_URI'], '/')) {
            http_response_code(400);
            throw new NativeBudgetFixtureException('Expected a local native-image port and request path.');
        }
        $decodedPath = rawurldecode($path);
        $segments = explode('/', ltrim($decodedPath, '/'));
        $allowed = preg_match('#^(?:/up|/favicon\.ico|/waterline(?:/[A-Za-z0-9_-]+)*|/vendor/waterline/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+)$#D', $decodedPath);
        if ($allowed !== 1 || array_intersect(['.', '..'], $segments) !== []) {
            http_response_code(400);
            throw new NativeBudgetFixtureException('Unexpected native proxy request path.');
        }
        $targetPath = '/'.implode('/', array_map('rawurlencode', $segments));
        $query = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
        $target = 'http://budget-app:'.$port.$targetPath.($query === null ? '' : '?'.$query);
        $headers = ['Host: 127.0.0.1:8080'];
        foreach (getallheaders() as $name => $value) {
            if (!in_array(strtolower($name), ['host', 'connection', 'content-length', 'transfer-encoding'], true)) {
                $headers[] = $name.': '.$value;
            }
        }
        $responseHeaders = [];
        $body = '';
        $bodyLimit = str_starts_with($path, '/vendor/waterline/') ? 8388608 : 2097152;
        $input = file_get_contents('php://input', false, null, 0, 16385);
        if (!is_string($input) || strlen($input) > 16384) {
            http_response_code(413);
            throw new NativeBudgetFixtureException('Source proxy request bound.');
        }
        $headerBytes = 0;
        $connection = curl_init($target);
        curl_setopt_array($connection, [
            CURLOPT_CUSTOMREQUEST => $_SERVER['REQUEST_METHOD'], CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $input, CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => static function ($_handle, string $chunk) use (&$body, $bodyLimit): int {
                if (strlen($body) + strlen($chunk) > $bodyLimit) { return 0; }
                $body .= $chunk; return strlen($chunk);
            },
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP, CURLOPT_TIMEOUT_MS => 7000,
            CURLOPT_HEADERFUNCTION => static function ($_handle, string $line) use (&$responseHeaders, &$headerBytes): int {
                $headerBytes += strlen($line);
                if (strlen($line) > 4096 || $headerBytes > 65536) { return 0; }
                $responseHeaders[] = trim($line);
                return strlen($line);
            },
        ]);
        $success = curl_exec($connection);
        if ($success === false) {
            http_response_code(502);
            throw new NativeBudgetFixtureException('Native image HTTP fixture proxy failed.');
        }
        http_response_code(curl_getinfo($connection, CURLINFO_RESPONSE_CODE));
        foreach ($responseHeaders as $line) {
            if ($line !== '' && !preg_match('/^(?:HTTP\/|connection:|transfer-encoding:|content-length:)/i', $line)) {
                header($line, false);
            }
        }
        header('Content-Length: ' . strlen($body));
        echo $body;
        return;
    }
}


throw new NativeBudgetFixtureException("The source proxy requires HTTP fixture mode.");
