<template>
    <div class="worker-health">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5 class="mb-0">Worker Fleet Health</h5>
                <button class="btn btn-sm btn-outline-secondary" @click="refresh">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon fill-text-color" style="width: 16px; height: 16px;">
                        <path d="M10 3v2a5 5 0 0 0-3.54 8.54l-1.41 1.41A7 7 0 0 1 10 3zm4.95 2.05A7 7 0 0 1 10 17v-2a5 5 0 0 0 3.54-8.54l1.41-1.41zM10 20l-4-4 4-4v8zm0-12V0l4 4-4 4z"></path>
                    </svg>
                    Refresh
                </button>
            </div>

            <div v-if="loading" class="card-body text-center py-5">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin fill-text-color" style="width: 32px; height: 32px;">
                    <path d="M12 10a2 2 0 0 1-3.41 1.41A2 2 0 0 1 10 8V0a9.97 9.97 0 0 1 10 10h-8zm7.9 1.41A10 10 0 1 1 8.59.1v2.03a8 8 0 1 0 9.29 9.29h2.02zm-4.07 0a6 6 0 1 1-7.25-7.25v2.1a3.99 3.99 0 0 0-1.4 6.57 4 4 0 0 0 6.56-1.42h2.1z"></path>
                </svg>
                <p class="mt-2 mb-0 text-muted">Loading worker health...</p>
            </div>

            <div v-else-if="error" class="card-body">
                <div class="alert alert-danger mb-0">
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
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>Worker ID</th>
                                    <th>Runtime</th>
                                    <th>Task Queue</th>
                                    <th>Heartbeat</th>
                                    <th>Status</th>
                                    <th>Workflows</th>
                                    <th>Activities</th>
                                    <th>Concurrency</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="worker in workers" :key="worker.worker_id" :class="workerRowClass(worker)">
                                    <td>
                                        <code class="small">{{ truncateId(worker.worker_id) }}</code>
                                    </td>
                                    <td>
                                        <span class="badge badge-secondary">{{ worker.runtime }}</span>
                                    </td>
                                    <td>
                                        <span class="text-monospace small">{{ worker.task_queue || 'default' }}</span>
                                    </td>
                                    <td>
                                        <span :class="heartbeatClass(worker)" class="small">
                                            {{ formatHeartbeat(worker.last_heartbeat_at) }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge" :class="statusBadgeClass(worker)">
                                            {{ worker.status || 'unknown' }}
                                        </span>
                                    </td>
                                    <td class="small">
                                        <span v-if="worker.supported_workflow_types && worker.supported_workflow_types.length > 0">
                                            {{ worker.supported_workflow_types.length }} types
                                        </span>
                                        <span v-else class="text-muted">none</span>
                                    </td>
                                    <td class="small">
                                        <span v-if="worker.supported_activity_types && worker.supported_activity_types.length > 0">
                                            {{ worker.supported_activity_types.length }} types
                                        </span>
                                        <span v-else class="text-muted">none</span>
                                    </td>
                                    <td class="small">
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
export default {
    name: 'WorkerHealth',

    props: {
        apiEndpoint: {
            type: String,
            default: '/waterline-api/v2/health'
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
        }
    },

    mounted() {
        this.loadData();
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
                const response = await axios.get(this.apiEndpoint);
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
