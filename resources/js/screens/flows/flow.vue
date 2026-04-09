<template>
    <div>
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5 v-if="!ready">Flow Preview</h5>
                <h5 v-if="ready">{{ flow.class }}</h5>

                <div class="d-flex align-items-center">
                    <button v-if="ready && canIssueQuery()"
                        class="btn btn-outline-secondary btn-sm mr-2"
                        @click="issueCommand('query')">
                        Query
                    </button>

                    <button v-if="ready && canIssueSignal()"
                        class="btn btn-outline-primary btn-sm mr-2"
                        @click="issueCommand('signal')">
                        Signal
                    </button>

                    <button v-if="ready && canIssueUpdate()"
                        class="btn btn-outline-success btn-sm mr-2"
                        @click="issueCommand('update')">
                        Update
                    </button>

                    <a v-if="ready && historyExportEndpoint()"
                        class="btn btn-outline-secondary btn-sm mr-2"
                        :href="historyExportEndpoint()"
                        target="_blank"
                        rel="noopener">
                        Export History
                    </a>

                    <button v-if="ready && canAction('repair')"
                        class="btn btn-outline-info btn-sm mr-2"
                        @click="issueCommand('repair')">
                        Repair
                    </button>

                    <button v-if="ready && canAction('cancel', flow.can_issue_terminal_commands)"
                        class="btn btn-outline-warning btn-sm mr-2"
                        @click="issueCommand('cancel')">
                        Cancel
                    </button>

                    <button v-if="ready && canAction('terminate', flow.can_issue_terminal_commands)"
                        class="btn btn-outline-danger btn-sm mr-3"
                        @click="issueCommand('terminate')">
                        Terminate
                    </button>

                    <button v-if="ready && canAction('archive')"
                        class="btn btn-outline-secondary btn-sm mr-3"
                        @click="issueCommand('archive')">
                        Archive
                    </button>

                    <a data-toggle="collapse" href="#collapseDetails" role="button">
                        Collapse
                    </a>
                </div>
            </div>

            <div v-if="!ready"
                class="d-flex align-items-center justify-content-center card-bg-secondary p-5 bottom-radius">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin mr-2 fill-text-color">
                    <path
                        d="M12 10a2 2 0 0 1-3.41 1.41A2 2 0 0 1 10 8V0a9.97 9.97 0 0 1 10 10h-8zm7.9 1.41A10 10 0 1 1 8.59.1v2.03a8 8 0 1 0 9.29 9.29h2.02zm-4.07 0a6 6 0 1 1-7.25-7.25v2.1a3.99 3.99 0 0 0-1.4 6.57 4 4 0 0 0 6.56-1.42h2.1z">
                    </path>
                </svg>

                <span>Loading...</span>
            </div>

            <div class="card-body card-bg-secondary collapse show" id="collapseDetails" v-if="ready">
                <div class="row mb-2">
                    <div class="col-md-2"><strong>ID</strong></div>
                    <div class="col">{{ flow.id }}</div>
                </div>

                <div class="row mb-2" v-if="hasDetailValue(flow.instance_id)">
                    <div class="col-md-2"><strong>Instance ID</strong></div>
                    <div class="col">{{ flow.instance_id }}</div>
                </div>

                <div class="row mb-2" v-if="hasDetailValue(flow.run_id)">
                    <div class="col-md-2"><strong>Run ID</strong></div>
                    <div class="col">{{ flow.run_id }}</div>
                </div>

                <div class="row mb-2">
                    <div class="col-md-2"><strong>Status</strong></div>
                    <div class="col">
                        {{ flow.status }}
                        <span v-if="hasDetailValue(flow.status_bucket)"> / {{ flow.status_bucket }}</span>
                    </div>
                </div>

                <div class="row mb-2" v-if="hasDetailValue(flow.read_only_reason)">
                    <div class="col-md-2"><strong>Mode</strong></div>
                    <div class="col">{{ flow.read_only_reason }}</div>
                </div>

                <div class="row mb-2" v-if="hasDetailValue(flow.archived_at)">
                    <div class="col-md-2"><strong>Archived At</strong></div>
                    <div class="col">
                        {{ timestamp(flow.archived_at) }}
                        <span v-if="hasDetailValue(flow.archive_reason)" class="text-muted"> / {{ flow.archive_reason }}</span>
                    </div>
                </div>

                <div class="row mb-2" v-if="actionStateRows().length">
                    <div class="col-md-2"><strong>Actions</strong></div>
                    <div class="col">
                        <div v-for="action in actionStateRows()" :key="action.name">
                            {{ action.label }}: {{ action.allowed ? 'available' : 'blocked' }}
                            <span v-if="hasDetailValue(action.reason)" class="text-muted">({{ actionReasonLabel(action.reason) }})</span>
                        </div>
                    </div>
                </div>

                <div class="row mb-2">
                    <div class="col-md-2"><strong>Started At</strong></div>
                    <div class="col">{{ timestamp(flow.created_at) }}</div>
                </div>

                <div class="row mb-2">
                    <div class="col-md-2"><strong>Closed At</strong></div>
                    <div class="col" v-if="isClosed(flow)">{{ timestamp(flow.closed_at || flow.updated_at) }}</div>
                    <div class="col" v-else>-</div>
                </div>

                <div class="row mb-2">
                    <div class="col-md-2"><strong>Duration</strong></div>
                    <div class="col" v-if="isClosed(flow)">{{ duration(flow.created_at, flow.closed_at || flow.updated_at) }}</div>
                    <div class="col" v-else>-</div>
                </div>

                <div class="row mb-2" v-if="hasDetailValue(flow.connection)">
                    <div class="col-md-2"><strong>Connection</strong></div>
                    <div class="col">{{ flow.connection }}</div>
                </div>

                <div class="row mb-2" v-if="hasDetailValue(flow.queue)">
                    <div class="col-md-2"><strong>Queue</strong></div>
                    <div class="col">{{ flow.queue }}</div>
                </div>

                <div class="row mb-2" v-if="declaredSignalTargets().length">
                    <div class="col-md-2"><strong>Signals</strong></div>
                    <div class="col">
                        <div v-for="target in declaredSignalTargets()" :key="target.name">
                            {{ signalTargetLabel(target) }}
                        </div>
                    </div>
                </div>

                <div class="row mb-2" v-if="declaredQueryTargets().length">
                    <div class="col-md-2"><strong>Queries</strong></div>
                    <div class="col">
                        <div v-for="target in declaredQueryTargets()" :key="target.name">
                            {{ queryTargetLabel(target) }}
                        </div>
                    </div>
                </div>

                <div class="row mb-2" v-if="hasDetailValue(flow.declared_contract_source)">
                    <div class="col-md-2"><strong>Command Contract</strong></div>
                    <div class="col">{{ contractSourceLabel(flow.declared_contract_source) }}</div>
                </div>

                <div class="row mb-2" v-if="declaredUpdateTargets().length">
                    <div class="col-md-2"><strong>Updates</strong></div>
                    <div class="col">
                        <div v-for="target in declaredUpdateTargets()" :key="target.name">
                            {{ updateTargetLabel(target) }}
                        </div>
                    </div>
                </div>

                <div class="row mb-2" v-if="hasDetailValue(flow.declared_contract_source)">
                    <div class="col-md-2"><strong>Contract Source</strong></div>
                    <div class="col">{{ contractSourceLabel(flow.declared_contract_source) }}</div>
                </div>

                <div class="row mb-2" v-if="hasDetailValue(flow.workflow_definition_fingerprint) || hasDetailValue(flow.workflow_definition_current_fingerprint) || flow.workflow_definition_matches_current !== null">
                    <div class="col-md-2"><strong>Definition</strong></div>
                    <div class="col">
                        <div v-if="hasDetailValue(flow.workflow_definition_fingerprint)">
                            Start fingerprint: {{ flow.workflow_definition_fingerprint }}
                        </div>
                        <div class="small text-muted" v-if="hasDetailValue(flow.workflow_definition_current_fingerprint)">
                            Current fingerprint: {{ flow.workflow_definition_current_fingerprint }}
                        </div>
                        <div class="small text-muted" v-if="flow.workflow_definition_matches_current === true">
                            Matches the current loadable workflow definition.
                        </div>
                        <div class="small text-muted" v-else-if="flow.workflow_definition_matches_current === false">
                            Selected run started on a different workflow definition than the current loadable class.
                        </div>
                    </div>
                </div>

                <div class="row mb-2" v-if="hasDetailValue(flow.workflow_determinism_status)">
                    <div class="col-md-2"><strong>Replay Safety</strong></div>
                    <div class="col">
                        <div>{{ workflowDeterminismStatusLabel(flow.workflow_determinism_status, flow.workflow_determinism_source) }}</div>
                        <div class="small text-muted" v-if="hasDetailValue(flow.workflow_determinism_source)">
                            Source: {{ contractSourceLabel(flow.workflow_determinism_source) }}
                        </div>
                        <div
                            v-for="finding in workflowDeterminismFindings()"
                            :key="workflowDeterminismFindingKey(finding)"
                            class="small text-muted"
                        >
                            {{ workflowDeterminismFindingLabel(finding) }}
                        </div>
                    </div>
                </div>

                <div class="row mb-2" v-if="hasDetailValue(flow.compatibility) || flow.compatibility_supported === false || hasDetailValue(flow.compatibility_supported_in_fleet)">
                    <div class="col-md-2"><strong>Compatibility</strong></div>
                    <div class="col">
                        <div>{{ flow.compatibility || '-' }}</div>
                        <div class="small text-muted" v-if="hasDetailValue(flow.compatibility_namespace)">
                            Namespace: {{ flow.compatibility_namespace }}
                        </div>
                        <div class="small text-muted" v-if="flow.compatibility_supported === false && hasDetailValue(flow.compatibility_reason)">
                            This build: {{ flow.compatibility_reason }}
                        </div>
                        <div class="small text-muted" v-if="hasDetailValue(flow.compatibility_supported_in_fleet)">
                            Fleet: {{ compatibilityFleetSummary(flow.compatibility_supported_in_fleet) }}
                        </div>
                        <div class="small text-muted" v-if="flow.compatibility_supported_in_fleet === false && hasDetailValue(flow.compatibility_fleet_reason)">
                            {{ flow.compatibility_fleet_reason }}
                        </div>
                        <div class="small text-muted mt-1" v-if="compatibilityFleetRows(flow.compatibility_fleet).length">
                            <div v-for="snapshot in compatibilityFleetRows(flow.compatibility_fleet)" :key="compatibilityFleetKey(snapshot)">
                                {{ compatibilityFleetLabel(snapshot) }}
                                <span v-if="hasDetailValue(snapshot.expires_at)">
                                    until {{ timestamp(snapshot.expires_at) }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mb-2" v-if="!flow.is_current_run && flow.current_run_id">
                    <div class="col-md-2"><strong>Current Run</strong></div>
                    <div class="col">
                        <router-link :to="canonicalRoute(flow.instance_id, flow.current_run_id)">
                            {{ flow.current_run_id }}
                        </router-link>
                        <span v-if="flow.current_run_status">
                            ({{ flow.current_run_status }}<span v-if="flow.current_run_status_bucket"> / {{ flow.current_run_status_bucket }}</span>)
                        </span>
                    </div>
                </div>

                <div class="row mb-2" v-if="runNavigationRows().length > 1">
                    <div class="col-md-2"><strong>Runs</strong></div>
                    <div class="col">
                        <div v-for="entry in runNavigationRows()" :key="entry.run_id">
                            <router-link v-if="entry.instance_id && entry.run_id"
                                :to="canonicalRoute(entry.instance_id, entry.run_id)">
                                Run {{ entry.run_number }} / {{ entry.run_id }}
                            </router-link>
                            <span v-else>Run {{ entry.run_number }} / {{ entry.run_id }}</span>
                            <span v-if="entry.is_selected_run" class="badge badge-info ml-1">Viewing</span>
                            <span v-if="entry.is_current_run" class="badge badge-success ml-1">Current</span>
                            <span v-if="entry.status">
                                - {{ entry.status }}<span v-if="entry.status_bucket"> / {{ entry.status_bucket }}</span>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="row mb-2" v-if="hasDetailValue(flow.wait_reason)">
                    <div class="col-md-2"><strong>Wait</strong></div>
                    <div class="col">
                        {{ flow.wait_reason }}
                        <span v-if="hasDetailValue(flow.wait_kind)">
                            ({{ flow.wait_kind }})
                        </span>
                    </div>
                </div>

                <div class="row mb-2" v-if="hasDetailValue(flow.open_wait_id)">
                    <div class="col-md-2"><strong>Open Wait ID</strong></div>
                    <div class="col">{{ flow.open_wait_id }}</div>
                </div>

                <div class="row mb-2" v-if="currentChildIdentityRows().length">
                    <div class="col-md-2"><strong>Current Child</strong></div>
                    <div class="col">
                        <div v-for="detail in currentChildIdentityRows()" :key="detail">
                            {{ detail }}
                        </div>
                    </div>
                </div>

                <div class="row mb-2" v-if="hasDetailValue(flow.open_wait_count) && flow.open_wait_count > 1">
                    <div class="col-md-2"><strong>Open Waits</strong></div>
                    <div class="col">{{ flow.open_wait_count }}</div>
                </div>

                <div class="row mb-2" v-if="resumeSourceSummary(flow.resume_source_kind, flow.resume_source_id)">
                    <div class="col-md-2"><strong>Resume Source</strong></div>
                    <div class="col">{{ resumeSourceSummary(flow.resume_source_kind, flow.resume_source_id) }}</div>
                </div>

                <div class="row mb-2" v-if="hasDetailValue(flow.liveness_reason)">
                    <div class="col-md-2"><strong>Liveness</strong></div>
                    <div class="col">
                        {{ flow.liveness_reason }}
                        <span v-if="hasDetailValue(flow.liveness_state)">
                            ({{ flow.liveness_state }})
                        </span>
                    </div>
                </div>

                <div class="row mb-2" v-if="hasDetailValue(flow.next_task_id)">
                    <div class="col-md-2"><strong>Next Task</strong></div>
                    <div class="col">
                        {{ flow.next_task_type }} / {{ flow.next_task_status }} / {{ flow.next_task_id }}
                    </div>
                </div>

                <div class="row mb-2" v-if="hasDetailValue(flow.history_event_count) || hasDetailValue(flow.history_size_bytes)">
                    <div class="col-md-2"><strong>History</strong></div>
                    <div class="col">
                        {{ historyBudgetSummary(flow) }}
                        <span v-if="flow.continue_as_new_recommended" class="badge badge-warning ml-1">
                            Continue as new recommended
                        </span>
                    </div>
                </div>

                <div class="row mb-2" v-if="lineageEntries().length">
                    <div class="col-md-2"><strong>Lineage</strong></div>
                    <div class="col">
                        <div v-for="entry in lineageEntries()" :key="entry.key">
                            <strong>{{ entry.label }}:</strong>
                            <router-link v-if="entry.instance_id && entry.run_id"
                                :to="canonicalRoute(entry.instance_id, entry.run_id)">
                                {{ entry.display_id }}
                            </router-link>
                            <span v-else>{{ entry.display_id }}</span>
                            <span v-if="entry.run_number"> (run {{ entry.run_number }})</span>
                            <span v-if="entry.status">
                                - {{ entry.status }}<span v-if="entry.status_bucket"> / {{ entry.status_bucket }}</span>
                            </span>
                            <div
                                v-for="detail in lineageIdentityRows(entry)"
                                :key="entry.key + '-' + detail"
                                class="small text-muted"
                            >
                                {{ detail }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mt-4" v-if="ready">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5>Arguments</h5>

                <a data-toggle="collapse" href="#collapseArguments" role="button">
                    Collapse
                </a>
            </div>

            <div class="card-body code-bg text-white collapse show" id="collapseArguments">
                <vue-json-pretty :data="unserialize(flow.arguments)"></vue-json-pretty>
            </div>
        </div>

        <div class="card mt-4" v-if="ready && isClosed(flow)">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5>Output</h5>

                <a data-toggle="collapse" href="#collapseOutput" role="button">
                    Collapse
                </a>
            </div>

            <div class="card-body code-bg text-white collapse show" id="collapseOutput">
                <vue-json-pretty :data="unserialize(flow.output)"></vue-json-pretty>
            </div>
        </div>

        <div class="card mt-4" v-if="ready && flow.chartData && flow.chartData.length">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5>Timeline Chart</h5>

                <a data-toggle="collapse" href="#collapseTimeline" role="button">
                    Collapse
                </a>
            </div>

            <div class="card-body code-bg text-white collapse show" id="collapseTimeline">
                <apexchart type="rangeBar" height="350" :options="chartOptions" :series="series"></apexchart>
            </div>
        </div>

        <div class="card mt-4" v-if="ready && flow.timeline && flow.timeline.length">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5>History</h5>

                <a data-toggle="collapse" href="#collapseHistory" role="button">
                    Collapse
                </a>
            </div>

            <div class="card-body collapse show" id="collapseHistory">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Seq</th>
                            <th scope="col">Recorded At</th>
                            <th scope="col">Kind</th>
                            <th scope="col">Summary</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="entry in flow.timeline" :key="entry.id">
                            <td>
                                #{{ entry.sequence }}
                                <div class="small text-muted" v-if="hasDetailValue(entry.workflow_sequence)">
                                    step {{ entry.workflow_sequence }}
                                </div>
                            </td>
                            <td>{{ timestamp(entry.recorded_at) }}</td>
                            <td>{{ historyKind(entry.kind) }}</td>
                            <td>
                                <div>{{ entry.summary }}</div>
                                <div class="small text-muted" v-if="historySource(entry)">
                                    {{ historySource(entry) }}
                                </div>
                                <div class="small text-muted" v-if="historySnapshot(entry)">
                                    {{ historySnapshot(entry) }}
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mt-4" v-if="ready && waitRows().length">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5>
                    Waits
                    <span v-if="openWaitCount() > 1" class="small text-muted">({{ openWaitCount() }} open)</span>
                </h5>

                <a data-toggle="collapse" href="#collapseWaits" role="button">
                    Collapse
                </a>
            </div>

            <div class="card-body collapse show" id="collapseWaits">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Wait</th>
                            <th scope="col">Status</th>
                            <th scope="col">Backing</th>
                            <th scope="col">Opened At</th>
                            <th scope="col">Resolved / Deadline</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="wait in waitRows()" :key="wait.id">
                            <td>
                                <div>
                                    {{ wait.summary }}
                                    <span v-if="isCurrentWait(wait)" class="badge badge-info ml-1">Current</span>
                                </div>
                                <div class="small text-muted" v-if="hasDetailValue(wait.target_name) || hasDetailValue(wait.target_type)">
                                    {{ wait.kind }}
                                    <span v-if="hasDetailValue(wait.target_name)"> / {{ wait.target_name }}</span>
                                    <span v-else-if="hasDetailValue(wait.target_type)"> / {{ wait.target_type }}</span>
                                </div>
                                <div
                                    v-for="detail in childWaitIdentityRows(wait)"
                                    :key="wait.id + '-child-' + detail"
                                    class="small text-muted"
                                >
                                    {{ detail }}
                                </div>
                                <div class="small text-muted" v-if="resumeSourceSummary(wait.resume_source_kind, wait.resume_source_id)">
                                    resume / {{ resumeSourceSummary(wait.resume_source_kind, wait.resume_source_id) }}
                                </div>
                                <div class="small text-muted" v-if="hasDetailValue(wait.sequence)">
                                    step {{ wait.sequence }}
                                </div>
                                <div class="small text-muted" v-if="parallelGroupLabel(wait)">
                                    {{ parallelGroupLabel(wait) }}
                                </div>
                                <div class="small text-muted" v-if="hasDetailValue(wait.signal_wait_id) || hasDetailValue(wait.condition_wait_id) || hasDetailValue(wait.command_sequence) || hasDetailValue(wait.timeout_seconds)">
                                    <span v-if="hasDetailValue(wait.signal_wait_id)">signal wait / {{ wait.signal_wait_id }}</span>
                                    <span v-if="hasDetailValue(wait.condition_wait_id)">
                                        <span v-if="hasDetailValue(wait.signal_wait_id)"> | </span>condition wait / {{ wait.condition_wait_id }}
                                    </span>
                                    <span v-if="hasDetailValue(wait.command_sequence)">
                                        <span v-if="hasDetailValue(wait.signal_wait_id) || hasDetailValue(wait.condition_wait_id)"> | </span>command / #{{ wait.command_sequence }}
                                    </span>
                                    <span v-if="hasDetailValue(wait.timeout_seconds)">
                                        <span v-if="hasDetailValue(wait.signal_wait_id) || hasDetailValue(wait.condition_wait_id) || hasDetailValue(wait.command_sequence)"> | </span>timeout / {{ wait.timeout_seconds }}s
                                    </span>
                                </div>
                            </td>
                            <td>
                                {{ wait.status }}
                                <div class="small text-muted" v-if="hasDetailValue(wait.source_status)">
                                    {{ wait.source_status }}
                                </div>
                            </td>
                            <td>{{ waitBacking(wait) }}</td>
                            <td>{{ hasDetailValue(wait.opened_at) ? timestamp(wait.opened_at) : '-' }}</td>
                            <td>{{ waitCompletion(wait) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mt-4" v-if="ready && taskRows().length">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5>Tasks</h5>

                <a data-toggle="collapse" href="#collapseTasks" role="button">
                    Collapse
                </a>
            </div>

            <div class="card-body collapse show" id="collapseTasks">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Type</th>
                            <th scope="col">Status</th>
                            <th scope="col">Transport</th>
                            <th scope="col">Target</th>
                            <th scope="col">Queue</th>
                            <th scope="col">Compatibility</th>
                            <th scope="col">Summary</th>
                            <th scope="col">Ready / Leased</th>
                            <th scope="col">Attempts</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="task in taskRows()" :key="task.id">
                            <td>{{ task.type }}</td>
                            <td>{{ task.status }}</td>
                            <td>
                                <div>{{ taskTransportState(task) }}</div>
                                <div class="small text-muted" v-if="hasDetailValue(task.last_dispatch_attempt_at)">
                                    {{ timestamp(task.last_dispatch_attempt_at) }}
                                </div>
                                <div class="small text-muted" v-if="hasDetailValue(task.last_claim_failed_at)">
                                    Claim failed {{ timestamp(task.last_claim_failed_at) }}
                                </div>
                            </td>
                            <td>{{ taskTarget(task) }}</td>
                            <td>{{ task.queue || '-' }}</td>
                            <td>
                                <div>{{ task.compatibility || '-' }}</div>
                                <div class="small text-muted" v-if="task.compatibility_supported === false && hasDetailValue(task.compatibility_reason)">
                                    This build: {{ task.compatibility_reason }}
                                </div>
                                <div class="small text-muted" v-if="hasDetailValue(task.compatibility_supported_in_fleet)">
                                    Fleet: {{ compatibilityFleetSummary(task.compatibility_supported_in_fleet) }}
                                </div>
                                <div class="small text-muted" v-if="task.compatibility_supported_in_fleet === false && hasDetailValue(task.compatibility_fleet_reason)">
                                    {{ task.compatibility_fleet_reason }}
                                </div>
                            </td>
                            <td>
                                <div>{{ task.summary }}</div>
                                <div class="small text-muted" v-if="hasDetailValue(task.last_dispatch_error)">
                                    {{ task.last_dispatch_error }}
                                </div>
                                <div class="small text-muted" v-if="hasDetailValue(task.last_claim_error)">
                                    {{ task.last_claim_error }}
                                </div>
                            </td>
                            <td>{{ taskAvailability(task) }}</td>
                            <td>{{ task.attempt_count }}<span v-if="task.repair_count"> / repair {{ task.repair_count }}</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mt-4" v-if="ready && flow.commands && flow.commands.length">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5>Commands</h5>

                <a data-toggle="collapse" href="#collapseCommands" role="button">
                    Collapse
                </a>
            </div>

            <div class="card-body collapse show" id="collapseCommands">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Seq</th>
                            <th scope="col">Type</th>
                            <th scope="col">Target</th>
                            <th scope="col">Outcome</th>
                            <th scope="col">Status</th>
                            <th scope="col">Source</th>
                            <th scope="col">Payload</th>
                            <th scope="col">Result</th>
                            <th scope="col">Accepted At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="command in flow.commands" :key="command.id">
                            <td>{{ hasDetailValue(command.sequence) ? '#' + command.sequence : '-' }}</td>
                            <td>{{ command.type }}</td>
                            <td>
                                <div>{{ command.target_name || command.target_scope }}</div>
                                <small v-if="commandTargetDetail(command)" class="text-muted">
                                    {{ commandTargetDetail(command) }}
                                </small>
                            </td>
                            <td>{{ command.outcome || '-' }}</td>
                            <td>{{ command.status }}</td>
                            <td>
                                <div>{{ commandSource(command) }}</div>
                                <small v-if="command.status === 'rejected' && hasDetailValue(command.rejection_reason)" class="text-muted d-block">
                                    {{ command.rejection_reason }}
                                </small>
                                <small v-if="commandSourceDetail(command)" class="text-muted">
                                    {{ commandSourceDetail(command) }}
                                </small>
                                <small
                                    v-for="(message, index) in commandValidationMessages(command)"
                                    :key="command.id + '-validation-' + index"
                                    class="text-muted d-block"
                                >
                                    {{ message }}
                                </small>
                            </td>
                            <td>
                                <button
                                    v-if="command.payload_available"
                                    title="View Payload"
                                    class="btn btn-outline-primary ml-auto"
                                    @click="showResult(command.payload, 'Command Payload')"
                                >View</button>
                                <span v-else>-</span>
                            </td>
                            <td>
                                <button
                                    v-if="command.result_available"
                                    title="View Result"
                                    class="btn btn-outline-primary ml-auto"
                                    @click="showResult(command.result, 'Command Result')"
                                >View</button>
                                <span v-else>-</span>
                            </td>
                            <td>{{ timestamp(command.accepted_at || command.rejected_at || command.applied_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mt-4" v-if="ready && signalRows().length">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5>Signals</h5>

                <a data-toggle="collapse" href="#collapseSignals" role="button">
                    Collapse
                </a>
            </div>

            <div class="card-body collapse show" id="collapseSignals">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Signal</th>
                            <th scope="col">Status</th>
                            <th scope="col">Target</th>
                            <th scope="col">Arguments</th>
                            <th scope="col">Received</th>
                            <th scope="col">Closed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="signal in signalRows()" :key="signal.id || signal.command_id">
                            <td>
                                <div>{{ signal.name || '-' }}</div>
                                <div v-if="hasDetailValue(signal.id)" class="small text-muted">
                                    signal / {{ signal.id }}
                                </div>
                                <div v-if="hasDetailValue(signal.signal_wait_id)" class="small text-muted">
                                    wait / {{ signal.signal_wait_id }}
                                </div>
                                <div v-if="hasDetailValue(signal.command_id)" class="small text-muted">
                                    command / {{ signal.command_id }}
                                </div>
                                <div v-if="hasDetailValue(signal.command_sequence)" class="small text-muted">
                                    command seq / #{{ signal.command_sequence }}
                                </div>
                                <div v-if="hasDetailValue(signal.workflow_sequence)" class="small text-muted">
                                    step / {{ signal.workflow_sequence }}
                                </div>
                            </td>
                            <td>
                                <div>{{ signal.status || '-' }}</div>
                                <div v-if="hasDetailValue(signal.outcome)" class="small text-muted">
                                    {{ signal.outcome }}
                                </div>
                                <div v-if="hasDetailValue(signal.rejection_reason)" class="small text-muted">
                                    {{ signal.rejection_reason }}
                                </div>
                                <small
                                    v-for="(message, index) in commandValidationMessages(signal)"
                                    :key="(signal.id || signal.command_id || 'signal') + '-validation-' + index"
                                    class="text-muted d-block"
                                >
                                    {{ message }}
                                </small>
                            </td>
                            <td>
                                <div>{{ signal.target_scope || '-' }}</div>
                                <small v-if="commandTargetDetail(signal)" class="text-muted">
                                    {{ commandTargetDetail(signal) }}
                                </small>
                            </td>
                            <td>
                                <button
                                    v-if="signal.arguments_available"
                                    title="View Arguments"
                                    class="btn btn-outline-primary ml-auto"
                                    @click="showResult(signal.arguments, 'Signal Arguments')"
                                >View</button>
                                <span v-else>-</span>
                            </td>
                            <td>{{ timestamp(signal.received_at || signal.rejected_at) }}</td>
                            <td>{{ timestamp(signal.closed_at || signal.applied_at || signal.rejected_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mt-4" v-if="ready && updateRows().length">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5>Updates</h5>

                <a data-toggle="collapse" href="#collapseUpdates" role="button">
                    Collapse
                </a>
            </div>

            <div class="card-body collapse show" id="collapseUpdates">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Update</th>
                            <th scope="col">Status</th>
                            <th scope="col">Target</th>
                            <th scope="col">Result</th>
                            <th scope="col">Accepted</th>
                            <th scope="col">Closed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="update in updateRows()" :key="update.id || update.command_id">
                            <td>
                                <div>{{ update.name || '-' }}</div>
                                <div v-if="hasDetailValue(update.id)" class="small text-muted">
                                    update / {{ update.id }}
                                </div>
                                <div v-if="hasDetailValue(update.command_id)" class="small text-muted">
                                    command / {{ update.command_id }}
                                </div>
                                <div v-if="hasDetailValue(update.command_sequence)" class="small text-muted">
                                    command seq / #{{ update.command_sequence }}
                                </div>
                                <div v-if="hasDetailValue(update.workflow_sequence)" class="small text-muted">
                                    step / {{ update.workflow_sequence }}
                                </div>
                            </td>
                            <td>
                                <div>{{ update.status || '-' }}</div>
                                <div v-if="hasDetailValue(update.outcome)" class="small text-muted">
                                    {{ update.outcome }}
                                </div>
                                <div v-if="hasDetailValue(update.rejection_reason)" class="small text-muted">
                                    {{ update.rejection_reason }}
                                </div>
                                <small
                                    v-for="(message, index) in commandValidationMessages(update)"
                                    :key="(update.id || update.command_id || 'update') + '-validation-' + index"
                                    class="text-muted d-block"
                                >
                                    {{ message }}
                                </small>
                            </td>
                            <td>
                                <div>{{ update.target_scope || '-' }}</div>
                                <small v-if="commandTargetDetail(update)" class="text-muted">
                                    {{ commandTargetDetail(update) }}
                                </small>
                            </td>
                            <td>
                                <button
                                    v-if="update.result_available"
                                    title="View Result"
                                    class="btn btn-outline-primary ml-auto"
                                    @click="showResult(update.result, 'Update Result')"
                                >View</button>
                                <div v-else-if="hasDetailValue(update.failure_message)" class="small text-muted">
                                    {{ update.failure_message }}
                                </div>
                                <span v-else>-</span>
                            </td>
                            <td>{{ timestamp(update.accepted_at || update.rejected_at || update.closed_at) }}</td>
                            <td>{{ timestamp(update.closed_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mt-4" v-if="ready && activityRows().length">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5>Activities</h5>

                <a data-toggle="collapse" href="#collapseActivities" role="button">
                    Collapse
                </a>
            </div>

            <div class="card-body code-bg text-white collapse show" id="collapseActivities">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Activity</th>
                            <th scope="col">Status</th>
                            <th scope="col">Attempts</th>
                            <th scope="col">Retry Policy</th>
                            <th scope="col">Queue</th>
                            <th scope="col">Started</th>
                            <th scope="col">Heartbeat</th>
                            <th scope="col">Closed</th>
                            <th scope="col">Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="activity in activityRows()" :key="activity.id">
                            <td>
                                {{ activity.type || activity.class }}
                                <div v-if="activity.idempotency_key" class="small text-muted">
                                    idempotency / {{ activity.idempotency_key }}
                                </div>
                            </td>
                            <td>{{ activity.status || '-' }}</td>
                            <td>
                                {{ activity.attempt_count || 0 }}
                                <div v-if="activity.attempt_id" class="small text-muted">{{ activity.attempt_id }}</div>
                                <div v-if="activity.attempts && activity.attempts.length" class="small text-muted mt-2">
                                    <div v-for="attempt in activity.attempts" :key="attempt.id">
                                        #{{ attempt.attempt_number }} / {{ attempt.status || '-' }}
                                        <div v-if="attempt.task_id">task / {{ attempt.task_id }}</div>
                                        <div v-if="attempt.lease_owner">worker / {{ attempt.lease_owner }}</div>
                                        <div v-if="attempt.lease_expires_at">lease / {{ timestamp(attempt.lease_expires_at) }}</div>
                                        <div v-if="attempt.cancel_requested">cancel requested</div>
                                        <div v-if="attempt.stop_reason">stop / {{ attempt.stop_reason }}</div>
                                    </div>
                                </div>
                            </td>
                            <td>{{ activityRetryPolicy(activity) }}</td>
                            <td>{{ activity.queue || '-' }}</td>
                            <td>{{ timestamp(activity.started_at) }}</td>
                            <td>{{ timestamp(activity.last_heartbeat_at) }}</td>
                            <td>{{ timestamp(activity.closed_at) }}</td>
                            <td><button title="View Result" class="btn btn-outline-primary ml-auto"
                                    @click="showResult(activity.result)">View</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mt-4" v-if="ready && flow.exceptions && flow.exceptions.length">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5>Exceptions</h5>

                <a data-toggle="collapse" href="#collapseExceptions" role="button">
                    Collapse
                </a>
            </div>

            <div class="card-body code-bg text-white collapse show" id="collapseExceptions">
                <table class="table" id="accordion">
                    <thead>
                        <tr>
                            <th scope="col">Activity</th>
                            <th scope="col">Trace</th>
                            <th scope="col">Logged At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="exception in flow.exceptions">
                            <tr>
                                <td>{{ exception.class }}</td>
                                <td v-if="exception.code"><button title="View Exception" class="btn btn-outline-primary ml-auto"
                                        data-toggle="collapse" :href="'#collapse' + exception.id" aria-expanded="false"
                                        :aria-controls="'collapse' + exception.id">View</button></td>
                                <td v-else>-</td>
                                <td>{{ timestamp(exception.created_at) }}</td>
                            </tr>
                            <tr :id="'collapse' + exception.id" class="collapse">
                                <td colspan="3">
                                    <div class="code-bg text-white">
                                        <div v-for="exception in [unserialize(exception.exception)]">
                                            <b>{{ exception.__constructor }}("{{ exception.message }}")</b>
                                            <span v-if="exception.code !== undefined && exception.code !== null">
                                                [code {{ exception.code }}]
                                            </span><br />
                                            <span style="opacity: 0.8">in {{ exception.file }} (line {{ exception.line
                                            }})</span><br /><br />
                                            <div v-if="exception.properties && exception.properties.length">
                                                <b>Custom Properties</b><br /><br />
                                                <div v-for="property in exception.properties"
                                                    :key="property.declaring_class + ':' + property.name">
                                                    <b>{{ property.declaring_class }}::{{ property.name }}</b>
                                                    <pre class="mb-3">{{ prettyJson(property.value) }}</pre>
                                                </div>
                                            </div>
                                        </div>
                                        <prism-editor :id="'prism' + exception.id" style="background-color: #424242" v-model="exception.code"
                                            :highlight="highlighter" line-numbers readonly></prism-editor>
                                        <br />
                                        <div v-for="trace in unserialize(exception.exception).trace">
                                            <b>{{ trace.class }}{{ trace.type }}{{ trace.function }}()</b> <br />
                                            <span style="opacity: 0.8">in {{ trace.file }} (line {{ trace.line
                                            }})</span><br /><br />
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<script type="text/ecmascript-6">
import phpunserialize from 'phpunserialize'
import moment from 'moment-timezone'
import Swal from 'sweetalert2'
import { highlight, languages } from 'prismjs/components/prism-core'
import 'prismjs/components/prism-markup-templating'
import 'prismjs/components/prism-php'
import 'prismjs/themes/prism-tomorrow.css'

export default {
    /**
     * The component's data.
     */
    data() {
        return {
            ready: false,
            flow: {},
            exception: null,
            code: 'console.log("Hello World")',
            series: [
                {
                    data: [
                    ]
                },
                {
                    data: [
                    ]
                }
            ],
            chartOptions: {
                chart: {
                    height: 350,
                    type: 'rangeBar'
                },
                theme: {
                    mode: 'dark'
                },
                plotOptions: {
                    bar: {
                        horizontal: true,
                        distributed: true,
                        rangeBarGroupRows: true,
                        dataLabels: {
                            formatter: function (value, timestamp) {
                                return new Date(timestamp)
                            },
                        }
                    }
                },
                tooltip: {
                    custom: ({ series, seriesIndex, dataPointIndex, w }) => {
                        if (seriesIndex === 0) {
                            let data = w.globals.initialSeries[seriesIndex].data[dataPointIndex]

                            return '<div style="padding: 1em">' +
                                '<b>'+data.type+'</b>: ' + data.x.split('_')[0] + '<br />' +
                                '<b>Time</b>: ' + (data.y[1] - data.y[0]) + 'ms </div>'
                        }
                        if (seriesIndex === 1) {
                            let exception = phpunserialize(this.flow.exceptions[dataPointIndex].exception)
                            if (typeof exception !== 'object') return '';
                            if (exception.class) {
                                exception.__constructor = exception.class
                            } else {
                                exception.__constructor = this.flow.exceptions[dataPointIndex].exception.split('"')[1]
                            }

                            return '<div style="padding: 1em">' +
                                '<b>Class</b>: ' + exception.__constructor + '<br />' +
                                '<b>Message</b>: ' + exception.message + '<br />' +
                                ((exception.code !== undefined && exception.code !== null)
                                    ? '<b>Code</b>: ' + exception.code + '<br />'
                                    : '') +
                                '<b>File</b>: ' + exception.file + '<br />' +
                                '<b>Line</b>: ' + exception.line + '<br />' +
                                '</div>'
                        }
                    }
                },
                dataLabels: {
                    enabled: false,
                },
                xaxis: {
                    type: 'datetime',
                },
                yaxis: {
                    show: false
                },
                grid: {
                    row: {
                        colors: ['#161b22', '#0d1117'],
                        opacity: 1
                    }
                },
                legend: {
                    show: false
                }
            },

        };
    },

    /**
     * Prepare the component.
     */
    mounted() {
        moment.relativeTimeThreshold('ss', 1);

        this.loadRouteFlow();

        document.title = "Waterline - Flow Detail";
    },

    watch: {
        '$route.fullPath'() {
            this.loadRouteFlow()
        }
    },

    methods: {
        loadRouteFlow() {
            if (this.isCanonicalRoute()) {
                return this.loadCanonicalFlow(this.$route.params.instanceId, this.$route.params.runId || null)
            }

            return this.loadLegacyFlow(this.$route.params.flowId)
        },

        isCanonicalRoute() {
            return ['flow-detail', 'flow-detail-run'].includes(this.$route.name)
        },

        loadCanonicalFlow(instanceId, runId = null) {
            const path = runId
                ? '/api/instances/' + instanceId + '/runs/' + runId
                : '/api/instances/' + instanceId

            return this.fetchFlow(Waterline.basePath + path)
        },

        loadLegacyFlow(id) {
            return this.fetchFlow(Waterline.basePath + '/api/flows/' + id)
                .then(() => {
                    this.replaceWithCanonicalRoute(this.flow)
                })
        },

        /**
         * Load a flow by the given ID.
         */
        fetchFlow(path) {
            this.ready = false;

            return this.$http.get(path)
                .then(response => {
                    this.flow = response.data;
                    this.series[0].data = response.data.chartData;
                    this.series[1].data = this.flow.exceptions.map((exception) => {
                        this.$nextTick(() => {
                            this.$nextTick(() => {
                                let lineNumbers = [...document.querySelectorAll('#prism' + exception.id + ' .prism-editor__line-number')]
                                let unserialized = this.unserialize(exception.exception)
                                for (let i = 0; i < lineNumbers.length; i++) {
                                    let currentLine = Number(lineNumbers[i].innerHTML) + (unserialized.line - 4)
                                    lineNumbers[i].innerHTML = currentLine
                                    if (currentLine == unserialized.line) {
                                        lineNumbers[i].style.color = 'yellow'
                                    }
                                }
                            })
                        })

                        return {
                            x: exception.class,
                            y: [
                                moment(exception.created_at).valueOf(),
                                moment(exception.created_at).valueOf() + 250,
                            ],
                            fillColor: '#721c24',
                        }
                    });

                    this.ready = true;
                });
        },

        replaceWithCanonicalRoute(flow) {
            const route = this.canonicalRoute(flow.instance_id, flow.selected_run_id || flow.run_id || flow.id)

            if (!route || this.routeMatches(route)) {
                return
            }

            this.$router.replace(route)
        },

        canonicalRoute(instanceId, runId = null) {
            if (!instanceId) {
                return null
            }

            return runId
                ? {
                    name: 'flow-detail-run',
                    params: {
                        instanceId,
                        runId,
                    },
                }
                : {
                    name: 'flow-detail',
                    params: {
                        instanceId,
                    },
                }
        },

        routeMatches(route) {
            if (!route || this.$route.name !== route.name) {
                return false
            }

            return Object.keys(route.params || {}).every((key) => this.$route.params[key] === route.params[key])
        },


        /**
         * Pretty print serialized flow.
         */
        unserialize(data) {
            try {
                let result = phpunserialize(data)
                if (result && typeof result === 'object' && !Array.isArray(result)) {
                    if (result.class) {
                        result.__constructor = result.class
                    } else {
                        result.__constructor = data.split('"')[1]
                    }
                }
                return result
            } catch (err) {
                try {
                    let result = phpunserialize(data)
                    return result
                } catch (err) {
                    return data
                }
            }
        },

        highlighter(code) {
            return highlight(code, languages.php)
        },

        prettyJson(value) {
            const encoded = JSON.stringify(value, null, 2)

            return encoded === undefined
                ? 'null'
                : encoded
        },

        historyBudgetSummary(flow) {
            const eventCount = this.hasDetailValue(flow.history_event_count)
                ? Number(flow.history_event_count).toLocaleString() + ' events'
                : '- events'
            const size = this.formatBytes(flow.history_size_bytes)
            const thresholds = []

            if (this.hasDetailValue(flow.history_event_threshold)) {
                thresholds.push(Number(flow.history_event_threshold).toLocaleString() + ' events')
            }

            if (this.hasDetailValue(flow.history_size_bytes_threshold)) {
                thresholds.push(this.formatBytes(flow.history_size_bytes_threshold))
            }

            return thresholds.length
                ? eventCount + ' / ' + size + ' (threshold ' + thresholds.join(' or ') + ')'
                : eventCount + ' / ' + size
        },

        formatBytes(value) {
            if (!this.hasDetailValue(value)) {
                return '-'
            }

            const bytes = Number(value)

            if (!Number.isFinite(bytes)) {
                return value
            }

            if (bytes < 1024) {
                return bytes.toLocaleString() + ' B'
            }

            if (bytes < 1024 * 1024) {
                return (bytes / 1024).toFixed(1) + ' KiB'
            }

            return (bytes / (1024 * 1024)).toFixed(1) + ' MiB'
        },

        duration(start, end) {
            return moment(end).from(moment(start), true)
        },

        isClosed(flow) {
            return ['completed', 'continued', 'failed', 'cancelled', 'terminated'].includes(flow.status)
        },

        hasDetailValue(value) {
            return value !== null && value !== undefined && value !== ''
        },

        contractSourceLabel(source) {
            switch (source) {
                case 'durable_history':
                    return 'Durable start history'
                case 'definition_drift':
                    return 'Definition drift'
                case 'live_definition':
                    return 'Live workflow definition'
                case 'unavailable':
                    return 'Unavailable'
                default:
                    return source
            }
        },

        workflowDeterminismStatusLabel(status, source = null) {
            switch (status) {
                case 'clean':
                    return 'No obvious replay-unsafe calls detected in the loadable workflow definition.'
                case 'warning':
                    if (source === 'definition_drift') {
                        return 'Current-source replay-safety findings are suppressed because this run started on a different workflow definition.'
                    }
                    return 'Replay-unsafe calls detected in the loadable workflow definition.'
                case 'unavailable':
                    return 'Workflow definition is unavailable, so replay-safety diagnostics could not run.'
                default:
                    return status
            }
        },

        workflowDeterminismFindings() {
            return Array.isArray(this.flow.workflow_determinism_findings)
                ? this.flow.workflow_determinism_findings
                : []
        },

        workflowDeterminismFindingKey(finding) {
            return [
                finding && finding.rule ? finding.rule : 'rule',
                finding && finding.symbol ? finding.symbol : 'symbol',
                finding && finding.file ? finding.file : 'file',
                finding && finding.line ? finding.line : 'line',
            ].join(':')
        },

        workflowDeterminismFindingLabel(finding) {
            const symbol = finding && finding.symbol ? finding.symbol : 'unknown symbol'
            const location = finding && finding.file
                ? `${finding.file}${finding.line ? ':' + finding.line : ''}`
                : ''
            const message = finding && finding.message ? finding.message : ''

            return [symbol, location, message]
                .filter(Boolean)
                .join(' / ')
        },

        commandContractLabel(contract) {
            const parameters = Array.isArray(contract && contract.parameters)
                ? contract.parameters
                : []

            const signature = parameters.map((parameter) => {
                const parts = [parameter.name]

                if (parameter.type) {
                    parts.push(': ' + parameter.type)
                }

                if (parameter.variadic === true) {
                    parts.push('...')
                }

                if (parameter.required !== true) {
                    parts.push(' ?')
                }

                return parts.join('')
            }).join(', ')

            return signature
                ? `${contract.name}(${signature})`
                : contract.name
        },

        signalContractLabel(contract) {
            return this.commandContractLabel(contract)
        },

        updateContractLabel(contract) {
            return this.commandContractLabel(contract)
        },

        queryContractLabel(contract) {
            return this.commandContractLabel(contract)
        },

        compatibilityFleetSummary(supported) {
            if (supported === true) {
                return 'supported by an active worker heartbeat'
            }

            if (supported === false) {
                return 'no active compatible worker heartbeat'
            }

            return '-'
        },

        compatibilityFleetRows(fleet) {
            return Array.isArray(fleet)
                ? fleet
                : []
        },

        compatibilityFleetKey(snapshot) {
            return [
                snapshot && snapshot.worker_id ? snapshot.worker_id : 'worker',
                snapshot && snapshot.namespace ? snapshot.namespace : 'namespace',
                snapshot && snapshot.connection ? snapshot.connection : 'connection',
                snapshot && snapshot.queue ? snapshot.queue : 'queue',
                snapshot && snapshot.source ? snapshot.source : 'source',
            ].join(':')
        },

        compatibilityFleetLabel(snapshot) {
            const parts = []
            const supported = Array.isArray(snapshot && snapshot.supported)
                ? snapshot.supported
                : []

            if (this.hasDetailValue(snapshot && snapshot.worker_id)) {
                parts.push(snapshot.worker_id)
            }

            if (this.hasDetailValue(snapshot && snapshot.namespace)) {
                parts.push(`namespace ${snapshot.namespace}`)
            }

            if (this.hasDetailValue(snapshot && snapshot.host)) {
                parts.push(snapshot.host)
            }

            if (this.hasDetailValue(snapshot && snapshot.process_id)) {
                parts.push(`pid ${snapshot.process_id}`)
            }

            if (this.hasDetailValue(snapshot && snapshot.connection) || this.hasDetailValue(snapshot && snapshot.queue)) {
                parts.push([
                    this.hasDetailValue(snapshot && snapshot.connection) ? `connection ${snapshot.connection}` : null,
                    this.hasDetailValue(snapshot && snapshot.queue) ? `queue ${snapshot.queue}` : null,
                ].filter(Boolean).join(' / '))
            }

            parts.push(`supports ${supported.length ? supported.join(', ') : 'none'}`)

            if (snapshot && snapshot.supports_required === true) {
                parts.push('matches selected marker')
            }

            if (snapshot && snapshot.source === 'cache') {
                parts.push('legacy cache heartbeat')
            }

            return parts.join(' / ')
        },

        showResult(result, title = 'Activity Result') {
            Swal.fire({
                title: title,
                text: JSON.stringify(this.unserialize(result), null, 2),
                icon: 'info',
                confirmButtonText: 'Okay',
                background: '#1c1c1c',
            })
        },

        activityRows() {
            return this.flow.activities && this.flow.activities.length
                ? this.flow.activities
                : (this.flow.logs || [])
        },

        activityRetryPolicy(activity) {
            const policy = activity && activity.retry_policy ? activity.retry_policy : null

            if (!policy) {
                return '-'
            }

            const parts = []

            if (Object.prototype.hasOwnProperty.call(policy, 'max_attempts')) {
                parts.push(policy.max_attempts === null ? 'unlimited attempts' : `${policy.max_attempts} attempts`)
            }

            if (Array.isArray(policy.backoff_seconds) && policy.backoff_seconds.length) {
                parts.push(`backoff ${policy.backoff_seconds.join(', ')}s`)
            }

            return parts.length ? parts.join(' / ') : '-'
        },

        updateRows() {
            return Array.isArray(this.flow.updates)
                ? this.flow.updates
                : []
        },

        signalRows() {
            return Array.isArray(this.flow.signals)
                ? this.flow.signals
                : []
        },

        declaredSignalTargets() {
            if (Array.isArray(this.flow.declared_signal_targets) && this.flow.declared_signal_targets.length) {
                return this.normalizeCommandTargets(this.flow.declared_signal_targets)
            }

            return this.normalizeCommandTargets(
                this.flow.declared_signals,
                this.flow.declared_signal_contracts,
            )
        },

        declaredQueryTargets() {
            if (Array.isArray(this.flow.declared_query_targets) && this.flow.declared_query_targets.length) {
                return this.normalizeCommandTargets(this.flow.declared_query_targets)
            }

            return this.normalizeCommandTargets(
                this.flow.declared_queries,
                this.flow.declared_query_contracts,
            )
        },

        declaredUpdateTargets() {
            if (Array.isArray(this.flow.declared_update_targets) && this.flow.declared_update_targets.length) {
                return this.normalizeCommandTargets(this.flow.declared_update_targets)
            }

            return this.normalizeCommandTargets(
                this.flow.declared_updates,
                this.flow.declared_update_contracts,
            )
        },

        normalizeCommandTargets(namesOrTargets, contracts = []) {
            if (Array.isArray(namesOrTargets) && namesOrTargets.every((target) => target && typeof target.name === 'string')) {
                return [...namesOrTargets]
                    .map((target) => ({
                        name: target.name,
                        parameters: Array.isArray(target.parameters) ? target.parameters : [],
                        has_contract: target.has_contract === true || (Array.isArray(target.parameters) && target.parameters.length > 0),
                    }))
                    .sort((left, right) => left.name.localeCompare(right.name))
            }

            const targets = new Map()

            if (Array.isArray(namesOrTargets)) {
                namesOrTargets
                    .filter((name) => typeof name === 'string' && name.length > 0)
                    .forEach((name) => {
                        targets.set(name, {
                            name,
                            parameters: [],
                            has_contract: false,
                        })
                    })
            }

            if (Array.isArray(contracts)) {
                contracts
                    .filter((contract) => contract && typeof contract.name === 'string' && contract.name.length > 0)
                    .forEach((contract) => {
                        const existing = targets.get(contract.name) || {
                            name: contract.name,
                            parameters: [],
                            has_contract: false,
                        }

                        targets.set(contract.name, {
                            ...existing,
                            parameters: Array.isArray(contract.parameters) ? contract.parameters : [],
                            has_contract: true,
                        })
                    })
            }

            return Array.from(targets.values()).sort((left, right) => left.name.localeCompare(right.name))
        },

        signalTargetLabel(target) {
            return target.has_contract ? this.signalContractLabel(target) : target.name
        },

        updateTargetLabel(target) {
            return target.has_contract ? this.updateContractLabel(target) : target.name
        },

        queryTargetLabel(target) {
            return target.has_contract ? this.queryContractLabel(target) : target.name
        },

        signalTargets() {
            return this.declaredSignalTargets().map((target) => ({
                name: target.name,
                label: this.signalTargetLabel(target),
            }))
        },

        queryTargets() {
            return this.declaredQueryTargets().map((target) => ({
                name: target.name,
                label: this.queryTargetLabel(target),
            }))
        },

        updateTargets() {
            return this.declaredUpdateTargets().map((target) => ({
                name: target.name,
                label: this.updateTargetLabel(target),
            }))
        },

        canIssueQuery() {
            return this.canAction('query', true) && this.queryTargets().length > 0
        },

        canIssueSignal() {
            return this.canAction('signal') && this.signalTargets().length > 0
        },

        canIssueUpdate() {
            return this.canAction('update') && this.updateTargets().length > 0
        },

        updateContractByName(name) {
            return this.declaredUpdateTargets().find((target) => target.name === name && target.has_contract) || null
        },

        queryContractByName(name) {
            return this.declaredQueryTargets().find((target) => target.name === name && target.has_contract) || null
        },

        signalContractByName(name) {
            return this.declaredSignalTargets().find((target) => target.name === name && target.has_contract) || null
        },

        defaultContractArguments(contract, fallback = '[]') {
            if (!contract || !Array.isArray(contract.parameters) || !contract.parameters.length) {
                return fallback
            }

            const template = {}

            contract.parameters.forEach((parameter) => {
                if (!parameter || !parameter.name || parameter.variadic === true) {
                    return
                }

                if (parameter.default_available === true) {
                    template[parameter.name] = parameter.default

                    return
                }

                template[parameter.name] = this.defaultContractArgumentValue(parameter)
            })

            return JSON.stringify(template, null, 2)
        },

        defaultContractArgumentValue(parameter) {
            if (!parameter) {
                return null
            }

            if (parameter.allows_null === true) {
                return null
            }

            const type = typeof parameter.type === 'string'
                ? parameter.type.replace(/^\?/, '')
                : null

            switch (type) {
                case 'bool':
                case 'false':
                case 'true':
                    return false
                case 'int':
                case 'float':
                    return 0
                case 'string':
                    return ''
                case 'array':
                case 'iterable':
                    return []
                case 'object':
                    return {}
                default:
                    return null
            }
        },

        defaultSignalArguments(name) {
            return this.defaultContractArguments(this.signalContractByName(name))
        },

        defaultQueryArguments(name) {
            return this.defaultContractArguments(this.queryContractByName(name))
        },

        defaultUpdateArguments(name) {
            return this.defaultContractArguments(this.updateContractByName(name))
        },

        parseCommandArguments(rawValue) {
            const value = (rawValue || '').trim()

            if (!value) {
                return []
            }

            return JSON.parse(value)
        },

        async promptForSignalCommand() {
            const signals = this.signalTargets()

            if (!signals.length) {
                return null
            }

            const options = signals.map((signal) =>
                `<option value="${this.escapeHtml(signal.name)}">${this.escapeHtml(signal.label)}</option>`
            ).join('')

            const firstSignal = signals[0].name

            const result = await Swal.fire({
                title: 'Send signal',
                html: `
                    <label class="d-block text-left mb-2" for="waterline-signal-target">Signal</label>
                    <select id="waterline-signal-target" class="swal2-input">${options}</select>
                    <label class="d-block text-left mb-2" for="waterline-signal-arguments">Arguments JSON</label>
                    <textarea id="waterline-signal-arguments" class="swal2-textarea" style="min-height: 8rem;">${this.escapeHtml(this.defaultSignalArguments(firstSignal))}</textarea>
                    <div class="small text-muted text-left">Use a JSON object for named arguments when the signal declares a contract, a JSON array for positional arguments, or any other JSON value for one payload.</div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Send signal',
                focusConfirm: false,
                background: '#1c1c1c',
                didOpen: () => {
                    const select = document.getElementById('waterline-signal-target')
                    const textarea = document.getElementById('waterline-signal-arguments')

                    if (!select || !textarea) {
                        return
                    }

                    select.addEventListener('change', () => {
                        textarea.value = this.defaultSignalArguments(select.value)
                    })
                },
                preConfirm: () => {
                    const target = document.getElementById('waterline-signal-target').value
                    const rawArguments = document.getElementById('waterline-signal-arguments').value

                    if (!target) {
                        Swal.showValidationMessage('Select a signal.')

                        return false
                    }

                    try {
                        return {
                            targetName: target,
                            arguments: this.parseCommandArguments(rawArguments),
                        }
                    } catch (error) {
                        Swal.showValidationMessage('Arguments must be valid JSON.')

                        return false
                    }
                },
            })

            return result.isConfirmed ? result.value : null
        },

        async promptForQueryCommand() {
            const queries = this.queryTargets()

            if (!queries.length) {
                return null
            }

            const options = queries.map((query) =>
                `<option value="${this.escapeHtml(query.name)}">${this.escapeHtml(query.label)}</option>`
            ).join('')

            const firstQuery = queries[0].name

            const result = await Swal.fire({
                title: 'Run query',
                html: `
                    <label class="d-block text-left mb-2" for="waterline-query-target">Query</label>
                    <select id="waterline-query-target" class="swal2-input">${options}</select>
                    <label class="d-block text-left mb-2" for="waterline-query-arguments">Arguments JSON</label>
                    <textarea id="waterline-query-arguments" class="swal2-textarea" style="min-height: 10rem;">${this.escapeHtml(this.defaultQueryArguments(firstQuery))}</textarea>
                    <div class="small text-muted text-left">Use a JSON object for named arguments or a JSON array for positional arguments.</div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Run query',
                focusConfirm: false,
                background: '#1c1c1c',
                didOpen: () => {
                    const select = document.getElementById('waterline-query-target')
                    const textarea = document.getElementById('waterline-query-arguments')

                    if (!select || !textarea) {
                        return
                    }

                    select.addEventListener('change', () => {
                        textarea.value = this.defaultQueryArguments(select.value)
                    })
                },
                preConfirm: () => {
                    const target = document.getElementById('waterline-query-target').value
                    const rawArguments = document.getElementById('waterline-query-arguments').value

                    if (!target) {
                        Swal.showValidationMessage('Select a query.')

                        return false
                    }

                    try {
                        return {
                            targetName: target,
                            arguments: this.parseCommandArguments(rawArguments),
                        }
                    } catch (error) {
                        Swal.showValidationMessage('Arguments must be valid JSON.')

                        return false
                    }
                },
            })

            return result.isConfirmed ? result.value : null
        },

        async promptForUpdateCommand() {
            const updates = this.updateTargets()

            if (!updates.length) {
                return null
            }

            const options = updates.map((update) =>
                `<option value="${this.escapeHtml(update.name)}">${this.escapeHtml(update.label)}</option>`
            ).join('')

            const firstUpdate = updates[0].name

            const result = await Swal.fire({
                title: 'Apply update',
                html: `
                    <label class="d-block text-left mb-2" for="waterline-update-target">Update</label>
                    <select id="waterline-update-target" class="swal2-input">${options}</select>
                    <label class="d-block text-left mb-2" for="waterline-update-arguments">Arguments JSON</label>
                    <textarea id="waterline-update-arguments" class="swal2-textarea" style="min-height: 10rem;">${this.escapeHtml(this.defaultUpdateArguments(firstUpdate))}</textarea>
                    <label class="d-block text-left mb-2" for="waterline-update-wait-for">Return after</label>
                    <select id="waterline-update-wait-for" class="swal2-input">
                        <option value="completed">Worker applies update</option>
                        <option value="accepted">Command is accepted</option>
                    </select>
                    <div class="small text-muted text-left">Use a JSON object for named arguments or a JSON array for positional arguments. Waterline always records the update durably first; this control decides whether the response waits for the workflow worker to apply it.</div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Apply update',
                focusConfirm: false,
                background: '#1c1c1c',
                didOpen: () => {
                    const select = document.getElementById('waterline-update-target')
                    const textarea = document.getElementById('waterline-update-arguments')

                    if (!select || !textarea) {
                        return
                    }

                    select.addEventListener('change', () => {
                        textarea.value = this.defaultUpdateArguments(select.value)
                    })
                },
                preConfirm: () => {
                    const target = document.getElementById('waterline-update-target').value
                    const rawArguments = document.getElementById('waterline-update-arguments').value
                    const waitFor = document.getElementById('waterline-update-wait-for').value

                    if (!target) {
                        Swal.showValidationMessage('Select an update.')

                        return false
                    }

                    try {
                        return {
                            targetName: target,
                            arguments: this.parseCommandArguments(rawArguments),
                            waitFor,
                        }
                    } catch (error) {
                        Swal.showValidationMessage('Arguments must be valid JSON.')

                        return false
                    }
                },
            })

            return result.isConfirmed ? result.value : null
        },

        commandRequestBody(command) {
            const body = {
                arguments: command.arguments,
            }

            if (command.waitFor === 'accepted') {
                body.wait_for = 'accepted'
            }

            return body
        },

        escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;')
        },

        canAction(name, fallback = false) {
            const key = 'can_' + name

            if (this.hasDetailValue(this.flow[key])) {
                return this.flow[key] === true
            }

            return fallback === true
        },

        actionBlockedReason(name) {
            const key = name + '_blocked_reason'

            return this.hasDetailValue(this.flow[key])
                ? this.flow[key]
                : null
        },

        actionReasonLabel(reason) {
            return this.commandRejectionMessage(reason)
        },

        commandRejectionMessage(reason, commandType = null) {
            switch (reason) {
                case 'earlier_signal_pending':
                    return commandType === 'update'
                        ? 'An earlier accepted signal is still waiting to be applied. Retry the update after the worker drains it.'
                        : 'An earlier accepted signal is still waiting to be applied.'
                case 'selected_run_not_current':
                    return 'The selected run is not the current active run.'
                case 'run_closed':
                    return 'The selected run is already closed.'
                case 'repair_not_needed':
                    return 'The selected run already has a durable resume path.'
                case 'run_not_closed':
                    return 'Only closed runs can be archived.'
                case 'run_archived':
                    return 'The selected run is already archived.'
                case 'workflow_definition_unavailable':
                    return commandType === 'query'
                        ? 'The durable query target is known, but this run cannot be replayed because its workflow definition is unavailable.'
                        : 'The durable target is known, but this run cannot execute because its workflow definition is unavailable.'
                default:
                    return reason
            }
        },

        actionStateRows() {
            const hasExplicitContract = ['signal', 'update', 'repair', 'cancel', 'terminate', 'archive']
                .some((name) => this.hasDetailValue(this.flow['can_' + name]) || this.hasDetailValue(this.flow[name + '_blocked_reason']))

            if (!hasExplicitContract) {
                return []
            }

            return [
                { name: 'signal', label: 'Signal' },
                { name: 'update', label: 'Update' },
                { name: 'repair', label: 'Repair' },
                { name: 'cancel', label: 'Cancel' },
                { name: 'terminate', label: 'Terminate' },
                { name: 'archive', label: 'Archive' },
            ].map((action) => ({
                ...action,
                allowed: this.canAction(action.name, action.name === 'cancel' || action.name === 'terminate'
                    ? this.flow.can_issue_terminal_commands
                    : false),
                reason: this.actionBlockedReason(action.name),
            }))
        },

        waitRows() {
            return this.flow.waits || []
        },

        openWaitCount() {
            if (this.hasDetailValue(this.flow.open_wait_count)) {
                return this.flow.open_wait_count
            }

            return this.waitRows()
                .filter((wait) => wait.status === 'open')
                .length
        },

        taskRows() {
            return this.flow.tasks || []
        },

        runNavigationRows() {
            return this.flow.run_navigation || []
        },

        isCurrentWait(wait) {
            return this.hasDetailValue(this.flow.open_wait_id) && this.flow.open_wait_id === wait.id
        },

        resumeSourceSummary(kind, id) {
            if (!this.hasDetailValue(kind) && !this.hasDetailValue(id)) {
                return ''
            }

            const parts = []

            if (this.hasDetailValue(kind)) {
                parts.push(String(kind).replace(/_/g, ' '))
            }

            if (this.hasDetailValue(id)) {
                parts.push(id)
            }

            return parts.join(' / ')
        },

        waitBacking(wait) {
            if (wait.kind === 'condition' && this.hasDetailValue(wait.timeout_seconds)) {
                if (!wait.task_backed && this.hasDetailValue(wait.task_id)) {
                    return ['stale timeout task', wait.task_status, 'external input']
                        .filter(Boolean)
                        .join(' / ')
                }

                if (!wait.task_backed) {
                    return 'external input / timeout task missing'
                }

                return [wait.task_type || 'timer', wait.task_status, 'external input']
                    .filter(Boolean)
                    .join(' / ')
            }

            if (wait.kind === 'child') {
                return 'child run'
            }

            if (wait.external_only) {
                return 'external input'
            }

            if (!wait.task_backed && this.hasDetailValue(wait.task_id)) {
                const prefix = wait.status === 'open'
                    ? 'stale'
                    : 'historical'

                return [prefix + ' ' + (wait.task_type || 'task'), wait.task_status]
                    .filter(Boolean)
                    .join(' / ')
            }

            if (!wait.task_backed) {
                return 'task missing'
            }

            return [wait.task_type, wait.task_status].filter(Boolean).join(' / ')
        },

        waitCompletion(wait) {
            if (this.hasDetailValue(wait.resolved_at)) {
                return this.timestamp(wait.resolved_at)
            }

            if (this.hasDetailValue(wait.deadline_at)) {
                return this.timestamp(wait.deadline_at)
            }

            return '-'
        },

        parallelGroupLabel(wait) {
            const path = Array.isArray(wait.parallel_group_path)
                ? wait.parallel_group_path.filter((entry) => this.hasDetailValue(entry?.parallel_group_size) && entry.parallel_group_size > 1)
                : []

            if (path.length === 0 && (!this.hasDetailValue(wait.parallel_group_size) || wait.parallel_group_size <= 1)) {
                return ''
            }

            const groups = path.length > 0
                ? path
                : [{
                    parallel_group_kind: wait.parallel_group_kind,
                    parallel_group_index: wait.parallel_group_index,
                    parallel_group_size: wait.parallel_group_size,
                }]

            return groups.map((group) => {
                const groupKind = this.hasDetailValue(group.parallel_group_kind)
                    ? String(group.parallel_group_kind)
                    : (wait.kind === 'activity' ? 'activity' : 'child')
                const position = this.hasDetailValue(group.parallel_group_index)
                    ? group.parallel_group_index + 1
                    : null
                const label = groupKind === 'activity'
                    ? 'parallel activity group'
                    : (groupKind === 'mixed' ? 'parallel group' : 'parallel child group')

                if (!this.hasDetailValue(position) || !this.hasDetailValue(group.parallel_group_size)) {
                    return label
                }

                return [label, position + '/' + group.parallel_group_size]
                    .filter(Boolean)
                    .join(' / ')
            }).join(' -> ')
        },

        historySource(entry) {
            if (!this.hasDetailValue(entry.source_kind) && !this.hasDetailValue(entry.source_id)) {
                return ''
            }

            return [entry.source_kind, entry.source_id].filter(Boolean).join(' / ')
        },

        historyKind(kind) {
            if (!this.hasDetailValue(kind)) {
                return '-'
            }

            return String(kind).replace(/_/g, ' ')
        },

        historySnapshot(entry) {
            const details = []

            if (this.hasDetailValue(entry.command_status) || this.hasDetailValue(entry.command_outcome)) {
                const commandDetails = ['command']

                if (this.hasDetailValue(entry.command_sequence)) {
                    commandDetails.push('#' + entry.command_sequence)
                }

                if (this.hasDetailValue(entry.command_status)) {
                    commandDetails.push(entry.command_status)
                }

                if (this.hasDetailValue(entry.command_outcome)) {
                    commandDetails.push(entry.command_outcome)
                }

                details.push(
                    commandDetails.join(' / ')
                )
            }

            if (this.hasDetailValue(entry.activity_status)) {
                details.push('activity / ' + entry.activity_status)
            }

            if (entry.activity && this.hasDetailValue(entry.activity.last_heartbeat_at)) {
                details.push('heartbeat / ' + this.timestamp(entry.activity.last_heartbeat_at))
            }

            if (entry.timer && this.hasDetailValue(entry.timer.status)) {
                details.push('timer / ' + entry.timer.status)
            }

            if (this.hasDetailValue(entry.child_status)) {
                details.push('child / ' + entry.child_status)
            }

            if (this.hasDetailValue(entry.child_call_id)) {
                details.push('child call / ' + entry.child_call_id)
            }

            if (entry.failure && this.hasDetailValue(entry.failure.propagation_kind)) {
                const handled = entry.failure.handled === true
                    ? 'handled'
                    : (entry.failure.handled === false ? 'unhandled' : null)

                details.push(
                    ['failure', entry.failure.propagation_kind, handled]
                        .filter(Boolean)
                        .join(' / ')
                )
            }

            return details.join(' | ')
        },

        commandSource(command) {
            return command.caller_label || command.source || '-'
        },

        commandTargetDetail(command) {
            if (!command) {
                return ''
            }

            const requested = command.requested_run_id || null
            const resolved = command.resolved_run_id || null

            if (requested && resolved && requested !== resolved) {
                return 'requested run ' + requested + ' -> resolved run ' + resolved
            }

            if (requested) {
                return 'requested run ' + requested
            }

            if (resolved && command.target_scope === 'instance') {
                return 'resolved run ' + resolved
            }

            return ''
        },

        commandSourceDetail(command) {
            const details = []
            const workflow = command.context && command.context.workflow
                ? command.context.workflow
                : null

            if (workflow) {
                const workflowDetails = []

                if (workflow.parent_instance_id) {
                    workflowDetails.push('instance ' + workflow.parent_instance_id)
                }

                if (workflow.parent_run_id) {
                    workflowDetails.push('run ' + workflow.parent_run_id)
                }

                if (workflow.sequence !== null && workflow.sequence !== undefined) {
                    workflowDetails.push('step ' + workflow.sequence)
                }

                if (workflow.child_call_id) {
                    workflowDetails.push('child call ' + workflow.child_call_id)
                }

                if (workflowDetails.length) {
                    details.push(workflowDetails.join(' / '))
                }
            }

            if (command.auth_status && command.auth_method) {
                details.push(command.auth_status + ' via ' + command.auth_method)
            } else if (command.auth_status) {
                details.push(command.auth_status)
            }

            const requestSummary = [command.request_method, command.request_path]
                .filter(Boolean)
                .join(' ')

            if (requestSummary) {
                details.push(requestSummary)
            }

            return details.join(' | ')
        },

        commandValidationMessages(command) {
            if (!command || !command.validation_errors) {
                return []
            }

            return Object.entries(command.validation_errors).flatMap(([field, messages]) => {
                if (!Array.isArray(messages)) {
                    return []
                }

                return messages.map((message) => `${field}: ${message}`)
            })
        },

        taskTarget(task) {
            if (this.hasDetailValue(task.activity_type)) {
                return task.activity_type
            }

            if (this.hasDetailValue(task.condition_wait_id)) {
                if (this.hasDetailValue(task.timer_sequence)) {
                    return 'condition timeout #' + task.timer_sequence
                }

                return 'condition timeout'
            }

            if (this.hasDetailValue(task.timer_sequence)) {
                return 'timer #' + task.timer_sequence
            }

            return 'selected run'
        },

        taskTransportState(task) {
            const labels = {
                ready: 'ready',
                scheduled: 'scheduled',
                leased: 'leased',
                lease_expired: 'lease expired',
                dispatch_overdue: 'dispatch overdue',
                dispatch_failed: 'dispatch failed',
                completed: 'completed',
                cancelled: 'cancelled',
                failed: 'failed',
            }

            return labels[task.transport_state] || task.transport_state || '-'
        },

        taskAvailability(task) {
            if (this.hasDetailValue(task.leased_at)) {
                return this.timestamp(task.leased_at)
            }

            if (this.hasDetailValue(task.available_at)) {
                return this.timestamp(task.available_at)
            }

            return '-'
        },

        currentChildIdentityRows() {
            const wait = this.waitRows().find((entry) => entry.kind === 'child' && this.isCurrentWait(entry))
                || this.waitRows().find((entry) => entry.kind === 'child' && entry.status === 'open')

            if (!wait) {
                return []
            }

            return this.childIdentityRows({
                instance_id: wait.target_name || null,
                run_id: wait.resume_source_id || null,
                child_call_id: wait.child_call_id || null,
            })
        },

        childWaitIdentityRows(wait) {
            if (!wait || wait.kind !== 'child') {
                return []
            }

            return this.childIdentityRows({
                instance_id: wait.target_name || null,
                run_id: wait.resume_source_id || null,
                child_call_id: wait.child_call_id || null,
            })
        },

        lineageIdentityRows(entry) {
            if (!entry) {
                return []
            }

            return this.childIdentityRows({
                instance_id: entry.instance_id || null,
                run_id: entry.run_id || null,
                child_call_id: entry.child_call_id || null,
            })
        },

        childIdentityRows(subject) {
            if (!subject) {
                return []
            }

            const details = []

            if (this.hasDetailValue(subject.instance_id)) {
                details.push('instance / ' + subject.instance_id)
            }

            if (this.hasDetailValue(subject.run_id)) {
                details.push('run / ' + subject.run_id)
            }

            if (this.hasDetailValue(subject.child_call_id)) {
                details.push('child call / ' + subject.child_call_id)
            }

            return details
        },

        lineageEntries() {
            const parents = (this.flow.parents || []).map((parent) => ({
                key: 'parent-' + (parent.id || parent.parent_workflow_run_id || parent.parent_workflow_id),
                label: this.lineageLabel(parent, 'parent'),
                display_id: parent.workflow_run_id || parent.parent_workflow_run_id || parent.parent_workflow_id,
                instance_id: parent.workflow_instance_id || parent.parent_workflow_id || null,
                run_id: parent.workflow_run_id || parent.parent_workflow_run_id || null,
                run_number: parent.run_number,
                status: parent.status,
                status_bucket: parent.status_bucket,
                child_call_id: parent.child_call_id || null,
            }))

            const continued = (this.flow.continuedWorkflows || []).map((link) => ({
                key: 'continued-' + (link.id || link.child_workflow_run_id || link.child_workflow_id),
                label: this.lineageLabel(link, 'child'),
                display_id: link.workflow_run_id || link.child_workflow_run_id || link.child_workflow_id,
                instance_id: link.workflow_instance_id || link.child_workflow_id || null,
                run_id: link.workflow_run_id || link.child_workflow_run_id || null,
                run_number: link.run_number,
                status: link.status,
                status_bucket: link.status_bucket,
                child_call_id: link.child_call_id || null,
            }))

            return [...parents, ...continued]
        },

        isContinuedParent(parent) {
            return parent.link_type === 'continue_as_new'
                || (parent.parent_index && parent.parent_index > Number.MAX_SAFE_INTEGER)
        },

        lineageLabel(link, direction) {
            if (link.link_type === 'continue_as_new') {
                return direction === 'parent' ? 'Continued from' : 'Continued as'
            }

            return direction === 'parent' ? 'Parent' : 'Child'
        },

        async issueCommand(commandType) {
            if (commandType === 'query') {
                return this.issueInteractiveCommand(commandType, await this.promptForQueryCommand())
            }

            if (commandType === 'signal') {
                return this.issueInteractiveCommand(commandType, await this.promptForSignalCommand())
            }

            if (commandType === 'update') {
                return this.issueInteractiveCommand(commandType, await this.promptForUpdateCommand())
            }

            const copy = {
                repair: {
                    title: 'Repair run?',
                    text: 'This recreates the durable next task for the current active run when liveness shows repair is needed. It does not restart an activity that is already marked running.',
                    confirmButtonText: 'Repair run',
                },
                cancel: {
                    title: 'Cancel run?',
                    text: 'This action applies to the current active run.',
                    confirmButtonText: 'Cancel run',
                },
                terminate: {
                    title: 'Terminate run?',
                    text: 'This action applies to the current active run.',
                    confirmButtonText: 'Terminate run',
                },
                archive: {
                    title: 'Archive run?',
                    text: 'This marks the selected closed run as archived while preserving its durable history and command audit trail.',
                    confirmButtonText: 'Archive run',
                },
            }[commandType]

            const confirmed = await Swal.fire({
                title: copy.title,
                text: copy.text,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: copy.confirmButtonText,
                background: '#1c1c1c',
            })

            if (! confirmed.isConfirmed) {
                return
            }

            return this.issueInteractiveCommand(commandType, null)
        },

        async issueInteractiveCommand(commandType, interactiveCommand = null) {
            if ((commandType === 'query' || commandType === 'signal' || commandType === 'update') && !interactiveCommand) {
                return
            }

            try {
                const response = await this.$http.post(
                    this.commandEndpoint(commandType, interactiveCommand ? interactiveCommand.targetName : null),
                    interactiveCommand ? this.commandRequestBody(interactiveCommand) : {},
                )

                if (commandType === 'query') {
                    const queryName = response.data && response.data.query_name
                        ? response.data.query_name
                        : interactiveCommand.targetName

                    this.showResult(response.data.result, 'Query Result: ' + queryName)

                    return
                }

                await this.loadRouteFlow()

                const successText = {
                    signal: 'Waterline recorded the signal command durably.',
                    update: response.data.update_status === 'accepted'
                        ? 'Waterline accepted the update command and queued a workflow task to apply it.'
                        : 'Waterline recorded the update command durably and the workflow worker applied it.',
                    repair: response.data.outcome === 'repair_dispatched'
                        ? 'Waterline recreated the durable task and re-dispatched it.'
                        : 'Waterline recorded the repair command, and no new task was needed.',
                    cancel: 'Waterline recorded the command durably.',
                    terminate: 'Waterline recorded the command durably.',
                    archive: response.data.outcome === 'archive_not_needed'
                        ? 'Waterline recorded the archive command; the run was already archived.'
                        : 'Waterline archived the selected run durably.',
                }[commandType] || 'Waterline recorded the command durably.'

                Swal.fire({
                    title: 'Command accepted',
                    text: successText,
                    icon: 'success',
                    confirmButtonText: 'Okay',
                    background: '#1c1c1c',
                })
            } catch (error) {
                const validationMessages = error.response && error.response.data
                    ? this.commandValidationMessages(error.response.data)
                    : []
                const message = validationMessages.length
                    ? validationMessages.join(' ')
                    : (error.response
                        && error.response.data
                        && error.response.data.blocked_reason
                        ? this.commandRejectionMessage(error.response.data.blocked_reason, commandType)
                        : error.response
                        && error.response.data
                        && error.response.data.rejection_reason
                        ? this.commandRejectionMessage(error.response.data.rejection_reason, commandType)
                        : error.response
                        && error.response.data
                        && error.response.data.message
                        ? error.response.data.message
                        : 'Command was rejected.')

                Swal.fire({
                    title: commandType === 'query' ? 'Query failed' : 'Command rejected',
                    text: message,
                    icon: 'error',
                    confirmButtonText: 'Okay',
                    background: '#1c1c1c',
                })
            }
        },

        commandEndpoint(commandType, targetName = null) {
            const collection = commandType === 'query'
                ? 'queries'
                : commandType + 's'
            const suffix = targetName
                ? collection + '/' + encodeURIComponent(targetName)
                : commandType

            if (this.flow.instance_id) {
                const selectedRunId = this.flow.selected_run_id || this.flow.run_id || this.flow.id

                if (selectedRunId) {
                    return Waterline.basePath + '/api/instances/' + this.flow.instance_id + '/runs/' + selectedRunId + '/' + suffix
                }

                return Waterline.basePath + '/api/instances/' + this.flow.instance_id + '/' + suffix
            }

            return Waterline.basePath + '/api/flows/' + (this.flow.run_id || this.flow.id) + '/' + suffix
        },

        historyExportEndpoint() {
            if (!this.flow || this.flow.engine_source !== 'v2') {
                return null
            }

            if (this.flow.instance_id) {
                const selectedRunId = this.flow.selected_run_id || this.flow.run_id || this.flow.id

                if (!selectedRunId) {
                    return null
                }

                return Waterline.basePath + '/api/instances/' + this.flow.instance_id + '/runs/' + selectedRunId + '/history-export'
            }

            const runId = this.flow.run_id || this.flow.id

            return runId
                ? Waterline.basePath + '/api/flows/' + runId + '/history-export'
                : null
        },
    }
}
</script>
