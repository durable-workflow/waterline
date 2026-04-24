<template>
    <div class="worker-health">
        <section class="worker-health__hero">
            <div>
                <p class="worker-health__eyebrow">Operator surface</p>
                <h1 class="worker-health__title">Workers</h1>
                <p class="worker-health__subtitle">
                    Heartbeats, compatibility coverage, and queue capacity across the active Waterline fleet.
                </p>
            </div>

            <div class="worker-health__actions">
                <span v-if="healthData" class="worker-health__pill" :class="statusToneClass(healthData.status)">
                    {{ (healthData.status || 'unknown').toUpperCase() }}
                </span>

                <button class="btn btn-sm btn-outline-secondary" @click="editViewOptions" :disabled="savingOperatorPreferences">
                    View Options
                </button>

                <button class="btn btn-sm btn-outline-secondary" @click="refresh">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon fill-text-color worker-health__button-icon">
                        <path d="M10 3v2a5 5 0 0 0-3.54 8.54l-1.41 1.41A7 7 0 0 1 10 3zm4.95 2.05A7 7 0 0 1 10 17v-2a5 5 0 0 0 3.54-8.54l1.41-1.41zM10 20l-4-4 4-4v8zm0-12V0l4 4-4 4z"></path>
                    </svg>
                    Refresh
                </button>
            </div>
        </section>

        <div v-if="loading" class="worker-health__state card card-bg-secondary">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin fill-text-color worker-health__state-icon">
                <path d="M12 10a2 2 0 0 1-3.41 1.41A2 2 0 0 1 10 8V0a9.97 9.97 0 0 1 10 10h-8zm7.9 1.41A10 10 0 1 1 8.59.1v2.03a8 8 0 1 0 9.29 9.29h2.02zm-4.07 0a6 6 0 1 1-7.25-7.25v2.1a3.99 3.99 0 0 0-1.4 6.57 4 4 0 0 0 6.56-1.42h2.1z"></path>
            </svg>
            <p class="worker-health__state-copy">Loading worker health…</p>
        </div>

        <div v-else-if="error" class="worker-health__state card card-bg-secondary worker-health__state--error">
            <strong>Worker health unavailable</strong>
            <p class="worker-health__state-copy">{{ error }}</p>
            <button class="btn btn-sm btn-outline-primary" @click="refresh">Retry</button>
        </div>

        <div v-else class="worker-health__content">
            <section class="worker-health__summary-grid">
                <article class="card worker-health__summary-card">
                    <div class="card-body card-bg-secondary">
                        <div class="worker-health__summary-label">Overall health</div>
                        <div class="worker-health__summary-value" :class="statusToneClass(healthData && healthData.status)">
                            {{ (healthData && healthData.status ? healthData.status : 'unknown').toUpperCase() }}
                        </div>
                        <div class="worker-health__summary-meta">Derived from worker heartbeats and health checks.</div>
                    </div>
                </article>

                <article class="card worker-health__summary-card">
                    <div class="card-body card-bg-secondary">
                        <div class="worker-health__summary-label">Active workers</div>
                        <div class="worker-health__summary-value">{{ activeWorkerCount.toLocaleString() }}</div>
                        <div class="worker-health__summary-meta">{{ workers.length.toLocaleString() }} registrations returned.</div>
                    </div>
                </article>

                <article class="card worker-health__summary-card">
                    <div class="card-body card-bg-secondary">
                        <div class="worker-health__summary-label">Compatible workers</div>
                        <div class="worker-health__summary-value">{{ supportedWorkerCount.toLocaleString() }}</div>
                        <div class="worker-health__summary-meta">Workers supporting the required compatibility marker.</div>
                    </div>
                </article>

                <article class="card worker-health__summary-card">
                    <div class="card-body card-bg-secondary">
                        <div class="worker-health__summary-label">Active leases</div>
                        <div class="worker-health__summary-value">{{ totalLeases.toLocaleString() }}</div>
                        <div class="worker-health__summary-meta">{{ staleWorkerCount.toLocaleString() }} worker<span v-if="staleWorkerCount !== 1">s</span> stale.</div>
                    </div>
                </article>
            </section>

            <section class="worker-health__grid">
                <article class="card worker-health__panel worker-health__panel--wide">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <div>
                            <h5 class="mb-0">Worker fleet</h5>
                            <small class="text-muted">Runtime, queues, capability coverage, and heartbeat freshness.</small>
                        </div>

                        <span class="worker-health__pill worker-health__pill--muted">
                            {{ workers.length.toLocaleString() }} workers
                        </span>
                    </div>

                    <div class="card-body card-bg-secondary p-0">
                        <div v-if="workers.length > 0" class="table-responsive">
                            <table :class="workersTableClass">
                                <thead>
                                    <tr>
                                        <th v-if="columnEnabled('worker_id')">Worker</th>
                                        <th v-if="columnEnabled('runtime')">Runtime</th>
                                        <th v-if="columnEnabled('task_queue')">Task Queue</th>
                                        <th v-if="columnEnabled('heartbeat')">Heartbeat</th>
                                        <th v-if="columnEnabled('status')">Status</th>
                                        <th v-if="columnEnabled('compatibility')">Compatibility</th>
                                        <th v-if="columnEnabled('source')">Source</th>
                                        <th v-if="columnEnabled('workflows')">Workflow Support</th>
                                        <th v-if="columnEnabled('activities')">Activity Support</th>
                                        <th v-if="columnEnabled('concurrency')">Concurrency</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="worker in workers" :key="worker.worker_id" :class="workerRowClass(worker)">
                                        <td v-if="columnEnabled('worker_id')">
                                            <div class="worker-health__cell-main">
                                                <code>{{ truncateId(worker.worker_id) }}</code>
                                                <div class="worker-health__cell-meta" v-if="worker.current_leases">
                                                    {{ worker.current_leases }} active lease<span v-if="worker.current_leases !== 1">s</span>
                                                </div>
                                            </div>
                                        </td>

                                        <td v-if="columnEnabled('runtime')">
                                            <span class="worker-health__pill worker-health__pill--muted">{{ worker.runtime || 'unknown' }}</span>
                                        </td>

                                        <td v-if="columnEnabled('task_queue')">
                                            <div class="worker-health__cell-main">
                                                <code>{{ worker.task_queue || 'default' }}</code>
                                                <div class="worker-health__cell-meta">Queue affinity</div>
                                            </div>
                                        </td>

                                        <td v-if="columnEnabled('heartbeat')">
                                            <div class="worker-health__cell-main">
                                                <span :class="heartbeatClass(worker)">{{ formatHeartbeat(worker.last_heartbeat_at) }}</span>
                                                <div class="worker-health__cell-meta" v-if="worker.last_heartbeat_at">
                                                    {{ worker.last_heartbeat_at }}
                                                </div>
                                            </div>
                                        </td>

                                        <td v-if="columnEnabled('status')">
                                            <span class="worker-health__pill" :class="workerStatusToneClass(worker)">
                                                {{ worker.status || 'unknown' }}
                                            </span>
                                        </td>

                                        <td v-if="columnEnabled('compatibility')">
                                            <div class="worker-health__cell-main">
                                                <span
                                                    v-if="workerCompatibilityMarkers(worker).length > 0"
                                                    class="worker-health__pill"
                                                    :class="compatibilityToneClass(worker)"
                                                    :title="compatibilityTitle(worker)"
                                                >
                                                    {{ workerCompatibilityMarkers(worker).slice(0, 2).join(', ') }}<span v-if="workerCompatibilityMarkers(worker).length > 2">…</span>
                                                </span>
                                                <span v-else class="text-muted">—</span>
                                                <div class="worker-health__cell-meta" v-if="workerCompatibilityMarkers(worker).length > 0">
                                                    {{ worker.supports_required === true ? 'Supports required marker' : worker.supports_required === false ? 'Does not support required marker' : 'Required marker not set' }}
                                                </div>
                                            </div>
                                        </td>

                                        <td v-if="columnEnabled('source')">
                                            <span class="worker-health__pill worker-health__pill--muted" v-if="worker.heartbeat_source">
                                                {{ worker.heartbeat_source }}
                                            </span>
                                            <span v-else class="text-muted">—</span>
                                        </td>

                                        <td v-if="columnEnabled('workflows')">
                                            <div class="worker-health__cell-main">
                                                <span v-if="worker.supported_workflow_types && worker.supported_workflow_types.length > 0">
                                                    {{ worker.supported_workflow_types.length }} types
                                                </span>
                                                <span v-else class="text-muted">None</span>
                                                <div class="worker-health__cell-meta" v-if="worker.supported_workflow_types && worker.supported_workflow_types.length > 0">
                                                    {{ worker.supported_workflow_types.slice(0, 2).join(', ') }}<span v-if="worker.supported_workflow_types.length > 2">…</span>
                                                </div>
                                            </div>
                                        </td>

                                        <td v-if="columnEnabled('activities')">
                                            <div class="worker-health__cell-main">
                                                <span v-if="worker.supported_activity_types && worker.supported_activity_types.length > 0">
                                                    {{ worker.supported_activity_types.length }} types
                                                </span>
                                                <span v-else class="text-muted">None</span>
                                                <div class="worker-health__cell-meta" v-if="worker.supported_activity_types && worker.supported_activity_types.length > 0">
                                                    {{ worker.supported_activity_types.slice(0, 2).join(', ') }}<span v-if="worker.supported_activity_types.length > 2">…</span>
                                                </div>
                                            </div>
                                        </td>

                                        <td v-if="columnEnabled('concurrency')">
                                            <div class="worker-health__cell-main">
                                                WF {{ worker.max_concurrent_workflow_tasks || 0 }} / ACT {{ worker.max_concurrent_activity_tasks || 0 }}
                                                <div class="worker-health__cell-meta">Task slot limits</div>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-else class="worker-health__empty-state">
                            <strong>No workers registered</strong>
                            <p class="mb-0 text-muted">Waterline has not observed any worker registrations for this scope yet.</p>
                        </div>
                    </div>
                </article>

                <article class="card worker-health__panel">
                    <div class="card-header">
                        <h5 class="mb-0">Health checks</h5>
                        <small class="text-muted">
                            Correctness answers <em>is work being discovered?</em>; acceleration answers <em>is the acceleration layer propagating?</em>.
                        </small>
                    </div>

                    <div class="card-body card-bg-secondary worker-health__categories-body">
                        <section
                            v-for="category in categorizedChecks"
                            :key="category.key"
                            class="worker-health__category"
                        >
                            <header class="worker-health__category-header">
                                <div>
                                    <span class="worker-health__category-eyebrow">{{ category.eyebrow }}</span>
                                    <h6 class="worker-health__category-title">{{ category.title }}</h6>
                                    <p class="worker-health__category-subtitle">{{ category.subtitle }}</p>
                                </div>

                                <span
                                    class="worker-health__pill"
                                    :class="statusToneClass(category.rollupStatus)"
                                    :title="category.rollupTitle"
                                >
                                    {{ category.rollupStatus.toUpperCase() }}
                                </span>
                            </header>

                            <div v-if="category.checks.length > 0" class="worker-health__checks">
                                <article
                                    v-for="check in category.checks"
                                    :key="check.name"
                                    class="worker-health__check"
                                >
                                    <div class="worker-health__check-head">
                                        <span class="worker-health__pill" :class="statusToneClass(check.status)">
                                            {{ check.status }}
                                        </span>
                                        <strong>{{ check.name }}</strong>
                                    </div>

                                    <p class="worker-health__check-copy">{{ check.message }}</p>
                                </article>
                            </div>

                            <div v-else class="worker-health__empty-state worker-health__empty-state--compact">
                                <strong>No {{ category.title.toLowerCase() }} checks reported</strong>
                                <p class="mb-0 text-muted">The health endpoint did not return any checks for this category.</p>
                            </div>
                        </section>
                    </div>
                </article>
            </section>
        </div>
    </div>
</template>

<script>
import axios from 'axios';
import Swal from 'sweetalert2';

export default {
    name: 'WorkerHealth',

    props: {
        apiEndpoint: {
            type: String,
            default: null
        },
        autoRefresh: {
            type: Boolean,
            default: false
        },
        refreshInterval: {
            type: Number,
            default: 30000
        }
    },

    data() {
        return {
            loading: true,
            error: null,
            healthData: null,
            workers: [],
            operatorPreferences: {},
            effectiveOperatorPreferences: {},
            savingOperatorPreferences: false,
            refreshTimer: null
        };
    },

    computed: {
        activeWorkerCount() {
            return this.healthData?.operator_metrics?.workers?.active_workers || 0;
        },

        supportedWorkerCount() {
            return this.healthData?.operator_metrics?.workers?.active_workers_supporting_required || 0;
        },

        totalLeases() {
            return this.workers.reduce((sum, worker) => {
                const leases = Number.isFinite(worker.current_leases) ? worker.current_leases : 0;

                return sum + leases;
            }, 0);
        },

        staleWorkerCount() {
            return this.workers.filter((worker) => this.isHeartbeatStale(worker.last_heartbeat_at)).length;
        },

        healthChecks() {
            return this.healthData?.checks || [];
        },

        categorizedChecks() {
            const definitions = [
                {
                    key: 'correctness',
                    eyebrow: 'Durable substrate',
                    title: 'Correctness',
                    subtitle: 'Answers "is work being discovered?" from durable dispatch state.',
                    rollupTitle: 'Rollup of correctness-category checks.',
                },
                {
                    key: 'acceleration',
                    eyebrow: 'Optional layer',
                    title: 'Acceleration',
                    subtitle: 'Answers "is the acceleration layer propagating?". Degraded acceleration never masks correctness.',
                    rollupTitle: 'Rollup of acceleration-category checks.',
                },
            ];

            const grouped = {correctness: [], acceleration: []};

            this.healthChecks.forEach((check) => {
                if (!check || typeof check !== 'object') return;

                if (check.name === 'engine_source') {
                    return;
                }

                const category = this.categoryForCheck(check);
                grouped[category].push(check);
            });

            const rollups = this.healthData?.categories || {};

            return definitions.map((definition) => ({
                ...definition,
                checks: grouped[definition.key],
                rollupStatus: this.rollupForCategory(definition.key, grouped[definition.key], rollups),
            }));
        },

        workersTableClass() {
            const classes = ['table', 'table-hover', 'mb-0'];

            if (this.workersListDensity() === 'dense') {
                classes.push('table-sm');
            }

            return classes.join(' ');
        }
    },

    mounted() {
        this.loadOperatorPreferences().finally(() => this.loadData());

        if (this.autoRefresh) {
            this.startAutoRefresh();
        }
    },

    beforeDestroy() {
        this.stopAutoRefresh();
    },

    methods: {
        async loadData() {
            this.loading = true;
            this.error = null;

            try {
                const response = await axios.get(this.resolvedApiEndpoint());
                this.healthData = response.data;
                this.workers = this.workersFromSnapshot(response.data);
            } catch (e) {
                this.error = e.response?.data?.message || e.message || 'Failed to load worker health';
                console.error('Worker health error:', e);
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

            return this.waterlineBasePath() + '/api/v2/health';
        },

        preferenceEndpoint() {
            return this.waterlineBasePath() + '/api/preferences/workers-list';
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
                    row_density: 'dense',
                    columns: this.defaultWorkersListColumns(),
                };
            }
        },

        applyOperatorPreferencePayload(payload) {
            this.operatorPreferences = payload.preferences || {};
            this.effectiveOperatorPreferences = {
                row_density: 'dense',
                columns: this.defaultWorkersListColumns(),
                ...(payload.effective_preferences || {}),
            };
            this.effectiveOperatorPreferences.columns = this.normalizeWorkersListColumns(
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

        workersListDensity() {
            return this.effectiveOperatorPreferences.row_density === 'comfortable'
                ? 'comfortable'
                : 'dense';
        },

        workersListColumnOptions() {
            return [
                {key: 'worker_id', label: 'Worker ID'},
                {key: 'runtime', label: 'Runtime'},
                {key: 'task_queue', label: 'Task Queue'},
                {key: 'heartbeat', label: 'Heartbeat'},
                {key: 'status', label: 'Status'},
                {key: 'compatibility', label: 'Compatibility'},
                {key: 'source', label: 'Heartbeat Source'},
                {key: 'workflows', label: 'Workflows'},
                {key: 'activities', label: 'Activities'},
                {key: 'concurrency', label: 'Concurrency'},
            ];
        },

        workersFromSnapshot(payload) {
            const workers = payload?.operator_metrics?.workers;

            if (!workers || typeof workers !== 'object') {
                return [];
            }

            const registrations = Array.isArray(workers.registrations)
                ? workers.registrations
                : null;

            if (registrations && registrations.length > 0) {
                return registrations;
            }

            const fleet = Array.isArray(workers.fleet) ? workers.fleet : [];

            return fleet
                .filter((entry) => entry && typeof entry === 'object')
                .map((entry) => this.fleetEntryToWorker(entry));
        },

        fleetEntryToWorker(entry) {
            const supported = Array.isArray(entry.supported) ? entry.supported : [];
            const supportsRequired = entry.supports_required === true;
            const connection = typeof entry.connection === 'string' && entry.connection !== ''
                ? entry.connection
                : null;
            const queue = typeof entry.queue === 'string' && entry.queue !== ''
                ? entry.queue
                : null;
            const taskQueue = [connection, queue].filter((value) => value !== null).join(':') || null;

            return {
                worker_id: typeof entry.worker_id === 'string' ? entry.worker_id : '',
                runtime: null,
                task_queue: taskQueue,
                last_heartbeat_at: typeof entry.recorded_at === 'string' ? entry.recorded_at : null,
                status: supportsRequired ? 'active' : 'incompatible',
                supported_workflow_types: null,
                supported_activity_types: null,
                max_concurrent_workflow_tasks: null,
                max_concurrent_activity_tasks: null,
                current_leases: null,
                supported_compatibility: supported,
                supports_required: supportsRequired,
                heartbeat_source: typeof entry.source === 'string' ? entry.source : null,
            };
        },

        defaultWorkersListColumns() {
            return this.workersListColumnOptions().map((column) => column.key);
        },

        normalizeWorkersListColumns(columns) {
            const allowed = this.workersListColumnOptions().map((column) => column.key);
            const requested = Array.isArray(columns) ? columns : this.defaultWorkersListColumns();
            const normalized = requested.filter((column) => allowed.includes(column));

            if (!normalized.includes('worker_id')) {
                normalized.unshift('worker_id');
            }

            return normalized.length > 0 ? normalized : this.defaultWorkersListColumns();
        },

        columnEnabled(column) {
            return this.normalizeWorkersListColumns(this.effectiveOperatorPreferences.columns).includes(column);
        },

        async persistWorkersListPreferences(preferences) {
            const payload = {
                ...this.operatorPreferences,
                ...preferences,
            };

            if (payload.columns) {
                payload.columns = this.normalizeWorkersListColumns(payload.columns);
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
            const columns = this.normalizeWorkersListColumns(this.effectiveOperatorPreferences.columns);
            const columnHtml = this.workersListColumnOptions().map((column) => `
                <label class="d-flex align-items-center justify-content-start mb-2" for="waterline-worker-column-${this.escapeHtml(column.key)}">
                    <input id="waterline-worker-column-${this.escapeHtml(column.key)}"
                           type="checkbox"
                           class="mr-2 waterline-worker-column-option"
                           value="${this.escapeHtml(column.key)}"
                           ${columns.includes(column.key) ? 'checked' : ''}
                           ${column.key === 'worker_id' ? 'disabled' : ''}>
                    <span>${this.escapeHtml(column.label)}</span>
                </label>
            `).join('');

            const result = await Swal.fire({
                title: 'View Options',
                html: `
                    <div class="text-left">
                        <label class="d-block mb-1">Density</label>
                        <select id="waterline-workers-density" class="swal2-input">
                            <option value="dense" ${this.workersListDensity() === 'dense' ? 'selected' : ''}>Dense</option>
                            <option value="comfortable" ${this.workersListDensity() === 'comfortable' ? 'selected' : ''}>Comfortable</option>
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
                    const selectedColumns = Array.from(document.querySelectorAll('.waterline-worker-column-option'))
                        .filter((input) => input.checked || input.value === 'worker_id')
                        .map((input) => input.value);

                    return {
                        row_density: document.getElementById('waterline-workers-density').value,
                        columns: selectedColumns,
                    };
                },
            });

            if (!result.isConfirmed) {
                return;
            }

            await this.persistWorkersListPreferences(result.value);
        },

        escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        },

        startAutoRefresh() {
            this.refreshTimer = setInterval(() => {
                this.loadData();
            }, this.refreshInterval);
        },

        stopAutoRefresh() {
            if (this.refreshTimer) {
                clearInterval(this.refreshTimer);
                this.refreshTimer = null;
            }
        },

        categoryForCheck(check) {
            const declared = typeof check.category === 'string' ? check.category.toLowerCase() : null;

            if (declared === 'correctness' || declared === 'acceleration') {
                return declared;
            }

            // Backwards-compatibility: if an older workflow package version
            // omits the category field, infer a safe default so the UI still
            // renders. Only the wake-acceleration check is known to belong to
            // the acceleration category; every other check is durable
            // correctness-substrate.
            if (check.name === 'long_poll_wake_acceleration') {
                return 'acceleration';
            }

            return 'correctness';
        },

        rollupForCategory(key, checks, rollups) {
            const declared = rollups && rollups[key] && rollups[key].status;

            if (declared === 'ok' || declared === 'warning' || declared === 'error') {
                return declared;
            }

            if (!Array.isArray(checks) || checks.length === 0) {
                return 'ok';
            }

            const statuses = checks.map((check) => check.status);

            if (statuses.includes('error')) return 'error';
            if (statuses.includes('warning')) return 'warning';

            return 'ok';
        },

        statusColor(status) {
            return {
                ok: 'text-success',
                warning: 'text-warning',
                error: 'text-danger'
            }[status] || 'text-secondary';
        },

        statusToneClass(status) {
            return {
                ok: 'is-ok',
                warning: 'is-warning',
                error: 'is-error',
                active: 'is-ok',
                idle: 'is-info',
                draining: 'is-warning',
                failed: 'is-error',
                offline: 'is-muted',
            }[status] || 'is-muted';
        },

        workerStatusToneClass(worker) {
            if (worker.status === 'incompatible') {
                return 'is-warning';
            }

            return this.statusToneClass(worker.status || 'unknown');
        },

        workerCompatibilityMarkers(worker) {
            if (Array.isArray(worker.supported_compatibility)) {
                return worker.supported_compatibility;
            }

            return [];
        },

        compatibilityToneClass(worker) {
            if (worker.supports_required === true) {
                return 'is-ok';
            }

            if (worker.supports_required === false) {
                return 'is-warning';
            }

            return 'is-muted';
        },

        compatibilityTitle(worker) {
            const markers = this.workerCompatibilityMarkers(worker);

            if (markers.length === 0) {
                return 'No compatibility markers advertised';
            }

            return 'Advertised: ' + markers.join(', ');
        },

        heartbeatClass(worker) {
            const isStale = this.isHeartbeatStale(worker.last_heartbeat_at);
            return isStale ? 'text-danger' : 'text-success';
        },

        workerRowClass(worker) {
            const isStale = this.isHeartbeatStale(worker.last_heartbeat_at);
            return isStale ? 'worker-health__row--stale' : '';
        },

        isHeartbeatStale(lastHeartbeat) {
            if (!lastHeartbeat) return true;
            const lastTime = new Date(lastHeartbeat);
            const now = new Date();
            const diffMinutes = (now - lastTime) / (1000 * 60);
            return diffMinutes > 5;
        },

        formatHeartbeat(timestamp) {
            if (!timestamp) return 'never';
            const date = new Date(timestamp);
            const now = new Date();
            const diffSeconds = Math.floor((now - date) / 1000);

            if (diffSeconds < 60) return `${diffSeconds}s ago`;
            if (diffSeconds < 3600) return `${Math.floor(diffSeconds / 60)}m ago`;
            if (diffSeconds < 86400) return `${Math.floor(diffSeconds / 3600)}h ago`;
            return date.toLocaleString();
        },

        truncateId(id) {
            if (!id) return '';
            return id.length > 12 ? `${id.substring(0, 8)}...${id.substring(id.length - 4)}` : id;
        },

        swalBackground() {
            return this.$root && this.$root.theme === 'light' ? '#ffffff' : '#1c1c1c';
        },
    }
};
</script>

<style scoped>
.worker-health {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.worker-health__hero {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 1rem;
}

.worker-health__eyebrow {
    margin: 0 0 0.45rem;
    color: var(--wl-text-soft);
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.72rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.worker-health__title {
    margin: 0;
    color: var(--wl-text);
    font-size: 2.1rem;
    font-weight: 600;
    letter-spacing: -0.04em;
}

.worker-health__subtitle {
    margin: 0.5rem 0 0;
    max-width: 40rem;
    color: var(--wl-text-muted);
}

.worker-health__actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    align-items: center;
    gap: 0.75rem;
}

.worker-health__button-icon {
    width: 0.95rem;
    height: 0.95rem;
    margin-right: 0.45rem;
}

.worker-health__pill {
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

.worker-health__pill.is-ok {
    background: color-mix(in srgb, var(--wl-success) 16%, transparent);
    color: var(--wl-success);
}

.worker-health__pill.is-warning {
    background: color-mix(in srgb, var(--wl-warning) 16%, transparent);
    color: var(--wl-warning);
}

.worker-health__pill.is-error {
    background: color-mix(in srgb, var(--wl-danger) 16%, transparent);
    color: var(--wl-danger);
}

.worker-health__pill.is-info {
    background: color-mix(in srgb, var(--wl-accent) 16%, transparent);
    color: var(--wl-accent);
}

.worker-health__pill.is-muted,
.worker-health__pill--muted {
    background: color-mix(in srgb, var(--wl-text) 5%, transparent);
    color: var(--wl-text-muted);
}

.worker-health__state {
    min-height: 18rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 2rem;
}

.worker-health__state-icon {
    width: 2rem;
    height: 2rem;
}

.worker-health__state-copy {
    margin: 0.85rem 0 0;
    color: var(--wl-text-muted);
}

.worker-health__state--error strong {
    color: var(--wl-text);
}

.worker-health__content {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.worker-health__summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
}

.worker-health__summary-label {
    color: var(--wl-text-soft);
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.72rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.worker-health__summary-value {
    margin-top: 0.65rem;
    color: var(--wl-text);
    font-size: 2rem;
    font-weight: 600;
    letter-spacing: -0.04em;
}

.worker-health__summary-value.is-ok {
    color: var(--wl-success);
}

.worker-health__summary-value.is-warning {
    color: var(--wl-warning);
}

.worker-health__summary-value.is-error {
    color: var(--wl-danger);
}

.worker-health__summary-meta {
    margin-top: 0.55rem;
    color: var(--wl-text-muted);
    font-size: 0.92rem;
}

.worker-health__grid {
    display: grid;
    grid-template-columns: minmax(0, 2fr) minmax(18rem, 1fr);
    gap: 1rem;
}

.worker-health__panel {
    min-width: 0;
}

.worker-health__panel--wide .card-body {
    min-height: 22rem;
}

.worker-health__categories-body {
    display: flex;
    flex-direction: column;
    gap: 1.4rem;
}

.worker-health__category {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    padding-bottom: 1.1rem;
    border-bottom: 1px solid color-mix(in srgb, var(--wl-text) 8%, transparent);
}

.worker-health__category:last-child {
    border-bottom: 0;
    padding-bottom: 0;
}

.worker-health__category-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
}

.worker-health__category-eyebrow {
    display: block;
    color: var(--wl-text-soft);
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.7rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.worker-health__category-title {
    margin: 0.25rem 0 0;
    color: var(--wl-text);
    font-size: 1rem;
    font-weight: 600;
}

.worker-health__category-subtitle {
    margin: 0.3rem 0 0;
    color: var(--wl-text-muted);
    font-size: 0.88rem;
    line-height: 1.4;
}

.worker-health__checks {
    display: grid;
    gap: 0.75rem;
}

.worker-health__check {
    padding: 0.95rem 1rem;
    border-radius: 14px;
    background: color-mix(in srgb, var(--wl-text) 4%, var(--wl-surface));
    border: 1px solid color-mix(in srgb, var(--wl-text) 8%, transparent);
}

.worker-health__check-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.6rem;
}

.worker-health__check-head strong {
    color: var(--wl-text);
}

.worker-health__check-copy {
    margin: 0.65rem 0 0;
    color: var(--wl-text-muted);
    line-height: 1.5;
}

.worker-health__empty-state {
    display: flex;
    min-height: 16rem;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.55rem;
    text-align: center;
    padding: 2rem;
}

.worker-health__empty-state--compact {
    min-height: 14rem;
}

.worker-health__empty-state strong {
    color: var(--wl-text);
}

.worker-health .table th,
.worker-health .table td {
    vertical-align: top;
}

.worker-health .table tbody tr.worker-health__row--stale {
    background: color-mix(in srgb, var(--wl-danger) 8%, transparent);
}

.worker-health__cell-main {
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
}

.worker-health__cell-main code {
    color: var(--wl-text);
    font-size: 0.86rem;
}

.worker-health__cell-meta {
    color: var(--wl-text-soft);
    font-size: 0.8rem;
    overflow-wrap: anywhere;
}

.worker-health .icon {
    display: inline-block;
    vertical-align: middle;
}

.worker-health .icon.spin {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

@media (max-width: 1200px) {
    .worker-health__summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .worker-health__grid {
        grid-template-columns: minmax(0, 1fr);
    }
}

@media (max-width: 768px) {
    .worker-health__hero {
        flex-direction: column;
        align-items: flex-start;
    }

    .worker-health__actions {
        width: 100%;
        justify-content: flex-start;
    }

    .worker-health__summary-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>