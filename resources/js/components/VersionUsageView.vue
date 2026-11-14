<template>
    <section class="version-usage" :aria-labelledby="'version-usage-title'">
        <h1 id="version-usage-title">{{ $t('Version usage') }}</h1>
        <p>{{ $t('Inspect recorded version decisions in open runs for one change point.') }}</p>
        <form class="usage-filters" @submit.prevent="search" @input="invalidate" @change="invalidate">
            <label>{{ $t('Namespace') }}
                <input v-model="draft.namespace" class="form-control" :readonly="fixedNamespace !== null" :placeholder="$t('Default namespace')" />
            </label>
            <label>{{ $t('Workflow type') }}
                <input v-model="draft.workflowType" class="form-control" required />
            </label>
            <label>{{ $t('Change point') }}
                <input v-model="draft.changeId" class="form-control" required />
            </label>
            <label>{{ $t('Minimum supported version') }}
                <input v-model="draft.minimum" class="form-control" type="number" min="-2147483648" max="2147483647" step="1" required aria-describedby="usage-minimum-help" />
            </label>
            <label>{{ $t('State') }}
                <select v-model="draft.state" class="form-control">
                    <option value="">{{ $t('All states') }}</option>
                    <option v-for="state in states" :key="state" :value="state">{{ $t(stateLabels[state]) }}</option>
                </select>
            </label>
            <button type="submit" class="btn btn-primary" :disabled="loading">{{ $t('Search') }}</button>
        </form>
        <p id="usage-minimum-help" class="usage-note">{{ $t('Use the proposed minimum supported version; use 1 for a patched() legacy branch.') }}</p>
        <p class="usage-note">{{ $t('Live observation: counts can change between pages. Restart after draining or fencing admissions before a removal decision. Unknown runs need investigation.') }}</p>
        <div v-if="error" class="alert alert-danger" role="alert">
            <p>{{ $t(error) }}</p>
            <button v-if="lastAttempt" type="button" class="btn btn-secondary" @click="retry" :disabled="loading">{{ $t('Retry') }}</button>
        </div>
        <output v-if="loading">{{ $t('Loading...') }}</output>
        <template v-if="page">
            <dl class="usage-counts" :aria-label="$t('All-state counts')">
                <div v-for="state in ['total', ...states]" :key="state" :class="{'usage-count--unknown': state === 'unknown'}">
                    <dt>{{ $t(stateLabels[state]) }}</dt><dd>{{ page.counts[state].toLocaleString($i18n.locale) }}</dd>
                </div>
            </dl>
            <section class="usage-table" tabindex="0" :aria-label="$t('Open runs')">
                <table class="table">
                    <thead><tr>
                        <th scope="col">{{ $t('Instance / run') }}</th><th scope="col">{{ $t('Status') }}</th>
                        <th scope="col">{{ $t('State') }}</th><th scope="col">{{ $t('Recorded version') }}</th><th scope="col">{{ $t('Unknown reason') }}</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="run in page.runs" :key="run.run_id">
                            <td><router-link :to="runRoute(run)">{{ run.instance_id }}<br /><code>{{ run.run_id }}</code></router-link></td>
                            <td>{{ $t(statusLabels[run.status]) }}</td><td>{{ $t(stateLabels[run.state]) }}</td>
                            <td>{{ run.recorded_version === null ? '—' : run.recorded_version }}</td>
                            <td>{{ run.unknown_reason === null ? '—' : $t(reasons[run.unknown_reason]) }}</td>
                        </tr>
                        <tr v-if="page.runs.length === 0"><td colspan="5">{{ $t('No matching open runs on this page.') }}</td></tr>
                    </tbody>
                </table>
            </section>
            <nav class="usage-paging" :aria-label="$t('Version usage pages')">
                <button type="button" class="btn btn-secondary" @click="search" :disabled="loading">{{ $t('Restart query') }}</button>
                <button type="button" class="btn btn-secondary" @click="next" :disabled="loading || !page.has_more">{{ $t('Next page') }}</button>
            </nav>
        </template>
    </section>
</template>

<script>
import http from '../http.mjs';
import {usageStates, usageReasons, usageQuery, usageParams, validateUsagePage} from '../version-usage.mjs';

export default {
    data() {
        const namespace = this.$root.bootstrap?.operator_scope?.namespace ?? null;
        return {
            fixedNamespace: namespace,
            draft: {namespace: namespace ?? '', workflowType: '', changeId: '', minimum: '1', state: ''},
            states: usageStates, reasons: usageReasons,
            stateLabels: {total: 'Total open runs', legacy: 'Legacy', current: 'Current', unknown: 'Unknown'},
            statusLabels: {pending: 'Pending', running: 'Running', waiting: 'Waiting'},
            page: null, query: null, loading: false, error: null, lastAttempt: null, serial: 0, controller: null,
        };
    },
    unmounted() { this.invalidate(); },
    methods: {
        invalidate() {
            this.serial++;
            this.controller?.abort();
            this.loading = false;
            this.page = null;
            this.query = null;
            this.lastAttempt = null;
            this.error = null;
        },
        search() {
            let query;
            try { query = usageQuery(this.draft, this.fixedNamespace ?? this.draft.namespace); }
            catch (error) { this.invalidate(); this.error = error.message; return; }
            return this.read(query);
        },
        next() {
            if (!this.page?.has_more || this.loading) return;
            return this.read({...this.query, cursor: this.page.next_cursor}, {
                through: this.page.through_run_id, after: this.page.runs.at(-1).run_id,
            });
        },
        retry() {
            if (!this.lastAttempt || this.loading) return;
            return this.read(this.lastAttempt.query, this.lastAttempt.boundary);
        },
        async read(query, boundary = null) {
            const request = ++this.serial;
            this.controller?.abort();
            this.controller = new AbortController();
            this.lastAttempt = {query, boundary};
            this.loading = true;
            this.error = null;
            this.page = null;
            try {
                const response = await http.get(`${Waterline.basePath}/api/v2/workflow-version-usage`, {params: usageParams(query), signal: this.controller.signal});
                if (request !== this.serial) return;
                this.page = validateUsagePage(response.data, query, boundary);
                this.query = query;
            } catch (error) {
                if (request !== this.serial) return;
                this.error = this.readError(error.response?.status);
            } finally {
                if (request === this.serial) this.loading = false;
            }
        },
        readError(status) {
            if (status === 501) return 'Version usage is unavailable on this backend.';
            if (status === 422) return 'The query or cursor was refused. Check the filters or restart the query.';
            return 'Version usage could not be read. No counts are available.';
        },
        runRoute(run) {
            return {name: 'flow-detail-run', params: {instanceId: run.instance_id, runId: run.run_id}};
        },
    },
};
</script>

<style scoped>
.version-usage { max-width: 90rem; color: var(--wl-text); }
.version-usage h1 { font-size: 1.6rem; }
.usage-filters { display: flex; flex-wrap: wrap; gap: 1rem; align-items: end; margin: 1.5rem 0 .5rem; }
.usage-filters label { flex: 1 1 12rem; min-width: 0; margin: 0; }
.usage-filters .form-control { margin-top: .4rem; }
.usage-filters input[readonly] { background: var(--wl-surface-muted); color: var(--wl-text); opacity: 1; }
.usage-filters input::placeholder { color: var(--wl-text-muted); opacity: 1; }
.usage-note { color: var(--wl-text-muted); font-size: .9rem; }
.usage-counts { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; margin: 1.5rem 0; }
.usage-counts > div { border: 1px solid var(--wl-border); border-radius: .5rem; padding: 1rem; }
.usage-counts dt { font-size: .85rem; font-weight: 500; }
.usage-counts dd { margin: .5rem 0 0; font-size: 1.6rem; }
.usage-count--unknown { background: color-mix(in srgb, var(--wl-text) 6%, transparent); }
.usage-table { overflow-x: auto; background: var(--wl-surface); border: 1px solid var(--wl-border); border-radius: .5rem; }
.usage-table td { overflow-wrap: anywhere; min-width: 8rem; max-width: 28rem; }
.usage-table td:first-child { min-width: 20rem; }
.usage-table a { color: var(--wl-text); text-decoration: underline; }
.usage-table a:focus-visible { outline: 2px solid currentColor; outline-offset: 2px; }
.usage-paging { display: flex; gap: .75rem; margin-top: 1rem; }
@media (max-width: 600px) { .usage-counts { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
