<template>
    <div class="schedule-view">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5 class="mb-0">Workflow Schedules</h5>
                <div>
                    <select v-model="statusFilter" class="form-control form-control-sm d-inline-block mr-2" style="width: auto;">
                        <option value="">All Statuses</option>
                        <option value="active">Active</option>
                        <option value="paused">Paused</option>
                        <option value="deleted">Deleted</option>
                    </select>
                    <button class="btn btn-sm btn-outline-secondary" @click="refresh">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon fill-text-color" style="width: 16px; height: 16px;">
                            <path d="M10 3v2a5 5 0 0 0-3.54 8.54l-1.41 1.41A7 7 0 0 1 10 3zm4.95 2.05A7 7 0 0 1 10 17v-2a5 5 0 0 0 3.54-8.54l1.41-1.41zM10 20l-4-4 4-4v8zm0-12V0l4 4-4 4z"></path>
                        </svg>
                        Refresh
                    </button>
                </div>
            </div>

            <div v-if="loading" class="card-body text-center py-5">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin fill-text-color" style="width: 32px; height: 32px;">
                    <path d="M12 10a2 2 0 0 1-3.41 1.41A2 2 0 0 1 10 8V0a9.97 9.97 0 0 1 10 10h-8zm7.9 1.41A10 10 0 1 1 8.59.1v2.03a8 8 0 1 0 9.29 9.29h2.02zm-4.07 0a6 6 0 1 1-7.25-7.25v2.1a3.99 3.99 0 0 0-1.4 6.57 4 4 0 0 0 6.56-1.42h2.1z"></path>
                </svg>
                <p class="mt-2 mb-0 text-muted">Loading schedules...</p>
            </div>

            <div v-else-if="error" class="card-body">
                <div class="alert alert-danger mb-0">
                    <strong>Error:</strong> {{ error }}
                </div>
            </div>

            <div v-else class="card-body p-0">
                <div v-if="schedules.length > 0" class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Schedule ID</th>
                                <th>Workflow Type</th>
                                <th>Spec</th>
                                <th>Status</th>
                                <th>Next Fire</th>
                                <th>Last Result</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="schedule in schedules" :key="schedule.id">
                                <td>
                                    <code class="small">{{ truncateId(schedule.id) }}</code>
                                </td>
                                <td>
                                    <code class="small">{{ schedule.workflow_type || schedule.workflow_class }}</code>
                                </td>
                                <td class="small">
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
                                <td>
                                    <span class="badge" :class="statusBadgeClass(schedule.status)">
                                        {{ schedule.status }}
                                    </span>
                                </td>
                                <td class="small">
                                    <span v-if="schedule.next_fire_at" :class="nextFireClass(schedule.next_fire_at)">
                                        {{ formatTimestamp(schedule.next_fire_at) }}
                                    </span>
                                    <span v-else class="text-muted">-</span>
                                </td>
                                <td class="small">
                                    <span v-if="schedule.last_fire_at">
                                        {{ formatTimestamp(schedule.last_fire_at) }}
                                        <span v-if="schedule.last_fire_result" class="ml-1" :class="resultClass(schedule.last_fire_result)">
                                            ({{ schedule.last_fire_result }})
                                        </span>
                                    </span>
                                    <span v-else class="text-muted">Never</span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button
                                            v-if="schedule.status === 'active'"
                                            class="btn btn-sm btn-outline-warning"
                                            @click="pauseSchedule(schedule.id)"
                                            title="Pause">
                                            ⏸
                                        </button>
                                        <button
                                            v-if="schedule.status === 'paused'"
                                            class="btn btn-sm btn-outline-success"
                                            @click="resumeSchedule(schedule.id)"
                                            title="Resume">
                                            ▶
                                        </button>
                                        <button
                                            class="btn btn-sm btn-outline-primary"
                                            @click="triggerNow(schedule.id)"
                                            title="Trigger Now">
                                            ⚡
                                        </button>
                                        <button
                                            class="btn btn-sm btn-outline-info"
                                            @click="showBackfillDialog(schedule)"
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
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item" :class="{ disabled: pagination.current_page === 1 }">
                                <button class="page-link" @click="goToPage(pagination.current_page - 1)">Previous</button>
                            </li>
                            <li
                                v-for="page in visiblePages"
                                :key="page"
                                class="page-item"
                                :class="{ active: page === pagination.current_page }">
                                <button class="page-link" @click="goToPage(page)">{{ page }}</button>
                            </li>
                            <li class="page-item" :class="{ disabled: pagination.current_page === pagination.last_page }">
                                <button class="page-link" @click="goToPage(pagination.current_page + 1)">Next</button>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>
        </div>

        <!-- Backfill Dialog (simple version - could use a modal library) -->
        <div v-if="showBackfill" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="showBackfill = false">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Backfill Schedule</h5>
                        <button type="button" class="close" @click="showBackfill = false">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted">
                            Backfill will trigger workflow executions for missed schedule times in the specified range.
                        </p>
                        <div class="form-group">
                            <label>From (ISO 8601)</label>
                            <input v-model="backfillFrom" type="datetime-local" class="form-control" />
                        </div>
                        <div class="form-group">
                            <label>To (ISO 8601)</label>
                            <input v-model="backfillTo" type="datetime-local" class="form-control" />
                        </div>
                        <div class="form-group">
                            <label>Overlap Policy</label>
                            <select v-model="backfillOverlapPolicy" class="form-control">
                                <option value="">Use schedule default</option>
                                <option value="skip">Skip</option>
                                <option value="allow">Allow</option>
                                <option value="terminate">Terminate</option>
                                <option value="cancel">Cancel</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" @click="showBackfill = false">Cancel</button>
                        <button class="btn btn-primary" @click="executeBackfill">Backfill</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    name: 'ScheduleView',

    props: {
        apiEndpoint: {
            type: String,
            default: '/waterline-api/v2/schedules'
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
            backfillOverlapPolicy: ''
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
        }
    },

    watch: {
        statusFilter() {
            this.currentPage = 1;
            this.loadData();
        }
    },

    mounted() {
        this.loadData();
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

                const response = await axios.get(this.apiEndpoint, { params });
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

        goToPage(page) {
            if (page < 1 || (this.pagination && page > this.pagination.last_page)) return;
            this.currentPage = page;
            this.loadData();
        },

        async pauseSchedule(scheduleId) {
            try {
                await axios.post(`${this.apiEndpoint}/${scheduleId}/pause`);
                await this.loadData();
            } catch (e) {
                alert(`Failed to pause schedule: ${e.response?.data?.error || e.message}`);
            }
        },

        async resumeSchedule(scheduleId) {
            try {
                await axios.post(`${this.apiEndpoint}/${scheduleId}/resume`);
                await this.loadData();
            } catch (e) {
                alert(`Failed to resume schedule: ${e.response?.data?.error || e.message}`);
            }
        },

        async triggerNow(scheduleId) {
            if (!confirm('Trigger this schedule now?')) return;

            try {
                const response = await axios.post(`${this.apiEndpoint}/${scheduleId}/trigger`);
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
                    `${this.apiEndpoint}/${this.backfillScheduleId}/backfill`,
                    payload
                );

                alert(`Backfill completed. Results: ${JSON.stringify(response.data.results)}`);
                this.showBackfill = false;
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
