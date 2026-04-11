<script type="text/ecmascript-6">
    import moment from 'moment';

    export default {
        components: {},


        /**
         * The component's data.
         */
        data() {
            return {
                stats: {},
                ready: false,
            };
        },


        /**
         * Prepare the component.
         */
        mounted() {
            moment.relativeTimeThreshold('ss', 1);

            document.title = "Waterline - Dashboard";

            this.refreshStatsPeriodically();
        },


        /**
         * Clean after the component is destroyed.
         */
        destroyed() {
            clearTimeout(this.timeout);
        },

        methods: {
            /**
             * Load the general stats.
             */
            loadStats() {
                return this.$http.get(Waterline.basePath + '/api/stats')
                    .then(response => {
                        this.stats = response.data;
                    });
            },

            /**
             * Refresh the stats every period of time.
             */
            refreshStatsPeriodically() {
                Promise.all([
                    this.loadStats(),
                ]).then(() => {
                    this.ready = true;

                    this.timeout = setTimeout(() => {
                        if (this.$root.autoLoadsNewEntries) {
                            this.refreshStatsPeriodically();
                        }
                    }, 5000);
                });
            },

            duration(start, end) {
                return moment(end).from(moment(start), true)
            },

            routeName(flow) {
                const type = ['failed', 'cancelled', 'terminated', 'completed'].includes(flow.status)
                    ? flow.status
                    : (flow.status_bucket || 'running');

                return type + '-flows-preview';
            },

            waitAge(flow) {
                return flow.wait_started_at
                    ? this.duration(flow.wait_started_at, new Date())
                    : '-';
            },

            flowDuration(flow) {
                const start = flow.started_at || flow.created_at;
                const end = flow.closed_at || flow.updated_at;

                if (! start || ! end) {
                    return '-';
                }

                return this.duration(start, end);
            },

            exceptionCount(flow) {
                return flow.exceptions_count ?? flow.exception_count ?? 0;
            },

            operatorMetric(section, key) {
                const metrics = this.stats.operator_metrics || {};
                const group = metrics[section] || {};

                return group[key] || 0;
            },

            operatorMetricLabel(section, key) {
                return this.operatorMetric(section, key).toLocaleString();
            },

            operatorPolicyMetric(key) {
                const metrics = this.stats.operator_metrics || {};
                const policy = metrics.repair_policy || {};

                return policy[key] || 0;
            },

            operatorPolicyMetricLabel(key) {
                return this.operatorPolicyMetric(key).toLocaleString();
            },

            operatorUpdateWaitMetric(key) {
                const metrics = this.stats.operator_metrics || {};
                const policy = metrics.update_wait || {};

                return policy[key] || 0;
            },

            operatorUpdateWaitMetricLabel(key) {
                return this.operatorUpdateWaitMetric(key).toLocaleString();
            },

            operatorProjectionMetric(group, key = null) {
                if (key === null) {
                    key = group
                    group = 'run_summaries'
                }

                const metrics = this.stats.operator_metrics || {};
                const projections = metrics.projections || {};
                const projection = projections[group] || {};

                return projection[key] || 0;
            },

            operatorProjectionMetricLabel(group, key = null) {
                return this.operatorProjectionMetric(group, key).toLocaleString();
            },

            operatorProjectionNeedsRebuild() {
                return this.operatorProjectionMetric('run_summaries', 'needs_rebuild')
                    + this.operatorProjectionMetric('run_waits', 'needs_rebuild')
                    + this.operatorProjectionMetric('run_timeline_entries', 'needs_rebuild')
                    + this.operatorProjectionMetric('run_timer_entries', 'needs_rebuild')
                    + this.operatorProjectionMetric('run_lineage_entries', 'needs_rebuild');
            },

            operatorBackend() {
                const metrics = this.stats.operator_metrics || {};

                return metrics.backend || {};
            },

            operatorBackendStatusLabel() {
                return this.operatorBackend().supported ? 'Supported' : 'Needs attention';
            },

            operatorBackendComponentLabel(component) {
                const backend = this.operatorBackend();
                const detail = backend[component] || {};

                if (component === 'cache') {
                    return [
                        detail.store || 'unknown',
                        detail.driver || 'unknown',
                    ].join('/');
                }

                return [
                    detail.connection || 'unknown',
                    detail.driver || 'unknown',
                ].join('/');
            },

            operatorBackendIssues() {
                const issues = this.operatorBackend().issues;

                return Array.isArray(issues) ? issues : [];
            },

            operatorBackendIssueKey(issue, index) {
                return [
                    issue && issue.component ? issue.component : 'backend',
                    issue && issue.code ? issue.code : 'capability_issue',
                    index,
                ].join(':');
            },

            operatorDurationMetricLabel(section, key) {
                const value = this.operatorMetric(section, key);

                return value > 0 ? moment.duration(value).humanize() : '-';
            },

            operatorRepairScopes() {
                const metrics = this.stats.operator_metrics || {};
                const repair = metrics.repair || {};
                const scopes = Array.isArray(repair.scopes) ? repair.scopes : [];

                return scopes.slice(0, 3);
            },

            operatorRepairScopeLabel(scope) {
                return [
                    scope.connection || 'default',
                    scope.queue || 'default',
                    scope.compatibility || 'any',
                ].join(' / ');
            },

            operatorRepairScopeDuration(scope, key) {
                const value = scope[key] || 0;

                return value > 0 ? moment.duration(value).humanize() : '-';
            },
        }
    }
</script>

<template>
    <div>
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5>Overview</h5>
            </div>

            <div class="card-bg-secondary">
                <div class="d-flex">
                    <div class="w-25 border-right border-bottom">
                        <div class="p-4">
                            <small class="text-uppercase">Flows Per Minute</small>

                            <h4 class="mt-4 mb-0">
                                {{ stats.flows_per_minute ? stats.flows_per_minute.toLocaleString() : 0 }}
                            </h4>
                        </div>
                    </div>

                    <div class="w-25 border-right border-bottom">
                        <div class="p-4">
                            <small class="text-uppercase">Flows Past Hour</small>

                            <h4 class="mt-4 mb-0">
                                {{ stats.flows_past_hour ? stats.flows_past_hour.toLocaleString() : 0 }}
                            </h4>
                        </div>
                    </div>

                    <div class="w-25 border-right border-bottom">
                        <div class="p-4">
                            <small class="text-uppercase">Exceptions Past Hour</small>

                            <h4 class="mt-4 mb-0">
                                {{ stats.exceptions_past_hour ? stats.exceptions_past_hour.toLocaleString() : 0 }}
                            </h4>
                        </div>
                    </div>

                    <div class="w-25 border-bottom">
                        <div class="p-4">
                            <small class="text-uppercase">Failed Flows Past Week</small>

                            <h4 class="mt-4 mb-0">
                                {{ stats.failed_flows_past_week ? stats.failed_flows_past_week.toLocaleString() : 0 }}
                            </h4>
                        </div>
                    </div>
                </div>

                <div class="d-flex">
                    <div class="w-25 border-right">
                        <div class="p-4 mb-0">
                            <small class="text-uppercase">Total Flows</small>

                            <h4 class="mt-4">
                                {{ stats.flows ? stats.flows.toLocaleString() : 0 }}
                            </h4>
                        </div>
                    </div>

                    <div class="w-25 border-right">
                        <div class="p-4 mb-0">
                            <small class="text-uppercase">Max Wait Time</small>

                            <h4 class="mt-4 mb-0">
                                {{ stats.max_wait_time_workflow ? waitAge(stats.max_wait_time_workflow) : '-' }}
                            </h4>

                            <small class="mt-1" v-if="stats.max_wait_time_workflow">
                                (<router-link :title="stats.max_wait_time_workflow.class" :to="{ name: routeName(stats.max_wait_time_workflow), params: { flowId: stats.max_wait_time_workflow.id }}">{{ flowBaseName(stats.max_wait_time_workflow.class) }}</router-link>)
                            </small>
                        </div>
                    </div>

                    <div class="w-25 border-right">
                        <div class="p-4 mb-0">
                            <small class="text-uppercase">Max Duration</small>

                            <h4 class="mt-4 mb-0">
                                {{ stats.max_duration_workflow ? flowDuration(stats.max_duration_workflow) : '-' }}
                            </h4>

                            <small class="mt-1" v-if="stats.max_duration_workflow">
                                (<router-link :title="stats.max_duration_workflow.class" :to="{ name: routeName(stats.max_duration_workflow), params: { flowId: stats.max_duration_workflow.id }}">{{ flowBaseName(stats.max_duration_workflow.class) }}</router-link>)
                            </small>
                        </div>
                    </div>

                    <div class="w-25">
                        <div class="p-4 mb-0">
                            <small class="text-uppercase">Max Exceptions</small>

                            <h4 class="mt-4 mb-0">
                                {{ stats.max_exceptions_workflow ? exceptionCount(stats.max_exceptions_workflow).toLocaleString() : 0 }}
                            </h4>

                            <small class="mt-1" v-if="stats.max_exceptions_workflow">
                                (<router-link :title="stats.max_exceptions_workflow.class" :to="{ name: routeName(stats.max_exceptions_workflow), params: { flowId: stats.max_exceptions_workflow.id }}">{{ flowBaseName(stats.max_exceptions_workflow.class) }}</router-link>)
                            </small>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <div class="card mt-4" v-if="stats.operator_metrics">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5>v2 Operator Metrics</h5>
                <small class="text-muted" v-if="stats.operator_metrics.generated_at">
                    {{ stats.operator_metrics.generated_at }}
                </small>
            </div>

            <div class="card-bg-secondary">
                <div class="d-flex">
                    <div class="w-25 border-right">
                        <div class="p-4">
                            <small class="text-uppercase">Runnable Tasks</small>

                            <h4 class="mt-4 mb-0">
                                {{ operatorMetricLabel('backlog', 'runnable_tasks') }}
                            </h4>
                        </div>
                    </div>

                    <div class="w-25 border-right">
                        <div class="p-4">
                            <small class="text-uppercase">Repair Needed Runs</small>

                            <h4 class="mt-4 mb-0">
                                {{ operatorMetricLabel('backlog', 'repair_needed_runs') }}
                            </h4>
                        </div>
                    </div>

                    <div class="w-25 border-right">
                        <div class="p-4">
                            <small class="text-uppercase">Compatibility Blocked</small>

                            <h4 class="mt-4 mb-0">
                                {{ operatorMetricLabel('backlog', 'compatibility_blocked_runs') }}
                            </h4>
                        </div>
                    </div>

                    <div class="w-25">
                        <div class="p-4">
                            <small class="text-uppercase">Active Workers</small>

                            <h4 class="mt-4 mb-0">
                                {{ operatorMetricLabel('workers', 'active_workers') }}
                            </h4>

                            <small class="mt-1 text-muted">
                                {{ operatorMetricLabel('workers', 'active_worker_scopes') }} queue scopes
                            </small>
                        </div>
                    </div>
                </div>

                <div class="d-flex border-top">
                    <div class="w-25 border-right">
                        <div class="p-4">
                            <small class="text-uppercase">Pending Starts</small>

                            <h4 class="mt-4 mb-0">
                                {{ operatorMetricLabel('starts', 'pending_runs') }}
                            </h4>
                        </div>
                    </div>

                    <div class="w-25 border-right">
                        <div class="p-4">
                            <small class="text-uppercase">Start Commands</small>

                            <h4 class="mt-4 mb-0">
                                {{ operatorMetricLabel('starts', 'pending_commands') }}
                            </h4>
                        </div>
                    </div>

                    <div class="w-25 border-right">
                        <div class="p-4">
                            <small class="text-uppercase">Due Start Tasks</small>

                            <h4 class="mt-4 mb-0">
                                {{ operatorMetricLabel('starts', 'ready_tasks') }}
                            </h4>
                        </div>
                    </div>

                    <div class="w-25">
                        <div class="p-4">
                            <small class="text-uppercase">Max Start Latency</small>

                            <h4 class="mt-4 mb-0">
                                {{ operatorDurationMetricLabel('starts', 'max_pending_ms') }}
                            </h4>

                            <small class="mt-1 text-muted" v-if="stats.operator_metrics.starts && stats.operator_metrics.starts.oldest_pending_start_at">
                                {{ stats.operator_metrics.starts.oldest_pending_start_at }}
                            </small>
                        </div>
                    </div>
                </div>

                <div class="border-top p-4">
                    <small class="text-uppercase">Activity Attempts</small>

                    <div class="mt-2 text-muted">
                        {{ operatorMetricLabel('activities', 'retrying') }} retrying,
                        {{ operatorMetricLabel('activities', 'running') }} running,
                        {{ operatorMetricLabel('activities', 'failed_attempts') }} failed attempts,
                        max {{ operatorMetricLabel('activities', 'max_attempt_count') }} attempts.
                    </div>
                </div>

                <div class="border-top p-4">
                    <small class="text-uppercase">Projection Health</small>

                    <div class="mt-2 text-muted">
                        Run summaries:
                        {{ operatorProjectionMetricLabel('summaries') }} summaries for
                        {{ operatorProjectionMetricLabel('runs') }} runs,
                        {{ operatorProjectionMetricLabel('missing') }} missing,
                        {{ operatorProjectionMetricLabel('orphaned') }} orphaned,
                        {{ operatorProjectionMetricLabel('stale') }} stale.
                    </div>

                    <div class="mt-1 text-muted">
                        Wait rows:
                        {{ operatorProjectionMetricLabel('run_waits', 'rows') }} rows across
                        {{ operatorProjectionMetricLabel('run_waits', 'projected_runs') }} runs,
                        {{ operatorProjectionMetricLabel('run_waits', 'runs_with_waits') }} canonical waits,
                        {{ operatorProjectionMetricLabel('run_waits', 'missing_runs_with_waits') }} runs missing,
                        {{ operatorProjectionMetricLabel('run_waits', 'stale_projected_runs') }} stale,
                        {{ operatorProjectionMetricLabel('run_waits', 'missing_current_open_waits') }} missing current open waits,
                        {{ operatorProjectionMetricLabel('run_waits', 'orphaned') }} orphaned.
                    </div>

                    <div class="mt-1 text-muted">
                        Timeline rows:
                        {{ operatorProjectionMetricLabel('run_timeline_entries', 'rows') }} rows for
                        {{ operatorProjectionMetricLabel('run_timeline_entries', 'history_events') }} history events,
                        {{ operatorProjectionMetricLabel('run_timeline_entries', 'missing_runs_with_history') }} runs missing,
                        {{ operatorProjectionMetricLabel('run_timeline_entries', 'stale_projected_runs') }} stale,
                        {{ operatorProjectionMetricLabel('run_timeline_entries', 'missing_history_events') }} missing history events,
                        {{ operatorProjectionMetricLabel('run_timeline_entries', 'orphaned') }} orphaned.
                    </div>

                    <div class="mt-1 text-muted">
                        Timer rows:
                        {{ operatorProjectionMetricLabel('run_timer_entries', 'rows') }} rows across
                        {{ operatorProjectionMetricLabel('run_timer_entries', 'projected_runs') }} projected runs,
                        {{ operatorProjectionMetricLabel('run_timer_entries', 'missing_runs_with_timers') }} timer runs missing,
                        {{ operatorProjectionMetricLabel('run_timer_entries', 'stale_projected_runs') }} stale,
                        {{ operatorProjectionMetricLabel('run_timer_entries', 'orphaned') }} orphaned.
                    </div>

                    <div class="mt-1 text-muted">
                        Lineage rows:
                        {{ operatorProjectionMetricLabel('run_lineage_entries', 'rows') }} rows across
                        {{ operatorProjectionMetricLabel('run_lineage_entries', 'projected_runs') }} projected runs,
                        {{ operatorProjectionMetricLabel('run_lineage_entries', 'missing_runs_with_lineage') }} lineage runs missing,
                        {{ operatorProjectionMetricLabel('run_lineage_entries', 'stale_projected_runs') }} stale,
                        {{ operatorProjectionMetricLabel('run_lineage_entries', 'orphaned') }} orphaned.
                    </div>

                    <div class="mt-1 text-muted" v-if="operatorProjectionNeedsRebuild()">
                        Run <code>php artisan workflow:v2:rebuild-projections --needs-rebuild --prune-stale</code>
                        to refresh the Waterline projection bridge.
                    </div>
                </div>

                <div class="border-top p-4">
                    <small class="text-uppercase">Command Contract Normalization</small>

                    <div class="mt-2 text-muted">
                        {{ operatorMetricLabel('command_contracts', 'backfill_needed_runs') }} runs still need backfill,
                        {{ operatorMetricLabel('command_contracts', 'backfill_available_runs') }} normalizable on this build,
                        {{ operatorMetricLabel('command_contracts', 'backfill_unavailable_runs') }} unavailable.
                    </div>

                    <div class="mt-1 text-muted" v-if="operatorMetric('command_contracts', 'backfill_needed_runs')">
                        Run <code>php artisan workflow:v2:rebuild-projections --needs-rebuild --prune-stale</code>
                        to sweep untouched loadable preview-era command contracts alongside projection drift, or use
                        <code>php artisan workflow:v2:backfill-command-contracts --dry-run</code> and then rerun it
                        without <code>--dry-run</code> for a targeted contract-only pass while a compatible build is
                        still available. Opening selected-run detail or exporting selected-run history now normalizes
                        loadable runs one at a time.
                    </div>
                </div>

                <div class="border-top p-4">
                    <small class="text-uppercase">Repair Policy</small>

                    <div class="mt-2 text-muted">
                        Redispatch after {{ operatorPolicyMetricLabel('redispatch_after_seconds') }}s,
                        throttle worker sweeps for {{ operatorPolicyMetricLabel('loop_throttle_seconds') }}s,
                        scan {{ operatorPolicyMetricLabel('scan_limit') }} rows per pass using {{ operatorPolicyMetric('scan_strategy') || 'global scan' }}.
                    </div>

                    <div class="mt-2 text-muted" v-if="stats.operator_metrics.repair">
                        {{ operatorMetricLabel('repair', 'existing_task_candidates') }} existing task candidates,
                        {{ operatorMetricLabel('repair', 'missing_task_candidates') }} missing-task runs,
                        selects {{ operatorMetricLabel('repair', 'selected_existing_task_candidates') }} task candidates and
                        {{ operatorMetricLabel('repair', 'selected_missing_task_candidates') }} missing-task runs this pass,
                        oldest task candidate {{ operatorDurationMetricLabel('repair', 'max_task_candidate_age_ms') }},
                        oldest missing run {{ operatorDurationMetricLabel('repair', 'max_missing_run_age_ms') }}.
                    </div>

                    <div class="mt-1 text-muted" v-if="stats.operator_metrics.repair && stats.operator_metrics.repair.scan_pressure">
                        Repair scan limit reached on this snapshot. Increase scan limit or add workers before backlog age keeps growing.
                    </div>

                    <div class="mt-1 text-muted">
                        Use <code>php artisan workflow:v2:repair-pass --run-id=...</code> for one or more selected runs
                        or <code>--instance-id=...</code> to sweep one workflow instance with the same repair policy.
                    </div>

                    <div class="mt-2 text-muted" v-if="operatorRepairScopes().length">
                        <div v-for="scope in operatorRepairScopes()" :key="scope.scope_key">
                            <code>{{ operatorRepairScopeLabel(scope) }}</code>:
                            {{ scope.total_candidates.toLocaleString() }} candidates
                            ({{ scope.existing_task_candidates.toLocaleString() }} tasks,
                            {{ scope.missing_task_candidates.toLocaleString() }} missing runs),
                            selects {{ (scope.selected_total_candidates || 0).toLocaleString() }}
                            ({{ (scope.selected_existing_task_candidates || 0).toLocaleString() }} tasks,
                            {{ (scope.selected_missing_task_candidates || 0).toLocaleString() }} missing runs),
                            oldest {{ operatorRepairScopeDuration(scope, 'max_task_candidate_age_ms') }},
                            missing {{ operatorRepairScopeDuration(scope, 'max_missing_run_age_ms') }}.
                            <span v-if="scope.scan_limited_by_global_policy">Scan limited.</span>
                        </div>
                    </div>
                </div>

                <div class="border-top p-4">
                    <small class="text-uppercase">Update Wait Policy</small>

                    <div class="mt-2 text-muted">
                        Wait up to {{ operatorUpdateWaitMetricLabel('completion_timeout_seconds') }}s for completion responses,
                        polling every {{ operatorUpdateWaitMetricLabel('poll_interval_milliseconds') }}ms before returning an accepted lifecycle.
                    </div>
                </div>

                <div class="border-top p-4">
                    <small class="text-uppercase">Backend Capability</small>

                    <div class="mt-2">
                        <span :class="operatorBackend().supported ? 'badge badge-success' : 'badge badge-warning'">
                            {{ operatorBackendStatusLabel() }}
                        </span>

                        <span class="ml-2 text-muted">
                            Database {{ operatorBackendComponentLabel('database') }},
                            queue {{ operatorBackendComponentLabel('queue') }},
                            cache {{ operatorBackendComponentLabel('cache') }}.
                        </span>
                    </div>

                    <div class="mt-2 text-muted" v-if="operatorBackendIssues().length">
                        <div v-for="(issue, index) in operatorBackendIssues()" :key="operatorBackendIssueKey(issue, index)">
                            <strong>{{ issue.code || 'capability_issue' }}</strong>:
                            {{ issue.message || 'Capability issue detected.' }}
                        </div>
                    </div>

                    <div class="mt-2 text-muted" v-if="operatorMetric('tasks', 'claim_failed') || operatorMetric('backlog', 'claim_failed_runs')">
                        {{ operatorMetricLabel('tasks', 'claim_failed') }} task claims failed across
                        {{ operatorMetricLabel('backlog', 'claim_failed_runs') }} runs. Fix backend capability
                        issues before retrying those workers.
                    </div>
                </div>
            </div>
        </div>

    </div>
</template>
