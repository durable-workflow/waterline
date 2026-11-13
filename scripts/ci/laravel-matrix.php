<?php

declare(strict_types=1);

use Composer\InstalledVersions;

const MATRIX_PATH = __DIR__.'/../../.github/laravel-matrix.json';

final class MatrixContractException extends RuntimeException
{
}

/**
 * @return array{include: list<array<string, string>>}
 */
function loadMatrix(bool $checkRootWorkflow = true): array
{
    $contents = file_get_contents(MATRIX_PATH);

    if ($contents === false) {
        throw new MatrixContractException('Unable to read '.MATRIX_PATH);
    }

    try {
        $matrix = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new MatrixContractException('Invalid matrix JSON: '.$exception->getMessage(), previous: $exception);
    }

    if (! is_array($matrix)) {
        throw new MatrixContractException('The matrix must be a JSON object.');
    }

    validateContract($matrix);
    validateWorkflowPins($matrix, $checkRootWorkflow ? rootWorkflowVersion() : null);
    validateSdkPins($matrix, $checkRootWorkflow ? rootSdkVersion() : null);

    /** @var array{include: list<array<string, string>>} $matrix */
    return $matrix;
}

/**
 * @param array<mixed> $matrix
 */
function validateContract(array $matrix): void
{
    $requiredKeys = [
        'label', 'boundary', 'laravel', 'testbench', 'workbench', 'php', 'phpunit', 'workflow', 'sdk', 'scaffold', 'resolution',
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
        throw new MatrixContractException('The matrix must contain only an include array.');
    }

    if (count($matrix['include']) !== count($expected)) {
        throw new MatrixContractException('The matrix must contain exactly one cell for each supported Laravel major (9-13).');
    }

    $seenMajors = [];
    $seenRows = [];

    foreach ($matrix['include'] as $index => $row) {
        $row = validateCellValues($row, $requiredKeys, $index);

        $laravelMajor = major($row['laravel']);
        if (! isset($expected[$laravelMajor])) {
            throw new MatrixContractException(sprintf('Laravel %d is outside the supported matrix range (9-13).', $laravelMajor));
        }

        if (isset($seenMajors[$laravelMajor])) {
            throw new MatrixContractException(sprintf('Laravel %d has more than one matrix cell.', $laravelMajor));
        }

        $seenMajors[$laravelMajor] = true;
        validateFrameworkCell($row, $expected[$laravelMajor], $laravelMajor);

        $fingerprint = implode('|', array_map(static fn (string $key): string => $row[$key], $requiredKeys));
        if (isset($seenRows[$fingerprint])) {
            throw new MatrixContractException(sprintf('Matrix cell %d duplicates another dependency tuple.', $index));
        }

        $seenRows[$fingerprint] = true;
    }

    if (array_keys($seenMajors) !== array_keys($expected)) {
        throw new MatrixContractException('Matrix cells must be ordered from Laravel 9 through Laravel 13.');
    }

    $testbenchConfig = file_get_contents(__DIR__.'/../../testbench.yaml');
    if ($testbenchConfig === false || preg_match('/^laravel:\s*[\'\"]?@testbench[\'\"]?\s*$/m', $testbenchConfig) !== 1) {
        throw new MatrixContractException('testbench.yaml must select the dependency-provided @testbench scaffold.');
    }
}

/**
 * @param list<string> $requiredKeys
 * @return array<string, string>
 */
function validateCellValues(mixed $row, array $requiredKeys, int $index): array
{
    if (! is_array($row) || array_keys($row) !== $requiredKeys) {
        throw new MatrixContractException(sprintf(
            'Matrix cell %d must define exactly: %s.',
            $index,
            implode(', ', $requiredKeys),
        ));
    }

    foreach ($requiredKeys as $key) {
        if (! is_string($row[$key]) || $row[$key] === '') {
            throw new MatrixContractException(sprintf('Matrix cell %d has an invalid %s value.', $index, $key));
        }
    }

    foreach (['laravel', 'testbench', 'workbench', 'phpunit', 'workflow', 'sdk'] as $package) {
        if (preg_match('/^(\d+)\.\d+\.\d+$/D', $row[$package]) !== 1) {
            throw new MatrixContractException(sprintf(
                'Matrix cell %d must pin %s to an exact semantic version; received %s.',
                $index,
                $package,
                $row[$package],
            ));
        }
    }

    if (preg_match('/^\d+\.\d+$/D', $row['php']) !== 1) {
        throw new MatrixContractException(sprintf('Matrix cell %d must pin a PHP major.minor line.', $index));
    }

    return $row;
}

/**
 * @param array<string, string> $row
 * @param array<string, int|string> $expectedRow
 */
function validateFrameworkCell(array $row, array $expectedRow, int $laravelMajor): void
{
    if ($row['label'] !== 'Laravel '.$laravelMajor
        || $row['php'] !== $expectedRow['php']
        || major($row['testbench']) !== $expectedRow['testbench']
        || major($row['workbench']) !== $expectedRow['workbench']
        || major($row['phpunit']) !== $expectedRow['phpunit']
        || $row['boundary'] !== $expectedRow['boundary']
        || $row['scaffold'] !== 'testbench'
        || $row['resolution'] !== $expectedRow['resolution']) {
        throw new MatrixContractException(sprintf(
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
}

/**
 * @param array{include: list<array<string, string>>} $matrix
 */
function validateWorkflowPins(array $matrix, ?string $rootWorkflow): void
{
    foreach ($matrix['include'] as $row) {
        $laravelMajor = major($row['laravel']);
        // Workflow 2.17.0 and later require Laravel 10 or newer, so a Laravel 9
        // host can run at most Workflow 2.16.x. Other cells test the version
        // Waterline itself develops against.
        if ($laravelMajor === 9 && version_compare($row['workflow'], '2.17.0', '>=')) {
            throw new MatrixContractException(sprintf(
                'Laravel 9 must pin a Workflow release before 2.17.0, the newest line a Laravel 9 host can install; received %s.',
                $row['workflow'],
            ));
        }

        // Workflow 2.21.0 and later require Laravel 12 or newer, so Laravel 10
        // and 11 hosts can run at most Workflow 2.20.x.
        if (in_array($laravelMajor, [10, 11], true) && version_compare($row['workflow'], '2.21.0', '>=')) {
            throw new MatrixContractException(sprintf(
                'Laravel %d must pin a Workflow release before 2.21.0, the newest line a Laravel %d host can install; received %s.',
                $laravelMajor,
                $laravelMajor,
                $row['workflow'],
            ));
        }

        if ($rootWorkflow !== null && $laravelMajor >= 12 && $row['workflow'] !== $rootWorkflow) {
            throw new MatrixContractException(sprintf(
                'Laravel %d must use the root require-dev Workflow %s; received %s.',
                $laravelMajor,
                $rootWorkflow,
                $row['workflow'],
            ));
        }
    }
}

/**
 * @param array{include: list<array<string, string>>} $matrix
 */
function validateSdkPins(array $matrix, ?string $rootSdk): void
{
    foreach ($matrix['include'] as $row) {
        $laravelMajor = major($row['laravel']);
        // The PHP SDK's auto-discovered Laravel bridge refuses Laravel before
        // 12.69 from SDK 3.10.0 on, so Laravel 9-11 cells keep an older SDK.
        // Other cells test the SDK Waterline itself develops against.
        if ($laravelMajor <= 11 && version_compare($row['sdk'], '3.10.0', '>=')) {
            throw new MatrixContractException(sprintf(
                'Laravel %d must pin a PHP SDK release before 3.10.0, the newest line whose Laravel bridge boots there; received %s.',
                $laravelMajor,
                $row['sdk'],
            ));
        }

        if ($rootSdk !== null && $laravelMajor >= 12 && $row['sdk'] !== $rootSdk) {
            throw new MatrixContractException(sprintf(
                'Laravel %d must use the root require-dev PHP SDK %s; received %s.',
                $laravelMajor,
                $rootSdk,
                $row['sdk'],
            ));
        }
    }
}

function rootSdkVersion(): string
{
    $manifest = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $version = $manifest['require-dev']['durable-workflow/sdk'] ?? null;

    if (! is_string($version) || preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1) {
        throw new MatrixContractException('composer.json must pin durable-workflow/sdk to an exact require-dev version.');
    }

    return $version;
}

function rootWorkflowVersion(): string
{
    $manifest = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $version = $manifest['require-dev']['durable-workflow/workflow'] ?? null;

    if (! is_string($version) || preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1) {
        throw new MatrixContractException('composer.json must pin durable-workflow/workflow to an exact require-dev version.');
    }

    return $version;
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
            throw new MatrixContractException('Expected matrix options in --key=value form; received '.$argument);
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
    $keys = ['laravel', 'testbench', 'workbench', 'php', 'phpunit', 'workflow', 'sdk', 'scaffold', 'resolution'];

    if (array_keys($requested) !== $keys) {
        throw new MatrixContractException('Cell validation requires exactly: '.implode(', ', $keys).'.');
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
            throw new MatrixContractException(sprintf(
                'Unsupported Laravel %d dependency tuple. Expected Laravel %s, Testbench %s, Workbench %s, PHP %s, PHPUnit %s, Workflow %s, PHP SDK %s, scaffold %s, resolution %s.',
                $laravelMajor,
                $row['laravel'],
                $row['testbench'],
                $row['workbench'],
                $row['php'],
                $row['phpunit'],
                $row['workflow'],
                $row['sdk'],
                $row['scaffold'],
                $row['resolution'],
            ));
        }
    }

    throw new MatrixContractException(sprintf(
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
        throw new MatrixContractException('Dependencies are not installed; vendor/autoload.php is missing.');
    }

    require_once $autoload;

    $actualPhp = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
    if ($actualPhp !== $row['php']) {
        throw new MatrixContractException(sprintf('Expected PHP %s but the matrix runner is using PHP %s.', $row['php'], $actualPhp));
    }

    $packages = [
        'laravel' => 'laravel/framework',
        'testbench' => 'orchestra/testbench',
        'workbench' => 'orchestra/workbench',
        'phpunit' => 'phpunit/phpunit',
        'workflow' => 'durable-workflow/workflow',
        'sdk' => 'durable-workflow/sdk',
    ];

    foreach ($packages as $key => $package) {
        $installed = InstalledVersions::getPrettyVersion($package);
        $normalized = ltrim((string) $installed, 'v');

        if ($normalized !== $row[$key]) {
            throw new MatrixContractException(sprintf(
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
        throw new MatrixContractException('The matrix cell is not using its installed Testbench scaffold.');
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
        throw new MatrixContractException('Self-test accepted a mismatched Testbench major.');
    } catch (RuntimeException $exception) {
        if (! str_contains($exception->getMessage(), 'Laravel 9 must use')) {
            throw $exception;
        }
    }

    $laravel9Workflow = $matrix;
    $laravel9Workflow['include'][0]['workflow'] = '2.17.0';

    try {
        validateWorkflowPins($laravel9Workflow, rootWorkflowVersion());
        throw new MatrixContractException('Self-test accepted Workflow 2.17.0 on Laravel 9.');
    } catch (RuntimeException $exception) {
        if (! str_contains($exception->getMessage(), 'Laravel 9 must pin a Workflow release before 2.17.0')) {
            throw $exception;
        }
    }

    $laravel10Workflow = $matrix;
    $laravel10Workflow['include'][1]['workflow'] = '2.21.0';

    try {
        validateWorkflowPins($laravel10Workflow, rootWorkflowVersion());
        throw new MatrixContractException('Self-test accepted Workflow 2.21.0 on Laravel 10.');
    } catch (RuntimeException $exception) {
        if (! str_contains($exception->getMessage(), 'Laravel 10 must pin a Workflow release before 2.21.0')) {
            throw $exception;
        }
    }

    $laravel11Sdk = $matrix;
    $laravel11Sdk['include'][2]['sdk'] = '3.10.0';

    try {
        validateSdkPins($laravel11Sdk, rootSdkVersion());
        throw new MatrixContractException('Self-test accepted PHP SDK 3.10.0 on Laravel 11.');
    } catch (RuntimeException $exception) {
        if (! str_contains($exception->getMessage(), 'Laravel 11 must pin a PHP SDK release before 3.10.0')) {
            throw $exception;
        }
    }

    $driftedSdk = $matrix;
    $driftedSdk['include'][4]['sdk'] = '3.9.4';

    try {
        validateSdkPins($driftedSdk, rootSdkVersion());
        throw new MatrixContractException('Self-test accepted a Laravel 13 PHP SDK that differs from require-dev.');
    } catch (RuntimeException $exception) {
        if (! str_contains($exception->getMessage(), 'Laravel 13 must use the root require-dev PHP SDK')) {
            throw $exception;
        }
    }

    $driftedWorkflow = $matrix;
    $driftedWorkflow['include'][3]['workflow'] = '2.16.0';

    try {
        validateWorkflowPins($driftedWorkflow, rootWorkflowVersion());
        throw new MatrixContractException('Self-test accepted a Laravel 12 Workflow that differs from require-dev.');
    } catch (RuntimeException $exception) {
        if (! str_contains($exception->getMessage(), 'Laravel 12 must use the root require-dev Workflow')) {
            throw $exception;
        }
    }

    $unsupported = array_intersect_key($matrix['include'][0], array_flip([
        'laravel', 'testbench', 'workbench', 'php', 'phpunit', 'workflow', 'sdk', 'scaffold', 'resolution',
    ]));
    $unsupported['workbench'] = $matrix['include'][1]['workbench'];

    try {
        requestedRow($matrix, $unsupported);
        throw new MatrixContractException('Self-test accepted an unsupported dependency tuple.');
    } catch (RuntimeException $exception) {
        if (! str_contains($exception->getMessage(), 'Unsupported Laravel 9 dependency tuple')) {
            throw $exception;
        }
    }
}

try {
    $command = $argv[1] ?? '';
    // Composer rewrites require-dev for the selected compatibility cell.
    // The original root pin is checked before resolution; afterward verify
    // the installed graph against that cell, including its Workflow override.
    $matrix = loadMatrix($command !== 'assert-installed');

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
            throw new MatrixContractException('Usage: php scripts/ci/laravel-matrix.php export|validate|assert-installed|self-test');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Laravel matrix contract error: '.$exception->getMessage().PHP_EOL);
    exit(1);
}
