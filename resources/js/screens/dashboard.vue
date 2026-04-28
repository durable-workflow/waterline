<template>
    <div class="wl-dashboard-view">
        <div v-if="!ready && !loadingError" class="wl-screen-state card card-bg-secondary">
            <div class="d-flex align-items-center justify-content-center">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin mr-2 fill-text-color">
                    <path d="M12 10a2 2 0 0 1-3.41 1.41A2 2 0 0 1 10 8V0a9.97 9.97 0 0 1 10 10h-8zm7.9 1.41A10 10 0 1 1 8.59.1v2.03a8 8 0 1 0 9.29 9.29h2.02zm-4.07 0a6 6 0 1 1-7.25-7.25v2.1a3.99 3.99 0 0 0-1.4 6.57 4 4 0 0 0 6.56-1.42h2.1z"></path>
                </svg>
                <span>Loading dashboard…</span>
            </div>
        </div>

        <div v-else-if="loadingError" class="wl-screen-state card card-bg-secondary wl-screen-state--error">
            <strong>Dashboard unavailable</strong>
            <span class="text-muted mt-2">{{ loadingError }}</span>
            <button class="btn btn-outline-primary btn-sm mt-3" @click="refreshNow">Retry</button>
        </div>

        <div v-else class="wl-dashboard-stack">
            <section class="wl-screen-hero">
                <div>
                    <p class="wl-screen-eyebrow">Workflow operations</p>
                    <h1 class="wl-screen-title">Dashboard</h1>
                    <p class="wl-screen-subtitle">
                        Fleet health, queue pressure, and repair posture for {{ engineSourceLabel() }} workflows.
                    </p>
                </div>

                <div class="wl-screen-hero__actions">
                    <span v-if="operatorMetrics && operatorMetrics.generated_at" class="wl-chip">
                        {{ operatorMetrics.generated_at }}
                    </span>

                    <button class="btn btn-outline-secondary btn-sm" @click="refreshNow">
                        Refresh
                    </button>
                </div>
            </section>

            <section v-if="needsAttention.total_alerts > 0" class="card wl-dashboard-alerts">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div>
                        <h5>Needs attention</h5>
                        <small class="text-muted">
                            {{ needsAttention.total_alerts }} active alert<span v-if="needsAttention.total_alerts !== 1">s</span>
                        </small>
                    </div>

                    <span class="wl-chip wl-chip--warning" v-if="needsAttention.has_critical">Critical</span>
                </div>

                <div class="card-body card-bg-secondary wl-dashboard-alerts__body">
                    <article
                        v-for="alert in needsAttention.alerts"
                        :key="alert.type"
                        class="wl-dashboard-alert"
                        :class="`is-${alert.severity || 'info'}`">
                        <div class="wl-dashboard-alert__title">{{ alert.message }}</div>
                        <div class="wl-dashboard-alert__action">{{ alert.action }}</div>
                    </article>
                </div>
            </section>

            <section class="wl-dashboard-summary-grid">
                <article v-for="tile in summaryTiles" :key="tile.label" class="card wl-summary-card">
                    <div class="card-body card-bg-secondary">
                        <div class="wl-summary-card__label">{{ tile.label }}</div>
                        <div class="wl-summary-card__value">{{ tile.value }}</div>
                        <div class="wl-summary-card__meta">{{ tile.meta }}</div>
                    </div>
                </article>
            </section>

            <section class="wl-dashboard-grid">
                <article class="card wl-dashboard-card wl-dashboard-card--wide">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <div>
                            <h5>Fleet trends</h5>
                            <small class="text-muted">Last 7 days, hourly resolution</small>
                        </div>

                        <span class="wl-chip">Completed vs. failed</span>
                    </div>

                    <div class="card-body card-bg-secondary">
                        <div v-if="fleetTrendsChartSeries.length" role="img" aria-label="Fleet trends chart showing completed and failed workflow volume over time.">
                            <apexchart
                                type="area"
                                height="320"
                                :options="fleetTrendsChartOptions"
                                :series="fleetTrendsChartSeries">
                            </apexchart>
                        </div>
                        <div v-else class="wl-empty-state">No trend data available yet.</div>
                    </div>
                </article>

                <article class="card wl-dashboard-card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <div>
                            <h5>Fleet overview</h5>
                            <small class="text-muted">Current posture and recent volume</small>
                        </div>

                        <span class="wl-chip" :class="operatorBackend().supported ? 'wl-chip--success' : 'wl-chip--warning'">
                            {{ operatorBackendStatusLabel() }}
                        </span>
                    </div>

                    <div class="card-body card-bg-secondary wl-dashboard-split">
                        <div>
                            <div class="wl-panel-subtitle">Current status</div>
                            <table class="table table-sm mb-0">
                                <tbody>
                                    <tr>
                                        <td>Running</td>
                                        <td class="text-right">{{ fleetMetric('current', 'running').toLocaleString() }}</td>
                                    </tr>
                                    <tr>
                                        <td>Failed</td>
                                        <td class="text-right text-danger">{{ fleetMetric('current', 'failed').toLocaleString() }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div>
                            <div class="wl-panel-subtitle">Recent trends</div>
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>Period</th>
                                        <th class="text-right">Completed</th>
                                        <th class="text-right">Failed</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Last hour</td>
                                        <td class="text-right text-success">{{ fleetMetric('trends', 'hour', 'completed').toLocaleString() }}</td>
                                        <td class="text-right text-danger">{{ fleetMetric('trends', 'hour', 'failed').toLocaleString() }}</td>
                                    </tr>
                                    <tr>
                                        <td>Last day</td>
                                        <td class="text-right text-success">{{ fleetMetric('trends', 'day', 'completed').toLocaleString() }}</td>
                                        <td class="text-right text-danger">{{ fleetMetric('trends', 'day', 'failed').toLocaleString() }}</td>
                                    </tr>
                                    <tr>
                                        <td>Last week</td>
                                        <td class="text-right text-success">{{ fleetMetric('trends', 'week', 'completed').toLocaleString() }}</td>
                                        <td class="text-right text-danger">{{ fleetMetric('trends', 'week', 'failed').toLocaleString() }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="wl-operator-backend">
                            <div class="wl-panel-subtitle">Backend capability</div>
                            <div v-if="operatorBackendSeverity()" class="wl-operator-backend__summary">
                                Severity {{ operatorBackendSeverity() }}
                            </div>
                            <div class="wl-operator-backend__summary">
                                Database {{ operatorBackendComponentLabel('database') }}
                            </div>
                            <div class="wl-operator-backend__summary">
                                Cache {{ operatorBackendComponentLabel('cache') }}
                            </div>
                            <div v-if="operatorBackendIssues().length" class="wl-operator-backend__issues">
                                <div v-for="(issue, index) in operatorBackendIssues()" :key="index">
                                    {{ issue.summary || issue.code || issue.component || 'Capability issue' }}
                                </div>
                            </div>
                        </div>
                    </div>
                </article>

                <article class="card wl-dashboard-card wl-dashboard-card--wide">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <div>
                            <h5>Workflow type health</h5>
                            <small class="text-muted">Top workflow types by volume</small>
                        </div>

                        <span class="wl-chip">{{ topWorkflowTypes.length }} tracked types</span>
                    </div>

                    <div class="card-body card-bg-secondary">
                        <div v-if="topWorkflowTypes.length" class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>Workflow type</th>
                                        <th class="text-right">Runs</th>
                                        <th class="text-right">Pass rate</th>
                                        <th class="text-right">Median duration</th>
                                        <th class="text-right">Errors</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="type in topWorkflowTypes" :key="type.workflow_type">
                                        <td>
                                            <code>{{ workflowLabel(type.workflow_type) }}</code>
                                        </td>
                                        <td class="text-right">{{ Number(type.total_runs || 0).toLocaleString() }}</td>
                                        <td class="text-right">
                                            <span class="badge" :class="workflowBadgeClass(type)">
                                                {{ Number(type.pass_rate || 0).toFixed(1) }}%
                                            </span>
                                        </td>
                                        <td class="text-right">
                                            {{ type.median_duration_ms ? formatDuration(type.median_duration_ms) : '-' }}
                                        </td>
                                        <td class="text-right">
                                            <span v-if="Number(type.error_count || 0) > 0" class="text-danger">
                                                {{ Number(type.error_count).toLocaleString() }}
                                            </span>
                                            <span v-else class="text-muted">-</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="wl-empty-state">No workflow type health data available yet.</div>
                    </div>
                </article>

                <article class="card wl-dashboard-card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <div>
                            <h5>Type breakdown</h5>
                            <small class="text-muted">Pass rate and latency for the busiest workflows</small>
                        </div>
                    </div>

                    <div class="card-body card-bg-secondary">
                        <div v-if="topWorkflowTypes.length" class="wl-dashboard-chart-stack">
                            <div>
                                <div class="wl-panel-subtitle">Pass rate</div>
                                <apexchart
                                    type="bar"
                                    height="220"
                                    :options="passRateChartOptions"
                                    :series="passRateChartSeries">
                                </apexchart>
                            </div>

                            <div>
                                <div class="wl-panel-subtitle">Median duration</div>
                                <apexchart
                                    type="bar"
                                    height="220"
                                    :options="durationChartOptions"
                                    :series="durationChartSeries">
                                </apexchart>
                            </div>
                        </div>
                        <div v-else class="wl-empty-state">No workflow health charts available yet.</div>
                    </div>
                </article>

                <article class="card wl-dashboard-card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <div>
                            <h5>Overview</h5>
                            <small class="text-muted">Throughput, outliers, and exception hotspots</small>
                        </div>
                    </div>

                    <div class="card-body card-bg-secondary">
                        <div class="wl-overview-grid">
                            <div v-for="tile in overviewTiles" :key="tile.label" class="wl-overview-tile">
                                <div class="wl-overview-tile__label">{{ tile.label }}</div>
                                <div class="wl-overview-tile__value">{{ tile.value }}</div>
                                <div class="wl-overview-tile__meta">
                                    <router-link v-if="tile.route && tile.linkLabel" :to="tile.route">
                                        {{ tile.linkLabel }}
                                    </router-link>
                                    <span v-else>{{ tile.meta }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </article>

                <article class="card wl-dashboard-card wl-dashboard-card--wide" v-if="operatorMetrics">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <div>
                            <h5>Operator metrics</h5>
                            <small class="text-muted">Backlog, projection health, and recovery posture</small>
                        </div>

                        <span class="wl-chip" v-if="operatorMetrics.generated_at">{{ operatorMetrics.generated_at }}</span>
                    </div>

                    <div class="card-body card-bg-secondary wl-operator-grid">
                        <div class="wl-operator-metrics-grid">
                            <div class="wl-operator-metric">
                                <div class="wl-operator-metric__label">Runnable tasks</div>
                                <div class="wl-operator-metric__value">{{ operatorMetricLabel('backlog', 'runnable_tasks') }}</div>
                                <div class="wl-operator-metric__meta">
                                    {{ operatorMetricLabel('backlog', 'delayed_tasks') }} delayed,
                                    {{ operatorMetricLabel('backlog', 'leased_tasks') }} leased
                                </div>
                                <div class="wl-operator-metric__meta">
                                    {{ operatorMetricLabel('backlog', 'tasks_added_last_minute') }} added last minute,
                                    {{ operatorMetricLabel('backlog', 'tasks_dispatched_last_minute') }} dispatched last minute
                                </div>
                                <div v-if="operatorReadyDueAgeAvailable()" class="wl-operator-metric__meta">
                                    oldest ready {{ operatorDurationMetricLabel('tasks', 'max_ready_due_age_ms') }} waiting
                                    <template v-if="operatorReadyDueOldestAt()">
                                        (since {{ operatorReadyDueOldestAt() }})
                                    </template>
                                </div>
                            </div>
                            <div class="wl-operator-metric">
                                <div class="wl-operator-metric__label">Unhealthy tasks</div>
                                <div class="wl-operator-metric__value">{{ operatorMetricLabel('backlog', 'unhealthy_tasks') }}</div>
                                <div class="wl-operator-metric__meta">
                                    {{ operatorMetricLabel('tasks', 'dispatch_overdue') }} dispatch overdue,
                                    {{ operatorMetricLabel('tasks', 'lease_expired') }} lease expired
                                </div>
                                <div v-if="operatorUnhealthyAgeAvailable()" class="wl-operator-metric__meta">
                                    worst {{ operatorDurationMetricLabel('tasks', 'max_unhealthy_age_ms') }} unhealthy
                                    <template v-if="operatorUnhealthyOldestAt()">
                                        (since {{ operatorUnhealthyOldestAt() }})
                                    </template>
                                </div>
                                <div v-if="operatorStuckLeaseAgeAvailable()" class="wl-operator-metric__meta">
                                    oldest lease {{ operatorDurationMetricLabel('tasks', 'max_lease_expired_age_ms') }} expired
                                    <template v-if="operatorStuckLeaseOldestExpiredAt()">
                                        (since {{ operatorStuckLeaseOldestExpiredAt() }})
                                    </template>
                                </div>
                                <div v-if="operatorDispatchOverdueAgeAvailable()" class="wl-operator-metric__meta">
                                    oldest overdue {{ operatorDurationMetricLabel('tasks', 'max_dispatch_overdue_age_ms') }} waiting dispatch
                                    <template v-if="operatorDispatchOverdueOldestSince()">
                                        (since {{ operatorDispatchOverdueOldestSince() }})
                                    </template>
                                </div>
                                <div v-if="operatorDispatchFailedAgeAvailable()" class="wl-operator-metric__meta">
                                    oldest dispatch {{ operatorDurationMetricLabel('tasks', 'max_dispatch_failed_age_ms') }} failed
                                    <template v-if="operatorDispatchFailedOldestAt()">
                                        (since {{ operatorDispatchFailedOldestAt() }})
                                    </template>
                                </div>
                            </div>
                            <div class="wl-operator-metric">
                                <div class="wl-operator-metric__label">Repair needed runs</div>
                                <div class="wl-operator-metric__value">{{ operatorMetricLabel('backlog', 'repair_needed_runs') }}</div>
                                <div v-if="operatorRunRepairNeededAgeAvailable()" class="wl-operator-metric__meta">
                                    oldest {{ operatorDurationMetricLabel('runs', 'max_repair_needed_age_ms') }} stuck
                                    <template v-if="operatorRunRepairNeededOldestAt()">
                                        (since {{ operatorRunRepairNeededOldestAt() }})
                                    </template>
                                </div>
                            </div>
                            <div class="wl-operator-metric">
                                <div class="wl-operator-metric__label">Claim failed runs</div>
                                <div class="wl-operator-metric__value">{{ operatorMetricLabel('backlog', 'claim_failed_runs') }}</div>
                                <div v-if="operatorClaimFailedAgeAvailable()" class="wl-operator-metric__meta">
                                    oldest claim {{ operatorDurationMetricLabel('tasks', 'max_claim_failed_age_ms') }} failed
                                    <template v-if="operatorClaimFailedOldestAt()">
                                        (since {{ operatorClaimFailedOldestAt() }})
                                    </template>
                                </div>
                            </div>
                            <div class="wl-operator-metric">
                                <div class="wl-operator-metric__label">Compatibility blocked</div>
                                <div class="wl-operator-metric__value">{{ operatorMetricLabel('backlog', 'compatibility_blocked_runs') }}</div>
                                <div v-if="operatorCompatibilityBlockedAgeAvailable()" class="wl-operator-metric__meta">
                                    oldest {{ operatorDurationMetricLabel('backlog', 'max_compatibility_blocked_age_ms') }} behind
                                    <template v-if="operatorCompatibilityBlockedOldestStartedAt()">
                                        (since {{ operatorCompatibilityBlockedOldestStartedAt() }})
                                    </template>
                                </div>
                            </div>
                            <div class="wl-operator-metric" v-if="operatorRunWaitAvailable()">
                                <div class="wl-operator-metric__label">Waiting runs</div>
                                <div class="wl-operator-metric__value">{{ operatorMetricLabel('runs', 'waiting') }}</div>
                                <div v-if="operatorRunWaitAgeAvailable()" class="wl-operator-metric__meta">
                                    oldest {{ operatorDurationMetricLabel('runs', 'max_wait_age_ms') }} parked
                                    <template v-if="operatorRunWaitOldestStartedAt()">
                                        (since {{ operatorRunWaitOldestStartedAt() }})
                                    </template>
                                </div>
                            </div>
                            <div class="wl-operator-metric">
                                <div class="wl-operator-metric__label">Active workers</div>
                                <div class="wl-operator-metric__value">{{ operatorMetricLabel('workers', 'active_workers') }}</div>
                                <div class="wl-operator-metric__meta">{{ operatorMetricLabel('workers', 'active_worker_scopes') }} queue scopes</div>
                            </div>
                            <div class="wl-operator-metric">
                                <div class="wl-operator-metric__label">Pending starts</div>
                                <div class="wl-operator-metric__value">{{ operatorMetricLabel('starts', 'pending_runs') }}</div>
                            </div>
                            <div class="wl-operator-metric">
                                <div class="wl-operator-metric__label">Start commands</div>
                                <div class="wl-operator-metric__value">{{ operatorMetricLabel('starts', 'pending_commands') }}</div>
                            </div>
                            <div class="wl-operator-metric">
                                <div class="wl-operator-metric__label">Due start tasks</div>
                                <div class="wl-operator-metric__value">{{ operatorMetricLabel('starts', 'ready_tasks') }}</div>
                            </div>
                            <div class="wl-operator-metric">
                                <div class="wl-operator-metric__label">Max start latency</div>
                                <div class="wl-operator-metric__value">{{ operatorDurationMetricLabel('starts', 'max_pending_ms') }}</div>
                            </div>
                        </div>

                        <section class="wl-operator-section">
                            <div class="wl-panel-subtitle">Activity attempts</div>
                            <p>
                                {{ operatorMetricLabel('activities', 'retrying') }} retrying,
                                {{ operatorMetricLabel('activities', 'running') }} running,
                                {{ operatorMetricLabel('activities', 'failed_attempts') }} failed attempts,
                                max {{ operatorMetricLabel('activities', 'max_attempt_count') }} attempts.
                            </p>
                            <p v-if="operatorRetryingActivityAgeAvailable()">
                                Oldest retrying activity {{ operatorDurationMetricLabel('activities', 'max_retrying_age_ms') }} behind
                                <template v-if="operatorRetryingActivityOldestStartedAt()">
                                    (since {{ operatorRetryingActivityOldestStartedAt() }})
                                </template>.
                            </p>
                            <p v-if="operatorActivityTimeoutOverdueAvailable()">
                                {{ operatorMetricLabel('activities', 'timeout_overdue') }} timeout overdue,
                                worst {{ operatorDurationMetricLabel('activities', 'max_timeout_overdue_age_ms') }} past deadline
                                <template v-if="operatorActivityTimeoutOverdueOldestAt()">
                                    (since {{ operatorActivityTimeoutOverdueOldestAt() }})
                                </template>.
                            </p>
                        </section>

                        <section class="wl-operator-section">
                            <div class="wl-panel-subtitle">Projection health</div>
                            <p>
                                Run summaries: {{ operatorProjectionMetricLabel('summaries') }} summaries for
                                {{ operatorProjectionMetricLabel('runs') }} runs,
                                {{ operatorProjectionMetricLabel('missing') }} missing,
                                {{ operatorProjectionMetricLabel('orphaned') }} orphaned,
                                {{ operatorProjectionMetricLabel('stale') }} stale.
                            </p>
                            <p v-if="operatorRunSummaryMissingAgeAvailable()">
                                Oldest run-summary missing run {{ operatorProjectionDurationMetricLabel('run_summaries', 'max_missing_run_age_ms') }} behind
                                <template v-if="operatorRunSummaryMissingOldestStartedAt()">
                                    (since {{ operatorRunSummaryMissingOldestStartedAt() }})
                                </template>.
                            </p>
                            <p>
                                Wait rows: {{ operatorProjectionMetricLabel('run_waits', 'rows') }} rows across
                                {{ operatorProjectionMetricLabel('run_waits', 'projected_runs') }} runs,
                                {{ operatorProjectionMetricLabel('run_waits', 'runs_with_waits') }} canonical waits,
                                {{ operatorProjectionMetricLabel('run_waits', 'missing_runs_with_waits') }} missing,
                                {{ operatorProjectionMetricLabel('run_waits', 'stale_projected_runs') }} stale,
                                {{ operatorProjectionMetricLabel('run_waits', 'orphaned') }} orphaned.
                            </p>
                            <p>
                                Timeline rows: {{ operatorProjectionMetricLabel('run_timeline_entries', 'rows') }} rows for
                                {{ operatorProjectionMetricLabel('run_timeline_entries', 'history_events') }} history events,
                                {{ operatorProjectionMetricLabel('run_timeline_entries', 'missing_runs_with_history') }} missing,
                                {{ operatorProjectionMetricLabel('run_timeline_entries', 'stale_projected_runs') }} stale,
                                {{ operatorProjectionMetricLabel('run_timeline_entries', 'orphaned') }} orphaned.
                            </p>
                            <p>
                                Timer rows: {{ operatorProjectionMetricLabel('run_timer_entries', 'rows') }} rows across
                                {{ operatorProjectionMetricLabel('run_timer_entries', 'projected_runs') }} projected runs,
                                {{ operatorProjectionMetricLabel('run_timer_entries', 'missing_runs_with_timers') }} missing,
                                {{ operatorProjectionMetricLabel('run_timer_entries', 'stale_projected_runs') }} stale,
                                {{ operatorProjectionMetricLabel('run_timer_entries', 'orphaned') }} orphaned.
                            </p>
                        </section>

                        <section class="wl-operator-section">
                            <div class="wl-panel-subtitle">Repair policy</div>
                            <p>
                                Redispatch after {{ operatorPolicyMetricLabel('redispatch_after_seconds') }} seconds,
                                throttle worker sweeps for {{ operatorPolicyMetricLabel('loop_throttle_seconds') }} seconds,
                                scan {{ operatorPolicyMetricLabel('scan_limit') }} rows per pass,
                                backoff capped at {{ operatorPolicyMetricLabel('failure_backoff_max_seconds') }} seconds.
                            </p>
                            <div v-if="operatorRepairScopes().length" class="wl-inline-list">
                                <span v-for="scope in operatorRepairScopes()" :key="operatorRepairScopeLabel(scope)">
                                    {{ operatorRepairScopeLabel(scope) }}
                                </span>
                            </div>
                        </section>

                        <section class="wl-operator-section">
                            <div class="wl-panel-subtitle">Stuck-run detectors</div>
                            <p>
                                {{ operatorMetricLabel('repair', 'missing_task_candidates') }} runs missing a next task
                                ({{ operatorMetricLabel('repair', 'selected_missing_task_candidates') }} selected this pass),
                                oldest {{ operatorDurationMetricLabel('repair', 'max_missing_run_age_ms') }} behind.
                            </p>
                            <p v-if="operatorRepairOldestStartedAt()">
                                Oldest missing-task run started at {{ operatorRepairOldestStartedAt() }}.
                            </p>
                        </section>

                        <section class="wl-operator-section">
                            <div class="wl-panel-subtitle">Scheduler-role health</div>
                            <p v-if="!operatorSchedulesAvailable()" class="text-muted">
                                No scheduler-role metrics exposed by the current workflow engine.
                            </p>
                            <template v-else>
                                <p>
                                    {{ operatorMetricLabel('schedules', 'active') }} active schedules,
                                    {{ operatorMetricLabel('schedules', 'paused') }} paused,
                                    {{ operatorMetricLabel('schedules', 'missed') }} overdue this tick,
                                    oldest {{ operatorDurationMetricLabel('schedules', 'max_overdue_ms') }} behind.
                                </p>
                                <p v-if="operatorScheduleOldestOverdueAt()">
                                    Oldest overdue fire due at {{ operatorScheduleOldestOverdueAt() }}.
                                </p>
                                <p>
                                    {{ operatorMetricLabel('schedules', 'fires_total') }} fires recorded against active schedules,
                                    {{ operatorMetricLabel('schedules', 'failures_total') }} failures.
                                </p>
                            </template>
                        </section>

                        <section class="wl-operator-section">
                            <div class="wl-panel-subtitle">Matching-role (this node)</div>
                            <p v-if="!operatorMatchingRoleAvailable()" class="text-muted">
                                No matching-role metrics exposed by the current workflow engine.
                            </p>
                            <template v-else>
                                <p>
                                    Shape <code>{{ operatorMatchingRoleShape() }}</code>,
                                    queue-wake {{ operatorMatchingRoleQueueWakeEnabled() ? 'enabled' : 'disabled' }},
                                    task dispatch <code>{{ operatorMatchingRoleTaskDispatchMode() }}</code>.
                                </p>
                                <p v-if="operatorMatchingRoleContractAvailable()">
                                    Partitions by <code>{{ operatorMatchingRolePartitionPrimitivesLabel() }}</code>,
                                    backpressure <code>{{ operatorMatchingRoleBackpressureModel() }}</code>.
                                </p>
                                <p class="text-muted">
                                    Single-process scope &mdash; read one snapshot per node to see the full deployment.
                                </p>
                            </template>
                        </section>

                        <section class="wl-operator-section">
                            <div class="wl-panel-subtitle">Worker compatibility fleet</div>
                            <p v-if="!operatorWorkerFleet().length" class="text-muted">
                                No active worker compatibility heartbeats in this namespace.
                            </p>
                            <div v-else class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead>
                                        <tr>
                                            <th>Worker</th>
                                            <th>Queue scope</th>
                                            <th>Supports</th>
                                            <th>Required</th>
                                            <th>Source</th>
                                            <th>Heartbeat</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="entry in operatorWorkerFleet()" :key="operatorWorkerFleetKey(entry)">
                                            <td><code>{{ entry.worker_id }}</code></td>
                                            <td>{{ operatorWorkerFleetScope(entry) }}</td>
                                            <td>{{ (entry.supported || []).join(', ') || '—' }}</td>
                                            <td>
                                                <span v-if="entry.supports_required" class="wl-chip">yes</span>
                                                <span v-else class="wl-chip wl-chip--warning">no</span>
                                            </td>
                                            <td>{{ entry.source || '—' }}</td>
                                            <td>{{ entry.recorded_at || '—' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <section class="wl-operator-section">
                            <div class="wl-panel-subtitle">Update wait policy</div>
                            <p>
                                Wait up to {{ operatorUpdateWaitMetricLabel('completion_timeout_ms') }} ms for completion responses,
                                polling every {{ operatorUpdateWaitMetricLabel('poll_interval_ms') }} ms before returning an accepted lifecycle.
                            </p>
                        </section>

                        <section class="wl-operator-section">
                            <div class="wl-panel-subtitle">Structural limits</div>
                            <div v-if="structuralLimitRows().length" class="wl-structural-limits">
                                <div v-for="entry in structuralLimitRows()" :key="entry.key" class="wl-structural-limits__row">
                                    <span>{{ entry.label }}</span>
                                    <span>{{ entry.value }}</span>
                                </div>
                            </div>
                            <p v-else>No structural limit snapshot available.</p>
                        </section>
                    </div>
                </article>
            </section>
        </div>
    </div>
</template>

<script>
import moment from 'moment';

export default {
    data() {
        return {
            stats: {},
            ready: false,
            loadingError: null,
            timeout: null,
        };
    },

    computed: {
        needsAttention() {
            return this.stats.needs_attention || {
                total_alerts: 0,
                has_critical: false,
                alerts: [],
            };
        },

        workflowTypes() {
            return Array.isArray(this.stats.workflow_type_health)
                ? this.stats.workflow_type_health
                : [];
        },

        topWorkflowTypes() {
            return this.workflowTypes.slice(0, 6);
        },

        operatorMetrics() {
            return this.stats.operator_metrics || null;
        },

        chartThemeMode() {
            return this.$root && this.$root.theme === 'light' ? 'light' : 'dark';
        },

        summaryTiles() {
            return [
                {
                    label: 'Running now',
                    value: this.fleetMetric('current', 'running').toLocaleString(),
                    meta: `${this.fleetMetric('current', 'failed').toLocaleString()} failed in the active fleet`,
                },
                {
                    label: 'Completed last day',
                    value: this.fleetMetric('trends', 'day', 'completed').toLocaleString(),
                    meta: `${this.fleetMetric('trends', 'hour', 'completed').toLocaleString()} completed in the last hour`,
                },
                {
                    label: 'Flows per minute',
                    value: this.formatRate(this.stats.flows_per_minute),
                    meta: `${Number(this.stats.flows_past_hour || 0).toLocaleString()} flows in the last hour`,
                },
                {
                    label: 'Active workers',
                    value: this.operatorMetricLabel('workers', 'active_workers'),
                    meta: `${this.operatorMetricLabel('workers', 'active_worker_scopes')} queue scopes`,
                },
            ];
        },

        overviewTiles() {
            return [
                {
                    label: 'Flows past hour',
                    value: Number(this.stats.flows_past_hour || 0).toLocaleString(),
                    meta: 'Recent run volume',
                },
                {
                    label: 'Exceptions past hour',
                    value: Number(this.stats.exceptions_past_hour || 0).toLocaleString(),
                    meta: 'Recent failure pressure',
                },
                {
                    label: 'Failed flows past week',
                    value: Number(this.stats.failed_flows_past_week || 0).toLocaleString(),
                    meta: 'Longer trend window',
                },
                {
                    label: 'Total flows',
                    value: Number(this.stats.flows || 0).toLocaleString(),
                    meta: 'All recorded workflow runs',
                },
                {
                    label: 'Max wait time',
                    value: this.stats.max_wait_time_workflow ? this.waitAge(this.stats.max_wait_time_workflow) : '-',
                    meta: this.stats.max_wait_time_workflow ? 'Most delayed open run' : 'No waiting runs',
                    route: this.stats.max_wait_time_workflow ? { name: this.routeName(this.stats.max_wait_time_workflow), params: { flowId: this.stats.max_wait_time_workflow.id } } : null,
                    linkLabel: this.stats.max_wait_time_workflow ? this.workflowLabel(this.stats.max_wait_time_workflow.class) : null,
                },
                {
                    label: 'Max duration',
                    value: this.stats.max_duration_workflow ? this.flowDuration(this.stats.max_duration_workflow) : '-',
                    meta: this.stats.max_duration_workflow ? 'Longest completed run' : 'No completed runs',
                    route: this.stats.max_duration_workflow ? { name: this.routeName(this.stats.max_duration_workflow), params: { flowId: this.stats.max_duration_workflow.id } } : null,
                    linkLabel: this.stats.max_duration_workflow ? this.workflowLabel(this.stats.max_duration_workflow.class) : null,
                },
                {
                    label: 'Max exceptions',
                    value: this.stats.max_exceptions_workflow ? this.exceptionCount(this.stats.max_exceptions_workflow).toLocaleString() : '0',
                    meta: this.stats.max_exceptions_workflow ? 'Run with the most exception rows' : 'No exception-heavy runs',
                    route: this.stats.max_exceptions_workflow ? { name: this.routeName(this.stats.max_exceptions_workflow), params: { flowId: this.stats.max_exceptions_workflow.id } } : null,
                    linkLabel: this.stats.max_exceptions_workflow ? this.workflowLabel(this.stats.max_exceptions_workflow.class) : null,
                },
                {
                    label: 'Projection rebuilds needed',
                    value: this.operatorProjectionNeedsRebuild().toLocaleString(),
                    meta: 'Outstanding projection normalization work',
                },
            ];
        },

        fleetTrendsChartOptions() {
            return {
                chart: {
                    type: 'area',
                    stacked: false,
                    toolbar: {
                        show: false,
                    },
                    zoom: {
                        enabled: false,
                    },
                    animations: {
                        easing: 'easeinout',
                    },
                },
                theme: {
                    mode: this.chartThemeMode,
                },
                colors: ['#28c76f', '#ff6b6b'],
                dataLabels: {
                    enabled: false,
                },
                stroke: {
                    curve: 'smooth',
                    width: 2.4,
                },
                fill: {
                    type: 'gradient',
                    gradient: {
                        shadeIntensity: 0.5,
                        opacityFrom: 0.35,
                        opacityTo: 0.05,
                    },
                },
                xaxis: {
                    type: 'datetime',
                    labels: {
                        datetimeUTC: false,
                    },
                },
                yaxis: {
                    min: 0,
                    labels: {
                        formatter: (value) => Math.round(value).toString(),
                    },
                },
                legend: {
                    position: 'top',
                    horizontalAlign: 'left',
                },
                tooltip: {
                    x: {
                        format: 'MMM dd, HH:mm',
                    },
                    y: {
                        formatter(value) {
                            return `${Math.round(value)} workflows`;
                        },
                    },
                },
                grid: {
                    borderColor: this.chartThemeMode === 'dark' ? '#303030' : '#d8dee6',
                },
            };
        },

        fleetTrendsChartSeries() {
            const series = this.stats.fleet_trends_series;

            if (!series || !Array.isArray(series.timestamps) || series.timestamps.length === 0) {
                return [];
            }

            return [
                {
                    name: 'Completed',
                    data: series.timestamps.map((timestamp, index) => ({
                        x: timestamp,
                        y: Number((series.completed || [])[index] || 0),
                    })),
                },
                {
                    name: 'Failed',
                    data: series.timestamps.map((timestamp, index) => ({
                        x: timestamp,
                        y: Number((series.failed || [])[index] || 0),
                    })),
                },
            ];
        },

        passRateChartOptions() {
            return {
                chart: {
                    type: 'bar',
                    toolbar: { show: false },
                },
                theme: {
                    mode: this.chartThemeMode,
                },
                plotOptions: {
                    bar: {
                        horizontal: true,
                        borderRadius: 6,
                        barHeight: '48%',
                    },
                },
                colors: ['#28c76f'],
                dataLabels: {
                    enabled: true,
                    formatter(value) {
                        return `${Number(value).toFixed(1)}%`;
                    },
                },
                xaxis: {
                    categories: this.topWorkflowTypes.map((type) => this.workflowLabel(type.workflow_type)),
                    max: 100,
                },
                yaxis: {
                    labels: {
                        maxWidth: 160,
                    },
                },
                grid: {
                    borderColor: this.chartThemeMode === 'dark' ? '#303030' : '#d8dee6',
                },
            };
        },

        passRateChartSeries() {
            return [{
                name: 'Pass rate',
                data: this.topWorkflowTypes.map((type) => Number(type.pass_rate || 0)),
            }];
        },

        durationChartOptions() {
            return {
                chart: {
                    type: 'bar',
                    toolbar: { show: false },
                },
                theme: {
                    mode: this.chartThemeMode,
                },
                plotOptions: {
                    bar: {
                        horizontal: true,
                        borderRadius: 6,
                        barHeight: '48%',
                    },
                },
                colors: ['#7c6cf6'],
                dataLabels: {
                    enabled: true,
                    formatter: (value) => this.formatDuration(value),
                },
                xaxis: {
                    categories: this.topWorkflowTypes.map((type) => this.workflowLabel(type.workflow_type)),
                    labels: {
                        formatter: (value) => this.formatDuration(value),
                    },
                },
                yaxis: {
                    labels: {
                        maxWidth: 160,
                    },
                },
                grid: {
                    borderColor: this.chartThemeMode === 'dark' ? '#303030' : '#d8dee6',
                },
            };
        },

        durationChartSeries() {
            return [{
                name: 'Median duration',
                data: this.topWorkflowTypes.map((type) => Number(type.median_duration_ms || 0)),
            }];
        },
    },

    mounted() {
        moment.relativeTimeThreshold('ss', 1);

        document.title = 'Waterline - Dashboard';

        this.refreshStatsPeriodically();
    },

    beforeDestroy() {
        clearTimeout(this.timeout);
    },

    methods: {
        loadStats() {
            return this.$http.get(Waterline.basePath + '/api/stats')
                .then((response) => {
                    this.stats = response.data || {};
                    this.loadingError = null;
                });
        },

        refreshStatsPeriodically() {
            clearTimeout(this.timeout);

            return this.loadStats()
                .then(() => {
                    this.ready = true;

                    if (this.$root.autoLoadsNewEntries) {
                        this.timeout = setTimeout(() => {
                            this.refreshStatsPeriodically();
                        }, 5000);
                    }
                })
                .catch((error) => {
                    this.ready = false;
                    this.loadingError = this.dashboardErrorMessage(error);
                });
        },

        refreshNow() {
            this.ready = false;

            return this.refreshStatsPeriodically();
        },

        dashboardErrorMessage(error) {
            if (error && error.code === 'ECONNABORTED') {
                return 'The request timed out before Waterline could load dashboard metrics.';
            }

            if (error && error.response && error.response.status) {
                const status = error.response.status;
                const message = error.response.data && error.response.data.message;

                return message
                    ? `Request failed with HTTP ${status}: ${message}`
                    : `Request failed with HTTP ${status}.`;
            }

            if (error && error.request) {
                return 'Waterline could not reach the dashboard endpoint.';
            }

            if (error && error.message) {
                return error.message;
            }

            return 'Waterline could not load dashboard metrics.';
        },

        formatRate(value) {
            const numeric = Number(value || 0);

            if (!Number.isFinite(numeric)) {
                return '0';
            }

            if (numeric >= 10) {
                return numeric.toFixed(0);
            }

            if (numeric >= 1) {
                return numeric.toFixed(1);
            }

            return numeric.toFixed(3).replace(/0+$/, '').replace(/\.$/, '');
        },

        workflowLabel(type) {
            return this.flowBaseName(type || 'UnknownWorkflow');
        },

        workflowBadgeClass(type) {
            const passRate = Number(type && type.pass_rate ? type.pass_rate : 0);

            if (passRate >= 95) {
                return 'badge-success';
            }

            if (passRate >= 80) {
                return 'badge-warning';
            }

            return 'badge-danger';
        },

        routeName(flow) {
            const type = ['failed', 'cancelled', 'terminated', 'completed'].includes(flow.status)
                ? flow.status
                : (flow.status_bucket || 'running');

            return type + '-flows-preview';
        },

        waitAge(flow) {
            return flow && flow.wait_started_at
                ? this.durationBetween(flow.wait_started_at, new Date())
                : '-';
        },

        flowDuration(flow) {
            const start = flow.started_at || flow.created_at;
            const end = flow.closed_at || flow.updated_at;

            if (!start || !end) {
                return '-';
            }

            return this.durationBetween(start, end);
        },

        exceptionCount(flow) {
            return flow && (flow.exceptions_count ?? flow.exception_count ?? 0);
        },

        fleetMetric(section, period, key = null) {
            const fleet = this.stats.fleet_overview || {};

            if (!fleet[section]) {
                return 0;
            }

            if (key) {
                return (fleet[section][period] && fleet[section][period][key]) || 0;
            }

            return fleet[section][period] || 0;
        },

        operatorMetric(section, key) {
            const metrics = this.operatorMetrics || {};
            const group = metrics[section] || {};

            return group[key] || 0;
        },

        operatorMetricLabel(section, key) {
            return this.operatorMetric(section, key).toLocaleString();
        },

        operatorDurationMetricLabel(section, key) {
            const value = this.operatorMetric(section, key);

            return value > 0 ? moment.duration(value).humanize() : '-';
        },

        operatorPolicyMetric(key) {
            const policy = (this.operatorMetrics && this.operatorMetrics.repair_policy) || {};

            return policy[key] || 0;
        },

        operatorPolicyMetricLabel(key) {
            return this.operatorPolicyMetric(key).toLocaleString();
        },

        operatorUpdateWaitMetric(key) {
            const policy = (this.operatorMetrics && this.operatorMetrics.update_wait) || {};

            return policy[key] || 0;
        },

        operatorUpdateWaitMetricLabel(key) {
            return this.operatorUpdateWaitMetric(key).toLocaleString();
        },

        operatorProjectionMetric(group, key = null) {
            let normalizedGroup = group;
            let normalizedKey = key;

            if (normalizedKey === null) {
                normalizedKey = normalizedGroup;
                normalizedGroup = 'run_summaries';
            }

            const projections = (this.operatorMetrics && this.operatorMetrics.projections) || {};
            const projection = projections[normalizedGroup] || {};

            return projection[normalizedKey] || 0;
        },

        operatorProjectionMetricLabel(group, key = null) {
            return this.operatorProjectionMetric(group, key).toLocaleString();
        },

        operatorProjectionDurationMetricLabel(group, key) {
            const value = this.operatorProjectionMetric(group, key);

            return value > 0 ? moment.duration(value).humanize() : '-';
        },

        operatorRunSummaryMissingAgeAvailable() {
            const projections = (this.operatorMetrics && this.operatorMetrics.projections) || {};
            const runSummaries = projections.run_summaries || {};

            if (runSummaries.max_missing_run_age_ms === undefined
                || runSummaries.max_missing_run_age_ms === null) {
                return false;
            }

            return Number(runSummaries.missing || 0) > 0
                || Number(runSummaries.max_missing_run_age_ms || 0) > 0;
        },

        operatorRunSummaryMissingOldestStartedAt() {
            const projections = (this.operatorMetrics && this.operatorMetrics.projections) || {};
            const runSummaries = projections.run_summaries || {};

            return runSummaries.oldest_missing_run_started_at || null;
        },

        operatorProjectionNeedsRebuild() {
            return this.operatorProjectionMetric('run_summaries', 'needs_rebuild')
                + this.operatorProjectionMetric('run_waits', 'needs_rebuild')
                + this.operatorProjectionMetric('run_timeline_entries', 'needs_rebuild')
                + this.operatorProjectionMetric('run_timer_entries', 'needs_rebuild')
                + this.operatorProjectionMetric('run_lineage_entries', 'needs_rebuild');
        },

        operatorBackend() {
            return (this.operatorMetrics && this.operatorMetrics.backend) || {};
        },

        operatorBackendStatusLabel() {
            return this.operatorBackend().supported ? 'Supported' : 'Needs attention';
        },

        operatorBackendComponentLabel(component) {
            const backend = this.operatorBackend();
            const detail = backend[component] || {};

            if (component === 'cache') {
                return [detail.store || 'unknown', detail.driver || 'unknown'].join('/');
            }

            return [detail.connection || 'unknown', detail.driver || 'unknown'].join('/');
        },

        operatorBackendIssues() {
            const issues = this.operatorBackend().issues;

            return Array.isArray(issues) ? issues : [];
        },

        operatorBackendSeverity() {
            const severity = this.operatorBackend().severity;

            return typeof severity === 'string' && severity !== '' ? severity : null;
        },

        structuralLimitsSnapshot() {
            return this.operatorMetrics && this.operatorMetrics.structural_limits
                ? this.operatorMetrics.structural_limits
                : null;
        },

        structuralLimitRows() {
            const snapshot = this.structuralLimitsSnapshot();

            if (!snapshot) {
                return [];
            }

            return Object.entries(snapshot)
                .filter(([key]) => key !== 'warning_threshold_percent')
                .map(([key, value]) => ({
                    key,
                    label: this.formatLimitKey(key),
                    value: this.formatLimitValue(key, value),
                }));
        },

        formatLimitKey(key) {
            return key
                .replace(/_/g, ' ')
                .replace(/\b\w/g, (character) => character.toUpperCase());
        },

        formatLimitValue(key, value) {
            if (key.endsWith('_bytes')) {
                if (value >= 1048576) return `${(value / 1048576).toFixed(1)} MiB`;
                if (value >= 1024) return `${(value / 1024).toFixed(0)} KiB`;
                return `${value} B`;
            }

            if (key === 'warning_threshold_percent') {
                return `${value}%`;
            }

            return typeof value === 'number' ? value.toLocaleString() : value;
        },

        operatorRepairScopes() {
            const repair = (this.operatorMetrics && this.operatorMetrics.repair) || {};
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

        operatorRepairOldestStartedAt() {
            const repair = (this.operatorMetrics && this.operatorMetrics.repair) || {};

            return repair.oldest_missing_run_started_at || null;
        },

        operatorCompatibilityBlockedAgeAvailable() {
            const backlog = (this.operatorMetrics && this.operatorMetrics.backlog) || {};

            if (backlog.max_compatibility_blocked_age_ms === undefined
                || backlog.max_compatibility_blocked_age_ms === null) {
                return false;
            }

            return Number(backlog.compatibility_blocked_runs || 0) > 0
                || Number(backlog.max_compatibility_blocked_age_ms || 0) > 0;
        },

        operatorCompatibilityBlockedOldestStartedAt() {
            const backlog = (this.operatorMetrics && this.operatorMetrics.backlog) || {};

            return backlog.oldest_compatibility_blocked_started_at || null;
        },

        operatorStuckLeaseAgeAvailable() {
            const tasks = (this.operatorMetrics && this.operatorMetrics.tasks) || {};

            if (tasks.max_lease_expired_age_ms === undefined
                || tasks.max_lease_expired_age_ms === null) {
                return false;
            }

            return Number(tasks.lease_expired || 0) > 0
                || Number(tasks.max_lease_expired_age_ms || 0) > 0;
        },

        operatorStuckLeaseOldestExpiredAt() {
            const tasks = (this.operatorMetrics && this.operatorMetrics.tasks) || {};

            return tasks.oldest_lease_expired_at || null;
        },

        operatorReadyDueAgeAvailable() {
            const tasks = (this.operatorMetrics && this.operatorMetrics.tasks) || {};

            if (tasks.max_ready_due_age_ms === undefined
                || tasks.max_ready_due_age_ms === null) {
                return false;
            }

            return Number(tasks.ready_due || 0) > 0
                || Number(tasks.max_ready_due_age_ms || 0) > 0;
        },

        operatorReadyDueOldestAt() {
            const tasks = (this.operatorMetrics && this.operatorMetrics.tasks) || {};

            return tasks.oldest_ready_due_at || null;
        },

        operatorDispatchOverdueAgeAvailable() {
            const tasks = (this.operatorMetrics && this.operatorMetrics.tasks) || {};

            if (tasks.max_dispatch_overdue_age_ms === undefined
                || tasks.max_dispatch_overdue_age_ms === null) {
                return false;
            }

            return Number(tasks.dispatch_overdue || 0) > 0
                || Number(tasks.max_dispatch_overdue_age_ms || 0) > 0;
        },

        operatorDispatchOverdueOldestSince() {
            const tasks = (this.operatorMetrics && this.operatorMetrics.tasks) || {};

            return tasks.oldest_dispatch_overdue_since || null;
        },

        operatorRunWaitAvailable() {
            const runs = (this.operatorMetrics && this.operatorMetrics.runs) || {};

            return runs.waiting !== undefined && runs.waiting !== null;
        },

        operatorRunWaitAgeAvailable() {
            const runs = (this.operatorMetrics && this.operatorMetrics.runs) || {};

            if (runs.max_wait_age_ms === undefined || runs.max_wait_age_ms === null) {
                return false;
            }

            return Number(runs.waiting || 0) > 0
                || Number(runs.max_wait_age_ms || 0) > 0;
        },

        operatorRunWaitOldestStartedAt() {
            const runs = (this.operatorMetrics && this.operatorMetrics.runs) || {};

            return runs.oldest_wait_started_at || null;
        },

        operatorRunRepairNeededAgeAvailable() {
            const runs = (this.operatorMetrics && this.operatorMetrics.runs) || {};

            if (runs.max_repair_needed_age_ms === undefined
                || runs.max_repair_needed_age_ms === null) {
                return false;
            }

            return Number(runs.repair_needed || 0) > 0
                || Number(runs.max_repair_needed_age_ms || 0) > 0;
        },

        operatorRunRepairNeededOldestAt() {
            const runs = (this.operatorMetrics && this.operatorMetrics.runs) || {};

            return runs.oldest_repair_needed_at || null;
        },

        operatorRetryingActivityAgeAvailable() {
            const activities = (this.operatorMetrics && this.operatorMetrics.activities) || {};

            if (activities.max_retrying_age_ms === undefined
                || activities.max_retrying_age_ms === null) {
                return false;
            }

            return Number(activities.retrying || 0) > 0
                || Number(activities.max_retrying_age_ms || 0) > 0;
        },

        operatorRetryingActivityOldestStartedAt() {
            const activities = (this.operatorMetrics && this.operatorMetrics.activities) || {};

            return activities.oldest_retrying_started_at || null;
        },

        operatorActivityTimeoutOverdueAvailable() {
            const activities = (this.operatorMetrics && this.operatorMetrics.activities) || {};

            if (activities.max_timeout_overdue_age_ms === undefined
                || activities.max_timeout_overdue_age_ms === null) {
                return false;
            }

            return Number(activities.timeout_overdue || 0) > 0
                || Number(activities.max_timeout_overdue_age_ms || 0) > 0;
        },

        operatorActivityTimeoutOverdueOldestAt() {
            const activities = (this.operatorMetrics && this.operatorMetrics.activities) || {};

            return activities.oldest_timeout_overdue_at || null;
        },

        operatorClaimFailedAgeAvailable() {
            const tasks = (this.operatorMetrics && this.operatorMetrics.tasks) || {};

            if (tasks.max_claim_failed_age_ms === undefined
                || tasks.max_claim_failed_age_ms === null) {
                return false;
            }

            return Number(tasks.claim_failed || 0) > 0
                || Number(tasks.max_claim_failed_age_ms || 0) > 0;
        },

        operatorClaimFailedOldestAt() {
            const tasks = (this.operatorMetrics && this.operatorMetrics.tasks) || {};

            return tasks.oldest_claim_failed_at || null;
        },

        operatorDispatchFailedAgeAvailable() {
            const tasks = (this.operatorMetrics && this.operatorMetrics.tasks) || {};

            if (tasks.max_dispatch_failed_age_ms === undefined
                || tasks.max_dispatch_failed_age_ms === null) {
                return false;
            }

            return Number(tasks.dispatch_failed || 0) > 0
                || Number(tasks.max_dispatch_failed_age_ms || 0) > 0;
        },

        operatorDispatchFailedOldestAt() {
            const tasks = (this.operatorMetrics && this.operatorMetrics.tasks) || {};

            return tasks.oldest_dispatch_failed_at || null;
        },

        operatorUnhealthyAgeAvailable() {
            const tasks = (this.operatorMetrics && this.operatorMetrics.tasks) || {};

            if (tasks.max_unhealthy_age_ms === undefined
                || tasks.max_unhealthy_age_ms === null) {
                return false;
            }

            return Number(tasks.unhealthy || 0) > 0
                || Number(tasks.max_unhealthy_age_ms || 0) > 0;
        },

        operatorUnhealthyOldestAt() {
            const tasks = (this.operatorMetrics && this.operatorMetrics.tasks) || {};

            return tasks.oldest_unhealthy_at || null;
        },

        operatorSchedulesAvailable() {
            const schedules = this.operatorMetrics && this.operatorMetrics.schedules;

            return schedules !== undefined && schedules !== null;
        },

        operatorMatchingRoleAvailable() {
            const matchingRole = this.operatorMetrics && this.operatorMetrics.matching_role;

            if (!matchingRole || typeof matchingRole !== 'object') {
                return false;
            }

            return typeof matchingRole.shape === 'string' && matchingRole.shape !== '';
        },

        operatorMatchingRoleShape() {
            const matchingRole = (this.operatorMetrics && this.operatorMetrics.matching_role) || {};

            return typeof matchingRole.shape === 'string' && matchingRole.shape !== ''
                ? matchingRole.shape
                : 'unknown';
        },

        operatorMatchingRoleQueueWakeEnabled() {
            const matchingRole = (this.operatorMetrics && this.operatorMetrics.matching_role) || {};

            return matchingRole.queue_wake_enabled === true;
        },

        operatorMatchingRoleTaskDispatchMode() {
            const matchingRole = (this.operatorMetrics && this.operatorMetrics.matching_role) || {};

            return typeof matchingRole.task_dispatch_mode === 'string' && matchingRole.task_dispatch_mode !== ''
                ? matchingRole.task_dispatch_mode
                : 'unknown';
        },

        operatorMatchingRoleContractAvailable() {
            const matchingRole = (this.operatorMetrics && this.operatorMetrics.matching_role) || {};

            return this.operatorMatchingRolePartitionPrimitives(matchingRole).length > 0
                || (typeof matchingRole.backpressure_model === 'string' && matchingRole.backpressure_model !== '');
        },

        operatorMatchingRolePartitionPrimitives(matchingRole = null) {
            const snapshot = matchingRole || ((this.operatorMetrics && this.operatorMetrics.matching_role) || {});
            const primitives = Array.isArray(snapshot.partition_primitives) ? snapshot.partition_primitives : [];

            return primitives.filter((primitive) => typeof primitive === 'string' && primitive !== '');
        },

        operatorMatchingRolePartitionPrimitivesLabel() {
            const primitives = this.operatorMatchingRolePartitionPrimitives();

            return primitives.length > 0
                ? primitives.join(' / ')
                : 'unknown';
        },

        operatorMatchingRoleBackpressureModel() {
            const matchingRole = (this.operatorMetrics && this.operatorMetrics.matching_role) || {};

            return typeof matchingRole.backpressure_model === 'string' && matchingRole.backpressure_model !== ''
                ? matchingRole.backpressure_model
                : 'unknown';
        },

        operatorScheduleOldestOverdueAt() {
            const schedules = (this.operatorMetrics && this.operatorMetrics.schedules) || {};

            return schedules.oldest_overdue_at || null;
        },

        operatorWorkerFleet() {
            const workers = (this.operatorMetrics && this.operatorMetrics.workers) || {};
            const fleet = Array.isArray(workers.fleet) ? workers.fleet : [];

            return fleet.slice(0, 20);
        },

        operatorWorkerFleetScope(entry) {
            if (!entry) {
                return '—';
            }

            return [
                entry.connection || 'default',
                entry.queue || 'default',
            ].join(' / ');
        },

        operatorWorkerFleetKey(entry) {
            if (!entry) {
                return '';
            }

            return [
                entry.worker_id || '',
                entry.connection || '',
                entry.queue || '',
                entry.namespace || '',
            ].join(':');
        },

        engineSourceLabel() {
            const source = this.stats.engine_source;

            if (!source) {
                return 'active';
            }

            if (typeof source === 'string') {
                return source.toUpperCase();
            }

            if (source.pinned === 'v2' || source.source === 'v2') {
                return 'V2';
            }

            if (source.pinned === 'v1' || source.source === 'v1') {
                return 'V1';
            }

            return 'active';
        },
    },
};
</script>

<style scoped>
.wl-dashboard-view {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.wl-screen-state {
    min-height: 18rem;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem;
    text-align: center;
}

.wl-screen-state--error {
    flex-direction: column;
}

.wl-dashboard-stack {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.wl-screen-hero {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 1rem;
}

.wl-screen-eyebrow {
    margin: 0 0 0.45rem;
    color: var(--wl-text-soft);
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.72rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.wl-screen-title {
    margin: 0;
    font-size: 2.2rem;
    font-weight: 600;
    letter-spacing: -0.04em;
    color: var(--wl-text);
}

.wl-screen-subtitle {
    margin: 0.5rem 0 0;
    max-width: 42rem;
    color: var(--wl-text-muted);
}

.wl-screen-hero__actions {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.wl-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.45rem 0.75rem;
    border-radius: 999px;
    background: color-mix(in srgb, var(--wl-accent) 14%, transparent);
    color: var(--wl-accent);
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.72rem;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.wl-chip--warning {
    background: color-mix(in srgb, var(--wl-warning) 16%, transparent);
    color: var(--wl-warning);
}

.wl-chip--success {
    background: color-mix(in srgb, var(--wl-success) 16%, transparent);
    color: var(--wl-success);
}

.wl-dashboard-alerts__body {
    display: grid;
    gap: 0.9rem;
}

.wl-dashboard-alert {
    border: 1px solid transparent;
    border-radius: 14px;
    padding: 1rem 1.1rem;
}

.wl-dashboard-alert.is-error {
    background: color-mix(in srgb, var(--wl-danger) 14%, transparent);
    border-color: color-mix(in srgb, var(--wl-danger) 22%, transparent);
}

.wl-dashboard-alert.is-warning {
    background: color-mix(in srgb, var(--wl-warning) 12%, transparent);
    border-color: color-mix(in srgb, var(--wl-warning) 24%, transparent);
}

.wl-dashboard-alert.is-info {
    background: color-mix(in srgb, var(--wl-accent) 10%, transparent);
    border-color: color-mix(in srgb, var(--wl-accent) 20%, transparent);
}

.wl-dashboard-alert__title {
    font-weight: 600;
    letter-spacing: -0.01em;
}

.wl-dashboard-alert__action {
    margin-top: 0.35rem;
    color: var(--wl-text-muted);
    font-size: 0.92rem;
}

.wl-dashboard-summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
}

.wl-summary-card__label,
.wl-panel-subtitle,
.wl-overview-tile__label,
.wl-operator-metric__label {
    color: var(--wl-text-soft);
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.72rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.wl-summary-card__value,
.wl-operator-metric__value {
    margin-top: 0.65rem;
    font-size: 2rem;
    font-weight: 600;
    letter-spacing: -0.04em;
    color: var(--wl-text);
}

.wl-summary-card__meta,
.wl-operator-metric__meta,
.wl-overview-tile__meta {
    margin-top: 0.55rem;
    color: var(--wl-text-muted);
    font-size: 0.92rem;
}

.wl-dashboard-grid {
    display: grid;
    grid-template-columns: repeat(12, minmax(0, 1fr));
    gap: 1rem;
}

.wl-dashboard-card {
    grid-column: span 4;
}

.wl-dashboard-card--wide {
    grid-column: span 8;
}

.wl-dashboard-split {
    display: grid;
    gap: 1rem;
}

.wl-operator-backend {
    padding-top: 0.2rem;
}

.wl-operator-backend__summary,
.wl-operator-section p {
    margin: 0.45rem 0 0;
    color: var(--wl-text-muted);
    line-height: 1.6;
}

.wl-operator-backend__issues {
    display: grid;
    gap: 0.35rem;
    margin-top: 0.65rem;
    color: var(--wl-warning);
    font-size: 0.92rem;
}

.wl-dashboard-chart-stack {
    display: grid;
    gap: 1.25rem;
}

.wl-overview-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.9rem;
}

.wl-overview-tile {
    padding: 1rem;
    border-radius: 14px;
    background: color-mix(in srgb, var(--wl-text) 4%, var(--wl-surface));
    border: 1px solid color-mix(in srgb, var(--wl-text) 8%, transparent);
}

.wl-overview-tile__value {
    margin-top: 0.65rem;
    font-size: 1.5rem;
    font-weight: 600;
    letter-spacing: -0.03em;
    color: var(--wl-text);
}

.wl-overview-tile__meta a {
    color: var(--wl-accent);
}

.wl-operator-grid {
    display: grid;
    gap: 1.25rem;
}

.wl-operator-metrics-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.9rem;
}

.wl-operator-metric {
    padding: 1rem;
    border-radius: 14px;
    background: color-mix(in srgb, var(--wl-text) 4%, var(--wl-surface));
    border: 1px solid color-mix(in srgb, var(--wl-text) 8%, transparent);
}

.wl-operator-section {
    padding-top: 0.25rem;
    border-top: 1px solid color-mix(in srgb, var(--wl-text) 6%, transparent);
}

.wl-inline-list {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 0.75rem;
}

.wl-inline-list span {
    padding: 0.35rem 0.6rem;
    border-radius: 999px;
    background: color-mix(in srgb, var(--wl-text) 4%, var(--wl-surface));
    color: var(--wl-text-muted);
    font-size: 0.86rem;
}

.wl-structural-limits {
    display: grid;
    gap: 0.55rem;
    margin-top: 0.75rem;
}

.wl-structural-limits__row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding-bottom: 0.45rem;
    border-bottom: 1px solid color-mix(in srgb, var(--wl-text) 6%, transparent);
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.86rem;
    color: var(--wl-text);
}

.wl-empty-state {
    padding: 1rem 0;
    color: var(--wl-text-muted);
}

@media (max-width: 1200px) {
    .wl-dashboard-summary-grid,
    .wl-operator-metrics-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .wl-dashboard-card,
    .wl-dashboard-card--wide {
        grid-column: span 12;
    }
}

@media (max-width: 768px) {
    .wl-screen-hero {
        flex-direction: column;
        align-items: flex-start;
    }

    .wl-dashboard-summary-grid,
    .wl-overview-grid,
    .wl-operator-metrics-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
