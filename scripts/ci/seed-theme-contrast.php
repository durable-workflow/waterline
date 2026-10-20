<?php

declare(strict_types=1);

// Seeds completed, failed and cancelled v2 runs and one schedule for
// scripts/ci/theme-contrast-visual.mjs, draining the queue in-process because
// the CI job runs no worker. Run from the Sample App root:
//   php ../waterline/scripts/ci/seed-theme-contrast.php

use App\Workflows\Diagnostics\DiagnosticFailureWorkflow;
use App\Workflows\Polyglot\PhpSignalQueryWorkflow;
use App\Workflows\Simple\SimpleWorkflow;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Workflow\V2\Support\ScheduleManager;
use Workflow\V2\WorkflowStub;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$drain = static fn () => Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 1, '--timeout' => 120]);
$runs = [
    'completed' => WorkflowStub::make(SimpleWorkflow::class, 'theme-contrast-completed'),
    'failed' => WorkflowStub::make(DiagnosticFailureWorkflow::class, 'theme-contrast-failed'),
    'cancelled' => WorkflowStub::make(PhpSignalQueryWorkflow::class, 'theme-contrast-cancelled'),
];
$runs['completed']->start();
$runs['failed']->start('theme-contrast-check');
$runs['cancelled']->start(['purpose' => 'theme-contrast']);
ScheduleManager::create('theme-contrast-nightly', SimpleWorkflow::class, '0 2 * * *');
$drain();
$runs['cancelled']->cancel('Theme contrast fixture');
$drain();

$ok = true;
foreach ($runs as $expected => $run) {
    $status = $run->refresh()->status();
    $ok = $ok && $status === $expected;
    fwrite(STDOUT, sprintf("%s %s run %s: %s\n", $status === $expected ? 'ok' : 'UNEXPECTED', $run->id(), $run->runId(), $status));
}
exit($ok ? 0 : 1);
