<template>
    <div class="wl-dashboard-view">
        <div v-if="!ready && !loadingError" class="wl-screen-state card card-bg-secondary">
            <div class="d-flex align-items-center justify-content-center">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin mr-2 fill-text-color">
                    <path d="M12 10a2 2 0 0 1-3.41 1.41A2 2 0 0 1 10 8V0a9.97 9.97 0 0 1 10 10h-8zm7.9 1.41A10 10 0 1 1 8.59.1v2.03a8 8 0 1 0 9.29 9.29h2.02zm-4.07 0a6 6 0 1 1-7.25-7.25v2.1a3.99 3.99 0 0 0-1.4 6.57 4 4 0 0 0 6.56-1.42h2.1z"></path>
                </svg>
                <span>{{ $t("Loading dashboard…") }}</span>
            </div>
        </div>

        <div v-else-if="loadingError" class="wl-screen-state card card-bg-secondary wl-screen-state--error">
            <strong>{{ $t("Dashboard unavailable") }}</strong>
            <span class="text-muted mt-2">{{ loadingError }}</span>
            <button class="btn btn-outline-primary btn-sm mt-3" @click="refreshNow">{{ $t("Retry") }}</button>
            <button v-if="selectedClassification" class="btn btn-outline-secondary btn-sm mt-2"
                @click="clearClassification">{{ $t("Show all workflow types") }}</button>
        </div>

        <div v-else class="wl-dashboard-stack">
            <section class="wl-screen-hero">
                <div>
                    <p class="wl-screen-eyebrow">{{ $t("Workflow operations") }}</p>
                    <h1 class="wl-screen-title">{{ $t("Dashboard") }}</h1>
                    <p class="wl-screen-subtitle">
                        {{ $t("Fleet health, queue pressure, and repair posture for") }} {{ engineSourceLabel() }} {{ $t("workflows.") }}
                    </p>
                </div>

                <div class="wl-screen-hero__actions">
                    <span v-if="operatorMetrics && operatorMetrics.generated_at" class="wl-chip">
                        {{ operatorMetrics.generated_at }}
                    </span>

                    <button class="btn btn-outline-secondary btn-sm" @click="refreshNow">
                        {{ $t("Refresh") }}
                    </button>
                </div>
            </section>

            <section class="card">
                <div class="card-body card-bg-secondary">
                    <label v-if="classificationOptions.length" for="dashboard-classification" class="d-block">
                        {{ $t("Workflow classification") }}
                        <select id="dashboard-classification" v-model="selectedClassification"
                            class="form-control mt-2" :disabled="!classificationAvailable" @change="changeClassification">
                            <option value="">{{ $t("All workflow types") }}</option>
                            <option v-for="option in classificationOptions" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                    </label>
                    <p class="mb-1">
                        {{ classificationScopeLabel }} · {{ dashboardNamespaceLabel }}
                    </p>
                    <p class="text-muted mb-1">
                        {{ $t("Totals include all retained runs. Recent volume uses the last hour, day and seven days. Trends use hourly buckets over seven days.") }}
                    </p>
                    <p v-if="stats.time_windows" class="text-muted mb-1">
                        {{ $t("As of") }} {{ stats.time_windows.generated_at }}
                    </p>
                    <p class="text-muted mb-0">
                        {{ $t("Worker, queue and storage metrics cover the full operator scope.") }}
                        <span v-if="classificationOptions.length && !classificationAvailable">
                            {{ $t("This backend does not support classification filters yet.") }}
                        </span>
                    </p>
                </div>
            </section>

            <section v-if="needsAttention.total_alerts > 0" class="card wl-dashboard-alerts">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div>
                        <h5>{{ $t("Needs attention") }}</h5>
                        <small class="text-muted">
                            {{ needsAttention.total_alerts }} {{ $t("active alert") }}<span v-if="needsAttention.total_alerts !== 1">{{ $t("s") }}</span>
                        </small>
                    </div>

                    <span class="wl-chip wl-chip--warning" v-if="needsAttention.has_critical">{{ $t("Critical") }}</span>
                </div>

                <div class="card-body card-bg-secondary wl-dashboard-alerts__body">
                    <article
                        v-for="alert in needsAttention.alerts"
                        :key="alert.type"
                        class="wl-dashboard-alert"
                        :class="`is-${alert.severity || 'info'}`">
                        <div class="wl-dashboard-alert__title">{{ alert.message }}</div>
                        <div class="wl-dashboard-alert__action">{{ alert.action }}</div>
                        <small v-if="alert.scope === 'operator_workers'" class="text-muted">{{ $t("Operator worker scope") }}</small>
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
                            <h5>{{ $t("Fleet trends") }}</h5>
                            <small class="text-muted">{{ $t("Last 7 days, hourly resolution") }}</small>
                        </div>

                        <span class="wl-chip">{{ $t("Completed vs. failed") }}</span>
                    </div>

                    <div class="card-body card-bg-secondary">
                        <div v-if="fleetTrendsChartSeries.length" role="img" :aria-label="$t('Fleet trends chart showing completed and failed workflow volume over time.')">
                            <apexchart
                                type="area"
                                height="320"
                                :options="fleetTrendsChartOptions"
                                :series="fleetTrendsChartSeries">
                            </apexchart>
                        </div>
                        <div v-else class="wl-empty-state">{{ $t("No trend data available yet.") }}</div>
                    </div>
                </article>

                <article class="card wl-dashboard-card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <div>
                            <h5>{{ $t("Fleet overview") }}</h5>
                            <small class="text-muted">{{ $t("Current posture and recent volume") }}</small>
                        </div>

                        <span class="wl-chip" :class="operatorBackend().supported ? 'wl-chip--success' : 'wl-chip--warning'">
                            {{ operatorBackendStatusLabel() }}
                        </span>
                    </div>

                    <div class="card-body card-bg-secondary wl-dashboard-split">
                        <div>
                            <div class="wl-panel-subtitle">{{ $t("Current status") }}</div>
                            <table class="table table-sm mb-0">
                                <tbody>
                                    <tr>
                                        <td>{{ $t("Running") }}</td>
                                        <td class="text-right">{{ fleetMetric('current', 'running').toLocaleString() }}</td>
                                    </tr>
                                    <tr>
                                        <td>{{ $t("Failed") }}</td>
                                        <td class="text-right text-danger">{{ fleetMetric('current', 'failed').toLocaleString() }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div>
                            <div class="wl-panel-subtitle">{{ $t("Recent trends") }}</div>
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>{{ $t("Period") }}</th>
                                        <th class="text-right">{{ $t("Completed") }}</th>
                                        <th class="text-right">{{ $t("Failed") }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>{{ $t("Last hour") }}</td>
                                        <td class="text-right text-success">{{ fleetMetric('trends', 'hour', 'completed').toLocaleString() }}</td>
                                        <td class="text-right text-danger">{{ fleetMetric('trends', 'hour', 'failed').toLocaleString() }}</td>
                                    </tr>
                                    <tr>
                                        <td>{{ $t("Last day") }}</td>
                                        <td class="text-right text-success">{{ fleetMetric('trends', 'day', 'completed').toLocaleString() }}</td>
                                        <td class="text-right text-danger">{{ fleetMetric('trends', 'day', 'failed').toLocaleString() }}</td>
                                    </tr>
                                    <tr>
                                        <td>{{ $t("Last week") }}</td>
                                        <td class="text-right text-success">{{ fleetMetric('trends', 'week', 'completed').toLocaleString() }}</td>
                                        <td class="text-right text-danger">{{ fleetMetric('trends', 'week', 'failed').toLocaleString() }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="wl-operator-backend">
                            <div class="wl-panel-subtitle">{{ $t("Backend capability") }}</div>
                            <div v-if="operatorBackendSeverity()" class="wl-operator-backend__summary">
                                {{ $t("Severity") }} {{ operatorBackendSeverity() }}
                            </div>
                            <div class="wl-operator-backend__summary">
                                {{ $t("Database") }} {{ operatorBackendComponentLabel('database') }}
                            </div>
                            <div class="wl-operator-backend__summary">
                                {{ $t("Cache") }} {{ operatorBackendComponentLabel('cache') }}
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
                            <h5>{{ $t("Workflow type health") }}</h5>
                            <small class="text-muted">{{ $t("Top workflow types by volume") }}</small>
                        </div>

                        <span class="wl-chip">{{ topWorkflowTypes.length }} {{ $t("tracked types") }}</span>
                    </div>

                    <div class="card-body card-bg-secondary">
                        <div v-if="topWorkflowTypes.length" class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>{{ $t("Workflow type") }}</th>
                                        <th class="text-right">{{ $t("Runs") }}</th>
                                        <th class="text-right">{{ $t("Pass rate") }}</th>
                                        <th class="text-right">{{ $t("Median duration") }}</th>
                                        <th class="text-right">{{ $t("Errors") }}</th>
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
                        <div v-else class="wl-empty-state">{{ $t("No workflow type health data available yet.") }}</div>
                    </div>
                </article>

                <article class="card wl-dashboard-card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <div>
                            <h5>{{ $t("Type breakdown") }}</h5>
                            <small class="text-muted">{{ $t("Pass rate and latency for the busiest workflows") }}</small>
                        </div>
                    </div>

                    <div class="card-body card-bg-secondary">
                        <div v-if="topWorkflowTypes.length" class="wl-dashboard-chart-stack">
                            <div>
                                <div class="wl-panel-subtitle">{{ $t("Pass rate") }}</div>
                                <apexchart
                                    type="bar"
                                    height="220"
                                    :options="passRateChartOptions"
                                    :series="passRateChartSeries">
                                </apexchart>
                            </div>

                            <div>
                                <div class="wl-panel-subtitle">{{ $t("Median duration") }}</div>
                                <apexchart
                                    type="bar"
                                    height="220"
                                    :options="durationChartOptions"
                                    :series="durationChartSeries">
                                </apexchart>
                            </div>
                        </div>
                        <div v-else class="wl-empty-state">{{ $t("No workflow health charts available yet.") }}</div>
                    </div>
                </article>

                <article class="card wl-dashboard-card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <div>
                            <h5>{{ $t("Overview") }}</h5>
                            <small class="text-muted">{{ $t("Throughput, outliers, and exception hotspots") }}</small>
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
                            <h5>{{ $t("Operator metrics") }}</h5>
                            <small class="text-muted">{{ $t("Backlog, projection health, and recovery posture") }}</small>
                        </div>

                        <span class="wl-chip" v-if="operatorMetrics.generated_at">{{ operatorMetrics.generated_at }}</span>
                    </div>

                    <div class="card-body card-bg-secondary wl-operator-grid">
                        <div class="wl-operator-metrics-grid">
                            <div class="wl-operator-metric">
                                <div class="wl-operator-metric__label">{{ $t("Runnable tasks") }}</div>
                                <div class="wl-operator-metric__value">{{ operatorMetricLabel('backlog', 'runnable_tasks') }}</div>
                                <div class="wl-operator-metric__meta">
                                    {{ operatorMetricLabel('backlog', 'delayed_tasks') }} {{ $t("delayed,") }}
                                    {{ operatorMetricLabel('backlog', 'leased_tasks') }} {{ $t("leased") }}
                                </div>
                                <div class="wl-operator-metric__meta">
                                    {{ operatorMetricLabel('backlog', 'tasks_added_last_minute') }} {{ $t("added last minute,") }}
                                    {{ operatorMetricLabel('backlog', 'tasks_dispatched_last_minute') }} {{ $t("dispatched last minute") }}
                                </div>
                                <div v-if="operatorReadyDueAgeAvailable()" class="wl-operator-metric__meta">
                                    {{ $t("oldest ready") }} {{ operatorDurationMetricLabel('tasks', 'max_ready_due_age_ms') }} {{ $t("waiting") }}
                                    <template v-if="operatorReadyDueOldestAt()">
                                        {{ $t("(since") }} {{ operatorReadyDueOldestAt() }})
                                    </template>
                                </div>
                            </div>
                            <div class="wl-operator-metric">
                                <div class="wl-operator-metric__label">{{ $t("Unhealthy tasks") }}</div>
                                <div class="wl-operator-metric__value">{{ operatorMetricLabel('backlog', 'unhealthy_tasks') }}</div>
                                <div class="wl-operator-metric__meta">
                                    {{ operatorMetricLabel('tasks', 'dispatch_overdue') }} {{ $t("dispatch overdue,") }}
                                    {{ operatorMetricLabel('tasks', 'lease_expired') }} {{ $t("lease expired") }}
                                </div>
                                <div v-if="operatorUnhealthyAgeAvailable()" class="wl-operator-metric__meta">
                                    {{ $t("worst") }} {{ operatorDurationMetricLabel('tasks', 'max_unhealthy_age_ms') }} {{ $t("unhealthy") }}
                                    <template v-if="operatorUnhealthyOldestAt()">
                                        {{ $t("(since") }} {{ operatorUnhealthyOldestAt() }})
                                    </template>
                                </div>
                                <div v-if="operatorStuckLeaseAgeAvailable()" class="wl-operator-metric__meta">
                                    {{ $t("oldest lease") }} {{ operatorDurationMetricLabel('tasks', 'max_lease_expired_age_ms') }} {{ $t("expired") }}
                                    <template v-if="operatorStuckLeaseOldestExpiredAt()">
                                        {{ $t("(since") }} {{ operatorStuckLeaseOldestExpiredAt() }})
                                    </template>
                                </div>
                                <div v-if="operatorDispatchOverdueAgeAvailable()" class="wl-operator-metric__meta">
                                    {{ $t("oldest overdue") }} {{ operatorDurationMetricLabel('tasks', 'max_dispatch_overdue_age_ms') }} {{ $t("waiting dispatch") }}
                                    <template v-if="operatorDispatchOverdueOldestSince()">
                                        {{ $t("(since") }} {{ operatorDispatchOverdueOldestSince() }})
                                    </template>
                                </div>
                                <div v-if="operatorDispatchFailedAgeAvailable()" class="wl-operator-metric__meta">
                                    {{ $t("oldest dispatch") }} {{ operatorDurationMetricLabel('tasks', 'max_dispatch_failed_age_ms') }} {{ $t("failed") }}
                                    <template v-if="operatorDispatchFailedOldestAt()">
                                        {{ $t("(since") }} {{ operatorDispatchFailedOldestAt() }})
                                    </template>
                                </div>
                            </div>
                            <div class="wl-operator-metric">
                                <div class="wl-operator-metric__label">{{ $t("Repair needed runs") }}</div>
                                <div class="wl-operator-metric__value">{{ operatorMetricLabel('backlog', 'repair_needed_runs') }}</div>
                                <div v-if="operatorRunRepairNeededAgeAvailable()" class="wl-operator-metric__meta">
                                    {{ $t("oldest") }} {{ operatorDurationMetricLabel('runs', 'max_repair_needed_age_ms') }} {{ $t("stuck") }}
                                    <template v-if="operatorRunRepairNeededOldestAt()">
                                        {{ $t("(since") }} {{ operatorRunRepairNeededOldestAt() }})
                                    </template>
                                </div>
                            </div>
                            <div class="wl-operator-metric">
                                <div class="wl-operator-metric__label">{{ $t("Claim failed runs") }}</div>
                                <div class="wl-operator-metric__value">{{ operatorMetricLabel('backlog', 'claim_failed_runs') }}</div>
                                <div v-if="operatorClaimFailedAgeAvailable()" class="wl-operator-metric__meta">
                                    {{ $t("oldest claim") }} {{ operatorDurationMetricLabel('tasks', 'max_claim_failed_age_ms') }} {{ $t("failed") }}
                                    <template v-if="operatorClaimFailedOldestAt()">
                                        {{ $t("(since") }} {{ operatorClaimFailedOldestAt() }})
                                    </template>
                                </div>
                            </div>
                            <div class="wl-operator-metric">
                                <div class="wl-operator-metric__label">{{ $t("Compatibility blocked") }}</div>
                                <div class="wl-operator-metric__value">{{ operatorMetricLabel('backlog', 'compatibility_blocked_runs') }}</div>
                                <div v-if="operatorCompatibilityBlockedAgeAvailable()" class="wl-operator-metric__meta">
                                    {{ $t("oldest") }} {{ operatorDurationMetricLabel('backlog', 'max_compatibility_blocked_age_ms') }} {{ $t("behind") }}
                                    <template v-if="operatorCompatibilityBlockedOldestStartedAt()">
                                        {{ $t("(since") }} {{ operatorCompatibilityBlockedOldestStartedAt() }})
                                    </template>
                                </div>
                            </div>
                            <div class="wl-operator-metric" v-if="operatorRunWaitAvailable()">
                                <div class="wl-operator-metric__label">{{ $t("Waiting runs") }}</div>
                                <div class="wl-operator-metric__value">{{ operatorMetricLabel('runs', 'waiting') }}</div>
                                <div v-if="operatorRunWaitAgeAvailable()" class="wl-operator-metric__meta">
                                    {{ $t("oldest") }} {{ operatorDurationMetricLabel('runs', 'max_wait_age_ms') }} {{ $t("parked") }}
                                    <template v-if="operatorRunWaitOldestStartedAt()">
                                        {{ $t("(since") }} {{ operatorRunWaitOldestStartedAt() }})
                                    </template>
                                </div>
                            </div>
                            <div class="wl-operator-metric">
                                <div class="wl-operator-metric__label">{{ $t("Active workers") }}</div>
                                <div class="wl-operator-metric__value">{{ operatorMetricLabel('workers', 'active_workers') }}</div>
                                <div class="wl-operator-metric__meta">{{ operatorMetricLabel('workers', 'active_worker_scopes') }} {{ $t("queue scopes") }}</div>
                            </div>
                            <div class="wl-operator-metric">
                                <div class="wl-operator-metric__label">{{ $t("Pending starts") }}</div>
                                <div class="wl-operator-metric__value">{{ operatorMetricLabel('starts', 'pending_runs') }}</div>
                            </div>
                            <div class="wl-operator-metric">
                                <div class="wl-operator-metric__label">{{ $t("Start commands") }}</div>
                                <div class="wl-operator-metric__value">{{ operatorMetricLabel('starts', 'pending_commands') }}</div>
                            </div>
                            <div class="wl-operator-metric">
                                <div class="wl-operator-metric__label">{{ $t("Due start tasks") }}</div>
                                <div class="wl-operator-metric__value">{{ operatorMetricLabel('starts', 'ready_tasks') }}</div>
                            </div>
                            <div class="wl-operator-metric">
                                <div class="wl-operator-metric__label">{{ $t("Max start latency") }}</div>
                                <div class="wl-operator-metric__value">{{ operatorDurationMetricLabel('starts', 'max_pending_ms') }}</div>
                            </div>
                        </div>

                        <section class="wl-operator-section">
                            <div class="wl-panel-subtitle">{{ $t("Activity attempts") }}</div>
                            <p>
                                {{ operatorMetricLabel('activities', 'retrying') }} {{ $t("retrying,") }}
                                {{ operatorMetricLabel('activities', 'running') }} {{ $t("running,") }}
                                {{ operatorMetricLabel('activities', 'failed_attempts') }} {{ $t("failed attempts, max") }} {{ operatorMetricLabel('activities', 'max_attempt_count') }} {{ $t("attempts.") }}
                            </p>
                            <p v-if="operatorRetryingActivityAgeAvailable()">
                                {{ $t("Oldest retrying activity") }} {{ operatorDurationMetricLabel('activities', 'max_retrying_age_ms') }} {{ $t("behind") }}
                                <template v-if="operatorRetryingActivityOldestStartedAt()">
                                    {{ $t("(since") }} {{ operatorRetryingActivityOldestStartedAt() }})
                                </template>.
                            </p>
                            <p v-if="operatorActivityTimeoutOverdueAvailable()">
                                {{ operatorMetricLabel('activities', 'timeout_overdue') }} {{ $t("timeout overdue, worst") }} {{ operatorDurationMetricLabel('activities', 'max_timeout_overdue_age_ms') }} {{ $t("past deadline") }}
                                <template v-if="operatorActivityTimeoutOverdueOldestAt()">
                                    {{ $t("(since") }} {{ operatorActivityTimeoutOverdueOldestAt() }})
                                </template>.
                            </p>
                        </section>

                        <section class="wl-operator-section">
                            <div class="wl-panel-subtitle">{{ $t("Projection health") }}</div>
                            <p>
                                {{ $t("Run summaries:") }} {{ operatorProjectionMetricLabel('summaries') }} {{ $t("summaries for") }}
                                {{ operatorProjectionMetricLabel('runs') }} {{ $t("runs,") }}
                                {{ operatorProjectionMetricLabel('missing') }} {{ $t("missing,") }}
                                {{ operatorProjectionMetricLabel('orphaned') }} {{ $t("orphaned,") }}
                                {{ operatorProjectionMetricLabel('stale') }} {{ $t("stale.") }}
                            </p>
                            <p v-if="operatorRunSummaryMissingAgeAvailable()">
                                {{ $t("Oldest run-summary missing run") }} {{ operatorProjectionDurationMetricLabel('run_summaries', 'max_missing_run_age_ms') }} {{ $t("behind") }}
                                <template v-if="operatorRunSummaryMissingOldestStartedAt()">
                                    {{ $t("(since") }} {{ operatorRunSummaryMissingOldestStartedAt() }})
                                </template>.
                            </p>
                            <p>
                                {{ $t("Wait rows:") }} {{ operatorProjectionMetricLabel('run_waits', 'rows') }} {{ $t("rows across") }}
                                {{ operatorProjectionMetricLabel('run_waits', 'projected_runs') }} {{ $t("runs,") }}
                                {{ operatorProjectionMetricLabel('run_waits', 'runs_with_waits') }} {{ $t("canonical waits,") }}
                                {{ operatorProjectionMetricLabel('run_waits', 'missing_runs_with_waits') }} {{ $t("missing,") }}
                                {{ operatorProjectionMetricLabel('run_waits', 'stale_projected_runs') }} {{ $t("stale,") }}
                                {{ operatorProjectionMetricLabel('run_waits', 'orphaned') }} {{ $t("orphaned.") }}
                            </p>
                            <p>
                                {{ $t("Timeline rows:") }} {{ operatorProjectionMetricLabel('run_timeline_entries', 'rows') }} {{ $t("rows for") }}
                                {{ operatorProjectionMetricLabel('run_timeline_entries', 'history_events') }} {{ $t("history events,") }}
                                {{ operatorProjectionMetricLabel('run_timeline_entries', 'missing_runs_with_history') }} {{ $t("missing,") }}
                                {{ operatorProjectionMetricLabel('run_timeline_entries', 'stale_projected_runs') }} {{ $t("stale,") }}
                                {{ operatorProjectionMetricLabel('run_timeline_entries', 'orphaned') }} {{ $t("orphaned.") }}
                            </p>
                            <p>
                                {{ $t("Timer rows:") }} {{ operatorProjectionMetricLabel('run_timer_entries', 'rows') }} {{ $t("rows across") }}
                                {{ operatorProjectionMetricLabel('run_timer_entries', 'projected_runs') }} {{ $t("projected runs,") }}
                                {{ operatorProjectionMetricLabel('run_timer_entries', 'missing_runs_with_timers') }} {{ $t("missing,") }}
                                {{ operatorProjectionMetricLabel('run_timer_entries', 'stale_projected_runs') }} {{ $t("stale,") }}
                                {{ operatorProjectionMetricLabel('run_timer_entries', 'orphaned') }} {{ $t("orphaned.") }}
                            </p>
                        </section>

                        <section class="wl-operator-section">
                            <div class="wl-panel-subtitle">{{ $t("Repair policy") }}</div>
                            <p>
                                {{ $t("Redispatch after") }} {{ operatorPolicyMetricLabel('redispatch_after_seconds') }} {{ $t("seconds, throttle worker sweeps for") }} {{ operatorPolicyMetricLabel('loop_throttle_seconds') }} {{ $t("seconds, scan") }} {{ operatorPolicyMetricLabel('scan_limit') }} {{ $t("rows per pass, backoff capped at") }} {{ operatorPolicyMetricLabel('failure_backoff_max_seconds') }} {{ $t("seconds.") }}
                            </p>
                            <div v-if="operatorRepairScopes().length" class="wl-inline-list">
                                <span v-for="scope in operatorRepairScopes()" :key="operatorRepairScopeLabel(scope)">
                                    {{ operatorRepairScopeLabel(scope) }}
                                </span>
                            </div>
                        </section>

                        <section class="wl-operator-section">
                            <div class="wl-panel-subtitle">{{ $t("Stuck-run detectors") }}</div>
                            <p>
                                {{ operatorMetricLabel('repair', 'missing_task_candidates') }} {{ $t("runs missing a next task (") }}{{ operatorMetricLabel('repair', 'selected_missing_task_candidates') }} {{ $t("selected this pass), oldest") }} {{ operatorDurationMetricLabel('repair', 'max_missing_run_age_ms') }} {{ $t("behind.") }}
                            </p>
                            <p v-if="operatorRepairOldestStartedAt()">
                                {{ $t("Oldest missing-task run started at") }} {{ operatorRepairOldestStartedAt() }}.
                            </p>
                        </section>

                        <section class="wl-operator-section">
                            <div class="wl-panel-subtitle">{{ $t("Scheduler-role health") }}</div>
                            <p v-if="!operatorSchedulesAvailable()" class="text-muted">
                                {{ $t("No scheduler-role metrics exposed by the current workflow engine.") }}
                            </p>
                            <template v-else>
                                <p>
                                    {{ operatorMetricLabel('schedules', 'active') }} {{ $t("active schedules,") }}
                                    {{ operatorMetricLabel('schedules', 'paused') }} {{ $t("paused,") }}
                                    {{ operatorMetricLabel('schedules', 'missed') }} {{ $t("overdue this tick, oldest") }} {{ operatorDurationMetricLabel('schedules', 'max_overdue_ms') }} {{ $t("behind.") }}
                                </p>
                                <p v-if="operatorScheduleOldestOverdueAt()">
                                    {{ $t("Oldest overdue fire due at") }} {{ operatorScheduleOldestOverdueAt() }}.
                                </p>
                                <p>
                                    {{ operatorMetricLabel('schedules', 'fires_total') }} {{ $t("fires recorded against active schedules,") }}
                                    {{ operatorMetricLabel('schedules', 'failures_total') }} {{ $t("failures.") }}
                                </p>
                            </template>
                        </section>

                        <section class="wl-operator-section">
                            <div class="wl-panel-subtitle">{{ $t("Matching-role (this node)") }}</div>
                            <p v-if="!operatorMatchingRoleAvailable()" class="text-muted">
                                {{ $t("No matching-role metrics exposed by the current workflow engine.") }}
                            </p>
                            <template v-else>
                                <p>
                                    {{ $t("Shape") }} <code>{{ operatorMatchingRoleShape() }}</code>{{ $t(", wake owner") }} <code>{{ operatorMatchingRoleWakeOwner() }}</code>{{ $t(", queue-wake") }} {{ operatorMatchingRoleQueueWakeEnabled() ? 'enabled' : 'disabled' }}{{ $t(", task dispatch") }} <code>{{ operatorMatchingRoleTaskDispatchMode() }}</code>.
                                </p>
                                <p v-if="operatorMatchingRoleContractAvailable()">
                                    {{ $t("Partitions by") }} <code>{{ operatorMatchingRolePartitionPrimitivesLabel() }}</code>{{ $t(", backpressure") }} <code>{{ operatorMatchingRoleBackpressureModel() }}</code>.
                                </p>
                                <p v-if="operatorMatchingRoleDiscoveryLimitsAvailable()">
                                    {{ $t("Discovery limits: poll batch cap") }} <code>{{ operatorMatchingRoleDiscoveryLimit('poll_batch_cap') }}</code>{{ $t(", availability ceiling") }} <code>{{ operatorMatchingRoleDiscoveryLimit('availability_ceiling_seconds') }}s</code>{{ $t(", wake signal TTL") }} <code>{{ operatorMatchingRoleDiscoveryLimit('wake_signal_ttl_seconds') }}s</code>{{ $t(", workflow task lease") }} <code>{{ operatorMatchingRoleDiscoveryLimit('workflow_task_lease_seconds') }}s</code>{{ $t(", activity task lease") }} <code>{{ operatorMatchingRoleDiscoveryLimit('activity_task_lease_seconds') }}s</code>.
                                </p>
                                <p class="text-muted">
                                    {{ $t("Single-process scope — read one snapshot per node to see the full deployment.") }}
                                </p>
                            </template>
                        </section>

                        <section class="wl-operator-section">
                            <div class="wl-panel-subtitle">{{ $t("Worker compatibility fleet") }}</div>
                            <p v-if="!operatorWorkerFleet().length" class="text-muted">
                                {{ $t("No active worker compatibility heartbeats in this namespace.") }}
                            </p>
                            <div v-else class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead>
                                        <tr>
                                            <th>{{ $t("Worker") }}</th>
                                            <th>{{ $t("Queue scope") }}</th>
                                            <th>{{ $t("Supports") }}</th>
                                            <th>{{ $t("Required") }}</th>
                                            <th>{{ $t("Source") }}</th>
                                            <th>{{ $t("Heartbeat") }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="entry in operatorWorkerFleet()" :key="operatorWorkerFleetKey(entry)">
                                            <td><code>{{ entry.worker_id }}</code></td>
                                            <td>{{ operatorWorkerFleetScope(entry) }}</td>
                                            <td>{{ (entry.supported || []).join(', ') || '—' }}</td>
                                            <td>
                                                <span v-if="entry.supports_required" class="wl-chip">{{ $t("yes") }}</span>
                                                <span v-else class="wl-chip wl-chip--warning">{{ $t("no") }}</span>
                                            </td>
                                            <td>{{ entry.source || '—' }}</td>
                                            <td>{{ entry.recorded_at || '—' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <section class="wl-operator-section">
                            <div class="wl-panel-subtitle">{{ $t("Update wait policy") }}</div>
                            <p>
                                {{ $t("Wait up to") }} {{ operatorUpdateWaitMetricLabel('completion_timeout_ms') }} {{ $t("ms for completion responses, polling every") }} {{ operatorUpdateWaitMetricLabel('poll_interval_ms') }} {{ $t("ms before returning an accepted lifecycle.") }}
                            </p>
                        </section>

                        <section class="wl-operator-section">
                            <div class="wl-panel-subtitle">{{ $t("Structural limits") }}</div>
                            <div v-if="structuralLimitRows().length" class="wl-structural-limits">
                                <div v-for="entry in structuralLimitRows()" :key="entry.key" class="wl-structural-limits__row">
                                    <span>{{ entry.label }}</span>
                                    <span>{{ entry.value }}</span>
                                </div>
                            </div>
                            <p v-else>{{ $t("No structural limit snapshot available.") }}</p>
                        </section>
                    </div>
                </article>
            </section>
        </div>
    </div>
</template>

<script>
import moment from 'moment';
import { projectionMetric, projectionMetricLabel, projectionRebuildTotal } from '../projection-metrics.mjs';

export default {
    data() {
        return {
            stats: {},
            ready: false,
            loadingError: null,
            timeout: null,
            selectedClassification: typeof this.$route.query.classification === 'string'
                ? this.$route.query.classification : '',
            statsRequest: 0,
        };
    },

    computed: {
        classificationOptions() {
            return this.stats.classification_scope?.options || [];
        },

        classificationAvailable() {
            return this.stats.classification_scope?.available === true;
        },

        classificationScopeLabel() {
            return this.stats.classification_scope?.label || 'All workflow types';
        },

        dashboardNamespaceLabel() {
            return this.stats.operator_scope?.label || 'Operator scope';
        },

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
                    meta: this.stats.max_wait_time_workflow ? 'Oldest recorded open wait' : 'No waiting runs',
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
                    value: projectionMetricLabel(this.operatorProjectionNeedsRebuild()),
                    meta: this.operatorProjectionNeedsRebuild() === null
                        ? 'Open a workflow to inspect its history'
                        : 'Outstanding projection normalization work',
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

    beforeUnmount() {
        clearTimeout(this.timeout);
    },

    methods: {
        loadStats() {
            const request = ++this.statsRequest;
            const params = this.selectedClassification ? { classification: this.selectedClassification } : {};
            return this.$http.get(Waterline.basePath + '/api/stats', { params })
                .then((response) => {
                    if (request !== this.statsRequest) return false;
                    this.stats = response.data || {};
                    this.loadingError = null;
                    return true;
                })
                .catch((error) => {
                    if (request !== this.statsRequest) return false;
                    throw error;
                });
        },

        changeClassification() {
            const query = { ...this.$route.query };
            if (this.selectedClassification) query.classification = this.selectedClassification;
            else delete query.classification;
            this.$router.replace({ query });
            return this.refreshNow();
        },

        clearClassification() {
            this.selectedClassification = '';
            return this.changeClassification();
        },

        refreshStatsPeriodically() {
            clearTimeout(this.timeout);

            return this.loadStats()
                .then((applied) => {
                    if (!applied) return;
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
            return projectionMetric(this.operatorMetrics, group, key);
        },

        operatorProjectionMetricLabel(group, key = null) {
            return projectionMetricLabel(this.operatorProjectionMetric(group, key));
        },

        operatorProjectionDurationMetricLabel(group, key) {
            const value = this.operatorProjectionMetric(group, key);

            return value === null ? 'Unknown' : (value > 0 ? moment.duration(value).humanize() : '-');
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
            return projectionRebuildTotal(this.operatorMetrics);
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

        operatorMatchingRoleWakeOwner() {
            const matchingRole = (this.operatorMetrics && this.operatorMetrics.matching_role) || {};

            return typeof matchingRole.wake_owner === 'string' && matchingRole.wake_owner !== ''
                ? matchingRole.wake_owner
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

        operatorMatchingRoleDiscoveryLimitsAvailable() {
            const matchingRole = (this.operatorMetrics && this.operatorMetrics.matching_role) || {};
            const limits = matchingRole.discovery_limits;

            if (!limits || typeof limits !== 'object') {
                return false;
            }

            return [
                'poll_batch_cap',
                'availability_ceiling_seconds',
                'wake_signal_ttl_seconds',
                'workflow_task_lease_seconds',
                'activity_task_lease_seconds',
            ].some((key) => Number.isFinite(limits[key]));
        },

        operatorMatchingRoleDiscoveryLimit(key) {
            const matchingRole = (this.operatorMetrics && this.operatorMetrics.matching_role) || {};
            const limits = (matchingRole.discovery_limits && typeof matchingRole.discovery_limits === 'object')
                ? matchingRole.discovery_limits
                : {};

            return Number.isFinite(limits[key]) ? limits[key] : 'unknown';
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
    font-family: SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', monospace;
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
    font-family: SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', monospace;
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
    font-family: SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', monospace;
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
    font-family: SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', monospace;
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
