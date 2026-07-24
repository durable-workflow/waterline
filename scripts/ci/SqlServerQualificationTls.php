<?php

declare(strict_types=1);

namespace DurableWorkflow\Waterline\CI;

/**
 * TLS policy for the ephemeral SQL Server used by database qualification.
 */
final class SqlServerQualificationTls
{
    /**
     * @var list<array{odbc: string, laravel: string, value: string}>
     */
    private const OPTIONS = [
        [
            'odbc' => 'Encrypt',
            'laravel' => 'encrypt',
            'value' => 'yes',
        ],
        [
            'odbc' => 'TrustServerCertificate',
            'laravel' => 'trust_server_certificate',
            'value' => 'true',
        ],
    ];

    public static function odbcDsnAttributes(): string
    {
        return implode(';', array_map(
            static fn (array $option): string => sprintf(
                '%s=%s',
                $option['odbc'],
                $option['value'],
            ),
            self::OPTIONS,
        ));
    }

    /**
     * @return array<string, string>
     */
    public static function laravelConfiguration(): array
    {
        $configuration = [];

        foreach (self::OPTIONS as $option) {
            $configuration[$option['laravel']] = $option['value'];
        }

        return $configuration;
    }
}
