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

                <div class="row mb-2" v-if="!flow.is_current_run && flow.current_run_id">
                    <div class="col-md-2"><strong>Current Run</strong></div>
                    <div class="col">
                        <router-link :to="{ name: flowRouteName(flow.current_run_status_bucket, flow.current_run_status), params: { flowId: flow.current_run_id } }">
                            {{ flow.current_run_id }}
                        </router-link>
                        <span v-if="flow.current_run_status">
                            ({{ flow.current_run_status }}<span v-if="flow.current_run_status_bucket"> / {{ flow.current_run_status_bucket }}</span>)
                        </span>
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
                            <router-link v-if="entry.route_id && entry.status"
                                :to="{ name: flowRouteName(entry.status_bucket, entry.status), params: { flowId: entry.route_id } }">
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
                            <th scope="col">Recorded At</th>
                            <th scope="col">Kind</th>
                            <th scope="col">Summary</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="entry in flow.timeline" :key="entry.id">
                            <td>{{ timestamp(entry.recorded_at) }}</td>
                            <td>{{ entry.kind }}</td>
                            <td>{{ entry.summary }}</td>
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
                            <th scope="col">Type</th>
                            <th scope="col">Target</th>
                            <th scope="col">Outcome</th>
                            <th scope="col">Status</th>
                            <th scope="col">Accepted At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="command in flow.commands" :key="command.id">
                            <td>{{ command.type }}</td>
                            <td>{{ command.target_name || command.target_scope }}</td>
                            <td>{{ command.outcome || '-' }}</td>
                            <td>{{ command.status }}</td>
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
                                            <b>{{ exception.__constructor }}("{{ exception.message }}")</b><br />
                                            <span style="opacity: 0.8">in {{ exception.file }} (line {{ exception.line
                                            }})</span><br /><br />
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

        this.loadFlow(this.$route.params.flowId);

        document.title = "Waterline - Flow Detail";
    },

    watch: {
        '$route.params.flowId'(flowId) {
            if (flowId) {
                this.loadFlow(flowId)
            }
        }
    },

    methods: {
        /**
         * Load a flow by the given ID.
         */
        loadFlow(id) {
            this.ready = false;

            return this.$http.get(Waterline.basePath + '/api/flows/' + id)
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

        duration(start, end) {
            return moment(end).from(moment(start), true)
        },

        isClosed(flow) {
            return ['completed', 'continued', 'failed', 'cancelled', 'terminated'].includes(flow.status)
        },

        hasDetailValue(value) {
            return value !== null && value !== undefined && value !== ''
        },

        flowRouteName(statusBucket, status) {
            let type = statusBucket

            if (!type) {
                type = ['failed', 'cancelled', 'terminated'].includes(status)
                    ? 'failed'
                    : (status === 'completed' ? 'completed' : 'running')
            }

            return type + '-flows-preview'
        },

        showResult(result) {
            Swal.fire({
                title: 'Activity Result',
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

        lineageEntries() {
            const parents = (this.flow.parents || []).map((parent) => ({
                key: 'parent-' + (parent.id || parent.parent_workflow_run_id || parent.parent_workflow_id),
                label: this.isContinuedParent(parent) ? 'Continued from' : 'Parent',
                display_id: parent.workflow_run_id || parent.parent_workflow_run_id || parent.parent_workflow_id,
                route_id: parent.workflow_run_id || parent.parent_workflow_run_id || null,
                run_number: parent.run_number,
                status: parent.status,
                status_bucket: parent.status_bucket,
            }))

            const continued = (this.flow.continuedWorkflows || []).map((link) => ({
                key: 'continued-' + (link.id || link.child_workflow_run_id || link.child_workflow_id),
                label: 'Continued as',
                display_id: link.workflow_run_id || link.child_workflow_run_id || link.child_workflow_id,
                route_id: link.workflow_run_id || link.child_workflow_run_id || null,
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

        async issueCommand(commandType) {
            const copy = {
                repair: {
                    title: 'Repair run?',
                    text: 'This recreates the durable next task for the selected run when liveness shows repair is needed.',
                    confirmButtonText: 'Repair run',
                },
                cancel: {
                    title: 'Cancel run?',
                    text: 'This action applies to the selected run only.',
                    confirmButtonText: 'Cancel run',
                },
                terminate: {
                    title: 'Terminate run?',
                    text: 'This action applies to the selected run only.',
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
                const response = await this.$http.post(Waterline.basePath + '/api/flows/' + this.flow.id + '/' + commandType)
                await this.loadFlow(this.flow.id)

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
    }
}
</script>
