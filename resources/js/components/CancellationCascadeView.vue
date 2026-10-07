<template>
    <section class="card mt-4" v-if="visible" aria-labelledby="cancellationCascadeTitle">
        <div class="card-header"><h5 id="cancellationCascadeTitle">{{ $t("Cancellation cascade") }}</h5></div>
        <div class="card-body">
            <p v-if="!view" class="mb-0">{{ emptyNotice }}</p>
            <p v-else-if="!recognized" class="mb-0">{{ $t("These cancellation details require a newer Waterline version.") }}</p>
            <template v-else>
                <dl class="row mb-2 wl-cancellation-detail">
                    <dt class="col-sm-3">{{ $t("Root request") }}</dt><dd class="col-sm-9 wl-cancellation-value">{{ label(root.root_request_id) }}</dd>
                    <dt class="col-sm-3">{{ $t("Requested") }}</dt><dd class="col-sm-9">{{ label(root.requested_at) }}</dd>
                    <dt class="col-sm-3">{{ $t("Original cleanup deadline") }}</dt><dd class="col-sm-9">{{ label(root.cleanup_deadline_at) }}</dd>
                    <dt class="col-sm-3">{{ $t("Reason") }}</dt><dd class="col-sm-9 wl-cancellation-value">{{ label(root.reason) }}</dd>
                    <dt class="col-sm-3">{{ $t("Requester") }}</dt><dd class="col-sm-9 wl-cancellation-value">{{ label(requester.label || requester.id) }} · {{ label(root.source) }}</dd>
                </dl>
                <div class="alert alert-warning" v-if="view.inspection_complete !== true" role="status">
                    {{ $t(view.truncated === true ? 'Some cancellation evidence is unavailable or outside this view’s limits.' : 'Some cancellation evidence is unavailable.') }}
                    <ul class="mb-0 mt-2" v-if="findings.length">
                        <li v-for="(finding, index) in findings" :key="index" class="wl-cancellation-value">{{ label(finding.message) }} ({{ label(finding.run_id) }})</li>
                    </ul>
                </div>
                <article v-for="run in runs" :key="run.run_id" class="border rounded p-3 mt-3">
                    <h6 class="wl-cancellation-value">
                        {{ label(run.workflow_type) }}
                        <span class="badge badge-secondary ml-2">{{ phase(run.lifecycle) }}</span>
                        <span v-if="run.run_id === view.selected_run_id" class="badge badge-info ml-2">{{ $t("Selected run") }}</span>
                    </h6>
                    <dl class="row mb-1 small wl-cancellation-detail">
                        <dt class="col-sm-3">{{ $t("Workflow / run") }}</dt><dd class="col-sm-9 wl-cancellation-value">{{ label(run.workflow_id) }} / {{ label(run.run_id) }}</dd>
                        <dt class="col-sm-3">{{ $t("Local request / parent") }}</dt><dd class="col-sm-9 wl-cancellation-value">{{ label(object(run.request).request_id) }} / {{ label(object(run.request).parent_request_id) }}</dd>
                        <dt class="col-sm-3">{{ $t("Budget") }}</dt><dd class="col-sm-9">{{ budget(run) }} · {{ label(object(run.request).cleanup_deadline_at) }}</dd>
                        <dt class="col-sm-3">{{ $t("Delivery") }}</dt><dd class="col-sm-9">{{ $t("History") }} {{ label(object(run.delivery).history_event_id) }} {{ $t("· sequence") }} {{ label(object(run.delivery).sequence) }} {{ $t("· span") }} {{ label(object(run.delivery).sequence_span) }} · {{ label(object(run.delivery).call_kind) }}</dd>
                        <dt class="col-sm-3">{{ $t("Cleanup outcome") }}</dt><dd class="col-sm-9">{{ phase(object(run.cleanup).outcome) }} · {{ label(object(run.cleanup).finished_at) }}</dd>
                        <dt class="col-sm-3">{{ $t("Terminal history") }}</dt><dd class="col-sm-9">{{ label(run.terminal_event_type) }} · {{ label(run.terminal_history_event_id) }}</dd>
                    </dl>
                    <div v-for="stop in objects(run.activity_stops)" :key="stop.fence_history_event_id" class="small mt-2 wl-cancellation-value">
                        <strong>{{ label(stop.activity_type) }} ({{ label(stop.execution_mode) }})</strong>
                        · {{ $t(stop.callback_state === 'reported_stopped' ? 'Callback reported stopped' : 'Callback stop unverified') }}
                        <div>{{ $t("Activity") }} {{ label(stop.activity_execution_id) }} {{ $t("· attempt") }} {{ label(stop.activity_attempt_id) }}</div>
                        <div>{{ $t("Fence history") }} {{ label(stop.fence_history_event_id) }} {{ $t("· stop receipt") }} {{ label(stop.stop_history_event_id) }} · {{ label(stop.evidence_source) }}</div>
                        <div v-if="stop.acknowledged_at">{{ $t(stop.received_after_deadline === true ? 'Acknowledged {at} after the original deadline' : 'Acknowledged {at}', { at: label(stop.acknowledged_at) }) }}</div>
                    </div>
                    <div v-for="recovery in objects(run.cleanup_recovery)" :key="recovery.history_event_id" class="small mt-2 wl-cancellation-value">
                        <strong>{{ $t("Cleanup worker recovery") }}</strong> {{ $t("· activity") }} {{ label(recovery.activity_execution_id) }} {{ $t("· history") }} {{ label(recovery.history_event_id) }}
                        <div>{{ label(object(recovery.attempt).original_lease_owner) }} {{ $t("(attempt") }} {{ label(object(recovery.attempt).original_workflow_task_attempt) }}) → {{ label(object(recovery.attempt).lease_owner) }} {{ $t("(attempt") }} {{ label(object(recovery.attempt).workflow_task_attempt) }})</div>
                        <div>{{ $t("Previous callback stop:") }} {{ phase(object(recovery.attempt).callback_stop_state) }} · {{ label(recovery.recorded_at) }}</div>
                    </div>
                    <div v-for="propagation in objects(run.child_propagation)" :key="propagation.history_event_id" class="small mt-2 wl-cancellation-value">
                        <strong>{{ $t("Child propagation") }}</strong> · {{ label(propagation.child_run_id) }} · {{ phase(propagation.policy) }} · {{ label(propagation.request_outcome) }}
                        <div v-if="propagation.rejection_reason">{{ label(propagation.rejection_reason) }}</div>
                        <div>{{ $t("History") }} {{ label(propagation.history_event_id) }} {{ $t("· child terminal history") }} {{ label(propagation.child_terminal_history_event_id) }}</div>
                    </div>
                </article>
                <ul class="small mt-3 mb-0" v-if="edges.length">
                    <li v-for="(edge, index) in edges" :key="index" class="wl-cancellation-value">
                        {{ label(edge.parent_run_id) }} → {{ label(edge.child_run_id) }} · {{ label(edge.kind) }} · {{ label(edge.reference_state) }}
                    </li>
                </ul>
            </template>
        </div>
    </section>
</template>

<script>
import { localizedState } from '../state-labels.mjs'
export default {
    props: { diagnostics: { type: Object, required: true } },
    computed: {
        visible() { return Object.hasOwn(this.diagnostics, 'cancellation_cascade_supported') || Object.hasOwn(this.diagnostics, 'cancellation_cascade') },
        view() { const value = this.diagnostics.cancellation_cascade; return value && typeof value === 'object' && !Array.isArray(value) ? value : null },
        recognized() { return this.view?.schema === 'durable-workflow.cancellation-cascade/v1' },
        root() { return this.object(this.view?.root) },
        requester() { return this.object(this.root.requester) },
        runs() { return this.objects(this.view?.runs) },
        edges() { return this.objects(this.view?.edges) },
        findings() { return this.objects(this.view?.findings) },
        emptyNotice() { return this.$t(this.diagnostics.cancellation_cascade_supported === true ? 'No cooperative cancellation request for this run.' : 'Cancellation details are unavailable with this runtime.') },
    },
    methods: {
        object(value) { return value && typeof value === 'object' && !Array.isArray(value) ? value : {} },
        objects(value) { return Array.isArray(value) ? value.filter(item => item && typeof item === 'object' && !Array.isArray(item)) : [] },
        label(value) { return ['string', 'number', 'boolean'].includes(typeof value) ? String(value) : this.$t('Not recorded') },
        phase(value) { return localizedState(value, this.$t) },
        budget(run) {
            const request = this.object(run.request)
            if (!request.root_request_id || !this.root.root_request_id) return this.$t('Unverified budget')
            if (run.same_root_budget === true) return this.$t('Original root budget')
            return this.$t(request.root_request_id !== this.root.root_request_id ? 'Independent root budget' : 'Conflicting or unverified budget')
        },
    },
}
</script>

<style scoped>
.wl-cancellation-value { overflow-wrap: anywhere; }
@media (max-width: 575.98px) {
    .wl-cancellation-detail > dt,
    .wl-cancellation-detail > dd { flex: 0 0 100%; max-width: 100%; }
    .wl-cancellation-detail > dt { overflow-wrap: normal; word-break: normal; }
}
</style>
