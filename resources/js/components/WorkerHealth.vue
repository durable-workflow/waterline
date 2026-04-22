<template>
    <div class="worker-health">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5 class="mb-0">Worker Fleet Health</h5>
                <div>
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
                <p class="mt-2 mb-0 text-muted">Loading worker health...</p>
            </div>

            <div v-else-if="error" class="card-body">
                <div class="alert alert-danger mb-0" role="alert">
                    <strong>Error:</strong> {{ error }}
                </div>
            </div>

            <div v-else class="card-body">
                <!-- Overall Status -->
                <div v-if="healthData" class="mb-4">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="border rounded p-3 text-center">
                                <div class="h2 mb-1" :class="statusColor(healthData.status)">
                                    {{ healthData.status.toUpperCase() }}
                                </div>
                                <small class="text-muted">Overall Health</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border rounded p-3 text-center">
                                <div class="h2 mb-1">{{ activeWorkerCount }}</div>
                                <small class="text-muted">Active Workers</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border rounded p-3 text-center">
                                <div class="h2 mb-1">{{ supportedWorkerCount }}</div>
                                <small class="text-muted">Compatible Workers</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border rounded p-3 text-center">
                                <div class="h2 mb-1">{{ totalLeases }}</div>
                                <small class="text-muted">Active Leases</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Worker List -->
                <div v-if="workers.length > 0">
                    <h6 class="mb-3">Registered Workers</h6>
                    <div class="table-responsive">
                        <table :class="workersTableClass">
                            <thead>
                                <tr>
                                    <th v-if="columnEnabled('worker_id')">Worker ID</th>
                                    <th v-if="columnEnabled('runtime')">Runtime</th>
                                    <th v-if="columnEnabled('task_queue')">Task Queue</th>
                                    <th v-if="columnEnabled('heartbeat')">Heartbeat</th>
                                    <th v-if="columnEnabled('status')">Status</th>
                                    <th v-if="columnEnabled('workflows')">Workflows</th>
                                    <th v-if="columnEnabled('activities')">Activities</th>
                                    <th v-if="columnEnabled('concurrency')">Concurrency</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="worker in workers" :key="worker.worker_id" :class="workerRowClass(worker)">
                                    <td v-if="columnEnabled('worker_id')">
                                        <code class="small">{{ truncateId(worker.worker_id) }}</code>
                                    </td>
                                    <td v-if="columnEnabled('runtime')">
                                        <span class="badge badge-secondary">{{ worker.runtime }}</span>
                                    </td>
                                    <td v-if="columnEnabled('task_queue')">
                                        <span class="text-monospace small">{{ worker.task_queue || 'default' }}</span>
                                    </td>
                                    <td v-if="columnEnabled('heartbeat')">
                                        <span :class="heartbeatClass(worker)" class="small">
                                            {{ formatHeartbeat(worker.last_heartbeat_at) }}
                                        </span>
                                    </td>
                                    <td v-if="columnEnabled('status')">
                                        <span class="badge" :class="statusBadgeClass(worker)">
                                            {{ worker.status || 'unknown' }}
                                        </span>
                                    </td>
                                    <td v-if="columnEnabled('workflows')" class="small">
                                        <span v-if="worker.supported_workflow_types && worker.supported_workflow_types.length > 0">
                                            {{ worker.supported_workflow_types.length }} types
                                        </span>
                                        <span v-else class="text-muted">none</span>
                                    </td>
                                    <td v-if="columnEnabled('activities')" class="small">
                                        <span v-if="worker.supported_activity_types && worker.supported_activity_types.length > 0">
                                            {{ worker.supported_activity_types.length }} types
                                        </span>
                                        <span v-else class="text-muted">none</span>
                                    </td>
                                    <td v-if="columnEnabled('concurrency')" class="small">
                                        WF: {{ worker.max_concurrent_workflow_tasks || 0 }} /
                                        ACT: {{ worker.max_concurrent_activity_tasks || 0 }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-else class="text-center py-4 text-muted">
                    <p class="mb-0">No workers registered</p>
                </div>

                <!-- Health Checks -->
                <div v-if="healthChecks.length > 0" class="mt-4">
                    <h6 class="mb-3">Health Checks</h6>
                    <div class="list-group list-group-flush">
                        <div
                            v-for="check in healthChecks"
                            :key="check.name"
                            class="list-group-item px-0">
                            <div class="d-flex align-items-center">
                                <span
                                    class="badge mr-2"
                                    :class="checkBadgeClass(check.status)">
                                    {{ check.status }}
                                </span>
                                <div class="flex-grow-1">
                                    <strong>{{ check.name }}</strong>
                                    <p class="mb-0 small text-muted">{{ check.message }}</p>
                                </div>
                            </div>
                        </div>
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
            default: 30000 // 30 seconds
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
            // This would come from metrics if available
            return this.workers.reduce((sum, w) => {
                return sum + (w.current_leases || 0);
            }, 0);
        },

        healthChecks() {
            return this.healthData?.checks || [];
        },

        workersTableClass() {
            const classes = ['table', 'table-hover'];

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

                // Extract worker data from health response if available
                // This may need adjustment based on actual API structure
                this.workers = response.data.operator_metrics?.workers?.registrations || [];
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
                {key: 'workflows', label: 'Workflows'},
                {key: 'activities', label: 'Activities'},
                {key: 'concurrency', label: 'Concurrency'},
            ];
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
                background: '#1c1c1c',
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

        statusColor(status) {
            return {
                'ok': 'text-success',
                'warning': 'text-warning',
                'error': 'text-danger'
            }[status] || 'text-secondary';
        },

        statusBadgeClass(worker) {
            const status = worker.status || 'unknown';
            return {
                'active': 'badge-success',
                'idle': 'badge-info',
                'draining': 'badge-warning',
                'offline': 'badge-secondary',
                'failed': 'badge-danger'
            }[status] || 'badge-secondary';
        },

        checkBadgeClass(status) {
            return {
                'ok': 'badge-success',
                'warning': 'badge-warning',
                'error': 'badge-danger'
            }[status] || 'badge-secondary';
        },

        heartbeatClass(worker) {
            const isStale = this.isHeartbeatStale(worker.last_heartbeat_at);
            return isStale ? 'text-danger' : 'text-success';
        },

        workerRowClass(worker) {
            const isStale = this.isHeartbeatStale(worker.last_heartbeat_at);
            return isStale ? 'table-danger' : '';
        },

        isHeartbeatStale(lastHeartbeat) {
            if (!lastHeartbeat) return true;
            const lastTime = new Date(lastHeartbeat);
            const now = new Date();
            const diffMinutes = (now - lastTime) / (1000 * 60);
            return diffMinutes > 5; // Consider stale if no heartbeat in 5 minutes
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
        }
    }
};
</script>

<style scoped>
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

.worker-health .table td {
    vertical-align: middle;
}

.worker-health code {
    font-size: 0.85rem;
}

.worker-health .badge {
    font-size: 0.75rem;
}
</style>
