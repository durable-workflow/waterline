<?php

declare(strict_types=1);

// Seeds the schedules that scripts/ci/schedule-actions-visual.mjs acts on.
// Run from the root of a Laravel host that installs Waterline and Workflow:
//   php ../waterline/scripts/ci/seed-schedule-visual.php 'App\Workflows\Simple\SimpleWorkflow'
//
// The same schedule ID exists in two namespaces so the browser check can
// prove that every row action reaches the namespace of the row it came from.

use Illuminate\Contracts\Console\Kernel;
use Workflow\V2\Support\ScheduleManager;
use Workflow\V2\Support\TypeRegistry;

$workflowClass = $argv[1] ?? '';

if ($workflowClass === '' || ! is_file(getcwd().'/bootstrap/app.php')) {
    fwrite(STDERR, "Usage: run from the Laravel host root with a workflow class argument.\n");
    exit(2);
}

require getcwd().'/vendor/autoload.php';

$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! class_exists($workflowClass)) {
    fwrite(STDERR, "Workflow class [{$workflowClass}] is not installed in this host.\n");
    exit(2);
}

ScheduleManager::create(
    'nightly-invoice-sync',
    $workflowClass,
    '0 2 * * *',
    timezone: 'America/Chicago',
    namespace: 'default',
);

ScheduleManager::create(
    'nightly-invoice-sync',
    $workflowClass,
    '30 3 * * *',
    namespace: 'tenant-b',
);

ScheduleManager::createFromSpec(
    'hourly-ledger',
    ['intervals' => [['every' => 'PT1H', 'offset' => 'PT5M']]],
    ['workflow_type' => TypeRegistry::for($workflowClass), 'workflow_class' => $workflowClass],
    namespace: 'default',
);

fwrite(STDOUT, "Seeded nightly-invoice-sync in default and tenant-b, and hourly-ledger in default.\n");
