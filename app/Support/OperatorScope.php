<?php

declare(strict_types=1);

namespace Waterline\Support;

final class OperatorScope
{
    public static function namespace(): ?string
    {
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
}
