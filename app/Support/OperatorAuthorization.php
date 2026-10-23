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
        $gate = BackendConfiguration::serviceMode()
            ? null
            : config('waterline.authorization.gates.'.$group);
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
}
