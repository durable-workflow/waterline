#!/usr/bin/env php
<?php

declare(strict_types=1);

if ($argc !== 2 || trim($argv[1]) === '') {
    fwrite(STDERR, "Usage: preflight-databases.php <database-host>\n");
    exit(2);
}

$host = trim($argv[1]);
$databases = [
    'MSSQL' => [
        'extension' => 'pdo_sqlsrv',
        'dsn' => sprintf(
            'sqlsrv:Server=%s,1433;Database=testing;LoginTimeout=3;TrustServerCertificate=1',
            $host,
        ),
        'username' => 'sa',
        'password' => 'P@ssword',
        'options' => [],
    ],
    'MySQL' => [
        'extension' => 'pdo_mysql',
        'dsn' => sprintf('mysql:host=%s;port=3306;dbname=testing;charset=utf8mb4', $host),
        'username' => 'testing',
        'password' => 'password',
        'options' => [PDO::ATTR_TIMEOUT => 3],
    ],
    'PostgreSQL' => [
        'extension' => 'pdo_pgsql',
        'dsn' => sprintf('pgsql:host=%s;port=5432;dbname=testing;connect_timeout=3', $host),
        'username' => 'testing',
        'password' => 'password',
        'options' => [],
    ],
    'SQLite' => [
        'extension' => 'pdo_sqlite',
        'dsn' => 'sqlite::memory:',
        'username' => null,
        'password' => null,
        'options' => [],
    ],
];

$missingExtensions = array_values(array_filter(
    array_column($databases, 'extension'),
    static fn (string $extension): bool => ! extension_loaded($extension),
));

if ($missingExtensions !== []) {
    fwrite(
        STDERR,
        sprintf(
            "Missing PDO driver extension(s): %s. Every database suite requires its native client driver.\n",
            implode(', ', $missingExtensions),
        ),
    );
    exit(1);
}

foreach ($databases as $name => $database) {
    $deadline = microtime(true) + 30;
    $lastError = 'no connection attempt was made';

    do {
        try {
            $connection = new PDO(
                $database['dsn'],
                $database['username'],
                $database['password'],
                $database['options'] + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $connection->query('SELECT 1')->fetchColumn();
            fwrite(STDOUT, sprintf("%s driver and connection are ready.\n", $name));
            continue 2;
        } catch (Throwable $exception) {
            $lastError = $exception->getMessage();
        }

        usleep(1_000_000);
    } while (microtime(true) < $deadline);

    fwrite(
        STDERR,
        sprintf(
            "%s connection did not become ready within 30 seconds using %s: %s\n",
            $name,
            $database['extension'],
            $lastError,
        ),
    );
    exit(1);
}
