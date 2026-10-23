<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Composer\InstalledVersions;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Waterline\Waterline;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;
use Workflow\V2\WorkflowTaskBridge;

const INSTALLED_ACCEPTANCE_ASSET_DIRECTORY = __DIR__.'/public/vendor/waterline/';

final class InstalledAcceptanceFailure extends RuntimeException {}
final class SyntheticInstalledWorkflowFailure extends RuntimeException {}

#[Type('installed.operator-command')]
#[Signal('name-provided', [['name' => 'name', 'type' => 'string']])]
final class TestOperatorCommandWorkflow extends Workflow
{
    public function handle(): string { return (string) \Workflow\V2\signal('name-provided'); }
}

#[Type('installed.contrast-completed')]
final class InstalledCompletedWorkflow extends Workflow
{
    public function handle(): array { return ['purpose' => 'published Waterline acceptance']; }
}

#[Type('installed.contrast-failed')]
final class InstalledFailedWorkflow extends Workflow
{
    public function handle(): array { throw new SyntheticInstalledWorkflowFailure('Synthetic installed acceptance failure.'); }
}

final class InstalledAcceptanceProvider extends ServiceProvider
{
    public function register(): void
    {
        $role = $_COOKIE['wl-test-role'] ?? 'viewer';
        config()->set([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.env' => 'testing', 'app.debug' => true,
            'auth.defaults.guard' => 'fixture', 'auth.guards.fixture' => ['driver' => 'fixture'],
            'database.default' => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => __DIR__.'/database.sqlite', 'foreign_key_constraints' => true],
            'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync',
            'workflows.v2.task_dispatch_mode' => 'poll',
            'waterline.backend' => 'embedded', 'waterline.engine_source' => 'v2',
            'waterline.middleware' => [], 'waterline.locale' => 'en',
            'waterline.read_only' => $role === 'readonly',
            'waterline.authorization.gates' => array_fill_keys(
                ['messages', 'repair', 'cancel', 'terminate', 'archive', 'schedules'], 'fixture-operator',
            ),
        ]);
    }

    public function boot(): void
    {
        Auth::viaRequest('fixture', static fn () => new GenericUser([
            'id' => 7, 'role' => $_COOKIE['wl-test-role'] ?? 'viewer',
        ]));
        Waterline::auth(static fn ($request): bool => $request->user() !== null);
        Gate::define('fixture-operator', static fn ($user): bool => $user->role === 'operator');
    }
}

$app = Application::configure(basePath: __DIR__)
    ->withProviders([
        \Waterline\WaterlineApplicationServiceProvider::class,
        \Workflow\Providers\WorkflowServiceProvider::class,
        \Waterline\WaterlineServiceProvider::class,
        InstalledAcceptanceProvider::class,
    ], withBootstrapProviders: false)->create();

function drainAcceptanceTasks(): void
{
    for ($round = 0; $round < 30; ++$round) {
        $tasks = WorkflowTaskBridge::poll(null, null, 10);
        if (!$tasks) {
            return;
        }
        foreach ($tasks as $task) {
            WorkflowTaskBridge::execute($task['task_id']);
        }
    }
    throw new InstalledAcceptanceFailure('Acceptance fixture exceeded its bounded task drain.');
}

function seedAcceptanceRuns(): void
{
    Artisan::call('migrate:fresh', ['--force' => true]);
    $runs = [];
    foreach ([
        'authorization-browser' => TestOperatorCommandWorkflow::class,
        'theme-contrast-completed' => InstalledCompletedWorkflow::class,
        'theme-contrast-failed' => InstalledFailedWorkflow::class,
        'theme-contrast-cancelled' => TestOperatorCommandWorkflow::class,
    ] as $id => $class) {
        $runs[$id] = WorkflowStub::make($class, $id);
        $runs[$id]->start();
    }
    drainAcceptanceTasks();
    $runs['theme-contrast-cancelled']->cancel('Synthetic installed acceptance');
    drainAcceptanceTasks();
    \Workflow\V2\Support\ScheduleManager::create('authorization-browser-schedule', TestOperatorCommandWorkflow::class, '0 * * * *');
    Artisan::call('vendor:publish', ['--tag' => 'waterline-assets', '--force' => true]);
    foreach ($runs as $id => $run) {
        $expected = $id === 'authorization-browser' ? 'waiting' : substr($id, strlen('theme-contrast-'));
        $actual = $run->refresh()->status();
        if ($actual !== $expected) {
            throw new InstalledAcceptanceFailure("$id expected $expected, got $actual");
        }
    }
}

function acceptancePackageIdentity(): array
{
    $identity = [];
    foreach (['durable-workflow/waterline' => (string) getenv('EXPECTED_WATERLINE_VERSION'), 'durable-workflow/workflow' => (string) getenv('EXPECTED_WORKFLOW_VERSION'), 'laravel/framework' => 'v12.70.1'] as $package => $expected) {
        $version = InstalledVersions::getPrettyVersion($package);
        if (ltrim((string) $version, 'v') !== ltrim($expected, 'v')) {
            throw new InstalledAcceptanceFailure("Unexpected $package $version");
        }
        $identity[$package] = ['version' => $version, 'reference' => InstalledVersions::getReference($package)];
    }
    $reference = getenv('EXPECTED_WATERLINE_SOURCE');
    if (!$reference || $identity['durable-workflow/waterline']['reference'] !== $reference) {
        throw new InstalledAcceptanceFailure('Installed Waterline reference does not match the qualified tag.');
    }
    return $identity;
}

function acceptanceAssets(): array
{
    $assets = [];
    $source = InstalledVersions::getInstallPath('durable-workflow/waterline').'/public';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($source) + 1);
        $digest = hash_file('sha256', $file->getPathname());
        $published = INSTALLED_ACCEPTANCE_ASSET_DIRECTORY.$relative;
        if (!is_file($published) || hash_file('sha256', $published) !== $digest) {
            throw new InstalledAcceptanceFailure('Published asset mismatch: '.$relative);
        }
        $assets[$relative] = $digest;
    }
    return $assets;
}

function runAcceptanceCli(Application $app, array $arguments): void
{
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (($arguments[1] ?? 'identity') === 'seed') {
        seedAcceptanceRuns();
    }
    echo json_encode([
        'php' => PHP_VERSION, 'packages' => acceptancePackageIdentity(), 'assets' => acceptanceAssets(),
        'runs' => \Workflow\V2\Models\WorkflowRun::query()->get(['id', 'workflow_instance_id', 'status'])->toArray(),
        'commands' => \Workflow\V2\Models\WorkflowCommand::query()->get(['command_type', 'status', 'outcome'])->toArray(),
        'schedule' => \Workflow\V2\Models\WorkflowSchedule::query()->first()?->status,
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
}

if (PHP_SAPI === 'cli') {
    try {
        runAcceptanceCli($app, $argv);
    } catch (Throwable $error) {
        fwrite(STDERR, (string) $error.PHP_EOL);
        exit(1);
    }
} else {
    $requestPath = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (str_starts_with($requestPath, '/vendor/waterline/')) {
        $asset = realpath(__DIR__.'/public'.$requestPath);
        $root = INSTALLED_ACCEPTANCE_ASSET_DIRECTORY;
        if ($asset && str_starts_with($asset, $root) && is_file($asset)) {
            $response = new \Symfony\Component\HttpFoundation\BinaryFileResponse($asset);
            $response->headers->set('Content-Type', match (pathinfo($asset, PATHINFO_EXTENSION)) {
                'css' => 'text/css', 'png' => 'image/png', 'json' => 'application/json', default => 'application/javascript',
            });
            $response->send();
            return;
        }
    }
    $app->handleRequest(\Illuminate\Http\Request::capture());
}
