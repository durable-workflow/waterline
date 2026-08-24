<script type="text/ecmascript-6">
    import FlowRow from './flow-row';

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
                flows: []
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

                this.$http.get(Waterline.basePath + '/api/flows/' + this.$route.params.type + '?page=' + page)
                    .then(response => {
                        const incomingFirst = response.data.data[0];
                        const currentFirst = this.flows[0];

                        if (!this.$root.autoLoadsNewEntries && refreshing && currentFirst && incomingFirst
                            && incomingFirst.id !== currentFirst.id) {
                            this.hasNewEntries = true;
                        } else {
                            this.flows = response.data.data;

                            this.totalPages = response.data.last_page;
                        }

                        this.ready = true;
                    });
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

            /**
             * Update the page title.
             */
            updatePageTitle() {
                document.title = this.$route.params.type == 'running'
                        ? 'Waterline - Running Flows'
                        : document.title = this.$route.params.type == 'failed'
                        ? 'Waterline - Failed Flows'
                        : 'Waterline - Completed Flows';
            }
        }
    }
</script>

<template>
    <div>
        <div class="card flow-list">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5 v-if="$route.params.type == 'running'">Running Flows</h5>
                <h5 v-if="$route.params.type == 'completed'">Completed Flows</h5>
                <h5 v-if="$route.params.type == 'failed'">Failed Flows</h5>
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

            <div v-if="ready && flows.length > 0" class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                    <thead>
                    <tr>
                        <th>Flow</th>
                        <th v-if="$route.params.type=='running'" class="text-right">Started At</th>
                        <th v-if="$route.params.type=='completed' || $route.params.type=='failed'">Started At</th>
                        <th v-if="$route.params.type=='completed'">Completed At</th>
                        <th v-if="$route.params.type=='failed'">Failed At</th>
                        <th v-if="$route.params.type=='completed' || $route.params.type=='failed'" class="text-right">Duration</th>
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
            </div>

            <div v-if="ready && flows.length" class="p-3 d-flex justify-content-between border-top">
                <button @click="previous" class="btn btn-secondary btn-md" :disabled="page==1">Previous</button>
                <button @click="next" class="btn btn-secondary btn-md" :disabled="page>=totalPages">Next</button>
            </div>
        </div>

    </div>
</template>
