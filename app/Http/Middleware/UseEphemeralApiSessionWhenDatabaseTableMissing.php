<?php

namespace Waterline\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class UseEphemeralApiSessionWhenDatabaseTableMissing
{
    public function __construct(private Application $app)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->databaseSessionTableIsMissing()) {
            $this->useEphemeralSession();
        }

        return $next($request);
    }

    private function databaseSessionTableIsMissing(): bool
    {
        if (strtolower((string) config('session.driver')) !== 'database') {
            return false;
        }

        $table = config('session.table', 'sessions');
        $table = is_scalar($table) && trim((string) $table) !== ''
            ? trim((string) $table)
            : 'sessions';

        try {
            return ! Schema::connection(config('session.connection'))->hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    private function useEphemeralSession(): void
    {
        config()->set('session.driver', 'array');

        if (! $this->app->bound('session')) {
            return;
        }

        $session = $this->app->make('session');

        if (method_exists($session, 'forgetDrivers')) {
            $session->forgetDrivers();
        }

        if (method_exists($session, 'setDefaultDriver')) {
            $session->setDefaultDriver('array');
        }
    }
}
