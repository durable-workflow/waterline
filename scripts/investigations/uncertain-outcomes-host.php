<?php

declare(strict_types=1);

// Isolated synthetic application for #232, never a production recovery endpoint.
require __DIR__.'/vendor/autoload.php';

use Composer\InstalledVersions;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Waterline\Waterline;
use Workflow\UpdateMethod;
use Workflow\V2\Activity;
use Workflow\V2\ActivityTaskBridge;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;
use Workflow\V2\WorkflowTaskBridge;

final class SyntheticLostResponse extends RuntimeException {}

/** A separate synthetic provider ledger, excluded from every Waterline route. */
function providerLedger(): array
{
    $file = fopen(__DIR__.'/provider-oracle.json', 'c+');
    if ($file === false || !flock($file, LOCK_EX)) throw new RuntimeException('Cannot open synthetic provider oracle.');
    try {
        $raw = stream_get_contents($file);
        return $raw ? json_decode($raw, true, flags: JSON_THROW_ON_ERROR) : [];
    } finally {
        flock($file, LOCK_UN);
        fclose($file);
    }
}

/** Ordinary HTTP calls from activities; no provider credential enters history. */
function providerRequest(string $method, string $path, ?array $body = null, int $timeout = 5000): array
{
    $request = curl_init('http://127.0.0.1:8081'.$path);
    curl_setopt_array($request, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT_MS => 500, CURLOPT_TIMEOUT_MS => $timeout]);
    if ($body !== null) curl_setopt_array($request, [CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR)]);
    $response = curl_exec($request);
    $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    $error = curl_error($request);
    curl_close($request);
    if ($response === false) throw new SyntheticLostResponse('No confirmed provider response: '.$error);
    return ['status' => $status, 'body' => json_decode($response, true, flags: JSON_THROW_ON_ERROR)];
}

function submitSyntheticOperation(string $scenario, string $key): array
{
    try {
        $response = providerRequest('POST', '/submit', ['scenario' => $scenario, 'operation_key' => $key], $scenario === 'lost-response' ? 100 : 5000);
    } catch (SyntheticLostResponse $error) {
        throw new SyntheticLostResponse('Response lost after submission; external outcome unknown to this caller. operation_key='.$key, previous: $error);
    }
    if ($response['status'] === 409) throw new RuntimeException('Synthetic provider confirmed rejection; accepted=false. operation_key='.$key);
    if ($response['status'] !== 200) throw new RuntimeException('Provider response did not confirm success.');
    return $response['body'];
}

final class SubmitSyntheticOperation extends Activity
{
    public function handle(string $scenario, string $key): array { return submitSyntheticOperation($scenario, $key); }
}

final class SubmitUnknownOperation extends Activity
{
    public function handle(string $key): array
    {
        try {
            return submitSyntheticOperation('lost-response', $key);
        } catch (SyntheticLostResponse) {
            return ['outcome' => 'unknown', 'operation_key' => $key];
        }
    }
}

final class LookupSyntheticReceipt extends Activity
{
    public function handle(string $key): array
    {
        $response = providerRequest('GET', '/operations/'.rawurlencode($key));
        $receipts = $response['body']['receipts'] ?? [];
        if (count($receipts) !== 1) throw new RuntimeException('Reconciliation cannot confirm one unique provider receipt.');
        return $receipts[0];
    }
}

#[Type('investigation.uncertain-terminal')]
#[Signal('reconcile', [])]
final class UncertainTerminalWorkflow extends Workflow
{
    public function handle(string $scenario, string $key): array
    {
        $receipt = \Workflow\V2\activity(SubmitSyntheticOperation::class, new ActivityOptions(maxAttempts: $scenario === 'confirmed-failure' ? 2 : 1, backoff: 0), $scenario, $key);
        if ($scenario === 'processing-failure') throw new RuntimeException('Synthetic local processing failure after the recorded provider response.');
        return $receipt;
    }

    #[UpdateMethod('note')]
    public function note(string $note): array { return ['note' => $note]; }
}

#[Type('investigation.authored-reconciliation')]
#[Signal('reconcile', [])]
final class AuthoredReconciliationWorkflow extends Workflow
{
    private string $operatorNote = '';

    public function handle(string $key): array
    {
        $observation = \Workflow\V2\activity(SubmitUnknownOperation::class, new ActivityOptions(maxAttempts: 1), $key);
        \Workflow\V2\signal('reconcile');
        $confirmed = \Workflow\V2\activity(LookupSyntheticReceipt::class, new ActivityOptions(maxAttempts: 1), $key);
        return ['initial' => $observation, 'confirmed' => $confirmed, 'operator_note' => $this->operatorNote];
    }

    #[UpdateMethod('note')]
    public function note(string $note): array
    {
        $this->operatorNote = $note;
        return ['note' => $note];
    }
}

final class UncertainOutcomesProvider extends ServiceProvider
{
    public function register(): void
    {
        config()->set([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.env' => 'testing', 'app.debug' => true,
            'auth.defaults.guard' => 'fixture', 'auth.guards.fixture' => ['driver' => 'fixture'],
            'database.default' => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => __DIR__.'/database.sqlite', 'foreign_key_constraints' => true],
            'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync',
            'workflows.v2.task_dispatch_mode' => 'poll',
            'waterline.backend' => 'embedded', 'waterline.engine_source' => 'v2', 'waterline.middleware' => [], 'waterline.locale' => 'en',
            'waterline.authorization.gates' => array_fill_keys(['messages','repair','cancel','terminate','archive','schedules'], 'fixture-operator'),
        ]);
    }

    public function boot(): void
    {
        Auth::viaRequest('fixture', static fn () => new GenericUser(['id' => 7, 'role' => $_COOKIE['wl-test-role'] ?? 'viewer']));
        Waterline::auth(static fn ($request): bool => $request->user() !== null);
        Gate::define('fixture-operator', static fn ($user): bool => $user->role === 'operator');
    }
}

$app = Application::configure(basePath: __DIR__)->withProviders([
    \Waterline\WaterlineApplicationServiceProvider::class,
    \Workflow\Providers\WorkflowServiceProvider::class,
    \Waterline\WaterlineServiceProvider::class,
    UncertainOutcomesProvider::class,
], withBootstrapProviders: false)->create();

function drainInvestigationTasks(): void
{
    for ($round = 0; $round < 40; ++$round) {
        $workflows = WorkflowTaskBridge::poll(null, null, 20);
        foreach ($workflows as $task) WorkflowTaskBridge::execute($task['task_id']);
        $activities = ActivityTaskBridge::poll(null, null, 20);
        foreach ($activities as $task) app()->call([new RunActivityTask($task['task_id']), 'handle']);
        if (!$workflows && !$activities) return;
    }
    throw new RuntimeException('Synthetic investigation exceeded its bounded task drain.');
}

function runUncertainOutcomesCli(Application $app, array $arguments): void
{
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    foreach (['durable-workflow/waterline' => ['EXPECTED_WATERLINE_VERSION','EXPECTED_WATERLINE_SOURCE'], 'durable-workflow/workflow' => ['EXPECTED_WORKFLOW_VERSION','EXPECTED_WORKFLOW_SOURCE']] as $package => [$version, $source]) {
        if (ltrim((string) InstalledVersions::getPrettyVersion($package), 'v') !== getenv($version) || InstalledVersions::getReference($package) !== getenv($source)) throw new RuntimeException('Unexpected installed identity for '.$package);
    }
    $operation = $arguments[1] ?? 'audit';
    if ($operation === 'seed') {
        Artisan::call('migrate:fresh', ['--force' => true]);
        file_put_contents(__DIR__.'/provider-oracle.json', '{}');
        foreach (['lost-response','confirmed-failure','processing-failure'] as $scenario) WorkflowStub::make(UncertainTerminalWorkflow::class, 'uncertain-'.$scenario)->start($scenario, 'operation-'.$scenario);
        WorkflowStub::make(AuthoredReconciliationWorkflow::class, 'uncertain-authored-reconciliation')->start('operation-authored-reconciliation');
        drainInvestigationTasks();
        Artisan::call('vendor:publish', ['--tag' => 'waterline-assets', '--force' => true]);
        $ledger = providerLedger();
        foreach (['lost-response','confirmed-failure','processing-failure'] as $scenario) {
            if (WorkflowStub::load('uncertain-'.$scenario)->status() !== 'failed') throw new RuntimeException('Scenario did not reach the intended actual failure: '.$scenario);
            $expectedAttempts = $scenario === 'confirmed-failure' ? 2 : 1;
            $expectedEffects = $scenario === 'confirmed-failure' ? 0 : 1;
            if ($ledger['operation-'.$scenario]['attempts'] !== $expectedAttempts || count($ledger['operation-'.$scenario]['receipts']) !== $expectedEffects) throw new RuntimeException('Unexpected provider oracle for '.$scenario);
        }
        if (WorkflowStub::load('uncertain-authored-reconciliation')->status() !== 'waiting') throw new RuntimeException('Authored control is not awaiting reconciliation.');
    } elseif ($operation === 'drain') {
        drainInvestigationTasks();
    } elseif ($operation === 'unsafe-restart-control') {
        foreach (['lost-response','processing-failure'] as $scenario) WorkflowStub::make(UncertainTerminalWorkflow::class, 'uncertain-naive-restart-'.$scenario)->start($scenario, 'operation-'.$scenario);
        drainInvestigationTasks();
        foreach (['lost-response','processing-failure'] as $scenario) if (count(providerLedger()['operation-'.$scenario]['receipts']) !== 2) throw new RuntimeException('Duplicate-effect control did not reproduce.');
    }
    $controlRun = \Workflow\V2\Models\WorkflowRun::query()->where('workflow_instance_id', 'uncertain-authored-reconciliation')->first();
    $controlOutput = $controlRun?->status->value === 'completed' ? $controlRun->workflowOutput() : null;
    if ($controlOutput !== null && (($controlOutput['initial']['outcome'] ?? null) !== 'unknown' || ($controlOutput['confirmed']['receipt_id'] ?? null) !== 'synthetic-operation-authored-reconciliation-1' || ($controlOutput['operator_note'] ?? null) !== 'operator requested lookup')) {
        throw new RuntimeException('Authored reconciliation did not preserve the confirmed receipt and operator update.');
    }
    echo json_encode([
        'generated_at' => gmdate(DATE_ATOM), 'php' => PHP_VERSION,
        'waterline' => ['version' => InstalledVersions::getPrettyVersion('durable-workflow/waterline'), 'source' => InstalledVersions::getReference('durable-workflow/waterline')],
        'workflow' => ['version' => InstalledVersions::getPrettyVersion('durable-workflow/workflow'), 'source' => InstalledVersions::getReference('durable-workflow/workflow')],
        'runs' => \Workflow\V2\Models\WorkflowRun::query()->get(['id','workflow_instance_id','status'])->toArray(),
        'activities' => \Workflow\V2\Models\ActivityExecution::query()->get(['id','workflow_run_id','status','attempt_count'])->toArray(),
        'attempts' => \Workflow\V2\Models\ActivityAttempt::query()->get(['id','workflow_run_id','activity_execution_id','attempt_number','status'])->toArray(),
        'oracle' => providerLedger(),
        'authored_control_output' => $controlOutput,
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
}

if (PHP_SAPI === 'cli') {
    try { runUncertainOutcomesCli($app, $argv); }
    catch (Throwable $error) { fwrite(STDERR, (string) $error.PHP_EOL); exit(1); }
} else {
    $path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (str_starts_with($path, '/vendor/waterline/')) {
        $file = realpath(__DIR__.'/public'.$path);
        if ($file && str_starts_with($file, __DIR__.'/public/vendor/waterline/') && is_file($file)) {
            $response = new \Symfony\Component\HttpFoundation\BinaryFileResponse($file);
            $response->headers->set('Content-Type', match (pathinfo($file, PATHINFO_EXTENSION)) {
                'css' => 'text/css', 'png' => 'image/png', 'json' => 'application/json', default => 'application/javascript',
            });
            $response->send();
            return;
        }
    }
    $app->handleRequest(\Illuminate\Http\Request::capture());
}
