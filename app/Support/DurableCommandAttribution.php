<?php

declare(strict_types=1);

namespace Waterline\Support;

use BackedEnum;
use Carbon\CarbonInterface;
use Throwable;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

final class DurableCommandAttribution
{
    /**
     * Reconcile selected-run projections with the command context persisted in
     * durable history. Projection rows are useful for bounded reads, but they
     * must not be the authority for operator identity.
     *
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    public static function annotateRunDetail(array $detail, WorkflowRun $run): array
    {
        [$commandsById, $historyByEventId] = self::catalogsForRun($run);

        if ($commandsById === [] && $historyByEventId === []) {
            return $detail;
        }

        $commands = [];
        $seenCommandIds = [];

        foreach (self::rows($detail['commands'] ?? null) as $command) {
            $commandId = self::stringValue($command['id'] ?? null);
            if ($commandId !== null && isset($commandsById[$commandId])) {
                $command = self::applyAttribution($command, $commandsById[$commandId]);
                $seenCommandIds[$commandId] = true;
            }

            $commands[] = $command;
        }

        uasort($commandsById, static function (array $left, array $right): int {
            $leftSequence = self::intValue($left['row']['sequence'] ?? null) ?? PHP_INT_MAX;
            $rightSequence = self::intValue($right['row']['sequence'] ?? null) ?? PHP_INT_MAX;

            return $leftSequence <=> $rightSequence;
        });

        foreach ($commandsById as $commandId => $attribution) {
            if (isset($seenCommandIds[$commandId])) {
                continue;
            }

            $commands[] = self::applyAttribution($attribution['row'], $attribution);
        }

        $detail['commands_scope'] = $detail['commands_scope'] ?? 'selected_run';
        $detail['commands'] = array_values($commands);

        $timeline = [];
        $seenHistoryEventIds = [];

        foreach (self::rows($detail['timeline'] ?? null) as $event) {
            $historyEventId = self::stringValue($event['id'] ?? null);
            $commandId = self::stringValue($event['command_id'] ?? null)
                ?? self::stringValue(data_get($event, 'command.id'));
            $attribution = $historyEventId === null ? null : ($historyByEventId[$historyEventId] ?? null);
            $attribution ??= $commandId === null ? null : ($commandsById[$commandId] ?? null);

            if ($attribution !== null) {
                $event['command'] = self::applyAttribution(
                    is_array($event['command'] ?? null) ? $event['command'] : [],
                    $attribution,
                );
            }

            if ($historyEventId !== null) {
                $seenHistoryEventIds[$historyEventId] = true;
            }

            $timeline[] = $event;
        }

        foreach ($historyByEventId as $historyEventId => $attribution) {
            if (isset($seenHistoryEventIds[$historyEventId])) {
                continue;
            }

            $timeline[] = self::timelineRow($attribution);
        }

        usort($timeline, static function (array $left, array $right): int {
            $leftSequence = self::intValue($left['sequence'] ?? null) ?? PHP_INT_MAX;
            $rightSequence = self::intValue($right['sequence'] ?? null) ?? PHP_INT_MAX;

            if ($leftSequence !== $rightSequence) {
                return $leftSequence <=> $rightSequence;
            }

            return (string) ($left['id'] ?? '') <=> (string) ($right['id'] ?? '');
        });

        $detail['timeline'] = array_values($timeline);
        $detail['timeline_total_count'] = max(
            self::intValue($detail['timeline_total_count'] ?? null) ?? 0,
            count($timeline),
        );

        return $detail;
    }

    /**
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private static function catalogsForRun(WorkflowRun $run): array
    {
        try {
            $run->loadMissing(['commands', 'historyEvents']);
        } catch (Throwable) {
            return [[], []];
        }

        $commandsById = [];

        foreach ($run->commands ?? [] as $command) {
            if (! $command instanceof WorkflowCommand || $command->workflow_run_id !== $run->id) {
                continue;
            }

            $commandsById[$command->id] = self::fromCommand($command);
        }

        $historyByEventId = [];

        foreach ($run->historyEvents ?? [] as $event) {
            if (! $event instanceof WorkflowHistoryEvent || $event->workflow_run_id !== $run->id) {
                continue;
            }

            $payload = is_array($event->payload) ? $event->payload : [];
            $snapshot = is_array($payload['command'] ?? null) ? $payload['command'] : [];
            $commandId = self::stringValue($event->workflow_command_id)
                ?? self::stringValue($snapshot['id'] ?? null);

            if ($commandId === null || ($snapshot === [] && ! isset($commandsById[$commandId]))) {
                continue;
            }

            $historyAttribution = self::fromHistoryEvent($event, $snapshot, $commandId);
            $attribution = isset($commandsById[$commandId])
                ? self::mergeAttribution($commandsById[$commandId], $historyAttribution)
                : $historyAttribution;

            $historyByEventId[$event->id] = $attribution;
            $commandsById[$commandId] = isset($commandsById[$commandId])
                ? self::mergeAttribution($commandsById[$commandId], $historyAttribution)
                : $historyAttribution;
        }

        return [$commandsById, $historyByEventId];
    }

    /**
     * @return array<string, mixed>
     */
    private static function fromCommand(WorkflowCommand $command): array
    {
        $context = is_array($command->context) ? $command->context : [];

        return self::attribution(
            $context,
            [
                'caller_label' => self::contextValue($context, 'caller', 'label'),
                'auth_status' => self::contextValue($context, 'auth', 'status'),
                'auth_method' => self::contextValue($context, 'auth', 'method'),
                'request_method' => self::contextValue($context, 'request', 'method'),
                'request_path' => self::contextValue($context, 'request', 'path'),
                'request_route_name' => self::contextValue($context, 'request', 'route_name'),
                'request_fingerprint' => self::contextValue($context, 'request', 'fingerprint'),
                'request_id' => self::contextValue($context, 'request', 'request_id'),
                'correlation_id' => self::contextValue($context, 'request', 'correlation_id'),
            ],
            self::contextEstablishesPrincipal($context),
            [
                'id' => $command->id,
                'sequence' => self::intValue($command->command_sequence),
                'type' => self::stringValue($command->command_type),
                'target_scope' => self::stringValue($command->target_scope),
                'source' => self::stringValue($command->source),
                'status' => self::stringValue($command->status),
                'outcome' => self::stringValue($command->outcome),
            ],
        );
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private static function fromHistoryEvent(
        WorkflowHistoryEvent $event,
        array $snapshot,
        string $commandId,
    ): array {
        $context = is_array($snapshot['context'] ?? null) ? $snapshot['context'] : [];
        $direct = [];

        foreach ([
            'principal_type',
            'principal_id',
            'principal_label',
            'caller_label',
            'auth_status',
            'auth_method',
            'request_method',
            'request_path',
            'request_route_name',
            'request_fingerprint',
            'request_id',
            'correlation_id',
        ] as $field) {
            if (array_key_exists($field, $snapshot)) {
                $direct[$field] = self::stringValue($snapshot[$field]);
            }
        }

        $principalIsAuthoritative = self::contextEstablishesPrincipal($context)
            || self::hasAnyKey($snapshot, [
                'principal_type',
                'principal_id',
                'principal_label',
                'caller_label',
                'auth_status',
                'auth_method',
            ]);

        $attribution = self::attribution(
            $context,
            $direct,
            $principalIsAuthoritative,
            [
                'id' => $commandId,
                'sequence' => self::intValue($snapshot['sequence'] ?? null),
                'type' => self::stringValue($snapshot['type'] ?? null),
                'target_scope' => self::stringValue($snapshot['target_scope'] ?? null),
                'source' => self::stringValue($snapshot['source'] ?? null),
                'status' => self::stringValue($snapshot['status'] ?? null),
                'outcome' => self::stringValue($snapshot['outcome'] ?? null),
            ],
        );

        $attribution['history_event'] = [
            'id' => $event->id,
            'sequence' => self::intValue($event->sequence),
            'type' => self::stringValue($event->event_type) ?? 'Unknown',
            'recorded_at' => self::timestamp($event->recorded_at),
            'command_id' => $commandId,
        ];

        return $attribution;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $direct
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function attribution(
        array $context,
        array $direct,
        bool $principalIsAuthoritative,
        array $row,
    ): array {
        $principal = self::principal($context['principal'] ?? null);

        if ($principal === null) {
            $principal = self::principal([
                'type' => $direct['principal_type'] ?? null,
                'id' => $direct['principal_id'] ?? null,
                'label' => $direct['principal_label'] ?? null,
            ]);
        }

        $fields = array_filter([
            'principal_type' => $principal['type'] ?? null,
            'principal_id' => $principal['id'] ?? null,
            'principal_label' => $principal['label'] ?? null,
            'caller_label' => self::stringValue($direct['caller_label'] ?? null)
                ?? self::contextValue($context, 'caller', 'label'),
            'auth_status' => self::stringValue($direct['auth_status'] ?? null)
                ?? self::contextValue($context, 'auth', 'status'),
            'auth_method' => self::stringValue($direct['auth_method'] ?? null)
                ?? self::contextValue($context, 'auth', 'method'),
            'request_method' => self::stringValue($direct['request_method'] ?? null)
                ?? self::contextValue($context, 'request', 'method'),
            'request_path' => self::stringValue($direct['request_path'] ?? null)
                ?? self::contextValue($context, 'request', 'path'),
            'request_route_name' => self::stringValue($direct['request_route_name'] ?? null)
                ?? self::contextValue($context, 'request', 'route_name'),
            'request_fingerprint' => self::stringValue($direct['request_fingerprint'] ?? null)
                ?? self::contextValue($context, 'request', 'fingerprint'),
            'request_id' => self::stringValue($direct['request_id'] ?? null)
                ?? self::contextValue($context, 'request', 'request_id'),
            'correlation_id' => self::stringValue($direct['correlation_id'] ?? null)
                ?? self::contextValue($context, 'request', 'correlation_id'),
        ], static fn (mixed $value): bool => $value !== null);

        return [
            'principal_authoritative' => $principalIsAuthoritative,
            'principal' => $principal,
            'fields' => $fields,
            'row' => array_filter($row, static fn (mixed $value): bool => $value !== null),
        ];
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $history
     * @return array<string, mixed>
     */
    private static function mergeAttribution(array $base, array $history): array
    {
        $principalAuthoritative = ($history['principal_authoritative'] ?? false) === true;
        $fields = array_replace($base['fields'] ?? [], $history['fields'] ?? []);

        if ($principalAuthoritative) {
            unset($fields['principal_type'], $fields['principal_id'], $fields['principal_label']);

            $principal = $history['principal'] ?? null;
            if (is_array($principal)) {
                $fields = array_replace($fields, array_filter([
                    'principal_type' => $principal['type'] ?? null,
                    'principal_id' => $principal['id'] ?? null,
                    'principal_label' => $principal['label'] ?? null,
                ], static fn (mixed $value): bool => $value !== null));
            }
        }

        return [
            'principal_authoritative' => $principalAuthoritative
                || ($base['principal_authoritative'] ?? false) === true,
            'principal' => $principalAuthoritative
                ? ($history['principal'] ?? null)
                : ($base['principal'] ?? null),
            'fields' => $fields,
            'row' => array_replace($base['row'] ?? [], $history['row'] ?? []),
            'history_event' => $history['history_event'] ?? ($base['history_event'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $attribution
     * @return array<string, mixed>
     */
    private static function applyAttribution(array $row, array $attribution): array
    {
        foreach ($attribution['row'] ?? [] as $field => $value) {
            if (! self::hasValue($row[$field] ?? null)) {
                $row[$field] = $value;
            }
        }

        if (($attribution['principal_authoritative'] ?? false) === true) {
            unset($row['principal_type'], $row['principal_id'], $row['principal_label']);

            $context = is_array($row['context'] ?? null) ? $row['context'] : [];
            unset($context['principal']);

            if (is_array($attribution['principal'] ?? null)) {
                $context['principal'] = $attribution['principal'];
            }

            if ($context === []) {
                unset($row['context']);
            } else {
                $row['context'] = $context;
            }
        }

        foreach ($attribution['fields'] ?? [] as $field => $value) {
            $row[$field] = $value;
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $attribution
     * @return array<string, mixed>
     */
    private static function timelineRow(array $attribution): array
    {
        $history = is_array($attribution['history_event'] ?? null) ? $attribution['history_event'] : [];
        $command = self::applyAttribution($attribution['row'] ?? [], $attribution);

        return [
            'id' => $history['id'] ?? null,
            'sequence' => $history['sequence'] ?? null,
            'type' => $history['type'] ?? 'Unknown',
            'kind' => 'command',
            'entry_kind' => 'point',
            'source_kind' => 'workflow_command',
            'source_id' => $history['command_id'] ?? ($command['id'] ?? null),
            'recorded_at' => $history['recorded_at'] ?? null,
            'command_id' => $history['command_id'] ?? ($command['id'] ?? null),
            'command_sequence' => $command['sequence'] ?? null,
            'command_type' => $command['type'] ?? null,
            'command_status' => $command['status'] ?? null,
            'command_outcome' => $command['outcome'] ?? null,
            'command' => $command,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * @return array{type: string, id: string, label?: string}|null
     */
    private static function principal(mixed $principal): ?array
    {
        if (! is_array($principal)) {
            return null;
        }

        $type = self::stringValue($principal['type'] ?? null);
        $id = self::stringValue($principal['id'] ?? null);
        $label = self::stringValue($principal['label'] ?? null);

        if ($type === null || $id === null) {
            return null;
        }

        return array_filter([
            'type' => $type,
            'id' => $id,
            'label' => $label,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function contextEstablishesPrincipal(array $context): bool
    {
        return self::hasAnyKey($context, ['principal', 'auth', 'caller']);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function contextValue(array $context, string $section, string $field): ?string
    {
        $values = $context[$section] ?? null;

        return is_array($values) ? self::stringValue($values[$field] ?? null) : null;
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $keys
     */
    private static function hasAnyKey(array $values, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $values)) {
                return true;
            }
        }

        return false;
    }

    private static function hasValue(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }

    private static function intValue(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function stringValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private static function timestamp(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toJSON();
        }

        return self::stringValue($value);
    }
}
