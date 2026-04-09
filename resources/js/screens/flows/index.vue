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
                selectedSavedView: null
            };
        },

        /**
         * Components
         */
        components: {
            FlowRow,
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

                ['workflow_type', 'business_key', 'compatibility', 'queue', 'connection'].forEach((field) => {
                    const value = this.$route.query[field];

                    if (typeof value === 'string' && value.length > 0) {
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

                <div class="d-flex align-items-center">
                    <select v-if="savedViews.length"
                            v-model="selectedSavedView"
                            @change="selectSavedView"
                            class="custom-select custom-select-sm mr-2"
                            style="width: 14rem;">
                        <option :value="null">System view</option>
                        <option v-for="view in savedViews" :key="view.id" :value="view.id">
                            {{ view.name }}
                        </option>
                    </select>

                    <button v-if="savedViews.length"
                            class="btn btn-outline-secondary btn-sm"
                            @click="saveCurrentView">
                        Save View
                    </button>
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
