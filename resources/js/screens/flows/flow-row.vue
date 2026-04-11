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
                Run: {{ flow.run_id || flow.id }}
                <span v-if="flow.status === 'continued' || flow.closed_reason === 'continued'" class="badge badge-info ml-1">Continued</span>
                <span v-if="showStatusBadge(flow)" :class="statusBadgeClass(flow)" class="badge ml-1">{{ statusBadgeLabel(flow) }}</span>
                <span v-if="showRepairBadge(flow)"
                      :class="repairBadgeClass(flow)"
                      class="badge ml-1"
                      :title="repairBadgeTitle(flow)">
                    {{ repairBadgeLabel(flow) }}
                </span>
                <span
                    v-if="showCompatibilityEntryBadge(flow)"
                    class="badge badge-info ml-1"
                    :title="compatibilityEntryBadgeTitle(flow)"
                >
                    Compat Entry
                </span>
                <span
                    v-if="showContractBackfillBadge(flow)"
                    :class="contractBackfillBadgeClass(flow)"
                    class="badge ml-1"
                    :title="contractBackfillBadgeTitle(flow)"
                >
                    {{ contractBackfillBadgeLabel(flow) }}
                </span>
            </small>
        </td>

        <td class="table-fit">
            {{ timestamp(flow.started_at || flow.created_at) }}
        </td>

        <td v-if="isTerminalCollection()" class="table-fit">
            {{ timestamp(flow.closed_at || flow.updated_at) }}
        </td>

        <td v-if="isTerminalCollection()" class="table-fit">
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

            isTerminalCollection() {
                return ['completed', 'failed', 'cancelled', 'terminated'].includes(this.$route.params.type)
            },

            showStatusBadge(flow) {
                return ['failed', 'cancelled', 'terminated'].includes(flow.status)
                    && this.$route.params.type !== flow.status
            },

            statusBadgeLabel(flow) {
                return flow.status.charAt(0).toUpperCase() + flow.status.slice(1)
            },

            statusBadgeClass(flow) {
                return {
                    'failed': 'badge-danger',
                    'cancelled': 'badge-warning',
                    'terminated': 'badge-dark',
                }[flow.status] || 'badge-secondary'
            },

            showRepairBadge(flow) {
                const repair = this.repairBlocked(flow)

                return repair ? repair.badge_visible === true : false
            },

            repairBadgeLabel(flow) {
                const repair = this.repairBlocked(flow)

                return repair && repair.label
                    ? repair.label
                    : 'Repair Blocked'
            },

            repairBadgeTitle(flow) {
                const repair = this.repairBlocked(flow)

                return repair && repair.description
                    ? repair.description
                    : 'Repair is currently blocked.'
            },

            repairBadgeClass(flow) {
                const repair = this.repairBlocked(flow)

                return this.badgeClassForTone(repair && repair.tone ? repair.tone : 'secondary')
            },

            repairBlocked(flow) {
                return flow && flow.repair_blocked
                    ? flow.repair_blocked
                    : null
            },

            showCompatibilityEntryBadge(flow) {
                return flow && flow.declared_entry_mode === 'compatibility'
            },

            compatibilityEntryBadgeTitle() {
                return 'This run still depends on the legacy execute() compatibility entry path.'
            },

            showContractBackfillBadge(flow) {
                return flow && flow.declared_contract_backfill_needed === true
            },

            contractBackfillBadgeLabel(flow) {
                return flow && flow.declared_contract_backfill_available === true
                    ? 'Contract Pending'
                    : 'Contract Blocked'
            },

            contractBackfillBadgeTitle(flow) {
                if (flow && flow.declared_contract_backfill_available === true) {
                    return 'This run still needs durable command-contract normalization, and a compatible build can backfill it.'
                }

                return 'This run still needs durable command-contract normalization, but the current build cannot resolve the workflow definition required to finish it.'
            },

            contractBackfillBadgeClass(flow) {
                return flow && flow.declared_contract_backfill_available === true
                    ? 'badge-warning'
                    : 'badge-dark'
            },

            badgeClassForTone(tone) {
                return {
                    'dark': 'badge-dark',
                    'danger': 'badge-danger',
                    'info': 'badge-info',
                    'primary': 'badge-primary',
                    'secondary': 'badge-secondary',
                    'success': 'badge-success',
                    'warning': 'badge-warning',
                }[tone] || 'badge-secondary'
            },
        }
    }
</script>
