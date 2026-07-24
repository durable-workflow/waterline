#!/usr/bin/env php
<?php

declare(strict_types=1);

use DurableWorkflow\Waterline\CI\SqlServerQualificationTls;

require_once __DIR__.'/SqlServerQualificationTls.php';

if ($argc !== 3 || trim($argv[1]) === '' || trim($argv[2]) === '') {
    fwrite(STDERR, "Usage: preflight-databases.php <database> <database-host>\n");
    exit(2);
}

$databaseName = strtolower(trim($argv[1]));
$host = trim($argv[2]);
$sqlServerDsn = static fn (string $database): string => sprintf(
    'sqlsrv:Server=%s,1433;Database=%s;LoginTimeout=3;%s',
    $host,
    $database,
    SqlServerQualificationTls::odbcDsnAttributes(),
);
$databases = [
    'mssql' => [
        'name' => 'MSSQL',
        'extension' => 'pdo_sqlsrv',
        'admin_dsn' => $sqlServerDsn('master'),
        'dsn' => $sqlServerDsn('testing'),
        'username' => 'sa',
        'password' => 'P@ssword',
        'options' => [],
    ],
    'mysql' => [
        'name' => 'MySQL',
        'extension' => 'pdo_mysql',
        'dsn' => sprintf('mysql:host=%s;port=3306;dbname=testing;charset=utf8mb4', $host),
        'username' => 'testing',
        'password' => 'password',
        'options' => [PDO::ATTR_TIMEOUT => 3],
    ],
    'pgsql' => [
        'name' => 'PostgreSQL',
        'extension' => 'pdo_pgsql',
        'dsn' => sprintf('pgsql:host=%s;port=5432;dbname=testing;connect_timeout=3', $host),
        'username' => 'testing',
        'password' => 'password',
        'options' => [],
    ],
    'sqlite' => [
        'name' => 'SQLite',
        'extension' => 'pdo_sqlite',
        'dsn' => 'sqlite::memory:',
        'username' => null,
        'password' => null,
        'options' => [],
    ],
];

if (! isset($databases[$databaseName])) {
    fwrite(STDERR, sprintf("Unknown database [%s]. Expected one of: %s.\n", $databaseName, implode(', ', array_keys($databases))));
    exit(2);
}

$database = $databases[$databaseName];

if (! extension_loaded($database['extension'])) {
    fwrite(STDERR, sprintf("Missing PDO driver extension: %s.\n", $database['extension']));
    exit(1);
}

$deadline = microtime(true) + 30;
$lastError = 'no connection attempt was made';

do {
    try {
        if ($databaseName === 'mssql') {
            $adminConnection = new PDO(
                $database['admin_dsn'],
                $database['username'],
                $database['password'],
                $database['options'] + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $adminConnection->query('SELECT 1')->fetchColumn();
            $adminConnection->exec(
                "IF DB_ID(N'testing') IS NULL CREATE DATABASE [testing];",
            );
        }

        $connection = new PDO(
            $database['dsn'],
            $database['username'],
            $database['password'],
            $database['options'] + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $connection->query('SELECT 1')->fetchColumn();
        fwrite(STDOUT, sprintf("%s driver and connection are ready.\n", $database['name']));
        exit(0);
    } catch (Throwable $exception) {
        $lastError = $exception->getMessage();
    }

    usleep(1_000_000);
} while (microtime(true) < $deadline);

fwrite(
    STDERR,
    sprintf(
        "%s connection did not become ready within 30 seconds using %s: %s\n",
        $database['name'],
        $database['extension'],
        $lastError,
    ),
);
exit(1);
