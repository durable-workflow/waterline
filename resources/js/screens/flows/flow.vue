<template>
    <div>
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5 v-if="!ready">Flow Preview</h5>
                <h5 v-if="ready">{{ flow.class }}</h5>

                <a data-toggle="collapse" href="#collapseDetails" role="button">
                    Collapse
                </a>
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

                <div class="row mb-2">
                    <div class="col-md-2"><strong>Status</strong></div>
                    <div class="col">{{ flow.status }}</div>
                </div>

                <div class="row mb-2">
                    <div class="col-md-2"><strong>Started At</strong></div>
                    <div class="col">{{ timestamp(flow.created_at) }}</div>
                </div>

                <div class="row mb-2">
                    <div class="col-md-2"><strong>Completed At</strong></div>
                    <div class="col" v-if="(flow.status === 'completed' || flow.status === 'continued')">{{ timestamp(flow.updated_at) }}</div>
                    <div class="col" v-else>-</div>
                </div>

                <div class="row mb-2">
                    <div class="col-md-2"><strong>Duration</strong></div>
                    <div class="col" v-if="(flow.status === 'completed' || flow.status === 'continued')">{{ duration(flow.created_at, flow.updated_at) }}</div>
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

                <div class="row mb-2" v-if="flow.parents.length">
                    <div class="col-md-2"><strong v-if="flow.parents[0].parent_index > Number.MAX_SAFE_INTEGER">Continued From</strong><strong v-else>Parent ID</strong></div>
                    <div class="col">{{ flow.parents[0].parent_workflow_id }}</div>
                </div>

                <div class="row mb-2" v-if="flow.continuedWorkflows.length">
                    <div class="col-md-2"><strong>Continued As</strong></div>
                    <div class="col">{{ flow.continuedWorkflows[0].child_workflow_id }}</div>
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

        <div class="card mt-4" v-if="ready && (flow.status === 'completed' || flow.status === 'continued')">
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

        <div class="card mt-4" v-if="ready && flow.logs && flow.logs.length">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5>Timeline</h5>

                <a data-toggle="collapse" href="#collapseTimeline" role="button">
                    Collapse
                </a>
            </div>

            <div class="card-body code-bg text-white collapse show" id="collapseTimeline">
                <apexchart type="rangeBar" height="350" :options="chartOptions" :series="series"></apexchart>
            </div>
        </div>

        <div class="card mt-4" v-if="ready && flow.logs && flow.logs.length">
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
                            <th scope="col">Result</th>
                            <th scope="col">Completed At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="activity in flow.logs">
                            <td>{{ activity.class }}</td>
                            <td><button title="View Result" class="btn btn-outline-primary ml-auto"
                                    @click="showResult(activity.result)">View</button></td>
                            <td>{{ timestamp(activity.created_at) }}</td>
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

    methods: {
        /**
         * Load a flow by the given ID.
         */
        loadFlow(id) {
            this.ready = false;

            this.$http.get(Waterline.basePath + '/api/flows/' + id)
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

        hasDetailValue(value) {
            return value !== null && value !== undefined && value !== ''
        },

        showResult(result) {
            Swal.fire({
                title: 'Activity Result',
                text: JSON.stringify(this.unserialize(result), null, 2),
                icon: 'info',
                confirmButtonText: 'Okay',
                background: '#1c1c1c',
            })
        }
    }
}
</script>
