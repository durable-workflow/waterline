<?php

declare(strict_types=1);

namespace Waterline\Support;

use Carbon\CarbonInterface;
use Throwable;
use Waterline\Models\WorkerRegistration;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\RunCommandContract;
use Workflow\V2\Support\WorkflowDefinitionFingerprint;

final class SelectedRunCommandContract
{
    public const SOURCE_EXTERNAL_WORKER_REGISTRATION = 'external_worker_registration';
    public const SOURCE_MIXED = 'durable_history_with_external_worker_registration';

    /**
     * @return array{name: string, parameters: list<array<string, mixed>>, has_contract: bool, source: string}|null
     */
    public static function declaredQueryTarget(WorkflowRun $run, string $query): ?array
    {
        $contract = self::queryContractForRun($run);

        foreach ($contract['query_targets'] as $target) {
            if (($target['name'] ?? null) !== $query) {
                continue;
            }

            return [
                'name' => $query,
                'parameters' => self::listOfMaps($target['parameters'] ?? null),
                'has_contract' => ($target['has_contract'] ?? false) === true,
                'source' => self::stringValue($target['source'] ?? null) ?? $contract['source'],
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    public static function annotateRunDetail(array $detail, WorkflowRun $run): array
    {
        $contract = self::queryContractForRun($run);

        if ($contract['queries'] === []) {
            return $detail;
        }

        $detail['declared_queries'] = self::mergeStringLists(
            self::stringList($detail['declared_queries'] ?? null),
            $contract['queries'],
        );
        $detail['declared_query_contracts'] = self::mergeNamedMaps(
            self::listOfMaps($detail['declared_query_contracts'] ?? null),
            $contract['query_contracts'],
        );
        $detail['declared_query_targets'] = self::mergeNamedMaps(
            self::listOfMaps($detail['declared_query_targets'] ?? null),
            $contract['query_targets'],
        );
        $detail['declared_contract_source'] = self::mergedSource(
            self::stringValue($detail['declared_contract_source'] ?? null),
            $contract['source'],
        );

        if ($contract['source'] === self::SOURCE_EXTERNAL_WORKER_REGISTRATION) {
            $detail['declared_contract_backfill_needed'] = false;
            $detail['declared_contract_backfill_available'] = false;
        }

        $queryBlockedReason = self::stringValue($detail['query_blocked_reason'] ?? null);
        if (
            in_array($contract['source'], [self::SOURCE_EXTERNAL_WORKER_REGISTRATION, self::SOURCE_MIXED], true)
            && ($queryBlockedReason === 'workflow_definition_unavailable' || ! array_key_exists('can_query', $detail))
        ) {
            $detail['can_query'] = true;
            $detail['query_blocked_reason'] = null;
        }

        return $detail;
    }

    /**
     * @return array{
     *     queries: list<string>,
     *     query_contracts: list<array<string, mixed>>,
     *     query_targets: list<array<string, mixed>>,
     *     source: string
     * }
     */
    private static function queryContractForRun(WorkflowRun $run): array
    {
        $durable = self::durableQueryContract($run);
        $external = self::externalWorkerQueryContract($run);

        return $external === null ? $durable : self::mergeQueryContracts($durable, $external);
    }

    /**
     * @return array{
     *     queries: list<string>,
     *     query_contracts: list<array<string, mixed>>,
     *     query_targets: list<array<string, mixed>>,
     *     source: string
     * }
     */
    private static function durableQueryContract(WorkflowRun $run): array
    {
        try {
            $contract = RunCommandContract::forRun($run);
        } catch (Throwable) {
            return self::emptyQueryContract(RunCommandContract::SOURCE_UNAVAILABLE);
        }

        return self::normalizeQueryContract(
            [
                'queries' => $contract['queries'] ?? [],
                'query_contracts' => $contract['query_contracts'] ?? [],
            ],
            self::stringValue($contract['source'] ?? null) ?? RunCommandContract::SOURCE_UNAVAILABLE,
        );
    }

    /**
     * @return array{
     *     queries: list<string>,
     *     query_contracts: list<array<string, mixed>>,
     *     query_targets: list<array<string, mixed>>,
     *     source: string
     * }|null
     */
    private static function externalWorkerQueryContract(WorkflowRun $run): ?array
    {
        $workflowType = self::stringValue($run->workflow_type);
        if ($workflowType === null) {
            return null;
        }

        try {
            $workers = WorkerRegistration::query()
                ->where('task_queue', self::stringValue($run->queue) ?? 'default')
                ->where('status', 'active')
                ->orderByDesc('last_heartbeat_at')
                ->orderByDesc('id');

            $namespace = self::stringValue($run->namespace) ?? OperatorScope::namespace();
            if ($namespace !== null) {
                $workers->where('namespace', $namespace);
            }

            $registrations = $workers->get();
        } catch (Throwable) {
            return null;
        }

        $compatibility = self::stringValue($run->compatibility);
        $recordedFingerprint = self::recordedFingerprint($run);
        $merged = null;

        foreach ($registrations as $worker) {
            if (! $worker instanceof WorkerRegistration
                || ! self::workerIsFresh($worker)
                || ! self::workerSupportsRun($worker, $workflowType, $compatibility, $recordedFingerprint)
            ) {
                continue;
            }

            $contract = self::workerQueryContractForType($worker, $workflowType);
            if ($contract === null || $contract['queries'] === []) {
                continue;
            }

            $merged = $merged === null ? $contract : self::mergeQueryContracts($merged, $contract);
        }

        return $merged;
    }

    private static function workerIsFresh(WorkerRegistration $worker): bool
    {
        $heartbeat = $worker->last_heartbeat_at;

        if (! $heartbeat instanceof CarbonInterface) {
            return true;
        }

        $interval = is_numeric($worker->heartbeat_interval_seconds)
            ? max(30, (int) $worker->heartbeat_interval_seconds * 3)
            : 300;

        return $heartbeat->gte(now()->subSeconds($interval));
    }

    private static function workerSupportsRun(
        WorkerRegistration $worker,
        string $workflowType,
        ?string $compatibility,
        ?string $recordedFingerprint,
    ): bool {
        if (! in_array($workflowType, self::stringList($worker->supported_workflow_types), true)) {
            return false;
        }

        $buildId = self::stringValue($worker->build_id);
        if ($compatibility !== null && ($buildId === null || ! hash_equals($compatibility, $buildId))) {
            return false;
        }

        if ($recordedFingerprint === null) {
            return true;
        }

        $fingerprints = self::mapValue($worker->workflow_definition_fingerprints);
        $advertised = self::stringValue($fingerprints[$workflowType] ?? null);

        return $advertised !== null && hash_equals($recordedFingerprint, $advertised);
    }

    /**
     * @return array{
     *     queries: list<string>,
     *     query_contracts: list<array<string, mixed>>,
     *     query_targets: list<array<string, mixed>>,
     *     source: string
     * }|null
     */
    private static function workerQueryContractForType(WorkerRegistration $worker, string $workflowType): ?array
    {
        $contracts = self::mapValue($worker->workflow_command_contracts);
        $contract = $contracts[$workflowType] ?? null;

        return is_array($contract)
            ? self::normalizeQueryContract($contract, self::SOURCE_EXTERNAL_WORKER_REGISTRATION)
            : null;
    }

    private static function recordedFingerprint(WorkflowRun $run): ?string
    {
        try {
            return WorkflowDefinitionFingerprint::recordedForRun($run);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $contract
     * @return array{
     *     queries: list<string>,
     *     query_contracts: list<array<string, mixed>>,
     *     query_targets: list<array<string, mixed>>,
     *     source: string
     * }
     */
    private static function normalizeQueryContract(array $contract, string $source): array
    {
        $queryContracts = self::contractList($contract['query_contracts'] ?? null);
        $queries = self::mergeStringLists(
            self::stringList($contract['queries'] ?? null),
            self::contractNames($queryContracts),
        );

        return [
            'queries' => $queries,
            'query_contracts' => $queryContracts,
            'query_targets' => self::queryTargets($queries, $queryContracts, $source),
            'source' => $source,
        ];
    }

    /**
     * @param array{queries: list<string>, query_contracts: list<array<string, mixed>>, query_targets: list<array<string, mixed>>, source: string} $left
     * @param array{queries: list<string>, query_contracts: list<array<string, mixed>>, query_targets: list<array<string, mixed>>, source: string} $right
     * @return array{
     *     queries: list<string>,
     *     query_contracts: list<array<string, mixed>>,
     *     query_targets: list<array<string, mixed>>,
     *     source: string
     * }
     */
    private static function mergeQueryContracts(array $left, array $right): array
    {
        if ($left['queries'] === [] && $left['query_contracts'] === []) {
            return $right;
        }

        if ($right['queries'] === [] && $right['query_contracts'] === []) {
            return $left;
        }

        $queryContracts = self::mergeNamedMaps($left['query_contracts'], $right['query_contracts']);
        $queries = self::mergeStringLists(
            self::mergeStringLists($left['queries'], $right['queries']),
            self::contractNames($queryContracts),
        );
        $source = self::mergedSource($left['source'], $right['source']);

        return [
            'queries' => $queries,
            'query_contracts' => $queryContracts,
            'query_targets' => self::mergeNamedMaps($left['query_targets'], $right['query_targets']),
            'source' => $source,
        ];
    }

    /**
     * @param list<string> $queries
     * @param list<array<string, mixed>> $contracts
     * @return list<array<string, mixed>>
     */
    private static function queryTargets(array $queries, array $contracts, string $source): array
    {
        $contractByName = [];

        foreach ($contracts as $contract) {
            $name = self::stringValue($contract['name'] ?? null);
            if ($name === null) {
                continue;
            }

            $contractByName[$name] = [
                'name' => $name,
                'parameters' => self::listOfMaps($contract['parameters'] ?? null),
                'has_contract' => true,
                'source' => $source,
            ];
        }

        $targets = [];
        foreach ($queries as $query) {
            $targets[$query] = $contractByName[$query] ?? [
                'name' => $query,
                'parameters' => [],
                'has_contract' => false,
                'source' => $source,
            ];
        }

        foreach ($contractByName as $name => $target) {
            $targets[$name] = $targets[$name] ?? $target;
        }

        ksort($targets);

        return array_values($targets);
    }

    /**
     * @return array{queries: list<string>, query_contracts: list<array<string, mixed>>, query_targets: list<array<string, mixed>>, source: string}
     */
    private static function emptyQueryContract(string $source): array
    {
        return [
            'queries' => [],
            'query_contracts' => [],
            'query_targets' => [],
            'source' => $source,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function contractList(mixed $value): array
    {
        $contracts = [];

        foreach (self::listOfMaps($value) as $contract) {
            $name = self::stringValue($contract['name'] ?? null);
            if ($name === null) {
                continue;
            }

            $contracts[$name] = array_merge($contract, [
                'name' => $name,
                'parameters' => self::listOfMaps($contract['parameters'] ?? null),
            ]);
        }

        ksort($contracts);

        return array_values($contracts);
    }

    /**
     * @param list<array<string, mixed>> $contracts
     * @return list<string>
     */
    private static function contractNames(array $contracts): array
    {
        return self::stringList(array_column($contracts, 'name'));
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     * @return list<string>
     */
    private static function mergeStringLists(array $left, array $right): array
    {
        $values = [];

        foreach (array_merge($left, $right) as $value) {
            if (is_string($value) && $value !== '') {
                $values[$value] = true;
            }
        }

        $values = array_keys($values);
        sort($values);

        return $values;
    }

    /**
     * @param list<array<string, mixed>> $left
     * @param list<array<string, mixed>> $right
     * @return list<array<string, mixed>>
     */
    private static function mergeNamedMaps(array $left, array $right): array
    {
        $merged = [];

        foreach (array_merge($left, $right) as $item) {
            $name = self::stringValue($item['name'] ?? null);
            if ($name === null) {
                continue;
            }

            $merged[$name] = array_merge($merged[$name] ?? [], $item);
            $merged[$name]['name'] = $name;
        }

        ksort($merged);

        return array_values($merged);
    }

    private static function mergedSource(?string $left, ?string $right): string
    {
        $left = $left === RunCommandContract::SOURCE_UNAVAILABLE ? null : $left;
        $right = $right === RunCommandContract::SOURCE_UNAVAILABLE ? null : $right;

        if ($left === null) {
            return $right ?? RunCommandContract::SOURCE_UNAVAILABLE;
        }

        if ($right === null || $right === $left) {
            return $left;
        }

        return self::SOURCE_MIXED;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $strings[] = trim($item);
            }
        }

        $strings = array_values(array_unique($strings));
        sort($strings);

        return $strings;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function listOfMaps(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_array($item)));
    }

    /**
     * @return array<string, mixed>
     */
    private static function mapValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
