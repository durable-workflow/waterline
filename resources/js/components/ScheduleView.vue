<template>
    <div class="schedule-view">
        <section class="schedule-view__hero">
            <div>
                <p class="schedule-view__eyebrow">{{ $t("Operator surface") }}</p>
                <h1 class="schedule-view__title">{{ $t("Schedules") }}</h1>
                <p class="schedule-view__subtitle">
                    {{ $t("Trigger posture, backfills, and next-fire timing for recurring workflow schedules.") }}
                </p>
            </div>

            <div class="schedule-view__actions">
                <button class="btn btn-sm btn-outline-secondary" @click="editViewOptions" :disabled="savingOperatorPreferences">
                    {{ $t("View Options") }}
                </button>

                <button class="btn btn-sm btn-outline-secondary" @click="refresh">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon fill-text-color schedule-view__button-icon" aria-hidden="true">
                        <path d="M10 3v2a5 5 0 0 0-3.54 8.54l-1.41 1.41A7 7 0 0 1 10 3zm4.95 2.05A7 7 0 0 1 10 17v-2a5 5 0 0 0 3.54-8.54l1.41-1.41zM10 20l-4-4 4-4v8zm0-12V0l4 4-4 4z"></path>
                    </svg>
                    {{ $t("Refresh") }}
                </button>
            </div>
        </section>

        <form class="schedule-view__filters card card-bg-secondary" @submit.prevent="applyFilters">
            <div>
                <label for="waterline-schedule-status">{{ $t("Status") }}</label>
                <select id="waterline-schedule-status" v-model="statusFilter" class="form-control form-control-sm">
                    <option value="">{{ $t("All statuses") }}</option>
                    <option value="active">{{ $t("Active") }}</option>
                    <option value="paused">{{ $t("Paused") }}</option>
                    <option v-if="!serviceMode" value="deleted">{{ $t("Deleted") }}</option>
                </select>
            </div>
            <div>
                <label for="waterline-schedule-type">{{ $t("Workflow Type") }}</label>
                <input id="waterline-schedule-type" v-model="workflowTypeFilter" class="form-control form-control-sm" type="text">
            </div>
            <div>
                <label for="waterline-schedule-query">{{ $t("Schedule visibility query") }}</label>
                <input id="waterline-schedule-query" v-model="visibilityQueryFilter" class="form-control form-control-sm" type="text">
            </div>
            <button class="btn btn-sm btn-primary" type="submit">{{ $t("Apply Filters") }}</button>
        </form>

        <div v-if="loading" class="schedule-view__state card card-bg-secondary">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin fill-text-color schedule-view__state-icon" aria-hidden="true">
                <path d="M12 10a2 2 0 0 1-3.41 1.41A2 2 0 0 1 10 8V0a9.97 9.97 0 0 1 10 10h-8zm7.9 1.41A10 10 0 1 1 8.59.1v2.03a8 8 0 1 0 9.29 9.29h2.02zm-4.07 0a6 6 0 1 1-7.25-7.25v2.1a3.99 3.99 0 0 0-1.4 6.57 4 4 0 0 0 6.56-1.42h2.1z"></path>
            </svg>
            <p class="schedule-view__state-copy">{{ $t("Loading schedules…") }}</p>
        </div>

        <div v-else-if="error" class="schedule-view__state card card-bg-secondary schedule-view__state--error">
            <strong>{{ $t("Schedules unavailable") }}</strong>
            <p class="schedule-view__state-copy">{{ error }}</p>
            <button class="btn btn-sm btn-outline-primary" @click="refresh">{{ $t("Retry") }}</button>
        </div>

        <div v-else class="schedule-view__content">
            <section class="schedule-view__summary-grid">
                <article class="card schedule-view__summary-card">
                    <div class="card-body card-bg-secondary">
                        <div class="schedule-view__summary-label">{{ $t("Returned schedules") }}</div>
                        <div class="schedule-view__summary-value">{{ totalSchedules.toLocaleString($i18n.locale) }}</div>
                        <div class="schedule-view__summary-meta">{{ pagination ? pagination.total.toLocaleString($i18n.locale) : schedules.length.toLocaleString($i18n.locale) }} {{ $t("total in the filtered result set.") }}</div>
                    </div>
                </article>

                <article class="card schedule-view__summary-card">
                    <div class="card-body card-bg-secondary">
                        <div class="schedule-view__summary-label">{{ $t("Active") }}</div>
                        <div class="schedule-view__summary-value is-success">{{ activeScheduleCount.toLocaleString($i18n.locale) }}</div>
                        <div class="schedule-view__summary-meta">{{ $t("Schedules currently dispatching on cadence.") }}</div>
                    </div>
                </article>

                <article class="card schedule-view__summary-card">
                    <div class="card-body card-bg-secondary">
                        <div class="schedule-view__summary-label">{{ $t("Paused") }}</div>
                        <div class="schedule-view__summary-value is-warning">{{ pausedScheduleCount.toLocaleString($i18n.locale) }}</div>
                        <div class="schedule-view__summary-meta">{{ $t("Schedules waiting for operator resume.") }}</div>
                    </div>
                </article>

                <article class="card schedule-view__summary-card">
                    <div class="card-body card-bg-secondary">
                        <div class="schedule-view__summary-label">{{ $t("Overdue next fires") }}</div>
                        <div class="schedule-view__summary-value" :class="overdueScheduleCount > 0 ? 'is-danger' : ''">{{ overdueScheduleCount.toLocaleString($i18n.locale) }}</div>
                        <div class="schedule-view__summary-meta">{{ $t("Active schedules whose next fire time is already behind.") }}</div>
                    </div>
                </article>
            </section>

            <article class="card schedule-view__panel">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div>
                        <h5 class="mb-0">{{ $t("Schedule registry") }}</h5>
                        <small class="text-muted">{{ $t("Specification, state, next fire time, and operational controls.") }}</small>
                    </div>

                    <span class="schedule-view__pill schedule-view__pill--muted">
                        {{ currentFilterLabel }}
                    </span>
                </div>

                <div v-if="mutationsUnavailable && schedules.length > 0" class="schedule-view__notice" role="note">
                    {{ mutationUnavailableMessage }}
                </div>

                <div class="card-body card-bg-secondary p-0">
                    <div v-if="schedules.length > 0" class="table-responsive">
                        <table :class="schedulesTableClass" :aria-busy="refreshing ? 'true' : 'false'">
                            <thead>
                                <tr>
                                    <th v-if="columnEnabled('schedule_id')">{{ $t("Schedule") }}</th>
                                    <th v-if="columnEnabled('workflow_type')">{{ $t("Workflow Type") }}</th>
                                    <th v-if="columnEnabled('spec')">{{ $t("Spec") }}</th>
                                    <th v-if="columnEnabled('status')">{{ $t("Status") }}</th>
                                    <th v-if="columnEnabled('next_fire')">{{ $t("Next Fire") }}</th>
                                    <th v-if="columnEnabled('last_result')">{{ $t("Last Result") }}</th>
                                    <th v-if="columnEnabled('actions')">{{ $t("Actions") }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in scheduleRows" :key="row.key" :class="scheduleRowClass(row.schedule)" :data-schedule-id="row.id" :data-schedule-namespace="row.schedule.namespace || ''">
                                    <td v-if="columnEnabled('schedule_id')" :data-label="$t('Schedule')">
                                        <div class="schedule-view__cell-main">
                                            <code :title="row.id">{{ truncateId(row.id) }}</code>
                                            <div class="schedule-view__cell-meta" v-if="row.id && row.id !== truncateId(row.id)">{{ row.id }}</div>
                                            <div class="schedule-view__cell-meta" v-if="clusterScope && row.schedule.namespace">
                                                {{ $t("Namespace") }} {{ row.schedule.namespace }}
                                            </div>
                                        </div>
                                    </td>

                                    <td v-if="columnEnabled('workflow_type')" :data-label="$t('Workflow Type')">
                                        <div class="schedule-view__cell-main">
                                            <code>{{ row.schedule.workflow_type || row.schedule.workflow_class || '-' }}</code>
                                            <div class="schedule-view__cell-meta" v-if="row.schedule.workflow_class && row.schedule.workflow_type && row.schedule.workflow_type !== row.schedule.workflow_class">
                                                {{ row.schedule.workflow_class }}
                                            </div>
                                        </div>
                                    </td>

                                    <td v-if="columnEnabled('spec')" :data-label="$t('Spec')">
                                        <div class="schedule-view__cell-main">
                                            <span v-for="(expression, index) in row.cadence.cron" :key="`cron-${index}`" class="schedule-view__pill schedule-view__pill--muted">
                                                {{ $t("Cron") }} {{ expression }}
                                            </span>
                                            <span v-for="(interval, index) in row.cadence.intervals" :key="`interval-${index}`" class="schedule-view__pill schedule-view__pill--muted" :title="interval.every">
                                                {{ $t("Every") }} {{ formatDuration(interval.every) }}
                                            </span>
                                            <span v-if="row.cadence.empty" class="text-muted">{{ $t("Custom") }}</span>

                                            <div class="schedule-view__cell-meta" v-for="(interval, index) in row.cadence.intervals.filter((item) => item.offset)" :key="`offset-${index}`">
                                                {{ $t("Offset") }} {{ formatDuration(interval.offset) }}
                                            </div>
                                            <div class="schedule-view__cell-meta" v-if="row.cadence.timezone">
                                                {{ $t("Timezone") }} {{ row.cadence.timezone }}
                                            </div>
                                        </div>
                                    </td>

                                    <td v-if="columnEnabled('status')" :data-label="$t('Status')">
                                        <span class="schedule-view__pill" :class="statusToneClass(row.schedule.status)">
                                            {{ stateLabel(row.schedule.status || 'unknown') }}
                                        </span>
                                    </td>

                                    <td v-if="columnEnabled('next_fire')" :data-label="$t('Next Fire')">
                                        <div class="schedule-view__cell-main">
                                            <span :class="nextFireClass(row.schedule)">
                                                {{ row.schedule.next_fire_at ? formatTimestamp(row.schedule.next_fire_at) : '—' }}
                                            </span>
                                            <div class="schedule-view__cell-meta" v-if="row.schedule.next_fire_at && isOverdue(row.schedule)">
                                                {{ $t("Overdue trigger window") }}
                                            </div>
                                        </div>
                                    </td>

                                    <td v-if="columnEnabled('last_result')" :data-label="$t('Last Result')">
                                        <div class="schedule-view__cell-main">
                                            <span v-if="row.lastResult.at">{{ formatTimestamp(row.lastResult.at) }}</span>
                                            <span v-else class="text-muted">{{ $t("Never") }}</span>
                                            <div class="schedule-view__cell-meta" v-if="row.lastResult.state === 'recorded'">
                                                <span :class="toneTextClass(row.lastResult.tone)">{{ outcomeText(row.lastResult) }}</span>
                                                <span v-if="row.lastResult.reason" class="schedule-view__reason" :title="row.lastResult.reason"> · {{ row.lastResult.reason }}</span>
                                            </div>
                                            <div class="schedule-view__cell-meta" v-else-if="row.lastResult.state === 'unreported'">
                                                {{ $t("Outcome not reported") }}
                                            </div>
                                        </div>
                                    </td>

                                    <td v-if="columnEnabled('actions')" :data-label="$t('Actions')">
                                        <div class="schedule-view__action-group">
                                            <button
                                                v-if="row.schedule.status === 'active'"
                                                class="btn btn-sm btn-outline-warning"
                                                :disabled="mutationsUnavailable || isPending(row.key)"
                                                data-row-action="pause"
                                                @click="pauseSchedule(row.schedule)">
                                                {{ $t("Pause") }}
                                            </button>
                                            <button
                                                v-if="row.schedule.status === 'paused'"
                                                class="btn btn-sm btn-outline-success"
                                                :disabled="mutationsUnavailable || isPending(row.key)"
                                                data-row-action="resume"
                                                @click="resumeSchedule(row.schedule)">
                                                {{ $t("Resume") }}
                                            </button>
                                            <button
                                                v-if="row.schedule.status !== 'deleted'"
                                                class="btn btn-sm btn-outline-primary"
                                                :disabled="mutationsUnavailable || isPending(row.key)"
                                                data-row-action="trigger"
                                                @click="triggerNow(row.schedule)">
                                                {{ $t("Trigger") }}
                                            </button>
                                            <button
                                                v-if="row.schedule.status !== 'deleted'"
                                                class="btn btn-sm btn-outline-info"
                                                :disabled="mutationsUnavailable || isPending(row.key)"
                                                data-row-action="backfill"
                                                @click="showBackfillDialog(row.schedule, $event)">
                                                {{ $t("Backfill") }}
                                            </button>
                                            <button
                                                class="btn btn-sm btn-outline-secondary"
                                                data-row-action="history"
                                                @click="showHistoryDialog(row.schedule, $event)">
                                                {{ $t("History") }}
                                            </button>
                                            <button
                                                class="btn btn-sm btn-outline-secondary"
                                                data-row-action="details"
                                                @click="showDetailDialog(row.schedule, $event)">
                                                {{ $t("Details") }}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-else class="schedule-view__empty-state">
                        <strong>{{ $t("No schedules found") }}</strong>
                        <p class="mb-0 text-muted">{{ $t("No schedule rows matched the current filter state.") }}</p>
                    </div>
                </div>

                <div v-if="pagination && pagination.last_page > 1" class="card-footer schedule-view__pagination">
                    <button class="btn btn-secondary btn-sm" @click="goToPage(pagination.current_page - 1)" :disabled="pagination.current_page === 1">
                        {{ $t("Previous") }}
                    </button>

                    <div class="schedule-view__pagination-pages">
                        <template v-for="(page, index) in visiblePages" :key="`${page}-${index}-${pagination.current_page}`">
                            <span v-if="page === '...'" class="schedule-view__pagination-ellipsis">…</span>
                            <button
                                v-else
                                class="btn btn-sm"
                                :class="page === pagination.current_page ? 'btn-primary' : 'btn-outline-secondary'"
                                @click="goToPage(page)">
                                {{ page }}
                            </button>
                        </template>
                    </div>

                    <button class="btn btn-secondary btn-sm" @click="goToPage(pagination.current_page + 1)" :disabled="pagination.current_page === pagination.last_page">
                        {{ $t("Next") }}
                    </button>
                </div>
            </article>
        </div>

        <div v-if="showBackfill" class="schedule-view__modal" @click.self="closeBackfillDialog" @keydown.esc="closeBackfillDialog" @keydown.tab="trapDialogFocus">
            <div ref="backfillDialog" class="schedule-view__dialog card" role="dialog" aria-modal="true" aria-labelledby="waterline-backfill-title" tabindex="-1">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div>
                        <h5 id="waterline-backfill-title" class="mb-0">{{ $t("Backfill schedule") }}</h5>
                        <small class="text-muted">
                            {{ $t("Trigger missed executions across a historical window.") }}
                            <code>{{ backfillSchedule ? backfillSchedule.schedule_id : '—' }}</code>
                        </small>
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="closeBackfillDialog">
                        {{ $t("Close") }}
                    </button>
                </div>

                <div class="card-body card-bg-secondary">
                    <p class="schedule-view__dialog-copy">
                        {{ $t("Backfill will trigger workflow executions for missed schedule times between the supplied timestamps.") }}
                    </p>

                    <div class="form-group">
                        <label for="waterline-backfill-from" class="schedule-view__field-label">{{ $t("From") }}</label>
                        <input id="waterline-backfill-from" v-model="backfillFrom" type="datetime-local" class="form-control schedule-view__field" />
                    </div>

                    <div class="form-group">
                        <label for="waterline-backfill-to" class="schedule-view__field-label">{{ $t("To") }}</label>
                        <input id="waterline-backfill-to" v-model="backfillTo" type="datetime-local" class="form-control schedule-view__field" />
                    </div>

                    <div class="form-group mb-0">
                        <label for="waterline-backfill-overlap" class="schedule-view__field-label">{{ $t("Overlap Policy") }}</label>
                        <select id="waterline-backfill-overlap" v-model="backfillOverlapPolicy" class="form-control schedule-view__field">
                            <option value="">{{ $t("Use schedule default") }}</option>
                            <option v-for="policy in overlapPolicies" :key="policy.value" :value="policy.value">{{ $t(policy.label) }}</option>
                        </select>
                    </div>
                </div>

                <div class="card-footer d-flex justify-content-end schedule-view__dialog-actions">
                    <button class="btn btn-secondary" @click="closeBackfillDialog">{{ $t("Cancel") }}</button>
                    <button class="btn btn-primary" :disabled="backfillSubmitting" @click="executeBackfill">{{ $t("Backfill") }}</button>
                </div>
            </div>
        </div>

        <div v-if="showHistory" class="schedule-view__modal" @click.self="closeHistoryDialog" @keydown.esc="closeHistoryDialog" @keydown.tab="trapDialogFocus">
            <div ref="historyDialog" class="schedule-view__dialog schedule-view__dialog--wide card" role="dialog" aria-modal="true" aria-labelledby="waterline-schedule-history-title" tabindex="-1">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div>
                        <h5 id="waterline-schedule-history-title" class="mb-0">{{ $t("Schedule audit history") }}</h5>
                        <small class="text-muted">
                            {{ $t("Lifecycle events recorded for") }}
                            <code>{{ historySchedule ? historySchedule.schedule_id : '—' }}</code>.
                            <template v-if="clusterScope && historySchedule && historySchedule.namespace">
                                {{ $t("Namespace") }} {{ historySchedule.namespace }}
                            </template>
                        </small>
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="closeHistoryDialog">
                        {{ $t("Close") }}
                    </button>
                </div>

                <div class="card-body card-bg-secondary schedule-view__history-body">
                    <div v-if="historyLoading && historyEvents.length === 0" class="schedule-view__state">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin fill-text-color schedule-view__state-icon" aria-hidden="true">
                            <path d="M12 10a2 2 0 0 1-3.41 1.41A2 2 0 0 1 10 8V0a9.97 9.97 0 0 1 10 10h-8zm7.9 1.41A10 10 0 1 1 8.59.1v2.03a8 8 0 1 0 9.29 9.29h2.02zm-4.07 0a6 6 0 1 1-7.25-7.25v2.1a3.99 3.99 0 0 0-1.4 6.57 4 4 0 0 0 6.56-1.42h2.1z"></path>
                        </svg>
                        <p class="schedule-view__state-copy">{{ $t("Loading audit history…") }}</p>
                    </div>

                    <div v-else-if="historyError" class="schedule-view__state schedule-view__state--error">
                        <strong>{{ $t("Unable to load audit history") }}</strong>
                        <p class="schedule-view__state-copy">{{ historyError }}</p>
                        <button class="btn btn-sm btn-outline-primary" @click="loadHistoryEvents(true)">{{ $t("Retry") }}</button>
                    </div>

                    <div v-else-if="historyEvents.length === 0" class="schedule-view__empty-state">
                        <strong>{{ $t("No audit events recorded") }}</strong>
                        <p class="mb-0 text-muted">
                            {{ $t("The audit stream begins at the next lifecycle transition (create, pause, resume, trigger, or delete).") }}
                        </p>
                    </div>

                    <ol v-else class="schedule-view__history-list">
                        <li
                            v-for="event in historyEvents"
                            :key="event.id || event.sequence"
                            class="schedule-view__history-entry">
                            <div class="schedule-view__history-header">
                                <span class="schedule-view__pill" :class="historyEventToneClass(event.event_type)">
                                    {{ formatHistoryEventType(event.event_type) }}
                                </span>
                                <span class="schedule-view__history-sequence">#{{ event.sequence }}</span>
                                <span class="schedule-view__history-timestamp" v-if="event.recorded_at">
                                    {{ formatTimestamp(event.recorded_at) }}
                                </span>
                            </div>

                            <div class="schedule-view__history-meta" v-if="event.workflow_instance_id || event.workflow_run_id">
                                <span v-if="event.workflow_instance_id">
                                    {{ $t("instance") }} <code>{{ truncateId(event.workflow_instance_id) }}</code>
                                </span>
                                <span v-if="event.workflow_run_id">
                                    {{ $t("run") }} <code>{{ truncateId(event.workflow_run_id) }}</code>
                                </span>
                            </div>

                            <pre
                                v-if="event.payload && Object.keys(event.payload).length > 0"
                                class="schedule-view__history-payload"
                            >{{ formatHistoryPayload(event.payload) }}</pre>
                        </li>
                    </ol>
                </div>

                <div class="card-footer d-flex justify-content-between schedule-view__dialog-actions">
                    <small class="text-muted">
                        {{ $t('Showing {count} events', { count: historyEvents.length.toLocaleString($i18n.locale) }, historyEvents.length) }}{{ historyHasMore ? $t(' (more available)') : '' }}.
                    </small>

                    <div>
                        <button
                            v-if="historyHasMore"
                            class="btn btn-sm btn-outline-primary"
                            :disabled="historyLoadingMore"
                            @click="loadMoreHistoryEvents">
                            {{ historyLoadingMore ? $t('Loading…') : $t('Load more') }}
                        </button>
                        <button class="btn btn-sm btn-secondary" @click="closeHistoryDialog">{{ $t("Close") }}</button>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="showDetail" class="schedule-view__modal" @click.self="closeDetailDialog" @keydown.esc="closeDetailDialog" @keydown.tab="trapDialogFocus">
            <div ref="detailDialog" class="schedule-view__dialog schedule-view__dialog--wide card" role="dialog" aria-modal="true" aria-labelledby="waterline-schedule-detail-title" tabindex="-1">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div>
                        <h5 id="waterline-schedule-detail-title" class="mb-0">{{ $t("Schedule details") }}</h5>
                        <small class="text-muted">
                            <code>{{ detailSchedule ? detailSchedule.schedule_id : '—' }}</code>
                            <template v-if="detailSchedule && detailSchedule.namespace">
                                · {{ $t("Namespace") }} {{ detailSchedule.namespace }}
                            </template>
                        </small>
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="closeDetailDialog">
                        {{ $t("Close") }}
                    </button>
                </div>

                <div class="card-body card-bg-secondary schedule-view__history-body">
                    <div v-if="detailLoading" class="schedule-view__state">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin fill-text-color schedule-view__state-icon" aria-hidden="true">
                            <path d="M12 10a2 2 0 0 1-3.41 1.41A2 2 0 0 1 10 8V0a9.97 9.97 0 0 1 10 10h-8zm7.9 1.41A10 10 0 1 1 8.59.1v2.03a8 8 0 1 0 9.29 9.29h2.02zm-4.07 0a6 6 0 1 1-7.25-7.25v2.1a3.99 3.99 0 0 0-1.4 6.57 4 4 0 0 0 6.56-1.42h2.1z"></path>
                        </svg>
                        <p class="schedule-view__state-copy">{{ $t("Loading schedule details…") }}</p>
                    </div>

                    <div v-else-if="detailError" class="schedule-view__state schedule-view__state--error">
                        <strong>{{ $t("Unable to load schedule details") }}</strong>
                        <p class="schedule-view__state-copy">{{ detailError }}</p>
                        <button class="btn btn-sm btn-outline-primary" @click="loadDetail">{{ $t("Retry") }}</button>
                    </div>

                    <template v-else-if="detail">
                        <dl class="schedule-view__detail-grid">
                            <dt>{{ $t("Status") }}</dt>
                            <dd>
                                <span class="schedule-view__pill" :class="statusToneClass(detail.status)">{{ stateLabel(detail.status || 'unknown') }}</span>
                                <span v-if="detail.paused_at" class="schedule-view__cell-meta"> {{ formatTimestamp(detail.paused_at) }}</span>
                            </dd>

                            <dt>{{ $t("Workflow Type") }}</dt>
                            <dd><code>{{ detail.workflow_type || detail.workflow_class || '-' }}</code></dd>

                            <template v-if="detail.workflow_class && detail.workflow_type && detail.workflow_class !== detail.workflow_type">
                                <dt>{{ $t("Workflow class") }}</dt>
                                <dd><code>{{ detail.workflow_class }}</code></dd>
                            </template>

                            <dt>{{ $t("Spec") }}</dt>
                            <dd>
                                <div class="schedule-view__cell-main">
                                    <span v-for="(expression, index) in detailCadence.cron" :key="`detail-cron-${index}`">{{ $t("Cron") }} <code>{{ expression }}</code></span>
                                    <span v-for="(interval, index) in detailCadence.intervals" :key="`detail-interval-${index}`">
                                        {{ $t("Every") }} {{ formatDuration(interval.every) }} <code>{{ interval.every }}</code>
                                        <template v-if="interval.offset"> · {{ $t("Offset") }} {{ formatDuration(interval.offset) }}</template>
                                    </span>
                                    <span v-if="detailCadence.empty" class="text-muted">{{ $t("Custom") }}</span>
                                    <span v-if="detailCadence.timezone" class="schedule-view__cell-meta">{{ $t("Timezone") }} {{ detailCadence.timezone }}</span>
                                </div>
                            </dd>

                            <dt>{{ $t("Overlap Policy") }}</dt>
                            <dd>{{ overlapPolicyLabel(detail.overlap_policy) }}</dd>

                            <dt>{{ $t("Next Fire") }}</dt>
                            <dd>{{ detail.next_fire_at ? formatTimestamp(detail.next_fire_at) : '—' }}</dd>

                            <dt>{{ $t("Last Result") }}</dt>
                            <dd>
                                <template v-if="detailLastResult.state === 'recorded'">
                                    <span :class="toneTextClass(detailLastResult.tone)">{{ outcomeText(detailLastResult) }}</span>
                                    <span v-if="detailLastResult.at"> · {{ formatTimestamp(detailLastResult.at) }}</span>
                                    <div v-if="detailLastResult.reason" class="schedule-view__cell-meta schedule-view__reason">{{ detailLastResult.reason }}</div>
                                </template>
                                <template v-else-if="detailLastResult.state === 'unreported'">
                                    {{ formatTimestamp(detailLastResult.at) }} · <span class="text-muted">{{ $t("Outcome not reported") }}</span>
                                </template>
                                <span v-else class="text-muted">{{ $t("Never") }}</span>
                            </dd>

                            <dt>{{ $t("Fires") }}</dt>
                            <dd>{{ formatCount(detail.fires_count) }}</dd>

                            <dt>{{ $t("Failures") }}</dt>
                            <dd>{{ formatCount(detail.failures_count) }}</dd>

                            <dt>{{ $t("Skipped triggers") }}</dt>
                            <dd>
                                {{ formatCount(detail.skipped_trigger_count) }}
                                <span v-if="detail.last_skip_reason" class="schedule-view__cell-meta"> · {{ detail.last_skip_reason }}</span>
                            </dd>

                            <template v-if="detail.remaining_actions !== null && detail.remaining_actions !== undefined">
                                <dt>{{ $t("Remaining actions") }}</dt>
                                <dd>{{ formatCount(detail.remaining_actions) }}</dd>
                            </template>

                            <template v-if="detail.note">
                                <dt>{{ $t("Note") }}</dt>
                                <dd class="schedule-view__reason">{{ detail.note }}</dd>
                            </template>

                            <template v-if="detail.latest_workflow_instance_id">
                                <dt>{{ $t("Latest workflow") }}</dt>
                                <dd>
                                    <router-link :to="{ name: 'flow-detail', params: { instanceId: detail.latest_workflow_instance_id } }" @click="closeDetailDialog">
                                        <code>{{ detail.latest_workflow_instance_id }}</code>
                                    </router-link>
                                </dd>
                            </template>
                        </dl>

                        <h6 class="schedule-view__detail-heading">{{ $t("Recent fires") }}</h6>
                        <div v-if="detailRecentActions.length > 0" class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>{{ $t("Fired") }}</th>
                                        <th>{{ $t("Outcome") }}</th>
                                        <th>{{ $t("Workflow") }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(action, index) in detailRecentActions" :key="`recent-${index}`">
                                        <td>{{ action.fired_at ? formatTimestamp(action.fired_at) : '—' }}</td>
                                        <td>
                                            <span :class="toneTextClass(outcomeTone(action.outcome))">{{ outcomeText({ outcome: action.outcome, label: outcomeLabelFor(action.outcome) }) }}</span>
                                            <div v-if="action.reason" class="schedule-view__cell-meta schedule-view__reason">{{ action.reason }}</div>
                                        </td>
                                        <td>
                                            <router-link v-if="action.workflow_id" :to="{ name: 'flow-detail', params: { instanceId: action.workflow_id } }" @click="closeDetailDialog">
                                                <code>{{ truncateId(action.workflow_id) }}</code>
                                            </router-link>
                                            <span v-else>—</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-else class="mb-0 text-muted">{{ $t("No fires recorded.") }}</p>
                    </template>
                </div>

                <div class="card-footer d-flex justify-content-end schedule-view__dialog-actions">
                    <button class="btn btn-sm btn-outline-secondary" @click="openHistoryFromDetail">{{ $t("History") }}</button>
                    <button class="btn btn-sm btn-secondary" @click="closeDetailDialog">{{ $t("Close") }}</button>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import axios from '../http.mjs';
import { ambiguousCommandError } from '../request-budget.mjs';
import { operatorPermission } from '../operator-permissions.mjs';
import {
    OVERLAP_POLICIES,
    backfillCounts,
    backfillIcon,
    formatIsoDuration,
    outcomeLabel,
    outcomeTone,
    scheduleCadence,
    scheduleId,
    scheduleLastResult,
    scheduleRequest,
    scheduleRowKey,
} from '../schedule-presenter.mjs';

export default {
    name: 'ScheduleView',

    props: {
        apiEndpoint: {
            type: String,
            default: null
        }
    },

    data() {
        return {
            loading: true,
            error: null,
            schedules: [],
            pagination: null,
            operatorScope: null,
            observedBackend: null,
            statusFilter: '',
            appliedStatus: '',
            workflowTypeFilter: '',
            visibilityQueryFilter: '',
            appliedWorkflowType: '',
            appliedVisibilityQuery: '',
            listRequest: 0,
            currentPage: 1,
            pendingRowKeys: {},
            refreshing: false,
            dialogReturnFocus: null,
            showBackfill: false,
            backfillSchedule: null,
            backfillFrom: '',
            backfillTo: '',
            backfillOverlapPolicy: '',
            backfillSubmitting: false,
            operatorPreferences: {},
            effectiveOperatorPreferences: {},
            savingOperatorPreferences: false,
            showHistory: false,
            historySchedule: null,
            historyEvents: [],
            historyLoading: false,
            historyLoadingMore: false,
            historyError: null,
            historyHasMore: false,
            historyNextCursor: null,
            historyPageLimit: 100,
            showDetail: false,
            detailSchedule: null,
            detail: null,
            detailLoading: false,
            detailError: null
        };
    },

    computed: {
        overlapPolicies() {
            return OVERLAP_POLICIES;
        },

        backend() {
            return this.observedBackend || (typeof Waterline !== 'undefined' && Waterline.backend ? Waterline.backend : {});
        },

        serviceMode() {
            return this.backend.mode === 'service';
        },

        mutationsUnavailable() {
            return !operatorPermission('schedules', this.backend).allowed;
        },

        mutationUnavailableMessage() {
            return operatorPermission('schedules', this.backend).reason === 'waterline_action_forbidden'
                ? this.$t("You are not authorized to change schedules.")
                : this.$t("This Waterline is read-only. Pause, resume, trigger, and backfill are unavailable.");
        },

        clusterScope() {
            return this.operatorScope?.mode === 'cluster';
        },

        scheduleRows() {
            return this.schedules.map((schedule) => ({
                schedule,
                key: scheduleRowKey(schedule),
                id: scheduleId(schedule),
                cadence: scheduleCadence(schedule.spec),
                lastResult: scheduleLastResult(schedule),
            }));
        },

        detailCadence() {
            return scheduleCadence(this.detail?.spec);
        },

        detailLastResult() {
            return scheduleLastResult(this.detail);
        },

        detailRecentActions() {
            const actions = Array.isArray(this.detail?.recent_actions) ? this.detail.recent_actions : [];

            return actions.slice().reverse();
        },

        totalSchedules() {
            return this.pagination?.total || this.schedules.length;
        },

        activeScheduleCount() {
            return this.schedules.filter((schedule) => schedule.status === 'active').length;
        },

        pausedScheduleCount() {
            return this.schedules.filter((schedule) => schedule.status === 'paused').length;
        },

        overdueScheduleCount() {
            return this.schedules.filter((schedule) => this.isOverdue(schedule)).length;
        },

        currentFilterLabel() {
            return this.appliedStatus ? this.$t("{value1} only", { value1: this.stateLabel(this.appliedStatus) }) : this.$t('all statuses');
        },

        visiblePages() {
            if (!this.pagination) return [];
            const total = this.pagination.last_page;
            const current = this.pagination.current_page;
            const delta = 2;
            const range = [];
            const rangeWithDots = [];

            for (let i = Math.max(2, current - delta); i <= Math.min(total - 1, current + delta); i++) {
                range.push(i);
            }

            if (current - delta > 2) {
                rangeWithDots.push(1, '...');
            } else {
                rangeWithDots.push(1);
            }

            rangeWithDots.push(...range);

            if (current + delta < total - 1) {
                rangeWithDots.push('...', total);
            } else if (total > 1) {
                rangeWithDots.push(total);
            }

            return rangeWithDots;
        },

        schedulesTableClass() {
            const classes = ['table', 'table-hover', 'mb-0', 'schedule-view__table'];

            if (this.schedulesListDensity() === 'dense') {
                classes.push('table-sm');
            }

            return classes.join(' ');
        }
    },

    mounted() {
        this.loadOperatorPreferences().finally(() => this.loadData());
    },

    methods: {
        applyFilters() {
            this.appliedStatus = this.statusFilter;
            this.appliedWorkflowType = this.workflowTypeFilter.trim();
            this.appliedVisibilityQuery = this.visibilityQueryFilter.trim();
            this.currentPage = 1;
            return this.loadData();
        },

        listParams() {
            const params = {page: this.currentPage};
            if (this.appliedStatus) params.status = this.appliedStatus;
            if (this.appliedWorkflowType) params.workflow_type = this.appliedWorkflowType;
            if (this.appliedVisibilityQuery) params.query = this.appliedVisibilityQuery;
            return params;
        },

        async loadData() {
            const request = ++this.listRequest;
            // A refresh keeps the current rows on screen so a row action keeps
            // its place and keyboard focus.
            this.loading = this.schedules.length === 0;
            this.refreshing = !this.loading;
            this.error = null;

            try {
                const response = await axios.get(this.resolvedApiEndpoint(), {params: this.listParams()});
                if (request !== this.listRequest) return;
                this.schedules = response.data.data || [];
                this.operatorScope = response.data.operator_scope || null;
                this.observedBackend = response.data.backend || null;
                this.pagination = {
                    current_page: response.data.current_page,
                    last_page: response.data.last_page,
                    per_page: response.data.per_page,
                    total: response.data.total
                };
            } catch (e) {
                if (request !== this.listRequest) return;
                this.error = this.responseMessage(e, this.$t("Failed to load schedules"));
            } finally {
                if (request === this.listRequest) {
                    this.loading = false;
                    this.refreshing = false;
                }
            }
        },

        refresh() {
            this.loadData();
        },

        resolvedApiEndpoint() {
            if (this.apiEndpoint) {
                return this.apiEndpoint;
            }

            return this.waterlineBasePath() + '/api/v2/schedules';
        },

        scheduleRequest(schedule, suffix = '') {
            return scheduleRequest(this.resolvedApiEndpoint(), schedule, suffix);
        },

        preferenceEndpoint() {
            return this.waterlineBasePath() + '/api/preferences/schedules-list';
        },

        waterlineBasePath() {
            return typeof Waterline !== 'undefined' && Waterline.basePath
                ? Waterline.basePath
                : '';
        },

        async loadOperatorPreferences() {
            try {
                const response = await axios.get(this.preferenceEndpoint() + '?' + this.operatorPreferenceQueryString());
                this.applyOperatorPreferencePayload(response.data || {});
            } catch (e) {
                this.operatorPreferences = {};
                this.effectiveOperatorPreferences = {
                    row_density: 'comfortable',
                    columns: this.defaultSchedulesListColumns(),
                };
            }
        },

        applyOperatorPreferencePayload(payload) {
            this.operatorPreferences = payload.preferences || {};
            this.effectiveOperatorPreferences = {
                row_density: 'comfortable',
                columns: this.defaultSchedulesListColumns(),
                ...(payload.effective_preferences || {}),
            };
            this.effectiveOperatorPreferences.columns = this.normalizeSchedulesListColumns(
                this.effectiveOperatorPreferences.columns
            );
        },

        operatorPreferenceQueryString() {
            const params = new URLSearchParams();
            const query = new URLSearchParams(window.location.search || '');

            ['density', 'row_density', 'columns'].forEach((key) => {
                if (query.has(key)) {
                    params.set(key, query.get(key));
                }
            });

            return params.toString();
        },

        schedulesListDensity() {
            return this.effectiveOperatorPreferences.row_density === 'dense'
                ? 'dense'
                : 'comfortable';
        },

        schedulesListColumnOptions() {
            return [
                {key: 'schedule_id', label: this.$t("Schedule ID")},
                {key: 'workflow_type', label: this.$t("Workflow Type")},
                {key: 'spec', label: this.$t("Spec")},
                {key: 'status', label: this.$t("Status")},
                {key: 'next_fire', label: this.$t("Next Fire")},
                {key: 'last_result', label: this.$t("Last Result")},
                {key: 'actions', label: this.$t("Actions")},
            ];
        },

        defaultSchedulesListColumns() {
            return this.schedulesListColumnOptions().map((column) => column.key);
        },

        normalizeSchedulesListColumns(columns) {
            const allowed = this.schedulesListColumnOptions().map((column) => column.key);
            const requested = Array.isArray(columns) ? columns : this.defaultSchedulesListColumns();
            const normalized = requested.filter((column) => allowed.includes(column));

            if (!normalized.includes('schedule_id')) {
                normalized.unshift('schedule_id');
            }

            return normalized.length > 0 ? normalized : this.defaultSchedulesListColumns();
        },

        columnEnabled(column) {
            return this.normalizeSchedulesListColumns(this.effectiveOperatorPreferences.columns).includes(column);
        },

        async persistSchedulesListPreferences(preferences) {
            const payload = {
                ...this.operatorPreferences,
                ...preferences,
            };

            if (payload.columns) {
                payload.columns = this.normalizeSchedulesListColumns(payload.columns);
            }

            this.savingOperatorPreferences = true;

            try {
                const response = await axios.put(this.preferenceEndpoint(), {
                    preferences: payload,
                });
                this.applyOperatorPreferencePayload(response.data || {});
            } finally {
                this.savingOperatorPreferences = false;
            }
        },

        async editViewOptions() {
            const columns = this.normalizeSchedulesListColumns(this.effectiveOperatorPreferences.columns);
            const columnHtml = this.schedulesListColumnOptions().map((column) => `
                <label class="d-flex align-items-center justify-content-start mb-2" for="waterline-schedule-column-${this.escapeHtml(column.key)}">
                    <input id="waterline-schedule-column-${this.escapeHtml(column.key)}"
                           type="checkbox"
                           class="mr-2 waterline-schedule-column-option"
                           value="${this.escapeHtml(column.key)}"
                           ${columns.includes(column.key) ? 'checked' : ''}
                           ${column.key === 'schedule_id' ? 'disabled' : ''}>
                    <span>${this.escapeHtml(column.label)}</span>
                </label>
            `).join('');

            const result = await this.$dialog({
                title: this.$t("View Options"),
                html: `
                    <div class="text-left">
                        <label class="d-block mb-1">${this.escapeHtml(this.$t("Density"))}</label>
                        <select id="waterline-schedules-density" class="swal2-input">
                            <option value="comfortable" ${this.schedulesListDensity() === 'comfortable' ? 'selected' : ''}>${this.escapeHtml(this.$t("Comfortable"))}</option>
                            <option value="dense" ${this.schedulesListDensity() === 'dense' ? 'selected' : ''}>${this.escapeHtml(this.$t("Dense"))}</option>
                        </select>
                        <div class="mt-3">
                            <label class="d-block mb-2">${this.escapeHtml(this.$t("Columns"))}</label>
                            ${columnHtml}
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: this.$t("Save Options"),
                background: this.swalBackground(),
                preConfirm: () => {
                    const selectedColumns = Array.from(document.querySelectorAll('.waterline-schedule-column-option'))
                        .filter((input) => input.checked || input.value === 'schedule_id')
                        .map((input) => input.value);

                    return {
                        row_density: document.getElementById('waterline-schedules-density').value,
                        columns: selectedColumns,
                    };
                },
            });

            if (!result.isConfirmed) {
                return;
            }

            await this.persistSchedulesListPreferences(result.value);
        },

        goToPage(page) {
            if (page < 1 || page === '...' || (this.pagination && page > this.pagination.last_page)) return;
            this.currentPage = page;
            this.loadData();
        },

        isPending(key) {
            return this.pendingRowKeys[key] === true;
        },

        // Runs one row's action while its buttons are disabled, then focuses
        // the row control the action names, once the buttons are enabled again.
        async runRowAction(schedule, action) {
            const key = scheduleRowKey(schedule);

            if (this.isPending(key)) {
                return;
            }

            this.pendingRowKeys = { ...this.pendingRowKeys, [key]: true };
            let focusTarget = null;

            try {
                focusTarget = await action();
            } finally {
                const pending = { ...this.pendingRowKeys };
                delete pending[key];
                this.pendingRowKeys = pending;
            }

            if (focusTarget) {
                await this.$nextTick();
                this.focusRowAction(schedule, focusTarget);
            }
        },

        focusRowAction(schedule, action) {
            const id = scheduleId(schedule);
            const namespace = typeof schedule?.namespace === 'string' ? schedule.namespace : '';
            const row = Array.from(this.$el?.querySelectorAll?.('tr[data-schedule-id]') || [])
                .find((element) => element.dataset.scheduleId === id && (element.dataset.scheduleNamespace || '') === namespace);
            const button = row?.querySelector(`[data-row-action="${action}"]`);

            if (button && !button.disabled) {
                button.focus();
            }
        },

        pauseSchedule(schedule) {
            return this.runRowAction(schedule, async () => {
                try {
                    const request = this.scheduleRequest(schedule, '/pause');
                    await axios.post(request.url, {}, { params: request.params });
                    await this.loadData();

                    return 'resume';
                } catch (e) {
                    await this.showActionError(this.$t("Failed to pause schedule"), e);

                    return 'pause';
                }
            });
        },

        resumeSchedule(schedule) {
            return this.runRowAction(schedule, async () => {
                try {
                    const request = this.scheduleRequest(schedule, '/resume');
                    await axios.post(request.url, {}, { params: request.params });
                    await this.loadData();

                    return 'pause';
                } catch (e) {
                    await this.showActionError(this.$t("Failed to resume schedule"), e);

                    return 'resume';
                }
            });
        },

        async triggerNow(schedule) {
            const confirmation = await this.$dialog({
                title: this.$t("Trigger this schedule now?"),
                text: this.$t("Waterline will attempt an immediate schedule dispatch."),
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: this.$t("Trigger now"),
                background: this.swalBackground(),
            });

            if (!confirmation.isConfirmed) {
                return;
            }

            await this.runRowAction(schedule, async () => {
                try {
                    const request = this.scheduleRequest(schedule, '/trigger');
                    const response = await axios.post(request.url, {}, { params: request.params });
                    await this.$dialog({
                        ...this.triggerResultDialog(response.data || {}),
                        confirmButtonText: this.$t("Okay"),
                        background: this.swalBackground(),
                    });

                    await this.loadData();
                } catch (e) {
                    await this.showActionError(this.$t("Failed to trigger schedule"), e);
                }

                return 'trigger';
            });
        },

        triggerResultDialog(result) {
            if (result.triggered === true || result.outcome === 'triggered') {
                return {
                    title: this.$t("Schedule triggered"),
                    text: this.$t("Schedule triggered. Instance ID: {value1}", { value1: result.workflow_id || result.instance_id || this.$t("Unavailable") }),
                    icon: 'success',
                };
            }

            if (result.outcome === 'buffered') {
                return {
                    title: this.$t("Trigger buffered"),
                    text: this.$t("The trigger was buffered and starts when the running workflow finishes."),
                    icon: 'info',
                };
            }

            return {
                title: this.$t("Trigger skipped"),
                text: result.reason
                    ? this.$t("The schedule trigger was skipped. Reason: {value1}", { value1: result.reason })
                    : this.$t("Schedule trigger was skipped, likely because of the configured overlap policy."),
                icon: 'info',
            };
        },

        rememberDialogFocus(event) {
            this.dialogReturnFocus = event?.currentTarget || document.activeElement || null;
        },

        focusDialog(ref) {
            this.$nextTick(() => {
                const dialog = this.$refs[ref];

                if (dialog && typeof dialog.focus === 'function') {
                    dialog.focus();
                }
            });
        },

        restoreDialogFocus() {
            const target = this.dialogReturnFocus;
            this.dialogReturnFocus = null;

            this.$nextTick(() => {
                if (target && target.isConnected && typeof target.focus === 'function') {
                    target.focus();
                }
            });
        },

        showBackfillDialog(schedule, event) {
            this.rememberDialogFocus(event);
            this.backfillSchedule = schedule;
            this.backfillFrom = '';
            this.backfillTo = '';
            this.backfillOverlapPolicy = '';
            this.showBackfill = true;
            this.focusDialog('backfillDialog');
        },

        closeBackfillDialog() {
            this.showBackfill = false;
            this.restoreDialogFocus();
        },

        async executeBackfill() {
            if (!this.backfillFrom || !this.backfillTo) {
                await this.$dialog({
                    title: this.$t("Missing backfill range"),
                    text: this.$t("Specify both the start and end timestamps for the backfill window."),
                    icon: 'warning',
                    confirmButtonText: this.$t("Okay"),
                    background: this.swalBackground(),
                });
                return;
            }

            const schedule = this.backfillSchedule;

            if (!schedule || this.backfillSubmitting) {
                return;
            }

            this.backfillSubmitting = true;

            try {
                const from = new Date(this.backfillFrom).toISOString();
                const to = new Date(this.backfillTo).toISOString();
                const payload = { from, to };

                if (this.backfillOverlapPolicy) {
                    payload.overlap_policy = this.backfillOverlapPolicy;
                }

                const request = this.scheduleRequest(schedule, '/backfill');
                const response = await axios.post(request.url, payload, { params: request.params });
                const counts = backfillCounts(response.data || {});

                // The result dialog takes focus first. Focus returns to the
                // row's Backfill button only after that dialog closes.
                this.showBackfill = false;
                this.dialogReturnFocus = null;

                await this.$dialog({
                    title: this.$t("Backfill complete"),
                    text: counts.attempted === 0
                        ? this.$t("No schedule times fell inside this window.")
                        : this.$t("Schedule times: {attempted}. Started: {started}. Skipped: {skipped}. Failed: {failed}.", {
                            attempted: counts.attempted.toLocaleString(this.$i18n.locale),
                            started: counts.started.toLocaleString(this.$i18n.locale),
                            skipped: counts.skipped.toLocaleString(this.$i18n.locale),
                            failed: counts.failed.toLocaleString(this.$i18n.locale),
                        }),
                    icon: backfillIcon(counts),
                    confirmButtonText: this.$t("Okay"),
                    background: this.swalBackground(),
                });

                await this.loadData();
                await this.$nextTick();
                this.focusRowAction(schedule, 'backfill');
            } catch (e) {
                this.showActionError(this.$t("Backfill failed"), e);
            } finally {
                this.backfillSubmitting = false;
            }
        },

        showHistoryDialog(schedule, event) {
            if (event) {
                this.rememberDialogFocus(event);
            }

            this.historySchedule = schedule;
            this.historyEvents = [];
            this.historyError = null;
            this.historyHasMore = false;
            this.historyNextCursor = null;
            this.showHistory = true;
            this.focusDialog('historyDialog');
            this.loadHistoryEvents(true);
        },

        closeHistoryDialog() {
            this.showHistory = false;
            this.restoreDialogFocus();
        },

        async loadHistoryEvents(reset = false) {
            if (!this.historySchedule) {
                return;
            }

            if (reset) {
                this.historyLoading = true;
                this.historyError = null;
                this.historyEvents = [];
                this.historyNextCursor = null;
                this.historyHasMore = false;
            }

            try {
                const request = this.scheduleRequest(this.historySchedule, '/history');
                const params = { ...request.params, limit: this.historyPageLimit };
                if (!reset && this.historyNextCursor !== null) {
                    params.after_sequence = this.historyNextCursor;
                }

                const response = await axios.get(request.url, { params });

                const events = Array.isArray(response.data?.events) ? response.data.events : [];

                if (reset) {
                    this.historyEvents = events;
                } else {
                    this.historyEvents = this.historyEvents.concat(events);
                }

                this.historyHasMore = Boolean(response.data?.has_more);
                this.historyNextCursor = response.data?.next_cursor ?? null;
            } catch (e) {
                this.historyError = this.responseMessage(e, this.$t("Failed to load audit history"));
            } finally {
                this.historyLoading = false;
                this.historyLoadingMore = false;
            }
        },

        async loadMoreHistoryEvents() {
            if (this.historyLoadingMore || !this.historyHasMore) {
                return;
            }
            this.historyLoadingMore = true;
            await this.loadHistoryEvents(false);
        },

        showDetailDialog(schedule, event) {
            this.rememberDialogFocus(event);
            this.detailSchedule = schedule;
            this.detail = null;
            this.detailError = null;
            this.showDetail = true;
            this.focusDialog('detailDialog');
            this.loadDetail();
        },

        closeDetailDialog() {
            this.showDetail = false;
            this.restoreDialogFocus();
        },

        openHistoryFromDetail() {
            const schedule = this.detailSchedule;
            this.showDetail = false;

            if (schedule) {
                this.showHistoryDialog(schedule);
            }
        },

        async loadDetail() {
            const schedule = this.detailSchedule;

            if (!schedule) {
                return;
            }

            this.detailLoading = true;
            this.detailError = null;

            try {
                const request = this.scheduleRequest(schedule);
                const response = await axios.get(request.url, { params: request.params });
                const detail = response.data;

                if (this.detailSchedule !== schedule) {
                    return;
                }

                if (!detail || typeof detail !== 'object' || Array.isArray(detail) || scheduleId(detail) === null) {
                    this.detailError = this.$t("Request failed");
                } else {
                    this.detail = detail;
                }
            } catch (e) {
                if (this.detailSchedule === schedule) {
                    this.detailError = this.responseMessage(e, this.$t("Request failed"));
                }
            } finally {
                if (this.detailSchedule === schedule) {
                    this.detailLoading = false;
                }
            }
        },

        formatHistoryEventType(type) {
            if (typeof type !== 'string' || type === '') {
                return this.$t("Unknown event");
            }
            return type
                .replace(/([a-z])([A-Z])/g, '$1 $2')
                .replace(/_/g, ' ');
        },

        historyEventToneClass(type) {
            switch (type) {
                case 'ScheduleCreated':
                case 'ScheduleResumed':
                case 'ScheduleTriggered':
                    return 'is-success';
                case 'SchedulePaused':
                case 'ScheduleTriggerSkipped':
                    return 'is-warning';
                case 'ScheduleDeleted':
                    return 'is-danger';
                case 'ScheduleUpdated':
                    return 'is-info';
                default:
                    return 'is-muted';
            }
        },

        formatHistoryPayload(payload) {
            try {
                return JSON.stringify(payload, null, 2);
            } catch (e) {
                return String(payload);
            }
        },

        statusToneClass(status) {
            return {
                active: 'is-success',
                paused: 'is-warning',
                deleted: 'is-muted',
            }[status] || 'is-muted';
        },

        toneTextClass(tone) {
            return {
                success: 'text-success',
                warning: 'text-warning',
                danger: 'text-danger',
            }[tone] || 'text-muted';
        },

        outcomeTone(outcome) {
            return outcomeTone(outcome);
        },

        // Keeps Tab and Shift+Tab inside an open schedule dialog.
        trapDialogFocus(event) {
            const dialog = event.currentTarget.querySelector('[role="dialog"]');

            if (!dialog) {
                return;
            }

            const focusable = Array.from(dialog.querySelectorAll(
                'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
            )).filter((element) => element.getClientRects().length > 0);

            if (focusable.length === 0) {
                event.preventDefault();
                dialog.focus();
                return;
            }

            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            const active = document.activeElement;

            if (event.shiftKey && (active === first || active === dialog || !dialog.contains(active))) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && (active === last || !dialog.contains(active))) {
                event.preventDefault();
                first.focus();
            }
        },

        outcomeLabelFor(outcome) {
            return outcomeLabel(outcome);
        },

        // Known outcomes are translated; an unrecognized outcome is a machine
        // value and is shown as reported.
        outcomeText(result) {
            if (result?.label) {
                return this.$t(result.label);
            }

            return result?.outcome || this.$t("Unknown");
        },

        overlapPolicyLabel(policy) {
            const known = OVERLAP_POLICIES.find((item) => item.value === policy);

            if (known) {
                return this.$t(known.label);
            }

            return policy || '—';
        },

        scheduleRowClass(schedule) {
            return this.isOverdue(schedule) ? 'schedule-view__row--overdue' : '';
        },

        isOverdue(schedule) {
            if (!schedule || !schedule.next_fire_at || schedule.status !== 'active') {
                return false;
            }

            return new Date(schedule.next_fire_at) < new Date();
        },

        nextFireClass(schedule) {
            if (!schedule?.next_fire_at || schedule.status !== 'active') return '';
            const fireTime = new Date(schedule.next_fire_at);
            const now = new Date();
            const diffMinutes = (fireTime - now) / (1000 * 60);

            if (diffMinutes < 0) return 'text-danger';
            if (diffMinutes < 60) return 'text-warning';
            return 'text-success';
        },

        formatTimestamp(timestamp) {
            if (!timestamp) return '';
            try {
                return new Date(timestamp).toLocaleString(this.$i18n.locale);
            } catch (e) {
                return timestamp;
            }
        },

        formatDuration(value) {
            return formatIsoDuration(value, this.$i18n.locale);
        },

        formatCount(value) {
            return Number.isFinite(value) ? value.toLocaleString(this.$i18n.locale) : '—';
        },

        truncateId(id) {
            if (!id) return '';
            return id.length > 20 ? `${id.substring(0, 12)}…${id.substring(id.length - 4)}` : id;
        },

        escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        },

        showActionError(title, error) {
            const unknown = ambiguousCommandError(error, 'schedule');
            const text = unknown
                ? this.$t("The command outcome is unknown. Inspect the schedule before sending it again.")
                : this.responseMessage(error, this.$t("Request failed"));

            return this.$dialog({
                title: unknown ? this.$t("Command outcome unknown") : title,
                text,
                icon: 'error',
                confirmButtonText: this.$t("Okay"),
                background: this.swalBackground(),
            });
        },

        // Service-mode errors carry a machine code in `error` and the
        // operator-facing text in `message`; embedded errors use `error` for text.
        responseMessage(error, fallback) {
            const data = error?.response?.data;

            return data?.message || data?.error || error?.message || fallback;
        },

        swalBackground() {
            return this.$root && this.$root.theme === 'light' ? '#ffffff' : '#1c1c1c';
        },
    }
};
</script>

<style scoped>
.schedule-view {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.schedule-view__hero {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 1rem;
}

.schedule-view__eyebrow {
    margin: 0 0 0.45rem;
    color: var(--wl-text-soft);
    font-family: SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', monospace;
    font-size: 0.72rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.schedule-view__title {
    margin: 0;
    color: var(--wl-text);
    font-size: 2.1rem;
    font-weight: 600;
    letter-spacing: -0.04em;
}

.schedule-view__subtitle {
    margin: 0.5rem 0 0;
    max-width: 40rem;
    color: var(--wl-text-muted);
}

.schedule-view__actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    align-items: center;
    gap: 0.75rem;
}

.schedule-view__filters {
    display: grid;
    grid-template-columns: minmax(8rem, 0.7fr) minmax(10rem, 1fr) minmax(12rem, 1.5fr) auto;
    gap: 1rem;
    align-items: end;
    padding: 1rem;
}

.schedule-view__filters > div {
    min-width: 0;
}

.schedule-view__filters label {
    color: var(--wl-text-muted);
}

.schedule-view__filters .form-control {
    width: 100%;
}

.schedule-view__button-icon {
    width: 0.95rem;
    height: 0.95rem;
    margin-right: 0.45rem;
}

.schedule-view__state {
    min-height: 18rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 2rem;
}

.schedule-view__state-icon {
    width: 2rem;
    height: 2rem;
}

.schedule-view__state-copy {
    margin: 0.85rem 0 0;
    color: var(--wl-text-muted);
}

.schedule-view__content {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.schedule-view__summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
}

.schedule-view__summary-label {
    color: var(--wl-text-soft);
    font-family: SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', monospace;
    font-size: 0.72rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.schedule-view__summary-value {
    margin-top: 0.65rem;
    color: var(--wl-text);
    font-size: 2rem;
    font-weight: 600;
    letter-spacing: -0.04em;
}

.schedule-view__summary-value.is-success {
    color: var(--wl-success);
}

.schedule-view__summary-value.is-warning {
    color: var(--wl-warning);
}

.schedule-view__summary-value.is-danger {
    color: var(--wl-danger);
}

.schedule-view__summary-meta {
    margin-top: 0.55rem;
    color: var(--wl-text-muted);
    font-size: 0.92rem;
}

.schedule-view__pill {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.45rem 0.75rem;
    border-radius: 999px;
    background: color-mix(in srgb, var(--wl-text) 5%, transparent);
    color: var(--wl-text-muted);
    font-family: SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', monospace;
    font-size: 0.72rem;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.schedule-view__pill.is-success {
    background: color-mix(in srgb, var(--wl-success) 16%, transparent);
    color: var(--wl-success);
}

.schedule-view__pill.is-warning {
    background: color-mix(in srgb, var(--wl-warning) 16%, transparent);
    color: var(--wl-warning);
}

.schedule-view__pill.is-muted,
.schedule-view__pill--muted {
    background: color-mix(in srgb, var(--wl-text) 5%, transparent);
    color: var(--wl-text-muted);
}

.schedule-view__pill.is-info {
    background: color-mix(in srgb, var(--wl-info, #3182ce) 16%, transparent);
    color: var(--wl-info, #3182ce);
}

.schedule-view__pill.is-danger {
    background: color-mix(in srgb, var(--wl-danger) 16%, transparent);
    color: var(--wl-danger);
}

.schedule-view__row--overdue {
    background: color-mix(in srgb, var(--wl-danger) 8%, transparent);
}

.schedule-view__cell-main {
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
}

.schedule-view__cell-main code {
    color: var(--wl-text);
    font-size: 0.86rem;
    overflow-wrap: anywhere;
}

.schedule-view__cell-meta {
    color: var(--wl-text-soft);
    font-size: 0.8rem;
    overflow-wrap: anywhere;
}

.schedule-view__action-group {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
}

.schedule-view__empty-state {
    display: flex;
    min-height: 18rem;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.55rem;
    text-align: center;
    padding: 2rem;
}

.schedule-view__empty-state strong {
    color: var(--wl-text);
}

.schedule-view__pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}

.schedule-view__pagination-pages {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.45rem;
}

.schedule-view__pagination-ellipsis {
    color: var(--wl-text-soft);
    font-family: SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', monospace;
    padding: 0 0.35rem;
}

.schedule-view__modal {
    position: fixed;
    inset: 0;
    z-index: 60;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    background: rgba(0, 0, 0, 0.45);
}

.schedule-view__dialog {
    width: min(34rem, 100%);
}

.schedule-view__dialog-copy {
    color: var(--wl-text-muted);
}

.schedule-view__field-label {
    display: block;
    margin-bottom: 0.45rem;
    color: var(--wl-text-soft);
    font-family: SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', monospace;
    font-size: 0.72rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.schedule-view__field {
    width: 100%;
}

.schedule-view__dialog-actions {
    gap: 0.75rem;
}

.schedule-view__dialog--wide {
    width: min(56rem, 100%);
}

.schedule-view__history-body {
    max-height: min(70vh, 40rem);
    overflow-y: auto;
}

.schedule-view__history-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.9rem;
}

.schedule-view__history-entry {
    padding: 0.85rem 1rem;
    border-radius: 0.5rem;
    background: color-mix(in srgb, var(--wl-text) 3%, transparent);
    border: 1px solid color-mix(in srgb, var(--wl-text) 8%, transparent);
}

.schedule-view__history-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.6rem;
    margin-bottom: 0.35rem;
}

.schedule-view__history-sequence {
    font-family: SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', monospace;
    font-size: 0.8rem;
    color: var(--wl-text-muted);
}

.schedule-view__history-timestamp {
    font-size: 0.8rem;
    color: var(--wl-text-muted);
    margin-left: auto;
}

.schedule-view__history-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    font-size: 0.8rem;
    color: var(--wl-text-muted);
    margin-bottom: 0.4rem;
}

.schedule-view__history-meta code {
    font-size: 0.75rem;
}

.schedule-view__history-payload {
    margin: 0;
    padding: 0.6rem 0.75rem;
    border-radius: 0.35rem;
    background: color-mix(in srgb, var(--wl-text) 6%, transparent);
    color: var(--wl-text-soft);
    font-family: SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', monospace;
    font-size: 0.78rem;
    line-height: 1.45;
    max-height: 14rem;
    overflow: auto;
}

.schedule-view .table th,
.schedule-view .table td {
    vertical-align: top;
}

.schedule-view .icon {
    display: inline-block;
    vertical-align: middle;
}

.schedule-view .icon.spin {
    animation: spin 1s linear infinite;
}

@media (prefers-reduced-motion: reduce) {
    .schedule-view .icon.spin { animation: none; }
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

@media (max-width: 1200px) {
    .schedule-view__summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 768px) {
    .schedule-view__filters {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .schedule-view__hero {
        flex-direction: column;
        align-items: flex-start;
    }

    .schedule-view__actions {
        width: 100%;
        justify-content: flex-start;
    }

    .schedule-view__summary-grid {
        grid-template-columns: minmax(0, 1fr);
    }

    .schedule-view__pagination {
        flex-direction: column;
        align-items: stretch;
    }

    .schedule-view__pagination-pages {
        justify-content: center;
    }
}

.schedule-view__notice {
    padding: 0.65rem 1.25rem;
    border-bottom: 1px solid var(--wl-border);
    color: var(--wl-text-muted);
    font-size: 0.86rem;
}

.schedule-view__reason {
    overflow-wrap: anywhere;
    white-space: pre-wrap;
}

.schedule-view__detail-grid {
    display: grid;
    grid-template-columns: minmax(9rem, max-content) minmax(0, 1fr);
    gap: 0.55rem 1.25rem;
    margin-bottom: 1.25rem;
}

.schedule-view__detail-grid dt {
    color: var(--wl-text-muted);
    font-weight: 600;
}

.schedule-view__detail-grid dd {
    margin: 0;
    min-width: 0;
    overflow-wrap: anywhere;
}

.schedule-view__detail-heading {
    margin-bottom: 0.65rem;
    color: var(--wl-text);
}

/* Narrow screens stack each schedule into labelled fields so the row
   actions stay on screen instead of behind a horizontal table scroll. */
@media (max-width: 768px) {
    .schedule-view__table thead {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip: rect(0 0 0 0);
        white-space: nowrap;
    }

    .schedule-view__table,
    .schedule-view__table tbody,
    .schedule-view__table tr,
    .schedule-view__table td {
        display: block;
        width: 100%;
    }

    .schedule-view__table tr {
        padding: 0.75rem 1rem;
        border-bottom: 1px solid var(--wl-border);
    }

    .schedule-view__table td {
        padding: 0.35rem 0;
        border: 0;
    }

    .schedule-view__table td::before {
        content: attr(data-label);
        display: block;
        margin-bottom: 0.2rem;
        color: var(--wl-text-muted);
        font-size: 0.75rem;
        font-weight: 600;
    }
}

@media (max-width: 576px) {
    .schedule-view__filters {
        grid-template-columns: minmax(0, 1fr);
    }

    .schedule-view__detail-grid {
        grid-template-columns: minmax(0, 1fr);
        gap: 0.2rem;
    }

    .schedule-view__detail-grid dd {
        margin-bottom: 0.55rem;
    }
}
</style>
