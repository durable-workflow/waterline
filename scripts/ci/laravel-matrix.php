<?php

declare(strict_types=1);

use Composer\InstalledVersions;

const MATRIX_PATH = __DIR__.'/../../.github/laravel-matrix.json';

/**
 * @return array{include: list<array<string, string>>}
 */
function loadMatrix(): array
{
    $contents = file_get_contents(MATRIX_PATH);

    if ($contents === false) {
        throw new RuntimeException('Unable to read '.MATRIX_PATH);
    }

    try {
        $matrix = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Invalid matrix JSON: '.$exception->getMessage(), previous: $exception);
    }

    if (! is_array($matrix)) {
        throw new RuntimeException('The matrix must be a JSON object.');
    }

    validateContract($matrix);

    /** @var array{include: list<array<string, string>>} $matrix */
    return $matrix;
}

/**
 * @param array<mixed> $matrix
 */
function validateContract(array $matrix): void
{
    $requiredKeys = [
        'label', 'boundary', 'laravel', 'testbench', 'workbench', 'php', 'phpunit', 'scaffold', 'resolution',
    ];
    $expected = [
        9 => [
            'php' => '8.1', 'testbench' => 7, 'workbench' => 7, 'phpunit' => 9,
            'boundary' => 'lowest', 'resolution' => 'legacy',
        ],
        10 => [
            'php' => '8.1', 'testbench' => 8, 'workbench' => 8, 'phpunit' => 10,
            'boundary' => 'intermediate', 'resolution' => 'legacy',
        ],
        11 => [
            'php' => '8.2', 'testbench' => 9, 'workbench' => 9, 'phpunit' => 10,
            'boundary' => 'intermediate', 'resolution' => 'legacy',
        ],
        12 => [
            'php' => '8.2', 'testbench' => 10, 'workbench' => 10, 'phpunit' => 11,
            'boundary' => 'intermediate', 'resolution' => 'strict',
        ],
        13 => [
            'php' => '8.3', 'testbench' => 11, 'workbench' => 11, 'phpunit' => 12,
            'boundary' => 'highest', 'resolution' => 'strict',
        ],
    ];

    if (array_keys($matrix) !== ['include'] || ! is_array($matrix['include'])) {
        throw new RuntimeException('The matrix must contain only an include array.');
    }

    if (count($matrix['include']) !== count($expected)) {
        throw new RuntimeException('The matrix must contain exactly one cell for each supported Laravel major (9-13).');
    }

    $seenMajors = [];
    $seenRows = [];

    foreach ($matrix['include'] as $index => $row) {
        if (! is_array($row) || array_keys($row) !== $requiredKeys) {
            throw new RuntimeException(sprintf(
                'Matrix cell %d must define exactly: %s.',
                $index,
                implode(', ', $requiredKeys),
            ));
        }

        foreach ($requiredKeys as $key) {
            if (! is_string($row[$key]) || $row[$key] === '') {
                throw new RuntimeException(sprintf('Matrix cell %d has an invalid %s value.', $index, $key));
            }
        }

        foreach (['laravel', 'testbench', 'workbench', 'phpunit'] as $package) {
            if (preg_match('/^(\d+)\.\d+\.\d+$/D', $row[$package]) !== 1) {
                throw new RuntimeException(sprintf(
                    'Matrix cell %d must pin %s to an exact semantic version; received %s.',
                    $index,
                    $package,
                    $row[$package],
                ));
            }
        }

        if (preg_match('/^\d+\.\d+$/D', $row['php']) !== 1) {
            throw new RuntimeException(sprintf('Matrix cell %d must pin a PHP major.minor line.', $index));
        }

        $laravelMajor = major($row['laravel']);
        if (! isset($expected[$laravelMajor])) {
            throw new RuntimeException(sprintf('Laravel %d is outside the supported matrix range (9-13).', $laravelMajor));
        }

        if (isset($seenMajors[$laravelMajor])) {
            throw new RuntimeException(sprintf('Laravel %d has more than one matrix cell.', $laravelMajor));
        }

        $seenMajors[$laravelMajor] = true;
        $expectedRow = $expected[$laravelMajor];

        if ($row['label'] !== 'Laravel '.$laravelMajor
            || $row['php'] !== $expectedRow['php']
            || major($row['testbench']) !== $expectedRow['testbench']
            || major($row['workbench']) !== $expectedRow['workbench']
            || major($row['phpunit']) !== $expectedRow['phpunit']
            || $row['boundary'] !== $expectedRow['boundary']
            || $row['scaffold'] !== 'testbench'
            || $row['resolution'] !== $expectedRow['resolution']) {
            throw new RuntimeException(sprintf(
                'Laravel %d must use PHP %s, Testbench %d, Workbench %d, PHPUnit %d, the %s boundary, the testbench scaffold, and %s resolution.',
                $laravelMajor,
                $expectedRow['php'],
                $expectedRow['testbench'],
                $expectedRow['workbench'],
                $expectedRow['phpunit'],
                $expectedRow['boundary'],
                $expectedRow['resolution'],
            ));
        }

        $fingerprint = implode('|', array_map(static fn (string $key): string => $row[$key], $requiredKeys));
        if (isset($seenRows[$fingerprint])) {
            throw new RuntimeException(sprintf('Matrix cell %d duplicates another dependency tuple.', $index));
        }

        $seenRows[$fingerprint] = true;
    }

    if (array_keys($seenMajors) !== array_keys($expected)) {
        throw new RuntimeException('Matrix cells must be ordered from Laravel 9 through Laravel 13.');
    }

    $testbenchConfig = file_get_contents(__DIR__.'/../../testbench.yaml');
    if ($testbenchConfig === false || preg_match('/^laravel:\s*[\'\"]?@testbench[\'\"]?\s*$/m', $testbenchConfig) !== 1) {
        throw new RuntimeException('testbench.yaml must select the dependency-provided @testbench scaffold.');
    }
}

function major(string $version): int
{
    return (int) strstr($version, '.', true);
}

/**
 * @param list<string> $arguments
 * @return array<string, string>
 */
function parseOptions(array $arguments): array
{
    $options = [];

    foreach ($arguments as $argument) {
        if (preg_match('/^--([a-z]+(?:-[a-z]+)*)=(.+)$/D', $argument, $matches) !== 1) {
            throw new RuntimeException('Expected matrix options in --key=value form; received '.$argument);
        }

        $options[$matches[1]] = $matches[2];
    }

    return $options;
}

/**
 * @param array{include: list<array<string, string>>} $matrix
 * @param array<string, string> $requested
 * @return array<string, string>
 */
function requestedRow(array $matrix, array $requested): array
{
    $keys = ['laravel', 'testbench', 'workbench', 'php', 'phpunit', 'scaffold', 'resolution'];

    if (array_keys($requested) !== $keys) {
        throw new RuntimeException('Cell validation requires exactly: '.implode(', ', $keys).'.');
    }

    foreach ($matrix['include'] as $row) {
        $matches = true;

        foreach ($keys as $key) {
            if ($row[$key] !== $requested[$key]) {
                $matches = false;
                break;
            }
        }

        if ($matches) {
            return $row;
        }
    }

    $laravelMajor = major($requested['laravel']);
    foreach ($matrix['include'] as $row) {
        if (major($row['laravel']) === $laravelMajor) {
            throw new RuntimeException(sprintf(
                'Unsupported Laravel %d dependency tuple. Expected Laravel %s, Testbench %s, Workbench %s, PHP %s, PHPUnit %s, scaffold %s, resolution %s.',
                $laravelMajor,
                $row['laravel'],
                $row['testbench'],
                $row['workbench'],
                $row['php'],
                $row['phpunit'],
                $row['scaffold'],
                $row['resolution'],
            ));
        }
    }

    throw new RuntimeException(sprintf(
        'Unsupported Laravel %d dependency tuple; supported Laravel majors are 9-13.',
        $laravelMajor,
    ));
}

/**
 * @param array<string, string> $row
 */
function assertInstalled(array $row): void
{
    $autoload = __DIR__.'/../../vendor/autoload.php';
    if (! is_file($autoload)) {
        throw new RuntimeException('Dependencies are not installed; vendor/autoload.php is missing.');
    }

    require $autoload;

    $actualPhp = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
    if ($actualPhp !== $row['php']) {
        throw new RuntimeException(sprintf('Expected PHP %s but the matrix runner is using PHP %s.', $row['php'], $actualPhp));
    }

    $packages = [
        'laravel' => 'laravel/framework',
        'testbench' => 'orchestra/testbench',
        'workbench' => 'orchestra/workbench',
        'phpunit' => 'phpunit/phpunit',
    ];

    foreach ($packages as $key => $package) {
        $installed = InstalledVersions::getPrettyVersion($package);
        $normalized = ltrim((string) $installed, 'v');

        if ($normalized !== $row[$key]) {
            throw new RuntimeException(sprintf(
                'Expected %s %s but Composer installed %s.',
                $package,
                $row[$key],
                $installed ?? 'nothing',
            ));
        }
    }

    $expectedScaffold = realpath(__DIR__.'/../../vendor/orchestra/testbench-core/laravel');
    $actualScaffold = realpath(Orchestra\Testbench\default_skeleton_path());

    if ($expectedScaffold === false || $actualScaffold !== $expectedScaffold) {
        throw new RuntimeException('The matrix cell is not using its installed Testbench scaffold.');
    }
}

/**
 * @param array{include: list<array<string, string>>} $matrix
 */
function selfTest(array $matrix): void
{
    $invalid = $matrix;
    $invalid['include'][0]['testbench'] = $matrix['include'][1]['testbench'];

    try {
        validateContract($invalid);
        throw new RuntimeException('Self-test accepted a mismatched Testbench major.');
    } catch (RuntimeException $exception) {
        if (! str_contains($exception->getMessage(), 'Laravel 9 must use')) {
            throw $exception;
        }
    }

    $unsupported = array_intersect_key($matrix['include'][0], array_flip([
        'laravel', 'testbench', 'workbench', 'php', 'phpunit', 'scaffold', 'resolution',
    ]));
    $unsupported['workbench'] = $matrix['include'][1]['workbench'];

    try {
        requestedRow($matrix, $unsupported);
        throw new RuntimeException('Self-test accepted an unsupported dependency tuple.');
    } catch (RuntimeException $exception) {
        if (! str_contains($exception->getMessage(), 'Unsupported Laravel 9 dependency tuple')) {
            throw $exception;
        }
    }
}

try {
    $command = $argv[1] ?? '';
    $matrix = loadMatrix();

    switch ($command) {
        case 'export':
            echo 'matrix=', json_encode($matrix, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
            break;

        case 'validate':
            $row = requestedRow($matrix, parseOptions(array_slice($argv, 2)));
            echo $row['label'], ' dependency contract is valid.', PHP_EOL;
            break;

        case 'assert-installed':
            $row = requestedRow($matrix, parseOptions(array_slice($argv, 2)));
            assertInstalled($row);
            echo $row['label'], ' installed dependency and scaffold contract is valid.', PHP_EOL;
            break;

        case 'self-test':
            selfTest($matrix);
            echo 'Laravel dependency matrix self-test passed.', PHP_EOL;
            break;

        default:
            throw new RuntimeException('Usage: php scripts/ci/laravel-matrix.php export|validate|assert-installed|self-test');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Laravel matrix contract error: '.$exception->getMessage().PHP_EOL);
    exit(1);
}
