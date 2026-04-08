<template>
    <tr>
        <td>
            <router-link :title="flow.class" :to="detailRoute(flow)">
                {{ flowBaseName(flow.class) }}
            </router-link>

            <br>

            <small class="text-muted">
                Workflow: {{ flow.instance_id || flow.workflow_instance_id || flow.id }}
            </small>

            <br>

            <small class="text-muted">
                Run: {{ flow.run_id || flow.id }} <span v-if="flow.status === 'continued' || flow.closed_reason === 'continued'" class="badge badge-info ml-1">Continued</span>
            </small>
        </td>

        <td class="table-fit">
            {{ timestamp(flow.started_at || flow.created_at) }}
        </td>

        <td v-if="$route.params.type=='completed' || $route.params.type=='failed'" class="table-fit">
            {{ timestamp(flow.closed_at || flow.updated_at) }}
        </td>

        <td v-if="$route.params.type=='completed' || $route.params.type=='failed'" class="table-fit">
            <span>{{ duration(flow.started_at || flow.created_at, flow.closed_at || flow.updated_at) }}</span>
        </td>
    </tr>
</template>

<script type="text/ecmascript-6">
    import phpunserialize from 'phpunserialize'
    import moment from 'moment-timezone';

    export default {
        props: {
            flow: {
                type: Object,
                required: true
            }
        },

        computed: {
            unserialized() {
                try {
                    return phpunserialize(this.flow.arguments);
                }catch(err){
                    //
                }
            },
        },

        methods: {
            duration(start, end) {
                moment.relativeTimeThreshold('ss', 1)
                return moment(end).from(moment(start), true)
            },

            detailRoute(flow) {
                const instanceId = flow.instance_id || flow.workflow_instance_id || flow.id
                const runId = flow.run_id || flow.id

                return {
                    name: 'flow-detail-run',
                    params: {
                        instanceId,
                        runId,
                    },
                }
            },
        }
    }
</script>
