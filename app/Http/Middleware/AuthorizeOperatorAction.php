<?php

declare(strict_types=1);

namespace Waterline\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Waterline\Support\BackendConfiguration;
use Waterline\Support\OperatorAuthorization;

final class AuthorizeOperatorAction
{
    public const SIGNAL = self::class.':signal';
    public const UPDATE = self::class.':update';
    public const REPAIR = self::class.':repair';
    public const CANCEL = self::class.':cancel';
    public const TERMINATE = self::class.':terminate';
    public const ARCHIVE = self::class.':archive';
    public const SCHEDULES = self::class.':schedules';

    public function handle(Request $request, Closure $next, string $action)
    {
        if (BackendConfiguration::serviceMode()) {
            return $next($request);
        }

        $decision = OperatorAuthorization::decision($action, $request);
        if ($decision['allowed']) {
            return $next($request);
        }

        return response()->json([
            'message' => $decision['reason'] === 'waterline_read_only'
                ? 'Waterline is configured read-only; this action is unavailable.'
                : 'You are not authorized to perform this Waterline action.',
            'reason' => $decision['reason'],
            'capability' => $action,
        ], 403);
    }
}
