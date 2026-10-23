<?php

declare(strict_types=1);

namespace Waterline\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class OperatorAuthorization
{
    private const GROUPS = [
        'signal' => 'messages',
        'update' => 'messages',
        'repair' => 'repair',
        'cancel' => 'cancel',
        'terminate' => 'terminate',
        'archive' => 'archive',
        'schedules' => 'schedules',
    ];

    public static function readOnly(): bool
    {
        return BackendConfiguration::serviceMode()
            ? BackendConfiguration::accessMode() === 'read_only'
            : (bool) config('waterline.read_only', false);
    }

    /** @return array{allowed: bool, reason: ?string} */
    public static function decision(string $action, ?Request $request = null): array
    {
        if (self::readOnly()) {
            return ['allowed' => false, 'reason' => 'waterline_read_only'];
        }

        $group = self::GROUPS[$action] ?? null;
        if ($group === null) {
            return ['allowed' => false, 'reason' => 'waterline_action_forbidden'];
        }

        // Service authorization remains the deployment's access mode and the
        // upstream token. Host user gates apply only to embedded Laravel.
        $gate = null;
        if (! BackendConfiguration::serviceMode()) {
            $authorization = config('waterline.authorization', []);
            $gates = is_array($authorization) ? ($authorization['gates'] ?? []) : false;
            $gate = is_array($gates) ? ($gates[$group] ?? null) : false;
        }
        $request ??= request();
        $allowed = $gate === null
            || (is_string($gate) && trim($gate) !== ''
                && Gate::forUser($request->user())->allows($gate, [$request]));

        return ['allowed' => $allowed, 'reason' => $allowed ? null : 'waterline_action_forbidden'];
    }

    /** @return array<string, array{allowed: bool, reason: ?string}> */
    public static function permissions(?Request $request = null): array
    {
        $permissions = [];
        foreach (array_keys(self::GROUPS) as $action) {
            $permissions[$action] = self::decision($action, $request);
        }

        return $permissions;
    }

    /** @param array<string, mixed> $payload
     *  @return array<string, mixed>
     */
    public static function annotateRun(array $payload): array
    {
        $payload['backend'] = BackendConfiguration::payload();
        foreach ($payload['backend']['permissions'] as $action => $permission) {
            if ($action === 'schedules' || $permission['allowed']) {
                continue;
            }
            $payload['can_'.$action] = false;
            $payload[$action.'_blocked_reason'] = $permission['reason'];
            if (isset($payload['actionability']['actions'])) {
                $payload['actionability']['actions'][$action] = [
                    ...$permission,
                    'derived_from' => 'operator_authorization',
                ];
            }
        }
        if (! $payload['backend']['permissions']['cancel']['allowed']
            && ! $payload['backend']['permissions']['terminate']['allowed']) {
            $payload['can_issue_terminal_commands'] = false;
        }

        return $payload;
    }
}
