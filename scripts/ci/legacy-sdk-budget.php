<?php

declare(strict_types=1);

require getcwd().'/vendor/autoload.php';

final class LegacyBudgetCompatibilityFailure extends RuntimeException {}

$version = \Composer\InstalledVersions::getPrettyVersion('durable-workflow/sdk');
$source = \Composer\InstalledVersions::getReference('durable-workflow/sdk');
if ($version !== '2.4.0' || $source !== '821716a92f179ec360e045c28979a86a5f587075') {
    throw new LegacyBudgetCompatibilityFailure('The legacy compatibility control must use normal SDK 2.4.0.');
}
$cases = [];
foreach (['default', 'null', 'unsupported-explicit'] as $case) {
    $mode = ['mode' => 'healthy', 'browser_budget' => 2];
    if ($case !== 'default') {
        $mode['service_budget'] = $case === 'null' ? null : 0.2;
    }
    file_put_contents('mode.json', json_encode($mode, JSON_THROW_ON_ERROR));
    $before = is_file('reads.jsonl') ? count(file('reads.jsonl', FILE_IGNORE_NEW_LINES)) : 0;
    $request = curl_init('http://127.0.0.1:8080/waterline/api/flows/running');
    curl_setopt_array($request, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $raw = curl_exec($request);
    $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    if (!is_string($raw)) {
        throw new LegacyBudgetCompatibilityFailure('Legacy host request failed.');
    }
    $body = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    $after = is_file('reads.jsonl') ? count(file('reads.jsonl', FILE_IGNORE_NEW_LINES)) : 0;
    if ($case === 'unsupported-explicit') {
        if ($status !== 501 || ($body['reason'] ?? null) !== 'backend_capability_unavailable' || $after !== $before) {
            throw new LegacyBudgetCompatibilityFailure('An unsupported explicit budget must refuse before an upstream request.');
        }
    } elseif ($status !== 200 || count($body['data'] ?? []) !== 2 || $after !== $before + 1) {
        throw new LegacyBudgetCompatibilityFailure('The default/null legacy SDK construction changed.');
    }
    $cases[] = ['case' => $case, 'http' => $status, 'upstream_reads' => $after - $before, 'reason' => $body['reason'] ?? null];
}
echo json_encode(['sdk' => $version, 'source' => $source, 'cases' => $cases], JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
