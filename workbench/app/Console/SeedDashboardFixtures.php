<?php

namespace Workbench\App\Console;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\CarbonImmutable;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;

class SeedDashboardFixtures extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'workflow:seed-dashboard-fixtures';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed dashboard fixtures for local UI and Copilot setup';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if (StoredWorkflow::query()->exists()) {
            $this->info('Dashboard fixtures already exist.');

            return Command::SUCCESS;
        }

        $now = CarbonImmutable::now();

        $running = StoredWorkflow::create([
            'class' => \Workbench\App\Workflows\TestWorkflow::class,
            'arguments' => Serializer::serialize([]),
            'output' => null,
            'status' => 'waiting',
            'created_at' => $now->subMinutes(4),
            'updated_at' => $now->subSeconds(20),
        ]);

        $running->logs()->create([
            'index' => 0,
            'now' => $now->subSeconds(30),
            'class' => 'Workbench\App\Activities\RenderDashboard',
            'result' => Serializer::serialize(['status' => 'queued']),
            'created_at' => $now->subSeconds(30),
        ]);

        $running->signals()->create([
            'method' => 'approve',
            'arguments' => Serializer::serialize(['source' => 'copilot-setup']),
            'created_at' => $now->subSeconds(15),
        ]);

        $completed = StoredWorkflow::create([
            'class' => \Workbench\App\Workflows\TestParentWorkflow::class,
            'arguments' => Serializer::serialize(['batch' => 'daily']),
            'output' => Serializer::serialize(['ok' => true]),
            'status' => 'completed',
            'created_at' => $now->subHours(2),
            'updated_at' => $now->subHours(1),
        ]);

        $completed->logs()->create([
            'index' => 0,
            'now' => $now->subHours(1),
            'class' => 'Workbench\App\Activities\ShipReport',
            'result' => Serializer::serialize(['status' => 'complete']),
            'created_at' => $now->subHours(1),
        ]);

        $continuedParent = StoredWorkflow::create([
            'class' => \Workbench\App\Workflows\TestContinueAsNewWorkflow::class,
            'arguments' => Serializer::serialize(['page' => 1]),
            'output' => null,
            'status' => 'continued',
            'created_at' => $now->subDay(),
            'updated_at' => $now->subHours(18),
        ]);

        $continuedChild = StoredWorkflow::create([
            'class' => \Workbench\App\Workflows\TestContinueAsNewWorkflow::class,
            'arguments' => Serializer::serialize(['page' => 2]),
            'output' => null,
            'status' => 'running',
            'created_at' => $now->subHours(12),
            'updated_at' => $now->subMinutes(5),
        ]);

        $continuedParent->children()->attach($continuedChild->id, [
            'parent_index' => StoredWorkflow::CONTINUE_PARENT_INDEX,
            'parent_now' => $now->subHours(12),
        ]);

        $failed = StoredWorkflow::create([
            'class' => \Workbench\App\Workflows\TestWorkflow::class,
            'arguments' => Serializer::serialize(['attempt' => 3]),
            'output' => null,
            'status' => 'failed',
            'created_at' => $now->subHours(6),
            'updated_at' => $now->subHours(5),
        ]);

        $failed->logs()->create([
            'index' => 0,
            'now' => $now->subHours(5),
            'class' => 'Workbench\App\Activities\PersistResult',
            'result' => Serializer::serialize(['status' => 'failed']),
            'created_at' => $now->subHours(5),
        ]);

        $failed->exceptions()->create([
            'class' => 'Workbench\App\Activities\PersistResult',
            'exception' => Serializer::serialize(new Exception('Synthetic dashboard fixture failure')),
            'created_at' => $now->subHours(5),
        ]);

        $this->info('Seeded dashboard fixtures.');

        return Command::SUCCESS;
    }
}
