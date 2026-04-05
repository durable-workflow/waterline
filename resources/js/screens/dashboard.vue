<script type="text/ecmascript-6">
    import moment from 'moment';

    export default {
        components: {},


        /**
         * The component's data.
         */
        data() {
            return {
                stats: {},
                ready: false,
            };
        },


        /**
         * Prepare the component.
         */
        mounted() {
            moment.relativeTimeThreshold('ss', 1);

            document.title = "Waterline - Dashboard";

            this.refreshStatsPeriodically();
        },


        /**
         * Clean after the component is destroyed.
         */
        destroyed() {
            clearTimeout(this.timeout);
        },

        methods: {
            /**
             * Load the general stats.
             */
            loadStats() {
                return this.$http.get(Waterline.basePath + '/api/stats')
                    .then(response => {
                        this.stats = response.data;
                    });
            },

            /**
             * Refresh the stats every period of time.
             */
            refreshStatsPeriodically() {
                Promise.all([
                    this.loadStats(),
                ]).then(() => {
                    this.ready = true;

                    this.timeout = setTimeout(() => {
                        if (this.$root.autoLoadsNewEntries) {
                            this.refreshStatsPeriodically();
                        }
                    }, 5000);
                });
            },

            duration(start, end) {
                return moment(end).from(moment(start), true)
            },

            routeName(flow) {
                const type = flow.status_bucket || (['failed', 'cancelled', 'terminated'].includes(flow.status)
                    ? 'failed'
                    : (flow.status === 'completed' ? 'completed' : 'running'));

                return type + '-flows-preview';
            },

            waitAge(flow) {
                return flow.wait_started_at
                    ? this.duration(flow.wait_started_at, new Date())
                    : '-';
            },

            flowDuration(flow) {
                const start = flow.started_at || flow.created_at;
                const end = flow.closed_at || flow.updated_at;

                if (! start || ! end) {
                    return '-';
                }

                return this.duration(start, end);
            },

            exceptionCount(flow) {
                return flow.exceptions_count ?? flow.exception_count ?? 0;
            },
        }
    }
</script>

<template>
    <div>
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5>Overview</h5>
            </div>

            <div class="card-bg-secondary">
                <div class="d-flex">
                    <div class="w-25 border-right border-bottom">
                        <div class="p-4">
                            <small class="text-uppercase">Flows Per Minute</small>

                            <h4 class="mt-4 mb-0">
                                {{ stats.flows_per_minute ? stats.flows_per_minute.toLocaleString() : 0 }}
                            </h4>
                        </div>
                    </div>

                    <div class="w-25 border-right border-bottom">
                        <div class="p-4">
                            <small class="text-uppercase">Flows Past Hour</small>

                            <h4 class="mt-4 mb-0">
                                {{ stats.flows_past_hour ? stats.flows_past_hour.toLocaleString() : 0 }}
                            </h4>
                        </div>
                    </div>

                    <div class="w-25 border-right border-bottom">
                        <div class="p-4">
                            <small class="text-uppercase">Exceptions Past Hour</small>

                            <h4 class="mt-4 mb-0">
                                {{ stats.exceptions_past_hour ? stats.exceptions_past_hour.toLocaleString() : 0 }}
                            </h4>
                        </div>
                    </div>

                    <div class="w-25 border-bottom">
                        <div class="p-4">
                            <small class="text-uppercase">Failed Flows Past Week</small>

                            <h4 class="mt-4 mb-0">
                                {{ stats.failed_flows_past_week ? stats.failed_flows_past_week.toLocaleString() : 0 }}
                            </h4>
                        </div>
                    </div>
                </div>

                <div class="d-flex">
                    <div class="w-25 border-right">
                        <div class="p-4 mb-0">
                            <small class="text-uppercase">Total Flows</small>

                            <h4 class="mt-4">
                                {{ stats.flows ? stats.flows.toLocaleString() : 0 }}
                            </h4>
                        </div>
                    </div>

                    <div class="w-25 border-right">
                        <div class="p-4 mb-0">
                            <small class="text-uppercase">Max Wait Time</small>

                            <h4 class="mt-4 mb-0">
                                {{ stats.max_wait_time_workflow ? waitAge(stats.max_wait_time_workflow) : '-' }}
                            </h4>

                            <small class="mt-1" v-if="stats.max_wait_time_workflow">
                                (<router-link :title="stats.max_wait_time_workflow.class" :to="{ name: routeName(stats.max_wait_time_workflow), params: { flowId: stats.max_wait_time_workflow.id }}">{{ flowBaseName(stats.max_wait_time_workflow.class) }}</router-link>)
                            </small>
                        </div>
                    </div>

                    <div class="w-25 border-right">
                        <div class="p-4 mb-0">
                            <small class="text-uppercase">Max Duration</small>

                            <h4 class="mt-4 mb-0">
                                {{ stats.max_duration_workflow ? flowDuration(stats.max_duration_workflow) : '-' }}
                            </h4>

                            <small class="mt-1" v-if="stats.max_duration_workflow">
                                (<router-link :title="stats.max_duration_workflow.class" :to="{ name: routeName(stats.max_duration_workflow), params: { flowId: stats.max_duration_workflow.id }}">{{ flowBaseName(stats.max_duration_workflow.class) }}</router-link>)
                            </small>
                        </div>
                    </div>

                    <div class="w-25">
                        <div class="p-4 mb-0">
                            <small class="text-uppercase">Max Exceptions</small>

                            <h4 class="mt-4 mb-0">
                                {{ stats.max_exceptions_workflow ? exceptionCount(stats.max_exceptions_workflow).toLocaleString() : 0 }}
                            </h4>

                            <small class="mt-1" v-if="stats.max_exceptions_workflow">
                                (<router-link :title="stats.max_exceptions_workflow.class" :to="{ name: routeName(stats.max_exceptions_workflow), params: { flowId: stats.max_exceptions_workflow.id }}">{{ flowBaseName(stats.max_exceptions_workflow.class) }}</router-link>)
                            </small>
                        </div>
                    </div>
                </div>

            </div>
        </div>

    </div>
</template>
