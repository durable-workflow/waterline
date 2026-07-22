<?php

declare(strict_types=1);

namespace Waterline\Support;

use Illuminate\Support\Str;

final class OperatorScope
{
    public static function namespace(): ?string
    {
        if (BackendConfiguration::serviceMode()) {
            return BackendConfiguration::namespace();
        }

        $namespace = config('waterline.namespace');

        return is_string($namespace) && trim($namespace) !== '' ? trim($namespace) : null;
    }

    /**
     * @return array{mode: string, namespace: string|null, label: string, authority: string, description: string}
     */
    public static function payload(): array
    {
        $namespace = self::namespace();

        if ($namespace === null) {
            return [
                'mode' => 'cluster',
                'namespace' => null,
                'label' => 'Cluster-wide',
                'authority' => 'cluster',
                'description' => 'Cluster-wide Waterline scope can observe all namespaces and should only be exposed behind an operator authorization boundary.',
            ];
        }

        return [
            'mode' => 'namespace',
            'namespace' => $namespace,
            'label' => $namespace,
            'authority' => 'tenant',
            'description' => 'Waterline is restricted to one workflow namespace.',
        ];
    }

    public static function persistenceScope(mixed $configuredScope): string
    {
        $scope = is_string($configuredScope) ? trim($configuredScope) : '';
        $namespace = self::namespace();

        if ($scope !== '' && ($scope !== 'default' || $namespace === null)) {
            return $scope;
        }

        if ($namespace === null) {
            return 'default';
        }

        return Str::limit('namespace:'.$namespace, 120, '');
    }
}
