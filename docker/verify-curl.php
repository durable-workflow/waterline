<?php

declare(strict_types=1);

final class CurlRuntimeRefused extends RuntimeException {}

function requirePatchedCurl(string $source, string $version): string
{
    if (!preg_match('~^(\d+\.\d+\.\d+)$~D', $version)) {
        throw new CurlRuntimeRefused('Cannot read the curl version from '.$source.'.');
    }
    if (version_compare($version, '8.23.0', '<')) {
        throw new CurlRuntimeRefused($source.' uses curl '.$version.'; this image requires 8.23.0 or newer.');
    }

    return $version;
}

function curlInspectionProcess(array $command): string
{
    $process = proc_open($command, [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new CurlRuntimeRefused('Cannot inspect the curl runtime.');
    }
    $output = stream_get_contents($pipes[1], 16384);
    $error = stream_get_contents($pipes[2], 16384);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new CurlRuntimeRefused('curl inspection failed: '.trim($error ?: 'unknown error'));
    }

    return $output ?: '';
}

function inspectCurlRuntime(): array
{
    if (!function_exists('curl_version') || !is_executable('/usr/bin/curl')) {
        throw new CurlRuntimeRefused('The PHP curl extension and curl executable are required.');
    }
    $phpVersion = requirePatchedCurl('PHP libcurl', curl_version()['version'] ?? '');
    $output = curlInspectionProcess(['/usr/bin/curl', '--version']);
    if (!preg_match('~^curl (\d+\.\d+\.\d+)\b.*\blibcurl/(\d+\.\d+\.\d+)\b~', $output, $match)) {
        throw new CurlRuntimeRefused('Cannot inspect the curl executable and its loaded libcurl.');
    }
    $versions = [
        'php_libcurl' => $phpVersion,
        'curl' => requirePatchedCurl('curl executable', $match[1]),
        'curl_libcurl' => requirePatchedCurl('curl loaded library', $match[2]),
    ];
    $libraries = glob('/usr/lib/libcurl.so*') ?: [];
    if ($libraries === []) {
        throw new CurlRuntimeRefused('Cannot inspect the vendor libcurl library.');
    }
    foreach (array_unique(array_map('realpath', $libraries)) as $library) {
        if (!is_string($library)) {
            throw new CurlRuntimeRefused('Cannot resolve a libcurl library.');
        }
        $owner = curlInspectionProcess(['apk', 'info', '--who-owns', $library]);
        if (!preg_match('~ is owned by libcurl-([\d.]+)-r\d+~', $owner, $match)) {
            throw new CurlRuntimeRefused('A libcurl library is outside the vendor package: '.$library);
        }
        $versions['vendor_libcurl'] = requirePatchedCurl('Vendor libcurl', $match[1]);
    }

    return ['curl' => $versions, 'minimum_version' => '8.23.0'];
}

if (realpath($argv[0] ?? '') === __FILE__) {
    echo json_encode(inspectCurlRuntime(), JSON_THROW_ON_ERROR).PHP_EOL;
}
