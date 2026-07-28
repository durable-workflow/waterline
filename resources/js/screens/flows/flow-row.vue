<template>
    <tr class="flow-row">
        <td v-if="columnEnabled('flow')">
            <div class="flow-row__main">
                <router-link class="flow-row__title" :title="flow.class" :to="detailRoute(flow)">
                    {{ flowBaseName(flow.class) }}
                </router-link>

                <div class="flow-row__meta">
                    <template v-if="flow.engine_source === 'v1'">
                        <span class="flow-row__mono">legacy workflow {{ flow.legacy_id || flow.id }}</span>
                    </template>
                    <template v-else>
                        <span class="flow-row__mono">workflow {{ flow.instance_id || flow.workflow_instance_id || flow.id }}</span>
                        <span class="flow-row__mono">run {{ flow.run_id || flow.id }}</span>
                    </template>
                </div>

                <div class="flow-row__badges">
                    <span v-if="flow.engine_source" class="badge badge-secondary">
                        Engine {{ flow.engine_source.toUpperCase() }}<template v-if="flow.engine_version"> {{ flow.engine_version }}</template>
                    </span>
                    <span v-if="flow.namespace" class="badge badge-light">Namespace {{ flow.namespace }}</span>
                    <span v-if="flow.status === 'continued' || flow.closed_reason === 'continued'" class="badge badge-info">Continued</span>
                    <span v-if="showStatusBadge(flow)" :class="statusBadgeClass(flow)" class="badge">{{ statusBadgeLabel(flow) }}</span>
                    <span v-if="showRepairBadge(flow)"
                          :class="repairBadgeClass(flow)"
                          class="badge"
                          :title="repairBadgeTitle(flow)">
                        {{ repairBadgeLabel(flow) }}
                    </span>
                    <span v-if="showTaskProblemBadge(flow)"
                          :class="taskProblemBadgeClass(flow)"
                          class="badge"
                          :title="taskProblemBadgeTitle(flow)">
                        {{ taskProblemBadgeLabel(flow) }}
                    </span>
                    <span v-if="showCompatibilityEntryBadge(flow)"
                          class="badge badge-info"
                          :title="compatibilityEntryBadgeTitle(flow)">
                        Entry Review
                    </span>
                    <span v-if="showCompatibilitySemanticsBadge(flow)"
                          :class="compatibilitySemanticsBadgeClass(flow)"
                          class="badge"
                          :title="compatibilitySemanticsBadgeTitle(flow)">
                        {{ compatibilitySemanticsBadgeLabel(flow) }}
                    </span>
                    <span v-if="showContractBackfillBadge(flow)"
                          :class="contractBackfillBadgeClass(flow)"
                          class="badge"
                          :title="contractBackfillBadgeTitle(flow)">
                        {{ contractBackfillBadgeLabel(flow) }}
                    </span>
                    <span v-if="showHistoryBudgetBadge(flow)"
                          :class="historyBudgetBadgeClass(flow)"
                          class="badge"
                          :title="historyBudgetBadgeTitle(flow)">
                        {{ historyBudgetBadgeLabel(flow) }}
                    </span>
                </div>
            </div>
        </td>

        <td v-if="columnEnabled('started_at')" class="table-fit flow-row__time-cell">
            <span class="flow-row__mono flow-row__timestamp">{{ timestamp(flow.started_at || flow.created_at) }}</span>
        </td>

        <td v-if="isTerminalCollection() && columnEnabled('closed_at')" class="table-fit flow-row__time-cell">
            <span class="flow-row__mono flow-row__timestamp">{{ timestamp(flow.closed_at || flow.updated_at) }}</span>
        </td>

        <td v-if="isTerminalCollection() && columnEnabled('duration')" class="table-fit flow-row__time-cell text-right">
            <span class="flow-row__mono flow-row__timestamp">{{ duration(flow.started_at || flow.created_at, flow.closed_at || flow.updated_at) }}</span>
        </td>

        <td v-if="columnEnabled('actions')" class="table-fit text-right flow-row__actions-cell">
            <router-link class="btn btn-sm btn-outline-primary flow-row__open" :to="detailRoute(flow)">
                Open
            </router-link>
        </td>
    </tr>
</template>

<script type="text/ecmascript-6">
    export default {
        props: {
            flow: {
                type: Object,
                required: true
            },

            columns: {
                type: Array,
                default() {
                    return ['flow', 'started_at', 'closed_at', 'duration', 'actions']
                }
            }
        },

        methods: {
            duration(start, end) {
                return this.durationBetween(start, end)
            },

            detailRoute(flow) {
                const isLegacyFlow = flow.engine_source === 'v1'
                    || (!flow.engine_source && !flow.instance_id && !flow.workflow_instance_id && !flow.run_id)

                if (isLegacyFlow) {
                    const bucket = this.$route.params.type || flow.status_bucket || 'running'

                    return {
                        name: bucket + '-flows-preview',
                        params: {
                            flowId: flow.id,
                        },
                    }
                }

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

            columnEnabled(column) {
                return this.columns.includes(column)
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
                    failed: 'badge-danger',
                    cancelled: 'badge-warning',
                    terminated: 'badge-dark',
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

            showTaskProblemBadge(flow) {
                const taskProblem = this.taskProblem(flow)

                return taskProblem ? taskProblem.badge_visible === true : false
            },

            taskProblemBadgeLabel(flow) {
                const taskProblem = this.taskProblem(flow)

                return taskProblem && taskProblem.label
                    ? taskProblem.label
                    : 'Task Problem'
            },

            taskProblemBadgeTitle(flow) {
                const taskProblem = this.taskProblem(flow)

                return taskProblem && taskProblem.description
                    ? taskProblem.description
                    : 'This run recorded workflow-task problems.'
            },

            taskProblemBadgeClass(flow) {
                const taskProblem = this.taskProblem(flow)

                return this.badgeClassForTone(taskProblem && taskProblem.tone ? taskProblem.tone : 'secondary')
            },

            taskProblem(flow) {
                return flow && flow.task_problem_badge
                    ? flow.task_problem_badge
                    : null
            },

            showCompatibilityEntryBadge(flow) {
                return flow && flow.declared_entry_mode === 'compatibility'
            },

            compatibilityEntryBadgeTitle() {
                return 'This run was recorded with older entry-contract metadata and should be reviewed before relying on command targets.'
            },

            showCompatibilitySemanticsBadge(flow) {
                const semantics = this.compatibilitySemantics(flow)

                return semantics
                    && semantics.required_marker
                    && semantics.state !== 'claimable_by_this_build'
            },

            compatibilitySemanticsBadgeLabel(flow) {
                const semantics = this.compatibilitySemantics(flow)

                if (semantics && semantics.state === 'supported_elsewhere_in_active_fleet') {
                    return 'Fleet Claimable'
                }

                return 'Compatibility Wait'
            },

            compatibilitySemanticsBadgeTitle(flow) {
                const semantics = this.compatibilitySemantics(flow)

                return semantics && semantics.operator_summary
                    ? semantics.operator_summary
                    : 'Compatibility claimability is not available for this build.'
            },

            compatibilitySemanticsBadgeClass(flow) {
                const semantics = this.compatibilitySemantics(flow)

                return semantics && semantics.state === 'supported_elsewhere_in_active_fleet'
                    ? 'badge-warning'
                    : 'badge-dark'
            },

            compatibilitySemantics(flow) {
                return flow && flow.compatibility_semantics
                    ? flow.compatibility_semantics
                    : null
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

            showHistoryBudgetBadge(flow) {
                const indicator = this.historyBudgetIndicator(flow)

                return indicator ? indicator.badge_visible === true : false
            },

            historyBudgetBadgeLabel(flow) {
                const indicator = this.historyBudgetIndicator(flow)

                return indicator && indicator.label
                    ? indicator.label
                    : 'History Budget'
            },

            historyBudgetBadgeTitle(flow) {
                const indicator = this.historyBudgetIndicator(flow)

                return indicator && indicator.description
                    ? indicator.description
                    : 'This run is approaching a configured history budget.'
            },

            historyBudgetBadgeClass(flow) {
                const indicator = this.historyBudgetIndicator(flow)

                return this.badgeClassForTone(indicator && indicator.tone ? indicator.tone : 'secondary')
            },

            historyBudgetIndicator(flow) {
                return flow && flow.history_budget_indicator
                    ? flow.history_budget_indicator
                    : null
            },

            badgeClassForTone(tone) {
                return {
                    dark: 'badge-dark',
                    danger: 'badge-danger',
                    info: 'badge-info',
                    primary: 'badge-primary',
                    secondary: 'badge-secondary',
                    success: 'badge-success',
                    warning: 'badge-warning',
                }[tone] || 'badge-secondary'
            },
        }
    }
</script>

<style scoped>
.flow-row td {
    vertical-align: top !important;
}

.flow-row__main {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
    min-width: 0;
}

.flow-row__title {
    color: var(--wl-text);
    font-size: 1rem;
    font-weight: 600;
    letter-spacing: -0.02em;
    text-decoration: none;
    overflow-wrap: anywhere;
}

.flow-row__title:hover {
    color: var(--wl-accent);
    text-decoration: none;
}

.flow-row__meta,
.flow-row__badges {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
}

.flow-row__mono {
    color: var(--wl-text-soft);
    font-family: SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', monospace;
    font-size: 0.78rem;
    overflow-wrap: anywhere;
}

.flow-row__time-cell {
    white-space: nowrap;
}

.flow-row__timestamp {
    color: var(--wl-text);
    font-size: 0.8rem;
}

.flow-row__actions-cell {
    white-space: nowrap;
}

.flow-row__open {
    min-width: 4.75rem;
}
</style>
