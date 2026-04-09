<script type="text/ecmascript-6">
    import FlowRow from './flow-row';
    import Swal from 'sweetalert2';

    export default {
        /**
         * The component's data.
         */
        data() {
            return {
                ready: false,
                loadingNewEntries: false,
                hasNewEntries: false,
                page: 1,
                totalPages: 1,
                flows: [],
                savedViews: [],
                selectedSavedView: null,
                visibilityFilters: null,
            };
        },

        /**
         * Components
         */
        components: {
            FlowRow,
        },

        computed: {
            selectedCustomView() {
                if (!this.selectedSavedView) {
                    return null
                }

                return this.savedViews.find((view) => view.id === this.selectedSavedView && !view.system) || null
            },

            appliedFilterEntries() {
                const applied = this.visibilityFilters && this.visibilityFilters.applied
                    ? this.visibilityFilters.applied
                    : this.currentFilterPayload()
                const entries = []

                this.visibilityFieldNames().forEach((field) => {
                    if (applied[field] === undefined || applied[field] === null || applied[field] === '') {
                        return
                    }

                    entries.push({
                        key: field,
                        label: this.filterFieldLabel(field),
                        value: this.formatAppliedFilterValue(field, applied[field]),
                    })
                })

                Object.entries(applied.labels || {}).forEach(([key, value]) => {
                    entries.push({
                        key: 'label:' + key,
                        label: 'Label',
                        value: key + '=' + value,
                    })
                })

                return entries
            },

            hasActiveFilters() {
                return this.appliedFilterEntries.length > 0 || !!this.selectedSavedView
            },
        },

        /**
         * Prepare the component.
         */
        mounted() {
            this.updatePageTitle();

            this.loadSavedViews();

            this.loadFlows();

            this.refreshFlowsPeriodically();
        },

        /**
         * Clean after the component is destroyed.
         */
        destroyed() {
            clearInterval(this.interval);
        },


        /**
         * Watch these properties for changes.
         */
        watch: {
            '$route'() {
                this.updatePageTitle();

                this.page = 1;

                this.loadSavedViews();

                this.loadFlows();
            }
        },


        methods: {
            /**
             * Load the flows of the given tag.
             */
            loadFlows(page = 1, refreshing = false) {
                if (!refreshing) {
                    this.ready = false;
                }

                this.$http.get(Waterline.basePath + '/api/flows/' + this.$route.params.type + '?' + this.apiQueryString(page))
                    .then(response => {
                        this.visibilityFilters = response.data.visibility_filters || null;

                        const incomingFirst = _.first(response.data.data);
                        const currentFirst = _.first(this.flows);

                        if (!this.$root.autoLoadsNewEntries && refreshing && this.flows.length && incomingFirst
                            && this.flowCursor(incomingFirst) !== this.flowCursor(currentFirst)) {
                            this.hasNewEntries = true;
                        } else {
                            this.flows = response.data.data;

                            this.totalPages = response.data.last_page;
                        }

                        this.ready = true;
                    });
            },

            loadSavedViews() {
                this.selectedSavedView = this.$route.query.view || null;

                return this.$http.get(Waterline.basePath + '/api/saved-views?bucket=' + encodeURIComponent(this.$route.params.type))
                    .then(response => {
                        this.savedViews = response.data.data || [];

                        if (this.selectedSavedView && !this.savedViews.find((view) => view.id === this.selectedSavedView)) {
                            this.selectedSavedView = null;
                        }
                    })
                    .catch(() => {
                        this.savedViews = [];
                        this.selectedSavedView = null;
                    });
            },

            apiQueryString(page) {
                const params = new URLSearchParams();

                params.set('page', page);

                Object.entries(this.$route.query || {}).forEach(([key, value]) => {
                    if (key === 'page' || value === undefined || value === null || value === '') {
                        return;
                    }

                    if (Array.isArray(value)) {
                        value.forEach((entry) => params.append(key, entry));

                        return;
                    }

                    if (typeof value === 'object') {
                        Object.entries(value).forEach(([childKey, childValue]) => {
                            if (childValue !== undefined && childValue !== null && childValue !== '') {
                                params.append(key + '[' + childKey + ']', childValue);
                            }
                        });

                        return;
                    }

                    params.set(key, value);
                });

                return params.toString();
            },

            selectSavedView() {
                const query = {...this.$route.query};

                if (this.selectedSavedView) {
                    query.view = this.selectedSavedView;
                } else {
                    delete query.view;
                }

                this.$router.push({
                    name: this.$route.name,
                    params: this.$route.params,
                    query,
                });
            },

            currentFilterPayload() {
                const filters = {};
                const labels = {};

                this.visibilityFieldNames().forEach((field) => {
                    const value = this.$route.query[field];

                    if (typeof value === 'string' && value.length > 0) {
                        filters[field] = value;
                    } else if (typeof value === 'boolean') {
                        filters[field] = value;
                    }
                });

                Object.entries(this.$route.query || {}).forEach(([key, value]) => {
                    const match = key.match(/^labels?\[([A-Za-z0-9_.:-]{1,64})\]$/);

                    if (match && typeof value === 'string' && value.length > 0) {
                        labels[match[1]] = value;
                    }
                });

                ['label', 'labels'].forEach((key) => {
                    const value = this.$route.query[key];

                    if (!value || typeof value !== 'object' || Array.isArray(value)) {
                        return;
                    }

                    Object.entries(value).forEach(([labelKey, labelValue]) => {
                        if (/^[A-Za-z0-9_.:-]{1,64}$/.test(labelKey) && typeof labelValue === 'string' && labelValue.length > 0) {
                            labels[labelKey] = labelValue;
                        }
                    });
                });

                if (Object.keys(labels).length > 0) {
                    filters.labels = labels;
                }

                return filters;
            },

            visibilityFieldNames() {
                return [
                    'instance_id',
                    'run_id',
                    'workflow_type',
                    'business_key',
                    'compatibility',
                    'queue',
                    'connection',
                    'status',
                    'status_bucket',
                    'closed_reason',
                    'wait_kind',
                    'liveness_state',
                    'archived',
                    'is_terminal',
                ]
            },

            filterFieldLabel(field) {
                return {
                    instance_id: 'Instance',
                    run_id: 'Run',
                    workflow_type: 'Workflow Type',
                    business_key: 'Business Key',
                    compatibility: 'Compatibility',
                    queue: 'Queue',
                    connection: 'Connection',
                    status: 'Status',
                    status_bucket: 'Status Bucket',
                    closed_reason: 'Closed Reason',
                    wait_kind: 'Wait Kind',
                    liveness_state: 'Liveness',
                    archived: 'Archived',
                    is_terminal: 'Terminal',
                }[field] || field
            },

            formatAppliedFilterValue(field, value) {
                if (field === 'archived' || field === 'is_terminal') {
                    return value === true || value === 'true' || value === '1'
                        ? 'Yes'
                        : 'No'
                }

                return value
            },

            filterValue(field, filters) {
                const value = filters[field]

                return value === undefined || value === null
                    ? ''
                    : String(value)
            },

            labelsText(filters) {
                return Object.entries(filters.labels || {})
                    .map(([key, value]) => key + '=' + value)
                    .join('\n')
            },

            escapeHtml(value) {
                return String(value || '')
                    .replace(/&/g, '&amp;')
                    .replace(/"/g, '&quot;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
            },

            filterEditorHtml(filters) {
                const textInput = (id, label, value) => `
                    <label class="d-block text-left mb-1" for="${id}">${label}</label>
                    <input id="${id}" class="swal2-input" value="${this.escapeHtml(value)}">
                `
                const booleanInput = (id, label, value) => `
                    <label class="d-block text-left mb-1" for="${id}">${label}</label>
                    <select id="${id}" class="swal2-input">
                        <option value="" ${value === '' ? 'selected' : ''}>Any</option>
                        <option value="true" ${value === 'true' ? 'selected' : ''}>Yes</option>
                        <option value="false" ${value === 'false' ? 'selected' : ''}>No</option>
                    </select>
                `

                return `
                    <div class="text-left">
                        ${textInput('waterline-filter-instance-id', 'Instance ID', this.filterValue('instance_id', filters))}
                        ${textInput('waterline-filter-run-id', 'Run ID', this.filterValue('run_id', filters))}
                        ${textInput('waterline-filter-workflow-type', 'Workflow Type', this.filterValue('workflow_type', filters))}
                        ${textInput('waterline-filter-business-key', 'Business Key', this.filterValue('business_key', filters))}
                        ${textInput('waterline-filter-compatibility', 'Compatibility', this.filterValue('compatibility', filters))}
                        ${textInput('waterline-filter-connection', 'Connection', this.filterValue('connection', filters))}
                        ${textInput('waterline-filter-queue', 'Queue', this.filterValue('queue', filters))}
                        ${textInput('waterline-filter-status', 'Status', this.filterValue('status', filters))}
                        ${textInput('waterline-filter-status-bucket', 'Status Bucket', this.filterValue('status_bucket', filters))}
                        ${textInput('waterline-filter-closed-reason', 'Closed Reason', this.filterValue('closed_reason', filters))}
                        ${textInput('waterline-filter-wait-kind', 'Wait Kind', this.filterValue('wait_kind', filters))}
                        ${textInput('waterline-filter-liveness-state', 'Liveness State', this.filterValue('liveness_state', filters))}
                        ${booleanInput('waterline-filter-archived', 'Archived', this.filterValue('archived', filters))}
                        ${booleanInput('waterline-filter-is-terminal', 'Terminal', this.filterValue('is_terminal', filters))}
                        <label class="d-block text-left mb-1" for="waterline-filter-labels">Labels</label>
                        <textarea id="waterline-filter-labels" class="swal2-textarea" rows="4" placeholder="tenant=acme&#10;region=us-east">${this.escapeHtml(this.labelsText(filters))}</textarea>
                    </div>
                `
            },

            parseLabelText(value) {
                const labels = {}
                const lines = String(value || '')
                    .split('\n')
                    .map((line) => line.trim())
                    .filter((line) => line.length > 0)

                for (const line of lines) {
                    const separator = line.indexOf('=')

                    if (separator === -1) {
                        throw new Error('Use key=value for label filters.')
                    }

                    const key = line.slice(0, separator).trim()
                    const labelValue = line.slice(separator + 1).trim()

                    if (!/^[A-Za-z0-9_.:-]{1,64}$/.test(key)) {
                        throw new Error('Label keys must match ^[A-Za-z0-9_.:-]{1,64}$.')
                    }

                    if (!labelValue) {
                        throw new Error('Label values cannot be empty.')
                    }

                    labels[key] = labelValue
                }

                return labels
            },

            filteredQueryWithoutVisibilityFields() {
                const query = {...this.$route.query}

                this.visibilityFieldNames().forEach((field) => {
                    delete query[field]
                })

                delete query.label
                delete query.labels

                Object.keys(query).forEach((key) => {
                    if (/^labels?\[[A-Za-z0-9_.:-]{1,64}\]$/.test(key)) {
                        delete query[key]
                    }
                })

                return query
            },

            pushVisibilityFilters(filters, options = {}) {
                const query = this.filteredQueryWithoutVisibilityFields()

                if (options.clearView) {
                    delete query.view
                }

                Object.entries(filters).forEach(([field, value]) => {
                    if (field === 'labels') {
                        Object.entries(value || {}).forEach(([labelKey, labelValue]) => {
                            query[`labels[${labelKey}]`] = labelValue
                        })

                        return
                    }

                    if (value === undefined || value === null || value === '') {
                        return
                    }

                    query[field] = typeof value === 'boolean'
                        ? (value ? 'true' : 'false')
                        : value
                })

                this.$router.push({
                    name: this.$route.name,
                    params: this.$route.params,
                    query,
                })
            },

            async editFilters() {
                const current = this.currentFilterPayload()
                const result = await Swal.fire({
                    title: 'Edit Filters',
                    html: this.filterEditorHtml(current),
                    showCancelButton: true,
                    confirmButtonText: 'Apply Filters',
                    background: '#1c1c1c',
                    preConfirm: () => {
                        try {
                            const filters = {}
                            const stringFields = [
                                'instance_id',
                                'run_id',
                                'workflow_type',
                                'business_key',
                                'compatibility',
                                'connection',
                                'queue',
                                'status',
                                'status_bucket',
                                'closed_reason',
                                'wait_kind',
                                'liveness_state',
                            ]
                            const ids = {
                                instance_id: 'waterline-filter-instance-id',
                                run_id: 'waterline-filter-run-id',
                                workflow_type: 'waterline-filter-workflow-type',
                                business_key: 'waterline-filter-business-key',
                                compatibility: 'waterline-filter-compatibility',
                                connection: 'waterline-filter-connection',
                                queue: 'waterline-filter-queue',
                                status: 'waterline-filter-status',
                                status_bucket: 'waterline-filter-status-bucket',
                                closed_reason: 'waterline-filter-closed-reason',
                                wait_kind: 'waterline-filter-wait-kind',
                                liveness_state: 'waterline-filter-liveness-state',
                            }

                            stringFields.forEach((field) => {
                                const value = document.getElementById(ids[field]).value.trim()

                                if (value) {
                                    filters[field] = value
                                }
                            })

                            ;['archived', 'is_terminal'].forEach((field) => {
                                const value = document.getElementById(`waterline-filter-${field.replace('_', '-')}`).value

                                if (value === 'true') {
                                    filters[field] = true
                                } else if (value === 'false') {
                                    filters[field] = false
                                }
                            })

                            const labels = this.parseLabelText(document.getElementById('waterline-filter-labels').value)

                            if (Object.keys(labels).length > 0) {
                                filters.labels = labels
                            }

                            return filters
                        } catch (error) {
                            Swal.showValidationMessage(error.message)
                        }
                    },
                })

                if (result.isConfirmed) {
                    this.pushVisibilityFilters(result.value || {}, {clearView: false})
                }
            },

            clearFilters() {
                this.pushVisibilityFilters({}, {clearView: true})
            },

            async manageCurrentView() {
                if (!this.selectedCustomView) {
                    return
                }

                const view = this.selectedCustomView
                const result = await Swal.fire({
                    title: 'Manage View',
                    html: `
                        <label class="d-block text-left mb-1" for="waterline-view-name">Name</label>
                        <input id="waterline-view-name" class="swal2-input" value="${this.escapeHtml(view.name)}">
                        <label class="d-flex align-items-center justify-content-start mt-2">
                            <input id="waterline-view-shared" type="checkbox" class="mr-2" ${view.shared ? 'checked' : ''}>
                            <span>Shared within this Waterline scope</span>
                        </label>
                        <small class="d-block text-left text-muted mt-3">Update uses the current route filters.</small>
                    `,
                    showCancelButton: true,
                    showDenyButton: true,
                    confirmButtonText: 'Update View',
                    denyButtonText: 'Delete View',
                    background: '#1c1c1c',
                    preConfirm: () => {
                        const name = document.getElementById('waterline-view-name').value.trim()

                        if (!name) {
                            Swal.showValidationMessage('Enter a view name.')
                            return
                        }

                        return {
                            name,
                            shared: document.getElementById('waterline-view-shared').checked,
                        }
                    },
                })

                if (result.isDenied) {
                    const confirmDelete = await Swal.fire({
                        title: 'Delete view?',
                        text: `Waterline will remove ${view.name}.`,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Delete View',
                        background: '#1c1c1c',
                    })

                    if (!confirmDelete.isConfirmed) {
                        return
                    }

                    try {
                        await this.$http.delete(Waterline.basePath + '/api/saved-views/' + view.id)
                        await this.loadSavedViews()
                        this.selectedSavedView = null
                        this.selectSavedView()
                    } catch (error) {
                        const message = error.response && error.response.data && error.response.data.message
                            ? error.response.data.message
                            : 'Waterline could not delete this view.'

                        Swal.fire({
                            title: 'View not deleted',
                            text: message,
                            icon: 'error',
                            confirmButtonText: 'Okay',
                            background: '#1c1c1c',
                        })
                    }

                    return
                }

                if (!result.isConfirmed) {
                    return
                }

                try {
                    await this.$http.put(Waterline.basePath + '/api/saved-views/' + view.id, {
                        name: result.value.name,
                        bucket: this.$route.params.type,
                        filters: this.currentFilterPayload(),
                        shared: result.value.shared,
                    })

                    await this.loadSavedViews()
                } catch (error) {
                    const message = error.response && error.response.data && error.response.data.message
                        ? error.response.data.message
                        : 'Waterline could not update this view.'

                    Swal.fire({
                        title: 'View not updated',
                        text: message,
                        icon: 'error',
                        confirmButtonText: 'Okay',
                        background: '#1c1c1c',
                    })
                }
            },

            async saveCurrentView() {
                const result = await Swal.fire({
                    title: 'Save view',
                    input: 'text',
                    inputLabel: 'Name',
                    inputPlaceholder: this.flowCollectionLabel() + ' view',
                    showCancelButton: true,
                    confirmButtonText: 'Save view',
                    background: '#1c1c1c',
                    inputValidator: (value) => {
                        if (!value || !value.trim()) {
                            return 'Enter a view name.';
                        }

                        return null;
                    },
                });

                if (!result.isConfirmed) {
                    return;
                }

                try {
                    const response = await this.$http.post(Waterline.basePath + '/api/saved-views', {
                        name: result.value.trim(),
                        bucket: this.$route.params.type,
                        filters: this.currentFilterPayload(),
                        shared: true,
                    });

                    await this.loadSavedViews();

                    this.selectedSavedView = response.data.id;
                    this.selectSavedView();
                } catch (error) {
                    const message = error.response && error.response.data && error.response.data.message
                        ? error.response.data.message
                        : 'Waterline could not save this view.';

                    Swal.fire({
                        title: 'View not saved',
                        text: message,
                        icon: 'error',
                        confirmButtonText: 'Okay',
                        background: '#1c1c1c',
                    });
                }
            },

            loadNewEntries() {
                this.flows = [];

                this.loadFlows(1, false);

                this.hasNewEntries = false;
            },


            /**
             * Refresh the flows every period of time.
             */
            refreshFlowsPeriodically() {
                this.interval = setInterval(() => {
                    if (this.page != 1) {
                        return;
                    }

                    if (this.$root.autoLoadsNewEntries) {
                        this.loadFlows(1, true);
                    }
                }, 3000);
            },


            /**
             * Load the flows for the previous page.
             */
            previous() {
                this.loadFlows(
                    --this.page
                );
                this.hasNewEntries = false;
            },


            /**
             * Load the flows for the next page.
             */
            next() {
                this.loadFlows(
                    ++this.page
                );
                this.hasNewEntries = false;
            },

            flowCursor(flow) {
                if (!flow) {
                    return null;
                }

                return flow.sort_key || flow.id || null
            },

            /**
             * Update the page title.
             */
            updatePageTitle() {
                document.title = 'Waterline - ' + this.flowCollectionLabel() + ' Flows';
            },

            flowCollectionLabel() {
                return {
                    running: 'Running',
                    completed: 'Completed',
                    failed: 'Failed',
                    cancelled: 'Cancelled',
                    terminated: 'Terminated',
                }[this.$route.params.type] || 'Workflow';
            },

            isTerminalCollection() {
                return ['completed', 'failed', 'cancelled', 'terminated'].includes(this.$route.params.type);
            },

            closedAtLabel() {
                return this.$route.params.type === 'completed'
                    ? 'Completed At'
                    : this.flowCollectionLabel() + ' At';
            }
        }
    }
</script>

<template>
    <div>
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5>{{ flowCollectionLabel() }} Flows</h5>

                <div class="d-flex align-items-center flex-wrap justify-content-end">
                    <select v-if="savedViews.length"
                            v-model="selectedSavedView"
                            @change="selectSavedView"
                            class="custom-select custom-select-sm mr-2 mb-2"
                            style="width: 14rem;">
                        <option :value="null">System view</option>
                        <option v-for="view in savedViews" :key="view.id" :value="view.id">
                            {{ view.name }}
                        </option>
                    </select>

                    <button class="btn btn-outline-secondary btn-sm mr-2 mb-2"
                            @click="editFilters">
                        Filters
                    </button>

                    <button v-if="hasActiveFilters"
                            class="btn btn-outline-secondary btn-sm mr-2 mb-2"
                            @click="clearFilters">
                        Clear
                    </button>

                    <button v-if="savedViews.length"
                            class="btn btn-outline-secondary btn-sm mr-2 mb-2"
                            @click="saveCurrentView">
                        Save View
                    </button>

                    <button v-if="selectedCustomView"
                            class="btn btn-outline-secondary btn-sm mb-2"
                            @click="manageCurrentView">
                        Manage View
                    </button>
                </div>
            </div>

            <div v-if="ready && (selectedSavedView || appliedFilterEntries.length)"
                 class="border-bottom px-3 py-2 card-bg-secondary">
                <div class="d-flex flex-wrap align-items-center">
                    <span v-if="visibilityFilters && visibilityFilters.saved_view"
                          class="badge badge-primary mr-2 mb-1">
                        View: {{ visibilityFilters.saved_view.name }}
                    </span>

                    <span v-for="entry in appliedFilterEntries"
                          :key="entry.key"
                          class="badge badge-secondary mr-2 mb-1">
                        {{ entry.label }}: {{ entry.value }}
                    </span>
                </div>
            </div>

            <div v-if="!ready"
                 class="d-flex align-items-center justify-content-center card-bg-secondary p-5 bottom-radius">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin mr-2 fill-text-color">
                    <path
                        d="M12 10a2 2 0 0 1-3.41 1.41A2 2 0 0 1 10 8V0a9.97 9.97 0 0 1 10 10h-8zm7.9 1.41A10 10 0 1 1 8.59.1v2.03a8 8 0 1 0 9.29 9.29h2.02zm-4.07 0a6 6 0 1 1-7.25-7.25v2.1a3.99 3.99 0 0 0-1.4 6.57 4 4 0 0 0 6.56-1.42h2.1z"></path>
                </svg>

                <span>Loading...</span>
            </div>


            <div v-if="ready && flows.length == 0"
                 class="d-flex flex-column align-items-center justify-content-center card-bg-secondary p-5 bottom-radius">
                <span>There aren't any flows.</span>
            </div>

            <table v-if="ready && flows.length > 0" class="table table-hover table-sm mb-0">
                <thead>
                <tr>
                    <th>Flow</th>
                    <th v-if="$route.params.type=='running'" class="text-right">Started At</th>
                    <th v-if="isTerminalCollection()">Started At</th>
                    <th v-if="isTerminalCollection()">{{ closedAtLabel() }}</th>
                    <th v-if="isTerminalCollection()" class="text-right">Duration</th>
                </tr>
                </thead>

                <tbody>
                    <tr v-if="hasNewEntries" key="newEntries" class="dontanimate">
                        <td colspan="100" class="text-center card-bg-secondary py-1">
                            <small><a href="#" v-on:click.prevent="loadNewEntries" v-if="!loadingNewEntries">Load New
                                Entries</a></small>

                            <small v-if="loadingNewEntries">Loading...</small>
                        </td>
                    </tr>

                    <tr v-for="flow in flows" :key="flow.id" :flow="flow" is="flow-row">
                    </tr>
                </tbody>
            </table>

            <div v-if="ready && flows.length" class="p-3 d-flex justify-content-between border-top">
                <button @click="previous" class="btn btn-secondary btn-md" :disabled="page==1">Previous</button>
                <button @click="next" class="btn btn-secondary btn-md" :disabled="page>=totalPages">Next</button>
            </div>
        </div>

    </div>
</template>
