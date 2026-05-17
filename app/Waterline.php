<?php

namespace Waterline;

use Closure;
use Illuminate\Support\Facades\File;
use RuntimeException;

class Waterline
{
    public static $authUsing;

    public static $principalUsing;

    public static function check($request)
    {
        if (static::allowsUnauthenticatedAccess()) {
            return true;
        }

        return (static::$authUsing ?: function () {
            return app()->environment('local');
        })($request);
    }

    public static function allowsUnauthenticatedAccess(): bool
    {
        $configured = config('waterline.allow_unauthenticated', false);

        if (is_bool($configured)) {
            return $configured;
        }

        if (is_scalar($configured)) {
            return filter_var($configured, FILTER_VALIDATE_BOOLEAN);
        }

        return false;
    }

    public static function auth(Closure $callback)
    {
        static::$authUsing = $callback;

        return new static;
    }

    public static function identifyPrincipalUsing(Closure $callback)
    {
        static::$principalUsing = $callback;

        return new static;
    }

    /**
     * @return array{type: string, id: string, label?: string}|null
     */
    public static function principalFor($request): ?array
    {
        $principal = static::$principalUsing
            ? (static::$principalUsing)($request)
            : static::defaultPrincipalFor($request);

        return static::normalizePrincipal($principal);
    }

    /**
     * @return array{type: string, id: string, label?: string}|null
     */
    private static function defaultPrincipalFor($request): ?array
    {
        $user = is_object($request) && method_exists($request, 'user')
            ? $request->user()
            : null;

        if (! is_object($user) || ! method_exists($user, 'getAuthIdentifier')) {
            return null;
        }

        $identifier = $user->getAuthIdentifier();

        if (! is_scalar($identifier) || trim((string) $identifier) === '') {
            return null;
        }

        $label = null;
        foreach (['name', 'email'] as $property) {
            if (isset($user->{$property}) && is_scalar($user->{$property}) && trim((string) $user->{$property}) !== '') {
                $label = trim((string) $user->{$property});
                break;
            }
        }

        return array_filter([
            'type' => get_class($user),
            'id' => get_class($user).':'.trim((string) $identifier),
            'label' => $label,
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');
    }

    /**
     * @return array{type: string, id: string, label?: string}|null
     */
    private static function normalizePrincipal(mixed $principal): ?array
    {
        if (! is_array($principal)) {
            return null;
        }

        $type = isset($principal['type']) && is_scalar($principal['type'])
            ? trim((string) $principal['type'])
            : '';
        $id = isset($principal['id']) && is_scalar($principal['id'])
            ? trim((string) $principal['id'])
            : '';
        $label = isset($principal['label']) && is_scalar($principal['label'])
            ? trim((string) $principal['label'])
            : '';

        if ($type === '' || $id === '') {
            return null;
        }

        return array_filter([
            'type' => $type,
            'id' => $id,
            'label' => $label === '' ? null : $label,
        ], static fn (mixed $value): bool => $value !== null);
    }

    public static function assetsAreCurrent()
    {
        $publishedPath = public_path('vendor/waterline/mix-manifest.json');

        if (! File::exists($publishedPath)) {
            throw new RuntimeException('Waterline assets are not published. Please run: php artisan waterline:publish');
        }

        return File::get($publishedPath) === File::get(__DIR__.'/../public/mix-manifest.json');
    }
}
