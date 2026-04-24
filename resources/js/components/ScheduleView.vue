<template>
    <div class="schedule-view">
        <section class="schedule-view__hero">
            <div>
                <p class="schedule-view__eyebrow">Operator surface</p>
                <h1 class="schedule-view__title">Schedules</h1>
                <p class="schedule-view__subtitle">
                    Trigger posture, backfills, and next-fire timing for recurring workflow schedules.
                </p>
            </div>

            <div class="schedule-view__actions">
                <select v-model="statusFilter" class="form-control form-control-sm schedule-view__filter">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="paused">Paused</option>
                    <option value="deleted">Deleted</option>
                </select>

                <button class="btn btn-sm btn-outline-secondary" @click="editViewOptions" :disabled="savingOperatorPreferences">
                    View Options
                </button>

                <button class="btn btn-sm btn-outline-secondary" @click="refresh">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon fill-text-color schedule-view__button-icon">
                        <path d="M10 3v2a5 5 0 0 0-3.54 8.54l-1.41 1.41A7 7 0 0 1 10 3zm4.95 2.05A7 7 0 0 1 10 17v-2a5 5 0 0 0 3.54-8.54l1.41-1.41zM10 20l-4-4 4-4v8zm0-12V0l4 4-4 4z"></path>
                    </svg>
                    Refresh
                </button>
            </div>
        </section>

        <div v-if="loading" class="schedule-view__state card card-bg-secondary">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin fill-text-color schedule-view__state-icon">
                <path d="M12 10a2 2 0 0 1-3.41 1.41A2 2 0 0 1 10 8V0a9.97 9.97 0 0 1 10 10h-8zm7.9 1.41A10 10 0 1 1 8.59.1v2.03a8 8 0 1 0 9.29 9.29h2.02zm-4.07 0a6 6 0 1 1-7.25-7.25v2.1a3.99 3.99 0 0 0-1.4 6.57 4 4 0 0 0 6.56-1.42h2.1z"></path>
            </svg>
            <p class="schedule-view__state-copy">Loading schedules…</p>
        </div>

        <div v-else-if="error" class="schedule-view__state card card-bg-secondary schedule-view__state--error">
            <strong>Schedules unavailable</strong>
            <p class="schedule-view__state-copy">{{ error }}</p>
            <button class="btn btn-sm btn-outline-primary" @click="refresh">Retry</button>
        </div>

        <div v-else class="schedule-view__content">
            <section class="schedule-view__summary-grid">
                <article class="card schedule-view__summary-card">
                    <div class="card-body card-bg-secondary">
                        <div class="schedule-view__summary-label">Returned schedules</div>
                        <div class="schedule-view__summary-value">{{ totalSchedules.toLocaleString() }}</div>
                        <div class="schedule-view__summary-meta">{{ pagination ? pagination.total.toLocaleString() : schedules.length.toLocaleString() }} total in the filtered result set.</div>
                    </div>
                </article>

                <article class="card schedule-view__summary-card">
                    <div class="card-body card-bg-secondary">
                        <div class="schedule-view__summary-label">Active</div>
                        <div class="schedule-view__summary-value is-success">{{ activeScheduleCount.toLocaleString() }}</div>
                        <div class="schedule-view__summary-meta">Schedules currently dispatching on cadence.</div>
                    </div>
                </article>

                <article class="card schedule-view__summary-card">
                    <div class="card-body card-bg-secondary">
                        <div class="schedule-view__summary-label">Paused</div>
                        <div class="schedule-view__summary-value is-warning">{{ pausedScheduleCount.toLocaleString() }}</div>
                        <div class="schedule-view__summary-meta">Schedules waiting for operator resume.</div>
                    </div>
                </article>

                <article class="card schedule-view__summary-card">
                    <div class="card-body card-bg-secondary">
                        <div class="schedule-view__summary-label">Overdue next fires</div>
                        <div class="schedule-view__summary-value" :class="overdueScheduleCount > 0 ? 'is-danger' : ''">{{ overdueScheduleCount.toLocaleString() }}</div>
                        <div class="schedule-view__summary-meta">Active schedules whose next fire time is already behind.</div>
                    </div>
                </article>
            </section>

            <article class="card schedule-view__panel">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div>
                        <h5 class="mb-0">Schedule registry</h5>
                        <small class="text-muted">Specification, state, next fire time, and operational controls.</small>
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
                                    <th v-if="columnEnabled('schedule_id')">Schedule</th>
                                    <th v-if="columnEnabled('workflow_type')">Workflow Type</th>
                                    <th v-if="columnEnabled('spec')">Spec</th>
                                    <th v-if="columnEnabled('status')">Status</th>
                                    <th v-if="columnEnabled('next_fire')">Next Fire</th>
                                    <th v-if="columnEnabled('last_result')">Last Result</th>
                                    <th v-if="columnEnabled('actions')">Actions</th>
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
                                                Cron {{ schedule.spec.cron }}
                                            </span>
                                            <span v-else-if="schedule.spec && schedule.spec.interval" class="schedule-view__pill schedule-view__pill--muted">
                                                Every {{ formatInterval(schedule.spec.interval) }}
                                            </span>
                                            <span v-else class="text-muted">Custom</span>

                                            <div class="schedule-view__cell-meta" v-if="schedule.spec && schedule.spec.timezone">
                                                Timezone {{ schedule.spec.timezone }}
                                            </div>
                                        </div>
                                    </td>

                                    <td v-if="columnEnabled('status')">
                                        <span class="schedule-view__pill" :class="statusToneClass(schedule.status)">
                                            {{ schedule.status || 'unknown' }}
                                        </span>
                                    </td>

                                    <td v-if="columnEnabled('next_fire')">
                                        <div class="schedule-view__cell-main">
                                            <span :class="nextFireClass(schedule.next_fire_at)">
                                                {{ schedule.next_fire_at ? formatTimestamp(schedule.next_fire_at) : '—' }}
                                            </span>
                                            <div class="schedule-view__cell-meta" v-if="schedule.next_fire_at && isOverdue(schedule)">
                                                Overdue trigger window
                                            </div>
                                        </div>
                                    </td>

                                    <td v-if="columnEnabled('last_result')">
                                        <div class="schedule-view__cell-main">
                                            <span v-if="schedule.last_fire_at">
                                                {{ formatTimestamp(schedule.last_fire_at) }}
                                            </span>
                                            <span v-else class="text-muted">Never</span>
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
                                                Pause
                                            </button>
                                            <button
                                                v-if="schedule.status === 'paused'"
                                                class="btn btn-sm btn-outline-success"
                                                @click="resumeSchedule(schedule.id)">
                                                Resume
                                            </button>
                                            <button
                                                class="btn btn-sm btn-outline-primary"
                                                @click="triggerNow(schedule.id)">
                                                Trigger
                                            </button>
                                            <button
                                                class="btn btn-sm btn-outline-info"
                                                @click="showBackfillDialog(schedule)">
                                                Backfill
                                            </button>
                                            <button
                                                class="btn btn-sm btn-outline-secondary"
                                                @click="showHistoryDialog(schedule)">
                                                History
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-else class="schedule-view__empty-state">
                        <strong>No schedules found</strong>
                        <p class="mb-0 text-muted">No schedule rows matched the current filter state.</p>
                    </div>
                </div>

                <div v-if="pagination && pagination.last_page > 1" class="card-footer schedule-view__pagination">
                    <button class="btn btn-secondary btn-sm" @click="goToPage(pagination.current_page - 1)" :disabled="pagination.current_page === 1">
                        Previous
                    </button>

                    <div class="schedule-view__pagination-pages">
                        <template v-for="page in visiblePages">
                            <span v-if="page === '...'" :key="`ellipsis-${page}-${pagination.current_page}`" class="schedule-view__pagination-ellipsis">…</span>
                            <button
                                v-else
                                :key="page"
                                class="btn btn-sm"
                                :class="page === pagination.current_page ? 'btn-primary' : 'btn-outline-secondary'"
                                @click="goToPage(page)">
                                {{ page }}
                            </button>
                        </template>
                    </div>

                    <button class="btn btn-secondary btn-sm" @click="goToPage(pagination.current_page + 1)" :disabled="pagination.current_page === pagination.last_page">
                        Next
                    </button>
                </div>
            </article>
        </div>

        <div v-if="showBackfill" class="schedule-view__modal" @click.self="showBackfill = false">
            <div class="schedule-view__dialog card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div>
                        <h5 class="mb-0">Backfill schedule</h5>
                        <small class="text-muted">Trigger missed executions across a historical window.</small>
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="showBackfill = false">
                        Close
                    </button>
                </div>

                <div class="card-body card-bg-secondary">
                    <p class="schedule-view__dialog-copy">
                        Backfill will trigger workflow executions for missed schedule times between the supplied timestamps.
                    </p>

                    <div class="form-group">
                        <label class="schedule-view__field-label">From</label>
                        <input v-model="backfillFrom" type="datetime-local" class="form-control schedule-view__field" />
                    </div>

                    <div class="form-group">
                        <label class="schedule-view__field-label">To</label>
                        <input v-model="backfillTo" type="datetime-local" class="form-control schedule-view__field" />
                    </div>

                    <div class="form-group mb-0">
                        <label class="schedule-view__field-label">Overlap Policy</label>
                        <select v-model="backfillOverlapPolicy" class="form-control schedule-view__field">
                            <option value="">Use schedule default</option>
                            <option value="skip">Skip</option>
                            <option value="allow">Allow</option>
                            <option value="terminate">Terminate</option>
                            <option value="cancel">Cancel</option>
                        </select>
                    </div>
                </div>

                <div class="card-footer d-flex justify-content-end schedule-view__dialog-actions">
                    <button class="btn btn-secondary" @click="showBackfill = false">Cancel</button>
                    <button class="btn btn-primary" @click="executeBackfill">Backfill</button>
                </div>
            </div>
        </div>

        <div v-if="showHistory" class="schedule-view__modal" @click.self="closeHistoryDialog">
            <div class="schedule-view__dialog schedule-view__dialog--wide card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div>
                        <h5 class="mb-0">Schedule audit history</h5>
                        <small class="text-muted">
                            Lifecycle events recorded for
                            <code>{{ historyScheduleId || '—' }}</code>.
                        </small>
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="closeHistoryDialog">
                        Close
                    </button>
                </div>

                <div class="card-body card-bg-secondary schedule-view__history-body">
                    <div v-if="historyLoading && historyEvents.length === 0" class="schedule-view__state">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin fill-text-color schedule-view__state-icon">
                            <path d="M12 10a2 2 0 0 1-3.41 1.41A2 2 0 0 1 10 8V0a9.97 9.97 0 0 1 10 10h-8zm7.9 1.41A10 10 0 1 1 8.59.1v2.03a8 8 0 1 0 9.29 9.29h2.02zm-4.07 0a6 6 0 1 1-7.25-7.25v2.1a3.99 3.99 0 0 0-1.4 6.57 4 4 0 0 0 6.56-1.42h2.1z"></path>
                        </svg>
                        <p class="schedule-view__state-copy">Loading audit history…</p>
                    </div>

                    <div v-else-if="historyError" class="schedule-view__state schedule-view__state--error">
                        <strong>Unable to load audit history</strong>
                        <p class="schedule-view__state-copy">{{ historyError }}</p>
                        <button class="btn btn-sm btn-outline-primary" @click="loadHistoryEvents(true)">Retry</button>
                    </div>

                    <div v-else-if="historyEvents.length === 0" class="schedule-view__empty-state">
                        <strong>No audit events recorded</strong>
                        <p class="mb-0 text-muted">
                            The audit stream begins at the next lifecycle transition (create, pause, resume, trigger, or delete).
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
                                    instance <code>{{ truncateId(event.workflow_instance_id) }}</code>
                                </span>
                                <span v-if="event.workflow_run_id">
                                    run <code>{{ truncateId(event.workflow_run_id) }}</code>
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
                        Showing {{ historyEvents.length.toLocaleString() }} event{{ historyEvents.length === 1 ? '' : 's' }}{{ historyHasMore ? ' (more available)' : '' }}.
                    </small>

                    <div>
                        <button
                            v-if="historyHasMore"
                            class="btn btn-sm btn-outline-primary"
                            :disabled="historyLoadingMore"
                            @click="loadMoreHistoryEvents">
                            {{ historyLoadingMore ? 'Loading…' : 'Load more' }}
                        </button>
                        <button class="btn btn-sm btn-secondary" @click="closeHistoryDialog">Close</button>
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
            return this.statusFilter ? `${this.statusFilter} only` : 'all statuses';
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
                this.error = e.response?.data?.message || e.message || 'Failed to load schedules';
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
                {key: 'schedule_id', label: 'Schedule ID'},
                {key: 'workflow_type', label: 'Workflow Type'},
                {key: 'spec', label: 'Spec'},
                {key: 'status', label: 'Status'},
                {key: 'next_fire', label: 'Next Fire'},
                {key: 'last_result', label: 'Last Result'},
                {key: 'actions', label: 'Actions'},
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

            const result = await Swal.fire({
                title: 'View Options',
                html: `
                    <div class="text-left">
                        <label class="d-block mb-1">Density</label>
                        <select id="waterline-schedules-density" class="swal2-input">
                            <option value="comfortable" ${this.schedulesListDensity() === 'comfortable' ? 'selected' : ''}>Comfortable</option>
                            <option value="dense" ${this.schedulesListDensity() === 'dense' ? 'selected' : ''}>Dense</option>
                        </select>
                        <div class="mt-3">
                            <label class="d-block mb-2">Columns</label>
                            ${columnHtml}
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Save Options',
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
                this.showActionError('Failed to pause schedule', e);
            }
        },

        async resumeSchedule(scheduleId) {
            try {
                await axios.post(`${this.resolvedApiEndpoint()}/${scheduleId}/resume`);
                await this.loadData();
            } catch (e) {
                this.showActionError('Failed to resume schedule', e);
            }
        },

        async triggerNow(scheduleId) {
            const confirmation = await Swal.fire({
                title: 'Trigger this schedule now?',
                text: 'Waterline will attempt an immediate schedule dispatch.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Trigger now',
                background: this.swalBackground(),
            });

            if (!confirmation.isConfirmed) {
                return;
            }

            try {
                const response = await axios.post(`${this.resolvedApiEndpoint()}/${scheduleId}/trigger`);
                const text = response.data.triggered
                    ? `Schedule triggered. Instance ID: ${response.data.instance_id || 'unknown'}`
                    : 'Schedule trigger was skipped, likely because of the configured overlap policy.';

                await Swal.fire({
                    title: response.data.triggered ? 'Schedule triggered' : 'Trigger skipped',
                    text,
                    icon: response.data.triggered ? 'success' : 'info',
                    confirmButtonText: 'Okay',
                    background: this.swalBackground(),
                });

                await this.loadData();
            } catch (e) {
                this.showActionError('Failed to trigger schedule', e);
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
                await Swal.fire({
                    title: 'Missing backfill range',
                    text: 'Specify both the start and end timestamps for the backfill window.',
                    icon: 'warning',
                    confirmButtonText: 'Okay',
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

                await Swal.fire({
                    title: 'Backfill queued',
                    text: `Backfill completed. Results: ${JSON.stringify(response.data.results || {})}`,
                    icon: 'success',
                    confirmButtonText: 'Okay',
                    background: this.swalBackground(),
                });

                await this.loadData();
            } catch (e) {
                this.showActionError('Backfill failed', e);
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
                    || 'Failed to load audit history';
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
                return 'Unknown event';
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
            const text = error.response?.data?.error || error.response?.data?.message || error.message || 'Request failed';

            Swal.fire({
                title,
                text,
                icon: 'error',
                confirmButtonText: 'Okay',
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
    font-family: 'IBM Plex Mono', monospace;
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
    font-family: 'IBM Plex Mono', monospace;
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
    font-family: 'IBM Plex Mono', monospace;
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
    font-family: 'IBM Plex Mono', monospace;
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
    font-family: 'IBM Plex Mono', monospace;
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
    font-family: 'IBM Plex Mono', monospace;
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
    font-family: 'IBM Plex Mono', monospace;
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
</style><template>
    <div class="schedule-view">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5 class="mb-0">Workflow Schedules</h5>
                <div class="d-flex align-items-center flex-wrap">
                    <label for="waterline-schedule-status-filter" class="sr-only">Filter schedules by status</label>
                    <select
                        id="waterline-schedule-status-filter"
                        v-model="statusFilter"
                        class="form-control form-control-sm d-inline-block mr-2"
                        style="width: auto;"
                        aria-label="Filter schedules by status">
                        <option value="">All Statuses</option>
                        <option value="active">Active</option>
                        <option value="paused">Paused</option>
                        <option value="deleted">Deleted</option>
                    </select>
                    <button class="btn btn-sm btn-outline-secondary mr-2" @click="editViewOptions" :disabled="savingOperatorPreferences">
                        View Options
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" @click="refresh" :disabled="loading">
                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon fill-text-color" style="width: 16px; height: 16px;">
                            <path d="M10 3v2a5 5 0 0 0-3.54 8.54l-1.41 1.41A7 7 0 0 1 10 3zm4.95 2.05A7 7 0 0 1 10 17v-2a5 5 0 0 0 3.54-8.54l1.41-1.41zM10 20l-4-4 4-4v8zm0-12V0l4 4-4 4z"></path>
                        </svg>
                        Refresh
                    </button>
                </div>
            </div>

            <div v-if="loading" class="card-body text-center py-5" role="status" aria-live="polite" aria-busy="true">
                <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin fill-text-color" style="width: 32px; height: 32px;">
                    <path d="M12 10a2 2 0 0 1-3.41 1.41A2 2 0 0 1 10 8V0a9.97 9.97 0 0 1 10 10h-8zm7.9 1.41A10 10 0 1 1 8.59.1v2.03a8 8 0 1 0 9.29 9.29h2.02zm-4.07 0a6 6 0 1 1-7.25-7.25v2.1a3.99 3.99 0 0 0-1.4 6.57 4 4 0 0 0 6.56-1.42h2.1z"></path>
                </svg>
                <p class="mt-2 mb-0 text-muted">Loading schedules...</p>
            </div>

            <div v-else-if="error" class="card-body">
                <div class="alert alert-danger mb-0" role="alert">
                    <strong>Error:</strong> {{ error }}
                </div>
            </div>

            <div v-else class="card-body p-0">
                <div v-if="schedules.length > 0" class="table-responsive">
                    <table :class="schedulesTableClass">
                        <thead>
                            <tr>
                                <th v-if="columnEnabled('schedule_id')">Schedule ID</th>
                                <th v-if="columnEnabled('workflow_type')">Workflow Type</th>
                                <th v-if="columnEnabled('spec')">Spec</th>
                                <th v-if="columnEnabled('status')">Status</th>
                                <th v-if="columnEnabled('next_fire')">Next Fire</th>
                                <th v-if="columnEnabled('last_result')">Last Result</th>
                                <th v-if="columnEnabled('actions')">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="schedule in schedules" :key="schedule.id">
                                <td v-if="columnEnabled('schedule_id')">
                                    <code class="small">{{ truncateId(schedule.id) }}</code>
                                </td>
                                <td v-if="columnEnabled('workflow_type')">
                                    <code class="small">{{ schedule.workflow_type || schedule.workflow_class }}</code>
                                </td>
                                <td v-if="columnEnabled('spec')" class="small">
                                    <div v-if="schedule.spec">
                                        <span v-if="schedule.spec.cron" class="badge badge-info">
                                            CRON: {{ schedule.spec.cron }}
                                        </span>
                                        <span v-else-if="schedule.spec.interval" class="badge badge-info">
                                            Every {{ formatInterval(schedule.spec.interval) }}
                                        </span>
                                        <span v-else class="text-muted">Custom</span>
                                    </div>
                                </td>
                                <td v-if="columnEnabled('status')">
                                    <span class="badge" :class="statusBadgeClass(schedule.status)">
                                        {{ schedule.status }}
                                    </span>
                                </td>
                                <td v-if="columnEnabled('next_fire')" class="small">
                                    <span v-if="schedule.next_fire_at" :class="nextFireClass(schedule.next_fire_at)">
                                        {{ formatTimestamp(schedule.next_fire_at) }}
                                    </span>
                                    <span v-else class="text-muted">-</span>
                                </td>
                                <td v-if="columnEnabled('last_result')" class="small">
                                    <span v-if="schedule.last_fire_at">
                                        {{ formatTimestamp(schedule.last_fire_at) }}
                                        <span v-if="schedule.last_fire_result" class="ml-1" :class="resultClass(schedule.last_fire_result)">
                                            ({{ schedule.last_fire_result }})
                                        </span>
                                    </span>
                                    <span v-else class="text-muted">Never</span>
                                </td>
                                <td v-if="columnEnabled('actions')">
                                    <div class="btn-group btn-group-sm">
                                        <button
                                            v-if="schedule.status === 'active'"
                                            class="btn btn-sm btn-outline-warning"
                                            @click="pauseSchedule(schedule.id)"
                                            :aria-label="'Pause schedule ' + schedule.id"
                                            title="Pause">
                                            ⏸
                                        </button>
                                        <button
                                            v-if="schedule.status === 'paused'"
                                            class="btn btn-sm btn-outline-success"
                                            @click="resumeSchedule(schedule.id)"
                                            :aria-label="'Resume schedule ' + schedule.id"
                                            title="Resume">
                                            ▶
                                        </button>
                                        <button
                                            class="btn btn-sm btn-outline-primary"
                                            @click="triggerNow(schedule.id)"
                                            :aria-label="'Trigger schedule ' + schedule.id + ' now'"
                                            title="Trigger Now">
                                            ⚡
                                        </button>
                                        <button
                                            class="btn btn-sm btn-outline-info"
                                            @click="showBackfillDialog(schedule)"
                                            :aria-label="'Backfill schedule ' + schedule.id"
                                            title="Backfill">
                                            📅
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-else class="text-center py-5 text-muted">
                    <p class="mb-0">No schedules found</p>
                </div>

                <!-- Pagination -->
                <div v-if="pagination && pagination.last_page > 1" class="card-footer">
                    <nav aria-label="Schedule pages">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item" :class="{ disabled: pagination.current_page === 1 }">
                                <button class="page-link" @click="goToPage(pagination.current_page - 1)" :disabled="pagination.current_page === 1">Previous</button>
                            </li>
                            <li
                                v-for="page in visiblePages"
                                :key="page"
                                class="page-item"
                                :class="{ active: page === pagination.current_page, disabled: page === '...' }">
                                <span v-if="page === '...'" class="page-link" aria-hidden="true">...</span>
                                <button
                                    v-else
                                    class="page-link"
                                    @click="goToPage(page)"
                                    :aria-current="page === pagination.current_page ? 'page' : null">
                                    {{ page }}
                                </button>
                            </li>
                            <li class="page-item" :class="{ disabled: pagination.current_page === pagination.last_page }">
                                <button class="page-link" @click="goToPage(pagination.current_page + 1)" :disabled="pagination.current_page === pagination.last_page">Next</button>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>
        </div>

        <!-- Backfill Dialog (simple version - could use a modal library) -->
        <div
            v-if="showBackfill"
            class="modal d-block"
            style="background: rgba(0,0,0,0.5);"
            role="dialog"
            aria-modal="true"
            aria-labelledby="waterline-backfill-title"
            aria-describedby="waterline-backfill-description"
            @click.self="closeBackfillDialog"
            @keydown.esc="closeBackfillDialog">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 id="waterline-backfill-title" class="modal-title">Backfill Schedule</h5>
                        <button
                            ref="backfillCloseButton"
                            type="button"
                            class="close"
                            aria-label="Close backfill dialog"
                            @click="closeBackfillDialog">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <p id="waterline-backfill-description" class="small text-muted">
                            Backfill will trigger workflow executions for missed schedule times in the specified range.
                        </p>
                        <div class="form-group">
                            <label for="waterline-backfill-from">From (ISO 8601)</label>
                            <input id="waterline-backfill-from" v-model="backfillFrom" type="datetime-local" class="form-control" />
                        </div>
                        <div class="form-group">
                            <label for="waterline-backfill-to">To (ISO 8601)</label>
                            <input id="waterline-backfill-to" v-model="backfillTo" type="datetime-local" class="form-control" />
                        </div>
                        <div class="form-group">
                            <label for="waterline-backfill-overlap-policy">Overlap Policy</label>
                            <select id="waterline-backfill-overlap-policy" v-model="backfillOverlapPolicy" class="form-control">
                                <option value="">Use schedule default</option>
                                <option value="skip">Skip</option>
                                <option value="allow">Allow</option>
                                <option value="terminate">Terminate</option>
                                <option value="cancel">Cancel</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" @click="closeBackfillDialog">Cancel</button>
                        <button class="btn btn-primary" @click="executeBackfill">Backfill</button>
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
        },

        showBackfill(isOpen) {
            if (!isOpen) {
                return;
            }

            this.$nextTick(() => {
                if (this.$refs.backfillCloseButton) {
                    this.$refs.backfillCloseButton.focus();
                }
            });
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
                this.error = e.response?.data?.message || e.message || 'Failed to load schedules';
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
                {key: 'schedule_id', label: 'Schedule ID'},
                {key: 'workflow_type', label: 'Workflow Type'},
                {key: 'spec', label: 'Spec'},
                {key: 'status', label: 'Status'},
                {key: 'next_fire', label: 'Next Fire'},
                {key: 'last_result', label: 'Last Result'},
                {key: 'actions', label: 'Actions'},
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

            const result = await Swal.fire({
                title: 'View Options',
                html: `
                    <div class="text-left">
                        <label class="d-block mb-1">Density</label>
                        <select id="waterline-schedules-density" class="swal2-input">
                            <option value="comfortable" ${this.schedulesListDensity() === 'comfortable' ? 'selected' : ''}>Comfortable</option>
                            <option value="dense" ${this.schedulesListDensity() === 'dense' ? 'selected' : ''}>Dense</option>
                        </select>
                        <div class="mt-3">
                            <label class="d-block mb-2">Columns</label>
                            ${columnHtml}
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Save Options',
                background: '#1c1c1c',
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
            if (page < 1 || (this.pagination && page > this.pagination.last_page)) return;
            this.currentPage = page;
            this.loadData();
        },

        async pauseSchedule(scheduleId) {
            try {
                await axios.post(`${this.resolvedApiEndpoint()}/${scheduleId}/pause`);
                await this.loadData();
            } catch (e) {
                alert(`Failed to pause schedule: ${e.response?.data?.error || e.message}`);
            }
        },

        async resumeSchedule(scheduleId) {
            try {
                await axios.post(`${this.resolvedApiEndpoint()}/${scheduleId}/resume`);
                await this.loadData();
            } catch (e) {
                alert(`Failed to resume schedule: ${e.response?.data?.error || e.message}`);
            }
        },

        async triggerNow(scheduleId) {
            if (!confirm('Trigger this schedule now?')) return;

            try {
                const response = await axios.post(`${this.resolvedApiEndpoint()}/${scheduleId}/trigger`);
                if (response.data.triggered) {
                    alert(`Schedule triggered. Instance ID: ${response.data.instance_id}`);
                } else {
                    alert('Schedule trigger was skipped (likely due to overlap policy)');
                }
                await this.loadData();
            } catch (e) {
                alert(`Failed to trigger schedule: ${e.response?.data?.error || e.message}`);
            }
        },

        showBackfillDialog(schedule) {
            this.backfillScheduleId = schedule.id;
            this.backfillFrom = '';
            this.backfillTo = '';
            this.backfillOverlapPolicy = '';
            this.showBackfill = true;
        },

        closeBackfillDialog() {
            this.showBackfill = false;
        },

        async executeBackfill() {
            if (!this.backfillFrom || !this.backfillTo) {
                alert('Please specify both from and to timestamps');
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

                alert(`Backfill completed. Results: ${JSON.stringify(response.data.results)}`);
                this.closeBackfillDialog();
                await this.loadData();
            } catch (e) {
                alert(`Backfill failed: ${e.response?.data?.error || e.message}`);
            }
        },

        statusBadgeClass(status) {
            return {
                'active': 'badge-success',
                'paused': 'badge-warning',
                'deleted': 'badge-secondary'
            }[status] || 'badge-secondary';
        },

        nextFireClass(timestamp) {
            if (!timestamp) return '';
            const fireTime = new Date(timestamp);
            const now = new Date();
            const diffMinutes = (fireTime - now) / (1000 * 60);

            if (diffMinutes < 0) return 'text-danger'; // Overdue
            if (diffMinutes < 60) return 'text-warning'; // Soon
            return 'text-success';
        },

        resultClass(result) {
            return {
                'started': 'text-success',
                'skipped': 'text-warning',
                'failed': 'text-danger'
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
            // Assuming interval is in seconds
            if (interval < 60) return `${interval}s`;
            if (interval < 3600) return `${Math.floor(interval / 60)}m`;
            if (interval < 86400) return `${Math.floor(interval / 3600)}h`;
            return `${Math.floor(interval / 86400)}d`;
        },

        truncateId(id) {
            if (!id) return '';
            return id.length > 12 ? `${id.substring(0, 8)}...` : id;
        },

        escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        }
    }
};
</script>

<style scoped>
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

.schedule-view .table td {
    vertical-align: middle;
}

.schedule-view code {
    font-size: 0.85rem;
}

.schedule-view .modal {
    display: block;
}
</style>
