<template>
    <tr>
        <td data-label="Flow">
            <div>
                <router-link :title="flow.class" :to="{ name: $route.params.type+'-flows-preview', params: { flowId: flow.id }}">
                    {{ flowBaseName(flow.class) }}
                </router-link>

                <br>

                <small class="text-muted">
                    ID: {{flow.id}} <span v-if="flow.status === 'continued'" class="badge badge-info ml-1">Continued</span>
                </small>
            </div>
        </td>

        <td class="table-fit" data-label="Started At">
            {{ timestamp(flow.created_at) }}
        </td>

        <td v-if="$route.params.type=='completed' || $route.params.type=='failed'"
            class="table-fit"
            :data-label="$route.params.type == 'completed' ? 'Completed At' : 'Failed At'">
            {{ timestamp(flow.updated_at) }}
        </td>

        <td v-if="$route.params.type=='completed' || $route.params.type=='failed'" class="table-fit" data-label="Duration">
            <span>{{ duration(flow.created_at, flow.updated_at) }}</span>
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
        }
    }
</script>
