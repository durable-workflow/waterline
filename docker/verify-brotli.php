<?php

declare(strict_types=1);

final class BrotliRuntimeRefused extends RuntimeException {}

function requirePatchedBrotli(string $source, string $version): string
{
    if (!preg_match('~^(?:brotli/)?(\d+\.\d+\.\d+)$~iD', $version, $match)) {
        throw new BrotliRuntimeRefused('Cannot read the Brotli version from '.$source.'.');
    }
    if (version_compare($match[1], '1.2.1', '<')) {
        throw new BrotliRuntimeRefused($source.' uses Brotli '.$match[1].'; this image requires 1.2.1 or newer.');
    }

    return $match[1];
}

function brotliProcess(array $command): string
{
    $process = proc_open($command, [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new BrotliRuntimeRefused('Cannot inspect the Brotli runtime.');
    }
    $output = stream_get_contents($pipes[1], 16384);
    $error = stream_get_contents($pipes[2], 16384);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new BrotliRuntimeRefused('Brotli inspection failed: '.trim($error ?: 'unknown error'));
    }

    return $output ?: '';
}

function verifyBrotliLibraryOwners(array $libraries): void
{
    foreach (array_unique(array_map('realpath', $libraries)) as $library) {
        $owner = brotliProcess(['apk', 'info', '--who-owns', $library]);
        if (!preg_match('~ is owned by brotli-libs-([\d.]+)-r\d+~', $owner, $match)) {
            throw new BrotliRuntimeRefused('A decoder is outside the vendor package: '.$library);
        }
        requirePatchedBrotli('Vendor decoder', $match[1]);
    }
}

function inspectBrotliRuntime(): array
{
    $versions = [];
    if (function_exists('curl_version')) {
        $curl = curl_version();
        if (isset($curl['brotli_version'])) {
            $versions['php_curl'] = requirePatchedBrotli('PHP libcurl', $curl['brotli_version']);
        }
    }
    if (is_executable('/usr/bin/curl')) {
        $output = brotliProcess(['/usr/bin/curl', '--version']);
        if (preg_match('~\bbrotli/(\d+\.\d+\.\d+)\b~i', $output ?: '', $match)) {
            $versions['curl'] = requirePatchedBrotli('curl', $match[1]);
        }
    }
    $libraries = array_merge(
        glob('/usr/lib/libbrotlidec.so*') ?: [],
        glob('/usr/lib/*/libbrotlidec.so*') ?: [],
        glob('/usr/local/lib/libbrotlidec.so*') ?: [],
    );
    if ($libraries !== [] && $versions === []) {
        throw new BrotliRuntimeRefused('A retained Brotli decoder has no inspectable runtime version.');
    }
    verifyBrotliLibraryOwners($libraries);

    return ['brotli' => $versions, 'decoder_absent' => $versions === []];
}

if (realpath($argv[0] ?? '') === __FILE__) {
    echo json_encode(inspectBrotliRuntime(), JSON_THROW_ON_ERROR).PHP_EOL;
}
