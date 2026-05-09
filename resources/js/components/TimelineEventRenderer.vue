<template>
    <div class="timeline-event border-left pl-3 py-2" :class="eventKindClass">
        <div class="d-flex align-items-start">
            <div class="flex-shrink-0 mr-2">
                <span class="badge" :class="eventBadgeClass">
                    {{ event.type }}
                </span>
            </div>
            <div class="flex-grow-1">
                <div class="event-summary mb-1">
                    <strong>{{ event.summary || event.type }}</strong>
                    <span v-if="event.recorded_at" class="text-muted small ml-2">
                        {{ formatTimestamp(event.recorded_at) }}
                    </span>
                </div>

                <!-- Activity Events -->
                <div v-if="isActivityEvent" class="event-details small">
                    <div v-if="event.activity_type || event.activity_class">
                        <span class="text-muted">Activity:</span>
                        <code class="ml-1">{{ event.activity_type || event.activity_class }}</code>
                    </div>
                    <div v-if="event.activity_execution_id">
                        <span class="text-muted">Execution ID:</span>
                        <a
                            href="#"
                            class="ml-1"
                            @click.prevent="$emit('drill-activity', event.activity_execution_id)">
                            {{ event.activity_execution_id.substring(0, 8) }}...
                        </a>
                    </div>
                    <div v-if="event.activity_status">
                        <span class="text-muted">Status:</span>
                        <span class="ml-1">{{ event.activity_status }}</span>
                    </div>
                </div>

                <!-- Timer Events -->
                <div v-else-if="isTimerEvent" class="event-details small">
                    <div v-if="event.timer_id">
                        <span class="text-muted">Timer ID:</span>
                        <code class="ml-1">{{ event.timer_id.substring(0, 8) }}...</code>
                    </div>
                    <div v-if="event.delay_seconds">
                        <span class="text-muted">Delay:</span>
                        <span class="ml-1">{{ formatDuration(event.delay_seconds) }}</span>
                    </div>
                </div>

                <!-- Child Workflow Events -->
                <div v-else-if="isChildEvent" class="event-details small">
                    <div v-if="event.child_workflow_type || event.child_workflow_class">
                        <span class="text-muted">Child Type:</span>
                        <code class="ml-1">{{ event.child_workflow_type || event.child_workflow_class }}</code>
                    </div>
                    <div v-if="event.child_workflow_run_id">
                        <span class="text-muted">Run ID:</span>
                        <a
                            href="#"
                            class="ml-1"
                            @click.prevent="$emit('drill-child', event.child_workflow_run_id)">
                            {{ event.child_workflow_run_id.substring(0, 8) }}...
                        </a>
                    </div>
                    <div v-if="event.child_status">
                        <span class="text-muted">Status:</span>
                        <span class="ml-1">{{ event.child_status }}</span>
                    </div>
                </div>

                <!-- Signal Events -->
                <div v-else-if="isSignalEvent" class="event-details small">
                    <div v-if="event.signal_name">
                        <span class="text-muted">Signal:</span>
                        <code class="ml-1">{{ event.signal_name }}</code>
                    </div>
                    <div v-if="event.signal_id">
                        <span class="text-muted">Signal ID:</span>
                        <code class="ml-1">{{ event.signal_id.substring(0, 8) }}...</code>
                    </div>
                    <div v-if="commandPrincipalLabel">
                        <span class="text-muted">Principal:</span>
                        <span class="ml-1">{{ commandPrincipalLabel }}</span>
                    </div>
                </div>

                <!-- Update Events -->
                <div v-else-if="isUpdateEvent" class="event-details small">
                    <div v-if="event.update_name">
                        <span class="text-muted">Update:</span>
                        <code class="ml-1">{{ event.update_name }}</code>
                    </div>
                    <div v-if="commandPrincipalLabel">
                        <span class="text-muted">Principal:</span>
                        <span class="ml-1">{{ commandPrincipalLabel }}</span>
                    </div>
                </div>

                <!-- Command Events -->
                <div v-else-if="isCommandEvent" class="event-details small">
                    <div v-if="commandSourceLabel">
                        <span class="text-muted">Actor:</span>
                        <span class="ml-1">{{ commandSourceLabel }}</span>
                    </div>
                    <div v-if="commandPrincipalLabel">
                        <span class="text-muted">Principal:</span>
                        <span class="ml-1">{{ commandPrincipalLabel }}</span>
                    </div>
                    <div v-if="commandStatusLabel">
                        <span class="text-muted">Command:</span>
                        <span class="ml-1">{{ commandStatusLabel }}</span>
                    </div>
                    <div v-if="commandRequestLabel">
                        <span class="text-muted">Request:</span>
                        <code class="ml-1">{{ commandRequestLabel }}</code>
                    </div>
                </div>

                <!-- Version Marker Events -->
                <div v-else-if="isVersionEvent" class="event-details small">
                    <div v-if="event.version_change_id">
                        <span class="text-muted">Change ID:</span>
                        <code class="ml-1">{{ event.version_change_id }}</code>
                    </div>
                    <div v-if="event.version !== null">
                        <span class="text-muted">Version:</span>
                        <span class="ml-1">{{ event.version }}</span>
                    </div>
                </div>

                <!-- Search Attributes -->
                <div v-else-if="event.type === 'SearchAttributesUpserted'" class="event-details small">
                    <span class="text-muted">Search attributes updated</span>
                </div>

                <!-- Side Effect -->
                <div v-else-if="event.type === 'SideEffectRecorded'" class="event-details small">
                    <span class="text-muted">Side effect recorded</span>
                </div>

                <!-- Failure Events -->
                <div v-if="hasFailure" class="event-details small text-danger mt-1">
                    <div v-if="event.exception_class">
                        <span class="text-muted">Exception:</span>
                        <code class="ml-1">{{ event.exception_class }}</code>
                    </div>
                    <div v-if="event.message">
                        <span class="text-muted">Message:</span>
                        <span class="ml-1">{{ event.message }}</span>
                    </div>
                </div>

                <!-- Payload Inspector -->
                <div v-if="hasPayload" class="mt-2">
                    <PayloadInspector
                        :payload="event.payload"
                        :collapse-threshold="300"
                        :start-collapsed="true" />
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import PayloadInspector from './PayloadInspector.vue';

export default {
    name: 'TimelineEventRenderer',

    components: {
        PayloadInspector
    },

    props: {
        event: {
            type: Object,
            required: true
        }
    },

    computed: {
        eventKindClass() {
            return `event-kind-${this.event.kind || 'workflow'}`;
        },

        eventBadgeClass() {
            const kind = this.event.kind || 'workflow';
            const type = this.event.type;

            if (type && (type.includes('Failed') || type.includes('Rejected') || type.includes('TimedOut'))) {
                return 'badge-danger';
            }
            if (type && (type.includes('Completed') || type.includes('Accepted') || type.includes('Fired') || type.includes('Satisfied'))) {
                return 'badge-success';
            }
            if (type && (type.includes('Cancelled') || type.includes('Terminated'))) {
                return 'badge-warning';
            }
            if (type && (type.includes('Scheduled') || type.includes('Started') || type.includes('Opened'))) {
                return 'badge-info';
            }

            return {
                'activity': 'badge-primary',
                'timer': 'badge-secondary',
                'child': 'badge-info',
                'signal': 'badge-warning',
                'update': 'badge-success',
                'command': 'badge-dark',
                'version': 'badge-light',
                'side_effect': 'badge-light',
                'failure': 'badge-danger'
            }[kind] || 'badge-secondary';
        },

        isActivityEvent() {
            return this.event.kind === 'activity';
        },

        isTimerEvent() {
            return this.event.kind === 'timer';
        },

        isChildEvent() {
            return this.event.kind === 'child';
        },

        isSignalEvent() {
            return this.event.kind === 'signal';
        },

        isUpdateEvent() {
            return this.event.kind === 'update';
        },

        isCommandEvent() {
            return this.event.kind === 'command';
        },

        isVersionEvent() {
            return this.event.kind === 'version';
        },

        commandSourceLabel() {
            const command = this.event.command || {};
            const caller = this.firstPresent(command.caller_label, this.event.caller_label, command.source);
            const authStatus = this.firstPresent(command.auth_status, this.event.auth_status);
            const authMethod = this.firstPresent(command.auth_method, this.event.auth_method);
            const auth = [authStatus, authMethod ? `via ${authMethod}` : null]
                .filter(Boolean)
                .join(' ');

            return [caller, auth].filter(Boolean).join(' / ');
        },

        commandPrincipalLabel() {
            const command = this.event.command || {};
            const label = this.firstPresent(command.principal_label, this.event.principal_label);
            const id = this.firstPresent(command.principal_id, this.event.principal_id);
            const type = this.firstPresent(command.principal_type, this.event.principal_type);
            const identity = [type, id].filter(Boolean).join(' / ');

            return [label, identity && identity !== label ? identity : null].filter(Boolean).join(' / ');
        },

        commandStatusLabel() {
            const command = this.event.command || {};
            const parts = [
                this.firstPresent(command.type, this.event.command_type),
                this.firstPresent(command.sequence, this.event.command_sequence) !== null
                    ? `#${this.firstPresent(command.sequence, this.event.command_sequence)}`
                    : null,
                this.firstPresent(command.status, this.event.command_status),
                this.firstPresent(command.outcome, this.event.command_outcome),
                this.firstPresent(command.target_name, this.event.signal_name, this.event.update_name)
            ].filter((value) => value !== null && value !== undefined && value !== '');

            return parts.join(' / ');
        },

        commandRequestLabel() {
            const command = this.event.command || {};
            const route = this.firstPresent(command.request_route_name, this.event.request_route_name);
            const method = this.firstPresent(command.request_method, this.event.request_method);
            const path = this.firstPresent(command.request_path, this.event.request_path);
            const request = [method, path].filter(Boolean).join(' ');

            return [route, request].filter(Boolean).join(' / ');
        },

        hasFailure() {
            return this.event.failure_id || this.event.exception_class || this.event.message;
        },

        hasPayload() {
            return this.event.payload !== null && this.event.payload !== undefined;
        }
    },

    methods: {
        formatTimestamp(timestamp) {
            if (!timestamp) return '';
            try {
                return new Date(timestamp).toLocaleString();
            } catch (e) {
                return timestamp;
            }
        },

        formatDuration(seconds) {
            if (seconds < 60) {
                return `${seconds}s`;
            } else if (seconds < 3600) {
                return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
            } else {
                const hours = Math.floor(seconds / 3600);
                const minutes = Math.floor((seconds % 3600) / 60);
                return `${hours}h ${minutes}m`;
            }
        },

        firstPresent(...values) {
            for (const value of values) {
                if (value !== null && value !== undefined && value !== '') {
                    return value;
                }
            }

            return null;
        }
    }
};
</script>

<style scoped>
.timeline-event {
    border-left-width: 3px !important;
    transition: background-color 0.2s;
}

.timeline-event:hover {
    background-color: rgba(0, 0, 0, 0.02);
}

.event-kind-activity {
    border-left-color: #007bff !important;
}

.event-kind-timer {
    border-left-color: #6c757d !important;
}

.event-kind-child {
    border-left-color: #17a2b8 !important;
}

.event-kind-signal {
    border-left-color: #ffc107 !important;
}

.event-kind-update {
    border-left-color: #28a745 !important;
}

.event-kind-command {
    border-left-color: #343a40 !important;
}

.event-kind-failure {
    border-left-color: #dc3545 !important;
}

.event-kind-workflow {
    border-left-color: #6f42c1 !important;
}

.event-details {
    color: #6c757d;
}

.event-details div {
    margin-bottom: 0.25rem;
}

.event-details code {
    font-size: 0.8rem;
    background-color: #f8f9fa;
    padding: 0.1rem 0.3rem;
    border-radius: 0.2rem;
}

.event-details a {
    text-decoration: none;
}

.event-details a:hover {
    text-decoration: underline;
}
</style>
