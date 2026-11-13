<?php

declare(strict_types=1);

namespace Waterline\Support;

use Illuminate\Support\Collection;
use Workflow\V2\Models\WorkflowSchedule;

/** Reads only the instance pointers and current-run status for one schedule page. */
final class EmbeddedScheduleRuns
{
    /** @return array<int, array{current_run: array|null}> keyed by schedule object identity */
    public static function forPage(Collection $schedules): array
    {
        $first = $schedules->first();
        if ($first === null || ! method_exists($first, 'latestInstance')) {
            return [];
        }
        // Resolve through the relationships so configured model classes,
        // storage connections and model scopes remain in effect.
        $instanceModel = $first->latestInstance()->getRelated();
        if (! method_exists($instanceModel, 'currentRun')) {
            return [];
        }
        $runModel = $instanceModel->currentRun()->getRelated();
        $ids = $schedules->map(static fn (WorkflowSchedule $schedule): ?string =>
            ScheduleCurrentRun::identifier($schedule->getRawOriginal('latest_workflow_instance_id'), 191))
            ->filter(static fn (?string $id): bool => $id !== null)->uniqueStrict()->values()->all();
        $instances = $ids === [] ? collect() : $instanceModel->newQuery()->whereIn('id', $ids)
            ->get(['id', 'namespace', 'current_run_id']);
        $matched = [];
        $runIds = [];
        foreach ($schedules as $schedule) {
            // SQL collations may match case or trailing spaces. Only exact
            // namespace and complete pointer matches can establish authority.
            $instance = $instances->first(static fn ($instance): bool =>
                $instance->getRawOriginal('id') === $schedule->getRawOriginal('latest_workflow_instance_id')
                && $instance->getRawOriginal('namespace') === $schedule->getRawOriginal('namespace'));
            $matched[spl_object_id($schedule)] = $instance;
            $runId = $instance === null ? null : ScheduleCurrentRun::identifier($instance->getRawOriginal('current_run_id'), 26);
            if ($runId !== null) {
                $runIds[] = $runId;
            }
        }
        $runs = $runIds === [] ? collect() : $runModel->newQuery()->whereIn('id', array_values(array_unique($runIds)))
            ->get(['id', 'namespace', 'workflow_instance_id', 'status']);
        $observations = [];
        foreach ($schedules as $schedule) {
            $instance = $matched[spl_object_id($schedule)];
            $run = $instance === null ? null : $runs->first(static fn ($run): bool =>
                $run->getRawOriginal('id') === $instance->getRawOriginal('current_run_id')
                && $run->getRawOriginal('workflow_instance_id') === $instance->getRawOriginal('id')
                && $run->getRawOriginal('namespace') === $schedule->getRawOriginal('namespace'));
            $observations[spl_object_id($schedule)] = ['current_run' => $run === null ? null : ScheduleCurrentRun::fromStatus(
                $run->getRawOriginal('id'), $run->getRawOriginal('status'),
            )];
        }

        return $observations;
    }
}
