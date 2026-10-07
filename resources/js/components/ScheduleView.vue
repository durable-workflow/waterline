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
                <select v-model="statusFilter" class="form-control form-control-sm schedule-view__filter">
                    <option value="">{{ $t("All statuses") }}</option>
                    <option value="active">{{ $t("Active") }}</option>
                    <option value="paused">{{ $t("Paused") }}</option>
                    <option value="deleted">{{ $t("Deleted") }}</option>
                </select>

                <button class="btn btn-sm btn-outline-secondary" @click="editViewOptions" :disabled="savingOperatorPreferences">
                    {{ $t("View Options") }}
                </button>

                <button class="btn btn-sm btn-outline-secondary" @click="refresh">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon fill-text-color schedule-view__button-icon">
                        <path d="M10 3v2a5 5 0 0 0-3.54 8.54l-1.41 1.41A7 7 0 0 1 10 3zm4.95 2.05A7 7 0 0 1 10 17v-2a5 5 0 0 0 3.54-8.54l1.41-1.41zM10 20l-4-4 4-4v8zm0-12V0l4 4-4 4z"></path>
                    </svg>
                    {{ $t("Refresh") }}
                </button>
            </div>
        </section>

        <div v-if="loading" class="schedule-view__state card card-bg-secondary">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin fill-text-color schedule-view__state-icon">
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
                        <div class="schedule-view__summary-value">{{ totalSchedules.toLocaleString() }}</div>
                        <div class="schedule-view__summary-meta">{{ pagination ? pagination.total.toLocaleString() : schedules.length.toLocaleString() }} {{ $t("total in the filtered result set.") }}</div>
                    </div>
                </article>

                <article class="card schedule-view__summary-card">
                    <div class="card-body card-bg-secondary">
                        <div class="schedule-view__summary-label">{{ $t("Active") }}</div>
                        <div class="schedule-view__summary-value is-success">{{ activeScheduleCount.toLocaleString() }}</div>
                        <div class="schedule-view__summary-meta">{{ $t("Schedules currently dispatching on cadence.") }}</div>
                    </div>
                </article>

                <article class="card schedule-view__summary-card">
                    <div class="card-body card-bg-secondary">
                        <div class="schedule-view__summary-label">{{ $t("Paused") }}</div>
                        <div class="schedule-view__summary-value is-warning">{{ pausedScheduleCount.toLocaleString() }}</div>
                        <div class="schedule-view__summary-meta">{{ $t("Schedules waiting for operator resume.") }}</div>
                    </div>
                </article>

                <article class="card schedule-view__summary-card">
                    <div class="card-body card-bg-secondary">
                        <div class="schedule-view__summary-label">{{ $t("Overdue next fires") }}</div>
                        <div class="schedule-view__summary-value" :class="overdueScheduleCount > 0 ? 'is-danger' : ''">{{ overdueScheduleCount.toLocaleString() }}</div>
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

                <div class="card-body card-bg-secondary p-0">
                    <div v-if="schedules.length > 0" class="table-responsive">
                        <table :class="schedulesTableClass">
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
                                <tr v-for="schedule in schedules" :key="schedule.id" :class="scheduleRowClass(schedule)">
                                    <td v-if="columnEnabled('schedule_id')">
                                        <div class="schedule-view__cell-main">
                                            <code>{{ truncateId(schedule.id) }}</code>
                                            <div class="schedule-view__cell-meta">{{ schedule.id }}</div>
                                        </div>
                                    </td>

                                    <td v-if="columnEnabled('workflow_type')">
                                        <div class="schedule-view__cell-main">
                                            <code>{{ schedule.workflow_type || schedule.workflow_class || '-' }}</code>
                                            <div class="schedule-view__cell-meta" v-if="schedule.workflow_class && schedule.workflow_type && schedule.workflow_type !== schedule.workflow_class">
                                                {{ schedule.workflow_class }}
                                            </div>
                                        </div>
                                    </td>

                                    <td v-if="columnEnabled('spec')">
                                        <div class="schedule-view__cell-main">
                                            <span v-if="schedule.spec && schedule.spec.cron" class="schedule-view__pill schedule-view__pill--muted">
                                                {{ $t("Cron") }} {{ schedule.spec.cron }}
                                            </span>
                                            <span v-else-if="schedule.spec && schedule.spec.interval" class="schedule-view__pill schedule-view__pill--muted">
                                                {{ $t("Every") }} {{ formatInterval(schedule.spec.interval) }}
                                            </span>
                                            <span v-else class="text-muted">{{ $t("Custom") }}</span>

                                            <div class="schedule-view__cell-meta" v-if="schedule.spec && schedule.spec.timezone">
                                                {{ $t("Timezone") }} {{ schedule.spec.timezone }}
                                            </div>
                                        </div>
                                    </td>

                                    <td v-if="columnEnabled('status')">
                                        <span class="schedule-view__pill" :class="statusToneClass(schedule.status)">
                                            {{ stateLabel(schedule.status || 'unknown') }}
                                        </span>
                                    </td>

                                    <td v-if="columnEnabled('next_fire')">
                                        <div class="schedule-view__cell-main">
                                            <span :class="nextFireClass(schedule.next_fire_at)">
                                                {{ schedule.next_fire_at ? formatTimestamp(schedule.next_fire_at) : '—' }}
                                            </span>
                                            <div class="schedule-view__cell-meta" v-if="schedule.next_fire_at && isOverdue(schedule)">
                                                {{ $t("Overdue trigger window") }}
                                            </div>
                                        </div>
                                    </td>

                                    <td v-if="columnEnabled('last_result')">
                                        <div class="schedule-view__cell-main">
                                            <span v-if="schedule.last_fire_at">
                                                {{ formatTimestamp(schedule.last_fire_at) }}
                                            </span>
                                            <span v-else class="text-muted">{{ $t("Never") }}</span>
                                            <div class="schedule-view__cell-meta" v-if="schedule.last_fire_result">
                                                <span :class="resultClass(schedule.last_fire_result)">{{ schedule.last_fire_result }}</span>
                                            </div>
                                        </div>
                                    </td>

                                    <td v-if="columnEnabled('actions')">
                                        <div class="schedule-view__action-group">
                                            <button
                                                v-if="schedule.status === 'active'"
                                                class="btn btn-sm btn-outline-warning"
                                                @click="pauseSchedule(schedule.id)">
                                                {{ $t("Pause") }}
                                            </button>
                                            <button
                                                v-if="schedule.status === 'paused'"
                                                class="btn btn-sm btn-outline-success"
                                                @click="resumeSchedule(schedule.id)">
                                                {{ $t("Resume") }}
                                            </button>
                                            <button
                                                class="btn btn-sm btn-outline-primary"
                                                @click="triggerNow(schedule.id)">
                                                {{ $t("Trigger") }}
                                            </button>
                                            <button
                                                class="btn btn-sm btn-outline-info"
                                                @click="showBackfillDialog(schedule)">
                                                {{ $t("Backfill") }}
                                            </button>
                                            <button
                                                class="btn btn-sm btn-outline-secondary"
                                                @click="showHistoryDialog(schedule)">
                                                {{ $t("History") }}
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

        <div v-if="showBackfill" class="schedule-view__modal" @click.self="showBackfill = false">
            <div class="schedule-view__dialog card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div>
                        <h5 class="mb-0">{{ $t("Backfill schedule") }}</h5>
                        <small class="text-muted">{{ $t("Trigger missed executions across a historical window.") }}</small>
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="showBackfill = false">
                        {{ $t("Close") }}
                    </button>
                </div>

                <div class="card-body card-bg-secondary">
                    <p class="schedule-view__dialog-copy">
                        {{ $t("Backfill will trigger workflow executions for missed schedule times between the supplied timestamps.") }}
                    </p>

                    <div class="form-group">
                        <label class="schedule-view__field-label">{{ $t("From") }}</label>
                        <input v-model="backfillFrom" type="datetime-local" class="form-control schedule-view__field" />
                    </div>

                    <div class="form-group">
                        <label class="schedule-view__field-label">{{ $t("To") }}</label>
                        <input v-model="backfillTo" type="datetime-local" class="form-control schedule-view__field" />
                    </div>

                    <div class="form-group mb-0">
                        <label class="schedule-view__field-label">{{ $t("Overlap Policy") }}</label>
                        <select v-model="backfillOverlapPolicy" class="form-control schedule-view__field">
                            <option value="">{{ $t("Use schedule default") }}</option>
                            <option value="skip">{{ $t("Skip") }}</option>
                            <option value="allow">{{ $t("Allow") }}</option>
                            <option value="terminate">{{ $t("Terminate") }}</option>
                            <option value="cancel">{{ $t("Cancel") }}</option>
                        </select>
                    </div>
                </div>

                <div class="card-footer d-flex justify-content-end schedule-view__dialog-actions">
                    <button class="btn btn-secondary" @click="showBackfill = false">{{ $t("Cancel") }}</button>
                    <button class="btn btn-primary" @click="executeBackfill">{{ $t("Backfill") }}</button>
                </div>
            </div>
        </div>

        <div v-if="showHistory" class="schedule-view__modal" @click.self="closeHistoryDialog">
            <div class="schedule-view__dialog schedule-view__dialog--wide card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div>
                        <h5 class="mb-0">{{ $t("Schedule audit history") }}</h5>
                        <small class="text-muted">
                            {{ $t("Lifecycle events recorded for") }}
                            <code>{{ historyScheduleId || '—' }}</code>.
                        </small>
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="closeHistoryDialog">
                        {{ $t("Close") }}
                    </button>
                </div>

                <div class="card-body card-bg-secondary schedule-view__history-body">
                    <div v-if="historyLoading && historyEvents.length === 0" class="schedule-view__state">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin fill-text-color schedule-view__state-icon">
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
    </div>
</template>

<script>
import axios from 'axios';
import Swal from 'sweetalert2';

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
            statusFilter: '',
            currentPage: 1,
            showBackfill: false,
            backfillScheduleId: null,
            backfillFrom: '',
            backfillTo: '',
            backfillOverlapPolicy: '',
            operatorPreferences: {},
            effectiveOperatorPreferences: {},
            savingOperatorPreferences: false,
            showHistory: false,
            historyScheduleId: null,
            historyEvents: [],
            historyLoading: false,
            historyLoadingMore: false,
            historyError: null,
            historyHasMore: false,
            historyNextCursor: null,
            historyPageLimit: 100
        };
    },

    computed: {
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
            return this.statusFilter ? this.$t("{value1} only", { value1: this.stateLabel(this.statusFilter) }) : this.$t('all statuses');
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
            const classes = ['table', 'table-hover', 'mb-0'];

            if (this.schedulesListDensity() === 'dense') {
                classes.push('table-sm');
            }

            return classes.join(' ');
        }
    },

    watch: {
        statusFilter() {
            this.currentPage = 1;
            this.loadData();
        }
    },

    mounted() {
        this.loadOperatorPreferences().finally(() => this.loadData());
    },

    methods: {
        async loadData() {
            this.loading = true;
            this.error = null;

            try {
                const params = {
                    page: this.currentPage
                };

                if (this.statusFilter) {
                    params.status = this.statusFilter;
                }

                const response = await axios.get(this.resolvedApiEndpoint(), { params });
                this.schedules = response.data.data || [];
                this.pagination = {
                    current_page: response.data.current_page,
                    last_page: response.data.last_page,
                    per_page: response.data.per_page,
                    total: response.data.total
                };
            } catch (e) {
                this.error = e.response?.data?.message || e.message || this.$t("Failed to load schedules");
                console.error('Schedule load error:', e);
            } finally {
                this.loading = false;
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

        async pauseSchedule(scheduleId) {
            try {
                await axios.post(`${this.resolvedApiEndpoint()}/${scheduleId}/pause`);
                await this.loadData();
            } catch (e) {
                this.showActionError(this.$t("Failed to pause schedule"), e);
            }
        },

        async resumeSchedule(scheduleId) {
            try {
                await axios.post(`${this.resolvedApiEndpoint()}/${scheduleId}/resume`);
                await this.loadData();
            } catch (e) {
                this.showActionError(this.$t("Failed to resume schedule"), e);
            }
        },

        async triggerNow(scheduleId) {
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

            try {
                const response = await axios.post(`${this.resolvedApiEndpoint()}/${scheduleId}/trigger`);
                const text = response.data.triggered
                    ? this.$t("Schedule triggered. Instance ID: {value1}", { value1: response.data.instance_id || 'unknown' })
                    : this.$t("Schedule trigger was skipped, likely because of the configured overlap policy.");

                await this.$dialog({
                    title: response.data.triggered ? this.$t("Schedule triggered") : this.$t("Trigger skipped"),
                    text,
                    icon: response.data.triggered ? 'success' : 'info',
                    confirmButtonText: this.$t("Okay"),
                    background: this.swalBackground(),
                });

                await this.loadData();
            } catch (e) {
                this.showActionError(this.$t("Failed to trigger schedule"), e);
            }
        },

        showBackfillDialog(schedule) {
            this.backfillScheduleId = schedule.id;
            this.backfillFrom = '';
            this.backfillTo = '';
            this.backfillOverlapPolicy = '';
            this.showBackfill = true;
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

            try {
                const from = new Date(this.backfillFrom).toISOString();
                const to = new Date(this.backfillTo).toISOString();
                const payload = { from, to };

                if (this.backfillOverlapPolicy) {
                    payload.overlap_policy = this.backfillOverlapPolicy;
                }

                const response = await axios.post(
                    `${this.resolvedApiEndpoint()}/${this.backfillScheduleId}/backfill`,
                    payload
                );

                this.showBackfill = false;

                await this.$dialog({
                    title: this.$t("Backfill queued"),
                    text: this.$t("Backfill completed. Results: {value1}", { value1: JSON.stringify(response.data.results || {}) }),
                    icon: 'success',
                    confirmButtonText: this.$t("Okay"),
                    background: this.swalBackground(),
                });

                await this.loadData();
            } catch (e) {
                this.showActionError(this.$t("Backfill failed"), e);
            }
        },

        showHistoryDialog(schedule) {
            this.historyScheduleId = schedule.id;
            this.historyEvents = [];
            this.historyError = null;
            this.historyHasMore = false;
            this.historyNextCursor = null;
            this.showHistory = true;
            this.loadHistoryEvents(true);
        },

        closeHistoryDialog() {
            this.showHistory = false;
        },

        async loadHistoryEvents(reset = false) {
            if (!this.historyScheduleId) {
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
                const params = { limit: this.historyPageLimit };
                if (!reset && this.historyNextCursor !== null) {
                    params.after_sequence = this.historyNextCursor;
                }

                const response = await axios.get(
                    `${this.resolvedApiEndpoint()}/${this.historyScheduleId}/history`,
                    { params }
                );

                const events = Array.isArray(response.data?.events) ? response.data.events : [];

                if (reset) {
                    this.historyEvents = events;
                } else {
                    this.historyEvents = this.historyEvents.concat(events);
                }

                this.historyHasMore = Boolean(response.data?.has_more);
                this.historyNextCursor = response.data?.next_cursor ?? null;
            } catch (e) {
                this.historyError = e.response?.data?.error
                    || e.response?.data?.message
                    || e.message
                    || this.$t("Failed to load audit history");
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

        scheduleRowClass(schedule) {
            return this.isOverdue(schedule) ? 'schedule-view__row--overdue' : '';
        },

        isOverdue(schedule) {
            if (!schedule || !schedule.next_fire_at || schedule.status !== 'active') {
                return false;
            }

            return new Date(schedule.next_fire_at) < new Date();
        },

        nextFireClass(timestamp) {
            if (!timestamp) return '';
            const fireTime = new Date(timestamp);
            const now = new Date();
            const diffMinutes = (fireTime - now) / (1000 * 60);

            if (diffMinutes < 0) return 'text-danger';
            if (diffMinutes < 60) return 'text-warning';
            return 'text-success';
        },

        resultClass(result) {
            return {
                started: 'text-success',
                skipped: 'text-warning',
                failed: 'text-danger'
            }[result] || 'text-muted';
        },

        formatTimestamp(timestamp) {
            if (!timestamp) return '';
            try {
                return new Date(timestamp).toLocaleString();
            } catch (e) {
                return timestamp;
            }
        },

        formatInterval(interval) {
            if (!interval) return '';
            if (interval < 60) return `${interval}s`;
            if (interval < 3600) return `${Math.floor(interval / 60)}m`;
            if (interval < 86400) return `${Math.floor(interval / 3600)}h`;
            return `${Math.floor(interval / 86400)}d`;
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
            const text = error.response?.data?.error || error.response?.data?.message || error.message || this.$t("Request failed");

            this.$dialog({
                title,
                text,
                icon: 'error',
                confirmButtonText: this.$t("Okay"),
                background: this.swalBackground(),
            });
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

.schedule-view__filter {
    min-width: 11rem;
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
</style>
