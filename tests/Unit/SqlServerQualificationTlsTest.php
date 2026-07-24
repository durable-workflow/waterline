<?php

declare(strict_types=1);

namespace Waterline\Tests\Unit;

use DurableWorkflow\Waterline\CI\SqlServerQualificationTls;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connectors\SqlServerConnector;
use PHPUnit\Framework\TestCase;
use Waterline\Tests\TestCase as WaterlineTestCase;

require_once dirname(__DIR__, 2).'/scripts/ci/SqlServerQualificationTls.php';

final class SqlServerQualificationTlsTest extends TestCase
{
    public function testPdoAndLaravelConnectionsReceiveTheSameTestTlsPolicy(): void
    {
        $this->assertSame(
            'Encrypt=yes;TrustServerCertificate=true',
            SqlServerQualificationTls::odbcDsnAttributes(),
        );
        $this->assertSame(
            [
                'encrypt' => 'yes',
                'trust_server_certificate' => 'true',
            ],
            SqlServerQualificationTls::laravelConfiguration(),
        );
    }

    public function testLaravelConnectorBuildsTheIntendedEncryptedTestDsn(): void
    {
        $connector = new class extends SqlServerConnector
        {
            public function qualificationDsn(array $configuration): string
            {
                return $this->getSqlSrvDsn($configuration);
            }
        };
        $configuration = array_merge(
            [
                'host' => '127.0.0.1',
                'port' => '1433',
                'database' => 'testing',
            ],
            SqlServerQualificationTls::laravelConfiguration(),
        );

        $this->assertSame(
            'sqlsrv:Server=127.0.0.1,1433;Database=testing;Encrypt=yes;TrustServerCertificate=true',
            $connector->qualificationDsn($configuration),
        );
    }

    public function testSqlServerTestEnvironmentReceivesTheQualificationPolicy(): void
    {
        $configuration = $this->configurationAfterEnvironmentSetup('sqlsrv');

        $this->assertSame(
            SqlServerQualificationTls::laravelConfiguration(),
            $configuration->get('database.connections.sqlsrv'),
        );
    }

    public function testNonSqlServerEnvironmentKeepsCertificateVerificationEnabled(): void
    {
        $configuration = $this->configurationAfterEnvironmentSetup('mysql', [
            'encrypt' => 'yes',
            'trust_server_certificate' => 'false',
        ]);

        $this->assertSame(
            [
                'encrypt' => 'yes',
                'trust_server_certificate' => 'false',
            ],
            $configuration->get('database.connections.sqlsrv'),
        );
    }

    /**
     * @param  array<string, string>  $sqlServerConfiguration
     */
    private function configurationAfterEnvironmentSetup(
        string $connection,
        array $sqlServerConfiguration = [],
    ): Repository {
        $hadPreviousConnection = array_key_exists('DB_CONNECTION', $_ENV);
        $previousConnection = $_ENV['DB_CONNECTION'] ?? null;
        $_ENV['DB_CONNECTION'] = $connection;

        try {
            $application = new Container;
            $configuration = new Repository([
                'database' => [
                    'connections' => [
                        'sqlsrv' => $sqlServerConfiguration,
                    ],
                ],
            ]);
            $application->instance('config', $configuration);

            $testCase = new class('placeholder') extends WaterlineTestCase
            {
                public function applyEnvironmentSetup(Container $application): void
                {
                    $this->getEnvironmentSetUp($application);
                }

                public function placeholder(): void {}
            };
            $testCase->applyEnvironmentSetup($application);

            return $configuration;
        } finally {
            if ($hadPreviousConnection) {
                $_ENV['DB_CONNECTION'] = $previousConnection;
            } else {
                unset($_ENV['DB_CONNECTION']);
            }
        }
    }
}
