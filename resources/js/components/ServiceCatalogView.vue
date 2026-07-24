<template>
    <div class="service-catalog-view">
        <section class="service-catalog-view__hero">
            <div>
                <p class="service-catalog-view__eyebrow">Operator surface</p>
                <h1 class="service-catalog-view__title">Services</h1>
                <p class="service-catalog-view__subtitle">
                    Namespace-scoped catalog entries and durable service-call outcomes.
                </p>
            </div>

            <div class="service-catalog-view__actions">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    class="btn btn-sm"
                    :class="activeTab === tab.key ? 'btn-primary' : 'btn-outline-secondary'"
                    @click="setTab(tab.key)">
                    {{ tab.label }}
                </button>

                <button class="btn btn-sm btn-outline-secondary" @click="refresh">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon fill-text-color service-catalog-view__button-icon">
                        <path d="M10 3v2a5 5 0 0 0-3.54 8.54l-1.41 1.41A7 7 0 0 1 10 3zm4.95 2.05A7 7 0 0 1 10 17v-2a5 5 0 0 0 3.54-8.54l1.41-1.41zM10 20l-4-4 4-4v8zm0-12V0l4 4-4 4z"></path>
                    </svg>
                    Refresh
                </button>
            </div>
        </section>

        <div v-if="activeTab === 'calls'" class="service-catalog-view__filters card">
            <div class="card-body card-bg-secondary">
                <div class="service-catalog-view__filter-grid">
                    <label>
                        <span>Scope</span>
                        <select v-model="callFilters.scope" class="form-control form-control-sm" @change="reloadFromFirstPage">
                            <option value="relevant">Relevant</option>
                            <option value="owned">Owned</option>
                            <option value="caller">Caller</option>
                            <option value="target">Target</option>
                        </select>
                    </label>

                    <label>
                        <span>Status</span>
                        <select v-model="callFilters.status" class="form-control form-control-sm" @change="onSpecificStatusChanged">
                            <option value="">Any status</option>
                            <option v-for="status in statusOptions" :key="status" :value="status">{{ formatLabel(status) }}</option>
                        </select>
                    </label>

                    <label>
                        <span>Status Bucket</span>
                        <select v-model="callFilters.status_bucket" class="form-control form-control-sm" @change="reloadFromFirstPage" :disabled="!!callFilters.status">
                            <option value="">Any status bucket</option>
                            <option v-for="bucket in statusBucketOptions" :key="bucket" :value="bucket">{{ formatLabel(bucket) }}</option>
                        </select>
                    </label>

                    <label>
                        <span>Outcome</span>
                        <select v-model="callFilters.outcome" class="form-control form-control-sm" @change="onSpecificOutcomeChanged">
                            <option value="">Any outcome</option>
                            <option v-for="outcome in outcomeOptions" :key="outcome" :value="outcome">{{ formatLabel(outcome) }}</option>
                        </select>
                    </label>

                    <label>
                        <span>Outcome Bucket</span>
                        <select v-model="callFilters.outcome_bucket" class="form-control form-control-sm" @change="reloadFromFirstPage" :disabled="!!callFilters.outcome">
                            <option value="">Any outcome bucket</option>
                            <option v-for="bucket in outcomeBucketOptions" :key="bucket" :value="bucket">{{ formatLabel(bucket) }}</option>
                        </select>
                    </label>
                </div>

                <div class="service-catalog-view__preset-bar">
                    <button
                        v-for="preset in callPresets"
                        :key="preset.key"
                        type="button"
                        class="btn btn-sm"
                        :class="activePresetKey === preset.key ? 'btn-primary' : 'btn-outline-secondary'"
                        @click="applyCallPreset(preset)">
                        {{ preset.label }}
                    </button>
                </div>
            </div>
        </div>

        <div v-if="loading" class="service-catalog-view__state card card-bg-secondary">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin fill-text-color service-catalog-view__state-icon">
                <path d="M12 10a2 2 0 0 1-3.41 1.41A2 2 0 0 1 10 8V0a9.97 9.97 0 0 1 10 10h-8zm7.9 1.41A10 10 0 1 1 8.59.1v2.03a8 8 0 1 0 9.29 9.29h2.02zm-4.07 0a6 6 0 1 1-7.25-7.25v2.1a3.99 3.99 0 0 0-1.4 6.57 4 4 0 0 0 6.56-1.42h2.1z"></path>
            </svg>
            <p class="service-catalog-view__state-copy">Loading {{ activeTabDefinition.label.toLowerCase() }}...</p>
        </div>

        <div v-else-if="error" class="service-catalog-view__state card card-bg-secondary service-catalog-view__state--error">
            <strong>Services unavailable</strong>
            <p class="service-catalog-view__state-copy">{{ error }}</p>
            <button class="btn btn-sm btn-outline-primary" @click="refresh">Retry</button>
        </div>

        <div v-else class="service-catalog-view__content">
            <section class="service-catalog-view__summary-grid">
                <article class="card service-catalog-view__summary-card">
                    <div class="card-body card-bg-secondary">
                        <div class="service-catalog-view__summary-label">Returned rows</div>
                        <div class="service-catalog-view__summary-value">{{ totalRows.toLocaleString() }}</div>
                        <div class="service-catalog-view__summary-meta">{{ pagination ? pagination.total.toLocaleString() : rows.length.toLocaleString() }} total in this result set.</div>
                    </div>
                </article>

                <article v-if="activeTab === 'calls'" class="card service-catalog-view__summary-card">
                    <div class="card-body card-bg-secondary">
                        <div class="service-catalog-view__summary-label">Open calls</div>
                        <div class="service-catalog-view__summary-value">{{ openCallCount.toLocaleString() }}</div>
                        <div class="service-catalog-view__summary-meta">Calls accepted but not terminal.</div>
                    </div>
                </article>

                <article v-if="activeTab === 'calls'" class="card service-catalog-view__summary-card">
                    <div class="card-body card-bg-secondary">
                        <div class="service-catalog-view__summary-label">Policy outcomes</div>
                        <div class="service-catalog-view__summary-value" :class="policyOutcomeCount > 0 ? 'is-warning' : ''">{{ policyOutcomeCount.toLocaleString() }}</div>
                        <div class="service-catalog-view__summary-meta">Boundary rejections in the returned page.</div>
                    </div>
                </article>

                <article v-if="activeTab === 'calls'" class="card service-catalog-view__summary-card">
                    <div class="card-body card-bg-secondary">
                        <div class="service-catalog-view__summary-label">Terminal calls</div>
                        <div class="service-catalog-view__summary-value">{{ terminalCallCount.toLocaleString() }}</div>
                        <div class="service-catalog-view__summary-meta">Completed, failed, or cancelled calls.</div>
                    </div>
                </article>
            </section>

            <article class="card service-catalog-view__panel">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div>
                        <h5 class="mb-0">{{ activeTabDefinition.panelTitle }}</h5>
                        <small class="text-muted">{{ activeTabDefinition.panelSubtitle }}</small>
                    </div>

                    <span class="service-catalog-view__pill service-catalog-view__pill--muted">
                        {{ activeFilterLabel }}
                    </span>
                </div>

                <div class="card-body card-bg-secondary p-0">
                    <div v-if="rows.length > 0" class="table-responsive">
                        <table class="table table-hover table-sm mb-0">
                            <thead>
                                <tr v-if="activeTab === 'endpoints'">
                                    <th>Endpoint</th>
                                    <th>Namespace</th>
                                    <th>Description</th>
                                    <th class="text-right">Created</th>
                                    <th class="text-right">Action</th>
                                </tr>
                                <tr v-else-if="activeTab === 'services'">
                                    <th>Service</th>
                                    <th>Endpoint</th>
                                    <th>Namespace</th>
                                    <th>Description</th>
                                    <th class="text-right">Action</th>
                                </tr>
                                <tr v-else-if="activeTab === 'operations'">
                                    <th>Operation</th>
                                    <th>Service</th>
                                    <th>Mode</th>
                                    <th>Binding</th>
                                    <th>Namespace</th>
                                    <th class="text-right">Action</th>
                                </tr>
                                <tr v-else>
                                    <th>Call</th>
                                    <th>Operation</th>
                                    <th>Caller</th>
                                    <th>Target</th>
                                    <th>Status</th>
                                    <th>Outcome</th>
                                    <th class="text-right">Accepted</th>
                                    <th class="text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in rows" :key="row.id" :class="rowClass(row)">
                                    <template v-if="activeTab === 'endpoints'">
                                        <td>
                                            <div class="service-catalog-view__cell-main">{{ row.endpoint_name || '-' }}</div>
                                            <div class="service-catalog-view__cell-meta mono">{{ row.id }}</div>
                                        </td>
                                        <td>{{ row.namespace || '-' }}</td>
                                        <td>{{ row.description || '-' }}</td>
                                        <td class="text-right">{{ timestamp(row.created_at) }}</td>
                                        <td class="text-right">
                                            <button class="btn btn-sm btn-outline-primary" @click="openDetail('endpoints', row)">View</button>
                                        </td>
                                    </template>

                                    <template v-else-if="activeTab === 'services'">
                                        <td>
                                            <div class="service-catalog-view__cell-main">{{ row.service_name || '-' }}</div>
                                            <div class="service-catalog-view__cell-meta mono">{{ row.id }}</div>
                                        </td>
                                        <td><code>{{ shortId(row.workflow_service_endpoint_id) }}</code></td>
                                        <td>{{ row.namespace || '-' }}</td>
                                        <td>{{ row.description || '-' }}</td>
                                        <td class="text-right">
                                            <button class="btn btn-sm btn-outline-primary" @click="openDetail('services', row)">View</button>
                                        </td>
                                    </template>

                                    <template v-else-if="activeTab === 'operations'">
                                        <td>
                                            <div class="service-catalog-view__cell-main">{{ row.operation_name || '-' }}</div>
                                            <div class="service-catalog-view__cell-meta mono">{{ row.id }}</div>
                                        </td>
                                        <td><code>{{ shortId(row.workflow_service_id) }}</code></td>
                                        <td>{{ formatLabel(row.operation_mode) }}</td>
                                        <td>{{ formatLabel(row.handler_binding_kind) }}</td>
                                        <td>{{ row.namespace || '-' }}</td>
                                        <td class="text-right">
                                            <button class="btn btn-sm btn-outline-primary" @click="openDetail('operations', row)">View</button>
                                        </td>
                                    </template>

                                    <template v-else>
                                        <td>
                                            <div class="service-catalog-view__cell-main mono">{{ shortId(row.id) }}</div>
                                            <div class="service-catalog-view__cell-meta mono">{{ row.id }}</div>
                                        </td>
                                        <td>
                                            <div class="service-catalog-view__cell-main">{{ row.operation_name || '-' }}</div>
                                            <div class="service-catalog-view__cell-meta">
                                                {{ row.endpoint_name || '-' }} / {{ row.service_name || '-' }}
                                            </div>
                                        </td>
                                        <td>
                                            <div>{{ row.caller_namespace || '-' }}</div>
                                            <div class="service-catalog-view__cell-meta mono" v-if="row.caller_workflow_run_id">
                                                {{ shortId(row.caller_workflow_run_id) }}
                                            </div>
                                        </td>
                                        <td>
                                            <div>{{ row.target_namespace || '-' }}</div>
                                            <div class="service-catalog-view__cell-meta mono" v-if="row.linked_workflow_run_id">
                                                {{ shortId(row.linked_workflow_run_id) }}
                                            </div>
                                        </td>
                                        <td>
                                            <span class="service-catalog-view__pill" :class="statusToneClass(row.status_bucket || row.status)">
                                                {{ row.status || 'unknown' }}
                                            </span>
                                        </td>
                                        <td>
                                            <span v-if="row.outcome" class="service-catalog-view__pill" :class="outcomeToneClass(row.outcome_bucket)">
                                                {{ formatLabel(row.outcome) }}
                                            </span>
                                            <span v-else class="text-muted">-</span>
                                        </td>
                                        <td class="text-right">{{ timestamp(row.accepted_at || row.created_at) }}</td>
                                        <td class="text-right">
                                            <button class="btn btn-sm btn-outline-primary" @click="openDetail('calls', row)">View</button>
                                        </td>
                                    </template>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-else class="service-catalog-view__empty-state">
                        <strong>No {{ activeTabDefinition.emptyLabel }} found</strong>
                        <p class="mb-0 text-muted">No rows matched the current namespace and filters.</p>
                    </div>
                </div>

                <div v-if="pagination && pagination.last_page > 1" class="card-footer service-catalog-view__pagination">
                    <button class="btn btn-secondary btn-sm" @click="goToPage(pagination.current_page - 1)" :disabled="pagination.current_page === 1">
                        Previous
                    </button>

                    <div class="service-catalog-view__pagination-pages">
                        <template v-for="(page, index) in visiblePages" :key="`${page}-${index}-${pagination.current_page}`">
                            <span v-if="page === '...'" class="service-catalog-view__pagination-ellipsis">...</span>
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
                        Next
                    </button>
                </div>
            </article>

            <article v-if="detail || detailLoading || detailError" class="card service-catalog-view__detail">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div>
                        <h5 class="mb-0">{{ detailTitle }}</h5>
                        <small class="text-muted" v-if="detail && detail.id"><code>{{ detail.id }}</code></small>
                    </div>

                    <button class="btn btn-sm btn-outline-secondary" @click="closeDetail">Close</button>
                </div>

                <div class="card-body card-bg-secondary">
                    <div v-if="detailLoading" class="service-catalog-view__state service-catalog-view__state--inline">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin fill-text-color service-catalog-view__state-icon">
                            <path d="M12 10a2 2 0 0 1-3.41 1.41A2 2 0 0 1 10 8V0a9.97 9.97 0 0 1 10 10h-8zm7.9 1.41A10 10 0 1 1 8.59.1v2.03a8 8 0 1 0 9.29 9.29h2.02zm-4.07 0a6 6 0 1 1-7.25-7.25v2.1a3.99 3.99 0 0 0-1.4 6.57 4 4 0 0 0 6.56-1.42h2.1z"></path>
                        </svg>
                        <p class="service-catalog-view__state-copy">Loading detail...</p>
                    </div>

                    <div v-else-if="detailError" class="service-catalog-view__state service-catalog-view__state--inline service-catalog-view__state--error">
                        <strong>Detail unavailable</strong>
                        <p class="service-catalog-view__state-copy">{{ detailError }}</p>
                    </div>

                    <div v-else-if="detail" class="service-catalog-view__detail-grid">
                        <section class="service-catalog-view__detail-section">
                            <h6>Summary</h6>
                            <dl>
                                <template v-for="row in detailRows" :key="row.key">
                                    <dt>{{ row.label }}</dt>
                                    <dd>
                                        <code v-if="row.mono">{{ row.value }}</code>
                                        <span v-else>{{ row.value }}</span>
                                    </dd>
                                </template>
                            </dl>
                        </section>

                        <section v-if="detailType === 'calls'" class="service-catalog-view__detail-section">
                            <h6>References</h6>
                            <div class="service-catalog-view__reference-list">
                                <div v-if="detail.caller_link" class="service-catalog-view__reference">
                                    <strong>Caller</strong>
                                    <span>{{ detail.caller_link.namespace || '-' }}</span>
                                    <router-link v-if="referenceRoute(detail.caller_link)" :to="referenceRoute(detail.caller_link)">
                                        {{ referenceLabel(detail.caller_link) }}
                                    </router-link>
                                    <code v-else>{{ referenceLabel(detail.caller_link) }}</code>
                                </div>

                                <div v-if="detail.linked_run_ref" class="service-catalog-view__reference">
                                    <strong>Target Run</strong>
                                    <span>{{ detail.linked_run_ref.namespace || '-' }}</span>
                                    <router-link v-if="referenceRoute(detail.linked_run_ref)" :to="referenceRoute(detail.linked_run_ref)">
                                        {{ referenceLabel(detail.linked_run_ref) }}
                                    </router-link>
                                    <code v-else>{{ referenceLabel(detail.linked_run_ref) }}</code>
                                </div>

                                <div v-if="detail.linked_update_ref" class="service-catalog-view__reference">
                                    <strong>Target Update</strong>
                                    <span>{{ detail.linked_update_ref.namespace || '-' }}</span>
                                    <router-link v-if="referenceRoute(detail.linked_update_ref)" :to="referenceRoute(detail.linked_update_ref)">
                                        {{ updateReferenceLabel(detail.linked_update_ref) }}
                                    </router-link>
                                    <code v-else>{{ updateReferenceLabel(detail.linked_update_ref) }}</code>
                                </div>
                            </div>
                        </section>

                        <section v-if="detail.endpoint || detail.service || nestedServices.length || nestedOperations.length" class="service-catalog-view__detail-section service-catalog-view__detail-section--wide">
                            <h6>Catalog Links</h6>
                            <div class="service-catalog-view__nested-grid">
                                <div v-if="detail.endpoint" class="service-catalog-view__nested-row">
                                    <strong>Endpoint</strong>
                                    <span>{{ detail.endpoint.endpoint_name || '-' }}</span>
                                    <code>{{ shortId(detail.endpoint.id) }}</code>
                                </div>

                                <div v-if="detail.service" class="service-catalog-view__nested-row">
                                    <strong>Service</strong>
                                    <span>{{ detail.service.service_name || '-' }}</span>
                                    <code>{{ shortId(detail.service.id) }}</code>
                                </div>

                                <div v-for="service in nestedServices" :key="'service-' + service.id" class="service-catalog-view__nested-row">
                                    <strong>Service</strong>
                                    <span>{{ service.service_name || '-' }}</span>
                                    <code>{{ shortId(service.id) }}</code>
                                </div>

                                <div v-for="operation in nestedOperations" :key="'operation-' + operation.id" class="service-catalog-view__nested-row">
                                    <strong>Operation</strong>
                                    <span>{{ operation.operation_name || '-' }}</span>
                                    <code>{{ shortId(operation.id) }}</code>
                                </div>
                            </div>
                        </section>

                        <section v-if="hasObjectEntries(detail.boundary_policy) || hasObjectEntries(detail.metadata) || hasObjectEntries(detail.retry_policy)" class="service-catalog-view__detail-section service-catalog-view__detail-section--wide">
                            <h6>Policy Snapshot</h6>
                            <pre v-if="hasObjectEntries(detail.boundary_policy)">{{ prettyJson(detail.boundary_policy) }}</pre>
                            <pre v-if="hasObjectEntries(detail.retry_policy)">{{ prettyJson(detail.retry_policy) }}</pre>
                            <pre v-if="hasObjectEntries(detail.metadata)">{{ prettyJson(detail.metadata) }}</pre>
                        </section>
                    </div>
                </div>
            </article>
        </div>
    </div>
</template>

<script>
import axios from 'axios';

export default {
    name: 'ServiceCatalogView',

    data() {
        return {
            tabs: [
                {key: 'calls', label: 'Calls', panelTitle: 'Service-call history', panelSubtitle: 'Durable calls visible to this namespace.', emptyLabel: 'service calls'},
                {key: 'endpoints', label: 'Endpoints', panelTitle: 'Service endpoints', panelSubtitle: 'Endpoint registry rows owned by this namespace.', emptyLabel: 'service endpoints'},
                {key: 'services', label: 'Services', panelTitle: 'Services', panelSubtitle: 'Service registry rows owned by this namespace.', emptyLabel: 'services'},
                {key: 'operations', label: 'Operations', panelTitle: 'Operations', panelSubtitle: 'Callable operations owned by this namespace.', emptyLabel: 'operations'},
            ],
            callPresets: [
                {key: 'relevant', label: 'Relevant', filters: {scope: 'relevant', status: '', status_bucket: '', outcome: '', outcome_bucket: ''}},
                {key: 'open', label: 'Open', filters: {scope: 'relevant', status: '', status_bucket: 'open', outcome: '', outcome_bucket: ''}},
                {key: 'failed', label: 'Failed', filters: {scope: 'relevant', status: '', status_bucket: 'failed', outcome: '', outcome_bucket: ''}},
                {key: 'policy', label: 'Policy', filters: {scope: 'relevant', status: '', status_bucket: '', outcome: '', outcome_bucket: 'policy'}},
                {key: 'caller', label: 'Caller', filters: {scope: 'caller', status: '', status_bucket: '', outcome: '', outcome_bucket: ''}},
                {key: 'target', label: 'Target', filters: {scope: 'target', status: '', status_bucket: '', outcome: '', outcome_bucket: ''}},
            ],
            activeTab: 'calls',
            loading: true,
            error: null,
            rows: [],
            pagination: null,
            currentPage: 1,
            detail: null,
            detailType: null,
            detailLoading: false,
            detailError: null,
            statusOptions: ['pending', 'accepted', 'started', 'completed', 'failed', 'cancelled'],
            outcomeOptions: [
                'accepted',
                'completed',
                'cancelled',
                'timed_out',
                'rejected_not_found',
                'rejected_forbidden',
                'rejected_throttled',
                'rejected_concurrency_limited',
                'rejected_circuit_open',
                'degraded',
                'handler_failed',
            ],
            statusBuckets: {
                open: ['pending', 'accepted', 'started'],
                completed: ['completed'],
                failed: ['failed'],
                cancelled: ['cancelled'],
            },
            outcomeBuckets: {
                open: ['accepted'],
                completed: ['completed', 'degraded'],
                failed: ['timed_out', 'handler_failed'],
                cancelled: ['cancelled'],
                policy: [
                    'rejected_not_found',
                    'rejected_forbidden',
                    'rejected_throttled',
                    'rejected_concurrency_limited',
                    'rejected_circuit_open',
                ],
            },
            callFilters: {
                scope: 'relevant',
                status: '',
                status_bucket: '',
                outcome: '',
                outcome_bucket: '',
            },
        };
    },

    computed: {
        activeTabDefinition() {
            return this.tabs.find((tab) => tab.key === this.activeTab) || this.tabs[0];
        },

        totalRows() {
            return this.pagination?.total || this.rows.length;
        },

        openCallCount() {
            return this.rows.filter((row) => row.status_bucket === 'open').length;
        },

        policyOutcomeCount() {
            return this.rows.filter((row) => row.outcome_bucket === 'policy' || row.is_policy_outcome === true).length;
        },

        terminalCallCount() {
            return this.rows.filter((row) => row.is_terminal === true).length;
        },

        statusBucketOptions() {
            return Object.keys(this.statusBuckets);
        },

        outcomeBucketOptions() {
            return Object.keys(this.outcomeBuckets);
        },

        activePresetKey() {
            const match = this.callPresets.find((preset) =>
                Object.keys(this.callFilters).every((key) => this.callFilters[key] === preset.filters[key])
            );

            return match ? match.key : null;
        },

        activeFilterLabel() {
            if (this.activeTab !== 'calls') {
                return 'namespace catalog';
            }

            const labels = [this.callFilters.scope];

            if (this.callFilters.status) {
                labels.push(this.callFilters.status);
            } else if (this.callFilters.status_bucket) {
                labels.push(this.callFilters.status_bucket + ' status');
            }

            if (this.callFilters.outcome) {
                labels.push(this.callFilters.outcome);
            } else if (this.callFilters.outcome_bucket) {
                labels.push(this.callFilters.outcome_bucket + ' outcome');
            }

            return labels.map((label) => this.formatLabel(label)).join(' / ');
        },

        detailTitle() {
            if (this.detailLoading) {
                return 'Loading detail';
            }

            if (!this.detail) {
                return 'Service detail';
            }

            if (this.detailType === 'endpoints') {
                return this.detail.endpoint_name || 'Endpoint detail';
            }

            if (this.detailType === 'services') {
                return this.detail.service_name || 'Service detail';
            }

            if (this.detailType === 'operations') {
                return this.detail.operation_name || 'Operation detail';
            }

            return this.detail.operation_name || 'Service-call detail';
        },

        detailRows() {
            if (!this.detail) {
                return [];
            }

            const rows = [
                {key: 'namespace', label: 'Namespace', value: this.detail.namespace || '-'},
            ];

            if (this.detailType === 'endpoints') {
                rows.push(
                    {key: 'endpoint_name', label: 'Endpoint', value: this.detail.endpoint_name || '-'},
                    {key: 'description', label: 'Description', value: this.detail.description || '-'},
                );
            } else if (this.detailType === 'services') {
                rows.push(
                    {key: 'service_name', label: 'Service', value: this.detail.service_name || '-'},
                    {key: 'endpoint_id', label: 'Endpoint ID', value: this.detail.workflow_service_endpoint_id || '-', mono: true},
                    {key: 'description', label: 'Description', value: this.detail.description || '-'},
                );
            } else if (this.detailType === 'operations') {
                rows.push(
                    {key: 'operation_name', label: 'Operation', value: this.detail.operation_name || '-'},
                    {key: 'mode', label: 'Mode', value: this.formatLabel(this.detail.operation_mode)},
                    {key: 'binding', label: 'Binding', value: this.formatLabel(this.detail.handler_binding_kind)},
                    {key: 'target', label: 'Target Reference', value: this.detail.handler_target_reference || '-', mono: true},
                );
            } else {
                rows.push(
                    {key: 'endpoint', label: 'Endpoint', value: this.detail.endpoint_name || '-'},
                    {key: 'service', label: 'Service', value: this.detail.service_name || '-'},
                    {key: 'operation', label: 'Operation', value: this.detail.operation_name || '-'},
                    {key: 'caller_namespace', label: 'Caller Namespace', value: this.detail.caller_namespace || '-'},
                    {key: 'target_namespace', label: 'Target Namespace', value: this.detail.target_namespace || '-'},
                    {key: 'status', label: 'Status', value: this.formatLabel(this.detail.status)},
                    {key: 'outcome', label: 'Outcome', value: this.formatLabel(this.detail.outcome)},
                    {key: 'failure_message', label: 'Failure Message', value: this.detail.failure_message || '-'},
                    {key: 'idempotency_key', label: 'Idempotency Key', value: this.detail.idempotency_key || '-', mono: true},
                );
            }

            rows.push(
                {key: 'created_at', label: 'Created', value: this.timestamp(this.detail.created_at)},
                {key: 'updated_at', label: 'Updated', value: this.timestamp(this.detail.updated_at)},
            );

            return rows;
        },

        nestedServices() {
            return Array.isArray(this.detail?.services) ? this.detail.services : [];
        },

        nestedOperations() {
            return Array.isArray(this.detail?.operations) ? this.detail.operations : [];
        },

        visiblePages() {
            if (!this.pagination) return [];

            const total = this.pagination.last_page;
            const current = this.pagination.current_page;
            const delta = 2;
            const range = [];
            const rangeWithDots = [];

            for (let page = Math.max(2, current - delta); page <= Math.min(total - 1, current + delta); page++) {
                range.push(page);
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
    },

    watch: {
        '$route.query': {
            handler() {
                this.applyRouteState();
            },
            deep: true,
        },
    },

    mounted() {
        this.applyRouteState();
    },

    methods: {
        applyRouteState() {
            const state = this.routeState();
            const tabChanged = state.tab !== this.activeTab;
            const filtersChanged = JSON.stringify(state.filters) !== JSON.stringify(this.callFilters);
            const pageChanged = state.page !== this.currentPage;

            this.activeTab = state.tab;
            this.callFilters = state.filters;
            this.currentPage = state.page;

            if (tabChanged || filtersChanged || pageChanged) {
                this.closeDetail();
            }

            this.loadData(state.page);
        },

        setTab(tab) {
            if (tab === this.activeTab) {
                return;
            }

            this.activeTab = tab;
            this.currentPage = 1;
            this.closeDetail();
            this.syncRouteQuery(1, 'push');
        },

        refresh() {
            this.loadData(this.currentPage);
        },

        reloadFromFirstPage() {
            this.currentPage = 1;
            this.closeDetail();
            this.syncRouteQuery(1);
        },

        onSpecificStatusChanged() {
            if (this.callFilters.status) {
                this.callFilters.status_bucket = '';
            }

            this.reloadFromFirstPage();
        },

        onSpecificOutcomeChanged() {
            if (this.callFilters.outcome) {
                this.callFilters.outcome_bucket = '';
            }

            this.reloadFromFirstPage();
        },

        applyCallPreset(preset) {
            this.callFilters = {
                ...this.callFilters,
                ...preset.filters,
            };
            this.reloadFromFirstPage();
        },

        async loadData(page = 1) {
            this.loading = true;
            this.error = null;
            this.currentPage = page;

            try {
                const response = await axios.get(this.listEndpoint(), {
                    params: this.listParams(page),
                });

                this.rows = response.data.data || [];
                this.pagination = {
                    current_page: response.data.current_page,
                    last_page: response.data.last_page,
                    per_page: response.data.per_page,
                    total: response.data.total,
                };
                this.applyMeta(response.data || {});
            } catch (error) {
                this.rows = [];
                this.pagination = null;
                this.error = error.response?.data?.message || error.response?.data?.error || error.message || 'Failed to load services';
            } finally {
                this.loading = false;
            }
        },

        listEndpoint() {
            return this.waterlineBasePath() + '/api/v2/services/' + this.activeTab;
        },

        listParams(page) {
            const params = {page};

            if (this.activeTab !== 'calls') {
                return params;
            }

            Object.entries(this.callFilters).forEach(([key, value]) => {
                if (value) {
                    params[key] = value;
                }
            });

            return params;
        },

        applyMeta(payload) {
            if (Array.isArray(payload.statuses)) {
                this.statusOptions = payload.statuses;
            }

            if (Array.isArray(payload.outcomes)) {
                this.outcomeOptions = payload.outcomes;
            }

            if (payload.status_buckets && typeof payload.status_buckets === 'object') {
                this.statusBuckets = payload.status_buckets;
            }

            if (payload.outcome_buckets && typeof payload.outcome_buckets === 'object') {
                this.outcomeBuckets = payload.outcome_buckets;
            }
        },

        async openDetail(type, row) {
            this.detailType = type;
            this.detail = null;
            this.detailError = null;
            this.detailLoading = true;

            try {
                const response = await axios.get(this.waterlineBasePath() + '/api/v2/services/' + type + '/' + encodeURIComponent(row.id));
                this.detail = response.data || {};
            } catch (error) {
                this.detailError = error.response?.data?.error || error.response?.data?.message || error.message || 'Failed to load detail';
            } finally {
                this.detailLoading = false;
            }
        },

        closeDetail() {
            this.detail = null;
            this.detailType = null;
            this.detailError = null;
            this.detailLoading = false;
        },

        goToPage(page) {
            if (!this.pagination || page < 1 || page > this.pagination.last_page || page === this.pagination.current_page) {
                return;
            }

            this.closeDetail();
            this.syncRouteQuery(page);
        },

        routeState() {
            const query = this.$route.query || {};
            const requested = typeof query.tab === 'string' ? query.tab : 'calls';
            const tab = this.tabs.some((candidate) => candidate.key === requested) ? requested : 'calls';
            const filters = {
                scope: this.parseScopeQuery(query.scope),
                status: this.stringQuery(query.status),
                status_bucket: this.stringQuery(query.status_bucket || query.bucket),
                outcome: this.stringQuery(query.outcome),
                outcome_bucket: this.stringQuery(query.outcome_bucket),
            };

            if (filters.status) {
                filters.status_bucket = '';
            }

            if (filters.outcome) {
                filters.outcome_bucket = '';
            }

            return {
                tab,
                filters,
                page: this.parsePageQuery(query.page),
            };
        },

        syncRouteQuery(page = 1, mode = 'replace') {
            const query = {
                ...this.$route.query,
                tab: this.activeTab,
            };

            delete query.page;
            delete query.scope;
            delete query.status;
            delete query.status_bucket;
            delete query.bucket;
            delete query.outcome;
            delete query.outcome_bucket;

            if (page > 1) {
                query.page = String(page);
            }

            if (this.activeTab === 'calls') {
                Object.entries(this.callFilters).forEach(([key, value]) => {
                    if (value) {
                        query[key] = value;
                    }
                });
            }

            const route = {
                path: this.$route.path,
                query,
            };

            const navigate = mode === 'push' ? this.$router.push : this.$router.replace;
            navigate.call(this.$router, route)
                .catch(() => {
                    this.loadData(page);
                });
        },

        parseScopeQuery(value) {
            const scope = typeof value === 'string' ? value : 'relevant';

            return ['relevant', 'owned', 'caller', 'target'].includes(scope) ? scope : 'relevant';
        },

        parsePageQuery(value) {
            const page = Number.parseInt(value, 10);

            return Number.isFinite(page) && page > 0 ? page : 1;
        },

        stringQuery(value) {
            return typeof value === 'string' ? value.trim() : '';
        },

        rowClass(row) {
            if (this.activeTab !== 'calls') {
                return '';
            }

            if (row.outcome_bucket === 'policy' || row.is_policy_outcome === true) {
                return 'service-catalog-view__row--warning';
            }

            if (row.status_bucket === 'failed') {
                return 'service-catalog-view__row--danger';
            }

            return '';
        },

        statusToneClass(bucket) {
            return {
                'service-catalog-view__pill--success': bucket === 'completed',
                'service-catalog-view__pill--danger': bucket === 'failed',
                'service-catalog-view__pill--warning': bucket === 'cancelled',
                'service-catalog-view__pill--info': bucket === 'open',
            };
        },

        outcomeToneClass(bucket) {
            return {
                'service-catalog-view__pill--success': bucket === 'completed',
                'service-catalog-view__pill--danger': bucket === 'failed',
                'service-catalog-view__pill--warning': bucket === 'cancelled' || bucket === 'policy',
                'service-catalog-view__pill--info': bucket === 'open',
            };
        },

        referenceRoute(ref) {
            if (!ref || ref.in_observer_namespace !== true || !ref.workflow_instance_id) {
                return null;
            }

            if (ref.workflow_run_id) {
                return {
                    name: 'flow-detail-run',
                    params: {
                        instanceId: ref.workflow_instance_id,
                        runId: ref.workflow_run_id,
                    },
                };
            }

            return {
                name: 'flow-detail',
                params: {
                    instanceId: ref.workflow_instance_id,
                },
            };
        },

        referenceLabel(ref) {
            if (!ref) {
                return '-';
            }

            return [
                ref.workflow_instance_id ? 'instance ' + ref.workflow_instance_id : null,
                ref.workflow_run_id ? 'run ' + ref.workflow_run_id : null,
            ].filter(Boolean).join(' / ') || '-';
        },

        updateReferenceLabel(ref) {
            if (!ref) {
                return '-';
            }

            return [
                ref.workflow_update_id ? 'update ' + ref.workflow_update_id : null,
                ref.workflow_run_id ? 'run ' + ref.workflow_run_id : null,
            ].filter(Boolean).join(' / ') || '-';
        },

        shortId(value) {
            if (!value) {
                return '-';
            }

            const text = String(value);
            return text.length > 14 ? text.slice(0, 8) + '...' + text.slice(-4) : text;
        },

        timestamp(value) {
            return typeof value === 'string' && value.length > 0
                ? value.replace('T', ' ').replace('Z', '')
                : '-';
        },

        formatLabel(value) {
            if (!value) {
                return '-';
            }

            return String(value)
                .replace(/_/g, ' ')
                .replace(/\b\w/g, (letter) => letter.toUpperCase());
        },

        prettyJson(value) {
            return JSON.stringify(value || {}, null, 2);
        },

        hasObjectEntries(value) {
            return value && typeof value === 'object' && Object.keys(value).length > 0;
        },

        waterlineBasePath() {
            return typeof Waterline !== 'undefined' && Waterline.basePath
                ? Waterline.basePath
                : '';
        },
    },
};
</script>

<style scoped>
.service-catalog-view {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

.service-catalog-view__hero {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
}

.service-catalog-view__eyebrow {
    color: var(--wl-text-soft);
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.75rem;
    letter-spacing: 0.08em;
    margin-bottom: 0.35rem;
    text-transform: uppercase;
}

.service-catalog-view__title {
    color: var(--wl-text);
    font-size: 2rem;
    font-weight: 650;
    letter-spacing: 0;
    line-height: 1.15;
    margin: 0;
}

.service-catalog-view__subtitle {
    color: var(--wl-text-muted);
    margin: 0.45rem 0 0;
    max-width: 42rem;
}

.service-catalog-view__actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 0.5rem;
}

.service-catalog-view__button-icon {
    height: 0.9rem;
    margin-right: 0.35rem;
    width: 0.9rem;
}

.service-catalog-view__filters .card-body {
    padding: 1rem;
}

.service-catalog-view__filter-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 0.75rem;
}

.service-catalog-view__preset-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 0.9rem;
}

.service-catalog-view__filter-grid label {
    color: var(--wl-text-soft);
    display: flex;
    flex-direction: column;
    font-size: 0.75rem;
    gap: 0.35rem;
    margin: 0;
    text-transform: uppercase;
}

.service-catalog-view__state {
    align-items: center;
    display: flex;
    justify-content: center;
    min-height: 10rem;
    padding: 2rem;
    text-align: center;
}

.service-catalog-view__state--inline {
    min-height: 6rem;
    padding: 1rem;
}

.service-catalog-view__state--error {
    color: var(--wl-danger);
    flex-direction: column;
}

.service-catalog-view__state-icon {
    height: 1rem;
    margin-right: 0.5rem;
    width: 1rem;
}

.service-catalog-view__state-copy {
    color: var(--wl-text-muted);
    margin: 0;
}

.service-catalog-view__content {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

.service-catalog-view__summary-grid {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.service-catalog-view__summary-card .card-body {
    padding: 1rem;
}

.service-catalog-view__summary-label {
    color: var(--wl-text-soft);
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.72rem;
    text-transform: uppercase;
}

.service-catalog-view__summary-value {
    color: var(--wl-text);
    font-size: 1.65rem;
    font-weight: 650;
    line-height: 1.2;
    margin-top: 0.35rem;
}

.service-catalog-view__summary-value.is-warning {
    color: var(--wl-warning);
}

.service-catalog-view__summary-meta,
.service-catalog-view__cell-meta {
    color: var(--wl-text-soft);
    font-size: 0.82rem;
    margin-top: 0.2rem;
}

.service-catalog-view__cell-main {
    font-weight: 600;
}

.service-catalog-view__pill {
    border: 1px solid var(--wl-border);
    border-radius: 999px;
    display: inline-flex;
    font-size: 0.75rem;
    font-weight: 600;
    line-height: 1;
    padding: 0.35rem 0.55rem;
    white-space: nowrap;
}

.service-catalog-view__pill--muted {
    color: var(--wl-text-soft);
}

.service-catalog-view__pill--success {
    border-color: rgba(40, 167, 69, 0.42);
    color: var(--wl-success);
}

.service-catalog-view__pill--danger {
    border-color: rgba(220, 53, 69, 0.42);
    color: var(--wl-danger);
}

.service-catalog-view__pill--warning {
    border-color: rgba(255, 193, 7, 0.42);
    color: var(--wl-warning);
}

.service-catalog-view__pill--info {
    border-color: rgba(23, 162, 184, 0.42);
    color: #17a2b8;
}

.service-catalog-view__row--warning td {
    background: rgba(255, 193, 7, 0.05);
}

.service-catalog-view__row--danger td {
    background: rgba(220, 53, 69, 0.05);
}

.service-catalog-view__empty-state {
    padding: 3rem 1rem;
    text-align: center;
}

.service-catalog-view__pagination {
    align-items: center;
    display: flex;
    justify-content: space-between;
    gap: 1rem;
}

.service-catalog-view__pagination-pages {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
    justify-content: center;
}

.service-catalog-view__pagination-ellipsis {
    align-items: center;
    color: var(--wl-text-soft);
    display: inline-flex;
    min-width: 2rem;
    justify-content: center;
}

.service-catalog-view__detail-grid {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.service-catalog-view__detail-section {
    border: 1px solid var(--wl-border);
    border-radius: var(--wl-radius-md);
    padding: 1rem;
}

.service-catalog-view__detail-section--wide {
    grid-column: 1 / -1;
}

.service-catalog-view__detail-section h6 {
    color: var(--wl-text);
    font-size: 0.9rem;
    font-weight: 650;
    margin-bottom: 0.8rem;
}

.service-catalog-view__detail-section dl {
    display: grid;
    gap: 0.45rem 1rem;
    grid-template-columns: 10rem minmax(0, 1fr);
    margin: 0;
}

.service-catalog-view__detail-section dt {
    color: var(--wl-text-soft);
    font-weight: 600;
}

.service-catalog-view__detail-section dd {
    margin: 0;
    min-width: 0;
    overflow-wrap: anywhere;
}

.service-catalog-view__detail-section pre {
    background: rgba(0, 0, 0, 0.18);
    border: 1px solid var(--wl-border);
    border-radius: var(--wl-radius-md);
    color: var(--wl-text);
    margin: 0 0 0.75rem;
    padding: 0.75rem;
    white-space: pre-wrap;
}

.service-catalog-view__reference-list,
.service-catalog-view__nested-grid {
    display: grid;
    gap: 0.65rem;
}

.service-catalog-view__reference,
.service-catalog-view__nested-row {
    align-items: center;
    display: grid;
    gap: 0.75rem;
    grid-template-columns: 8rem 1fr minmax(0, 2fr);
}

.service-catalog-view__reference strong,
.service-catalog-view__nested-row strong {
    color: var(--wl-text-soft);
}

@media (max-width: 1100px) {
    .service-catalog-view__filter-grid,
    .service-catalog-view__summary-grid,
    .service-catalog-view__detail-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 768px) {
    .service-catalog-view__hero,
    .service-catalog-view__pagination {
        align-items: stretch;
        flex-direction: column;
    }

    .service-catalog-view__actions {
        justify-content: flex-start;
    }

    .service-catalog-view__filter-grid,
    .service-catalog-view__summary-grid,
    .service-catalog-view__detail-grid {
        grid-template-columns: 1fr;
    }

    .service-catalog-view__detail-section dl,
    .service-catalog-view__reference,
    .service-catalog-view__nested-row {
        grid-template-columns: 1fr;
    }
}
</style>
