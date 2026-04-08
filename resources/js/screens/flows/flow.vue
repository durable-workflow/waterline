<template>
    <div>
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5 v-if="!ready">Flow Preview</h5>
                <h5 v-if="ready">{{ flow.class }}</h5>

                <div class="d-flex align-items-center">
                    <button v-if="ready && flow.can_repair"
                        class="btn btn-outline-info btn-sm mr-2"
                        @click="issueCommand('repair')">
                        Repair
                    </button>

                    <button v-if="ready && flow.can_issue_terminal_commands"
                        class="btn btn-outline-warning btn-sm mr-2"
                        @click="issueCommand('cancel')">
                        Cancel
                    </button>

                    <button v-if="ready && flow.can_issue_terminal_commands"
                        class="btn btn-outline-danger btn-sm mr-3"
                        @click="issueCommand('terminate')">
                        Terminate
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

                <div class="row mb-2" v-if="flow.declared_signals && flow.declared_signals.length">
                    <div class="col-md-2"><strong>Signals</strong></div>
                    <div class="col">{{ flow.declared_signals.join(', ') }}</div>
                </div>

                <div class="row mb-2" v-if="flow.declared_updates && flow.declared_updates.length">
                    <div class="col-md-2"><strong>Updates</strong></div>
                    <div class="col">{{ flow.declared_updates.join(', ') }}</div>
                </div>

                <div class="row mb-2" v-if="hasDetailValue(flow.declared_contract_source)">
                    <div class="col-md-2"><strong>Contract Source</strong></div>
                    <div class="col">{{ contractSourceLabel(flow.declared_contract_source) }}</div>
                </div>

                <div class="row mb-2" v-if="hasDetailValue(flow.compatibility) || flow.compatibility_supported === false || hasDetailValue(flow.compatibility_supported_in_fleet)">
                    <div class="col-md-2"><strong>Compatibility</strong></div>
                    <div class="col">
                        <div>{{ flow.compatibility || '-' }}</div>
                        <div class="small text-muted" v-if="flow.compatibility_supported === false && hasDetailValue(flow.compatibility_reason)">
                            This build: {{ flow.compatibility_reason }}
                        </div>
                        <div class="small text-muted" v-if="hasDetailValue(flow.compatibility_supported_in_fleet)">
                            Fleet: {{ compatibilityFleetSummary(flow.compatibility_supported_in_fleet) }}
                        </div>
                        <div class="small text-muted" v-if="flow.compatibility_supported_in_fleet === false && hasDetailValue(flow.compatibility_fleet_reason)">
                            {{ flow.compatibility_fleet_reason }}
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
                <h5>Waits</h5>

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
                                <div class="small text-muted" v-if="resumeSourceSummary(wait.resume_source_kind, wait.resume_source_id)">
                                    resume / {{ resumeSourceSummary(wait.resume_source_kind, wait.resume_source_id) }}
                                </div>
                                <div class="small text-muted" v-if="hasDetailValue(wait.sequence)">
                                    step {{ wait.sequence }}
                                </div>
                                <div class="small text-muted" v-if="hasDetailValue(wait.signal_wait_id) || hasDetailValue(wait.command_sequence)">
                                    <span v-if="hasDetailValue(wait.signal_wait_id)">signal wait / {{ wait.signal_wait_id }}</span>
                                    <span v-if="hasDetailValue(wait.command_sequence)">
                                        <span v-if="hasDetailValue(wait.signal_wait_id)"> | </span>command / #{{ wait.command_sequence }}
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
                            <th scope="col">Result</th>
                            <th scope="col">Accepted At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="command in flow.commands" :key="command.id">
                            <td>{{ hasDetailValue(command.sequence) ? '#' + command.sequence : '-' }}</td>
                            <td>{{ command.type }}</td>
                            <td>{{ command.target_name || command.target_scope }}</td>
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
                            <th scope="col">Queue</th>
                            <th scope="col">Result</th>
                            <th scope="col">Recorded At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="activity in activityRows()" :key="activity.id">
                            <td>{{ activity.type || activity.class }}</td>
                            <td>{{ activity.status || '-' }}</td>
                            <td>{{ activity.queue || '-' }}</td>
                            <td><button title="View Result" class="btn btn-outline-primary ml-auto"
                                    @click="showResult(activity.result)">View</button></td>
                            <td>{{ timestamp(activity.closed_at || activity.started_at || activity.created_at) }}</td>
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
                case 'live_definition':
                    return 'Live workflow definition'
                case 'unavailable':
                    return 'Unavailable'
                default:
                    return source
            }
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

        waitRows() {
            return this.flow.waits || []
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

            if (entry.timer && this.hasDetailValue(entry.timer.status)) {
                details.push('timer / ' + entry.timer.status)
            }

            if (this.hasDetailValue(entry.child_status)) {
                details.push('child / ' + entry.child_status)
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

        taskTarget(task) {
            if (this.hasDetailValue(task.activity_type)) {
                return task.activity_type
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

            try {
                const response = await this.$http.post(this.commandEndpoint(commandType))
                await this.loadRouteFlow()

                const successText = commandType === 'repair'
                    ? (
                        response.data.outcome === 'repair_dispatched'
                            ? 'Waterline recreated the durable task and re-dispatched it.'
                            : 'Waterline recorded the repair command, and no new task was needed.'
                    )
                    : 'Waterline recorded the command durably.'

                Swal.fire({
                    title: 'Command accepted',
                    text: successText,
                    icon: 'success',
                    confirmButtonText: 'Okay',
                    background: '#1c1c1c',
                })
            } catch (error) {
                const message = error.response
                    && error.response.data
                    && error.response.data.rejection_reason
                    ? error.response.data.rejection_reason
                    : 'Command was rejected.'

                Swal.fire({
                    title: 'Command rejected',
                    text: message,
                    icon: 'error',
                    confirmButtonText: 'Okay',
                    background: '#1c1c1c',
                })
            }
        },

        commandEndpoint(commandType) {
            if (this.flow.instance_id) {
                const selectedRunId = this.flow.selected_run_id || this.flow.run_id || this.flow.id

                if (selectedRunId) {
                    return Waterline.basePath + '/api/instances/' + this.flow.instance_id + '/runs/' + selectedRunId + '/' + commandType
                }

                return Waterline.basePath + '/api/instances/' + this.flow.instance_id + '/' + commandType
            }

            return Waterline.basePath + '/api/flows/' + (this.flow.run_id || this.flow.id) + '/' + commandType
        },
    }
}
</script>
