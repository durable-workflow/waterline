<template>
    <div>
        <div v-if="hasError" class="card mb-4 error-boundary-panel" role="alert" aria-live="assertive">
            <div class="card-body">
                <h6 class="mb-1">
                    <span class="badge badge-danger mr-2" aria-hidden="true">!</span>
                    {{ panelLabel }} did not render
                </h6>
                <p class="small text-muted mb-2">
                    This panel ran into an unexpected error. Other panels on the page are not affected.
                    Retry re-mounts the panel without reloading the page.
                </p>
                <p v-if="showDetails" class="small text-muted mb-2 error-boundary-detail">
                    <code>{{ errorMessage }}</code>
                </p>
                <div>
                    <button type="button" class="btn btn-sm btn-outline-secondary mr-2" @click="retry">
                        Retry
                    </button>
                    <button type="button" class="btn btn-sm btn-link" @click="showDetails = !showDetails">
                        {{ showDetails ? 'Hide' : 'Show' }} technical detail
                    </button>
                </div>
            </div>
        </div>
        <template v-else>
            <slot />
        </template>
    </div>
</template>

<script>
    export default {
        name: 'ErrorBoundary',

        props: {
            label: {
                type: String,
                default: '',
            },
        },

        data() {
            return {
                hasError: false,
                errorMessage: '',
                retryKey: 0,
                showDetails: false,
            };
        },

        computed: {
            panelLabel() {
                return this.label ? this.label : 'This panel';
            },
        },

        errorCaptured(err, vm, info) {
            this.hasError = true;
            this.errorMessage = this.describeError(err, info);

            // Surface to devtools so developers can still see the original stack.
            // eslint-disable-next-line no-console
            console.error('[ErrorBoundary:' + this.panelLabel + ']', err, info);

            // Stop propagation — the parent view should stay mounted.
            return false;
        },

        methods: {
            describeError(err, info) {
                const message = err && err.message ? err.message : String(err);
                return info ? message + ' (' + info + ')' : message;
            },

            retry() {
                this.hasError = false;
                this.errorMessage = '';
                this.showDetails = false;
                this.retryKey += 1;
                this.$emit('retry');
            },
        },
    };
</script>

<style scoped>
    .error-boundary-panel {
        border-left: 3px solid var(--danger, #aa2e28);
    }

    .error-boundary-detail code {
        word-break: break-word;
        white-space: pre-wrap;
    }
</style>
