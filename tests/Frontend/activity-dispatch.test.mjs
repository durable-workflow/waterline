import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { createWaterlineI18n } from '../../resources/js/localization.mjs';
import Base from '../../resources/js/base.js';
import * as dispatch from '../../resources/js/activity-dispatch.mjs';
import { operatorPermission } from '../../resources/js/operator-permissions.mjs';

const source = fs.readFileSync(new URL('../../resources/js/components/WorkerHealth.vue', import.meta.url), 'utf8');
// The component's own sections, cut at their fixed SFC delimiters.
function section(open, close) {
    const start = source.indexOf(open) + open.length;
    return source.slice(start, source.indexOf(close, start));
}
const script = section('<script>\n', '\n</script>');
const template = section('<template>\n', '\n</template>\n\n<script>');

function component(overrides = {}) {
    const executable = script.replace(/^import .*;$/gm, '').replace('export default', 'return');
    const imports = {
        axios: overrides.axios || { get: async () => ({ data: {} }), post: async () => ({ data: {} }) },
        Swal: { showValidationMessage() {} },
        escapeDialogHtml: (value) => String(value),
        showDialogValidationText() {},
        operatorPermission,
        dispatch,
    };
    return Function(...Object.keys(imports), executable)(...Object.values(imports));
}

const PAUSED = {
    state: 'paused',
    paused: true,
    reason: 'payments vendor outage',
    paused_at: '2026-11-12T10:00:00.123456Z',
    paused_by: { type: 'auth:token', id: 'role:operator', label: 'Operator' },
    resumed_at: null,
    resumed_by: null,
};

const RESUMED = {
    state: 'active',
    paused: false,
    reason: 'payments vendor outage',
    paused_at: '2026-11-12T10:00:00.123456Z',
    paused_by: { type: 'auth:token', id: 'role:operator', label: 'Operator' },
    resumed_at: '2026-11-12T11:00:00Z',
    resumed_by: { type: 'auth:token', id: 'role:admin' },
};

const NEVER_PAUSED = {
    state: 'active', paused: false, reason: null, paused_at: null, paused_by: null, resumed_at: null, resumed_by: null,
};

const AVAILABLE = { available: true, reason: null };

function queue(activityDispatch) {
    const value = {
        name: 'payments',
        stats: { pollers: {}, workflow_tasks: {}, activity_tasks: {} },
        repair: {},
    };
    if (activityDispatch !== undefined) value.activity_dispatch = activityDispatch;
    return value;
}

function health(queues, control, backend = { mode: 'service', read_only: false, permissions: {} }) {
    const visibility = { available: true, namespace: 'orders', task_queues: queues };
    if (control !== undefined) visibility.activity_dispatch_control = control;
    return { status: 'healthy', backend, queue_visibility: visibility, checks: [] };
}

async function render(healthData, locale = 'en') {
    const options = component();
    return renderToString(createSSRApp({
        ...options,
        template,
        data() {
            return { ...options.data.call(this), loading: false, healthData, workers: [] };
        },
    }).mixin(Base).use(createWaterlineI18n(locale)));
}

test('a queue without activity_dispatch shows nothing new, whatever the control says', async () => {
    assert.equal(dispatch.reportedActivityDispatch(queue()), null);
    assert.equal(dispatch.reportedActivityDispatch(queue(['paused'])), null);
    assert.equal(dispatch.activityDispatchState(null), null);
    assert.deepEqual(dispatch.activityDispatchActions(queue(), AVAILABLE), { pause: false, resume: false });

    for (const control of [undefined, AVAILABLE]) {
        const html = await render(health([queue()], control));
        assert.match(html, /payments/);
        assert.doesNotMatch(html, /data-activity-dispatch/);
        assert.doesNotMatch(html, /Activity dispatch/);
        assert.doesNotMatch(html, /pause-activities|resume-activities/);
    }
});

test('state comes from state, else the paused flag', () => {
    assert.equal(dispatch.activityDispatchState(PAUSED), 'paused');
    assert.equal(dispatch.activityDispatchState(RESUMED), 'active');
    assert.equal(dispatch.activityDispatchState({ paused: true }), 'paused');
    assert.equal(dispatch.activityDispatchState({ paused: false }), 'active');
    assert.equal(dispatch.activityDispatchState({ state: 'future' }), null);
});

test('a paused queue lists the pause, a resumed queue its last pause and resume, a new queue nothing', () => {
    assert.deepEqual(dispatch.activityDispatchDetails(PAUSED), [
        { label: 'Reason', value: 'payments vendor outage', kind: 'text' },
        { label: 'Paused at', value: '2026-11-12T10:00:00.123456Z', kind: 'timestamp' },
        { label: 'Paused by', value: 'Operator (auth:token role:operator)', kind: 'text' },
    ]);
    assert.deepEqual(dispatch.activityDispatchDetails(RESUMED).map((row) => [row.label, row.value]), [
        ['Last pause reason', 'payments vendor outage'],
        ['Last paused at', '2026-11-12T10:00:00.123456Z'],
        ['Last paused by', 'Operator (auth:token role:operator)'],
        ['Resumed at', '2026-11-12T11:00:00Z'],
        ['Resumed by', 'auth:token role:admin'],
    ]);
    assert.deepEqual(dispatch.activityDispatchDetails(NEVER_PAUSED), []);
    assert.equal(dispatch.principalLabel({ label: 'Ops' }), 'Ops');
    assert.equal(dispatch.principalLabel({}), null);
    assert.equal(dispatch.principalLabel('system'), 'system');
});

test('the paused queue renders a badge, the details, Resume and what a pause holds', async () => {
    const html = await render(health([queue(PAUSED)], AVAILABLE));
    assert.match(html, /data-activity-dispatch-badge="paused"/);
    assert.match(html, /Activities paused/);
    assert.match(html, /data-activity-dispatch="paused"/);
    assert.match(html, /payments vendor outage/);
    assert.match(html, /Operator \(auth:token role:operator\)/);
    assert.match(html, /data-queue-action="resume-activities"/);
    assert.doesNotMatch(html, /data-queue-action="pause-activities"/);
    assert.match(html, /stops new activity leases on this queue only/);
    assert.match(html, /keep their attempt count/);
    assert.match(html, /activity deadlines keep running/);
    assert.match(html, /signals, queries and updates are delivered as before/);
    assert.match(html, /not a build drain/);
});

test('an active queue offers Pause and no badge', async () => {
    const html = await render(health([queue(RESUMED)], AVAILABLE));
    assert.doesNotMatch(html, /data-activity-dispatch-badge/);
    assert.match(html, /data-activity-dispatch="active"/);
    assert.match(html, /Resumed by/);
    assert.match(html, /data-queue-action="pause-activities"/);
    assert.doesNotMatch(html, /data-queue-action="resume-activities"/);
});

test('without the capability the state is shown but no action is offered', async () => {
    for (const [reason, message] of [
        ['activity_dispatch_server_unavailable', /native Server 3\.8\.0 or later/],
        ['activity_dispatch_sdk_unavailable', /PHP SDK that can pause activity dispatch/],
        ['activity_dispatch_capability_unreadable', /could not read the Server capabilities/],
    ]) {
        const control = { available: false, reason };
        assert.deepEqual(dispatch.activityDispatchActions(queue(PAUSED), control), { pause: false, resume: false });
        const html = await render(health([queue(PAUSED)], control));
        assert.match(html, /Activities paused/);
        assert.doesNotMatch(html, /pause-activities|resume-activities/);
        assert.match(html, message);
    }

    // Embedded mode reports no control at all.
    const html = await render(health([queue(PAUSED)], undefined, { mode: 'embedded', permissions: {} }));
    assert.doesNotMatch(html, /pause-activities|resume-activities/);
});

test('an unauthorized or read-only viewer sees the state without actions', async () => {
    const forbidden = { allowed: false, reason: 'waterline_action_forbidden' };
    assert.deepEqual(dispatch.activityDispatchActions(queue(RESUMED), AVAILABLE, forbidden), { pause: false, resume: false });
    assert.equal(dispatch.activityDispatchUnavailableMessage(AVAILABLE, forbidden), 'You are not authorized to pause activity dispatch.');

    const readOnly = await render(health([queue(RESUMED)], { available: false, reason: 'waterline_read_only' },
        { mode: 'service', read_only: true, permissions: {} }));
    assert.doesNotMatch(readOnly, /pause-activities|resume-activities/);
    assert.match(readOnly, /read-only\. Pause and resume are unavailable/);

    const backendSaysReadOnly = await render(health([queue(RESUMED)], AVAILABLE,
        { mode: 'service', read_only: true, permissions: {} }));
    assert.doesNotMatch(backendSaysReadOnly, /pause-activities|resume-activities/);
});

test('the reason is optional, trimmed and limited to 1000 characters', () => {
    assert.equal(dispatch.MAX_PAUSE_REASON_LENGTH, 1000);
    assert.deepEqual(dispatch.normalizePauseReason(undefined), { valid: true, reason: null, length: 0 });
    assert.deepEqual(dispatch.normalizePauseReason('   '), { valid: true, reason: null, length: 0 });
    assert.deepEqual(dispatch.normalizePauseReason('  outage '), { valid: true, reason: 'outage', length: 6 });
    assert.equal(dispatch.normalizePauseReason('é'.repeat(1000)).valid, true);
    assert.equal(dispatch.normalizePauseReason('é'.repeat(1001)).valid, false);
    // Characters, not UTF-16 units, as the Server counts them.
    assert.equal(dispatch.normalizePauseReason('😀'.repeat(1000)).valid, true);
});

test('requests address the exact queue name under the Waterline base path', () => {
    assert.equal(dispatch.activityDispatchRequest('/waterline', queue(), 'pause'),
        '/waterline/api/v2/task-queues/payments/activity-dispatch/pause');
    assert.equal(dispatch.activityDispatchRequest('', 'billing/EU west', 'resume'),
        '/api/v2/task-queues/billing%2FEU%20west/activity-dispatch/resume');
    assert.throws(() => dispatch.activityDispatchRequest('', 'q', 'drain'), /Unknown/);
    assert.throws(() => dispatch.activityDispatchRequest('', {}, 'pause'), /no name/);
});

test('already_paused and not_paused are informational; paused and resumed succeed', () => {
    assert.equal(dispatch.activityDispatchOutcome({ outcome: 'paused' }).icon, 'success');
    assert.equal(dispatch.activityDispatchOutcome({ outcome: 'resumed' }).icon, 'success');
    const already = dispatch.activityDispatchOutcome({ outcome: 'already_paused' });
    assert.equal(already.icon, 'info');
    assert.match(already.text, /nothing changed/);
    assert.match(already.text, /original pause and its reason are kept/);
    const notPaused = dispatch.activityDispatchOutcome({ outcome: 'not_paused' });
    assert.equal(notPaused.icon, 'info');
    assert.match(notPaused.text, /was not paused; nothing changed/);
    assert.equal(dispatch.activityDispatchOutcome({}).title, 'Request completed');
});

test('403, 422, 501 and lost answers are classified for display', () => {
    const response = (status, data) => ({ response: { status, data } });
    assert.deepEqual(dispatch.activityDispatchError(response(403, { error: 'remote_authorization_failed', reason: 'forbidden', message: 'This action is unauthorized.' })),
        { kind: 'forbidden', reason: 'server_forbidden', message: 'This action is unauthorized.' });
    assert.equal(dispatch.activityDispatchError(response(403, { reason: 'waterline_read_only' })).reason, 'waterline_read_only');
    assert.deepEqual(dispatch.activityDispatchError(response(422, { message: 'The given data was invalid.', errors: { reason: ['The reason field must not be greater than 1000 characters.'] } })),
        { kind: 'validation', message: 'The reason field must not be greater than 1000 characters.' });
    assert.deepEqual(dispatch.activityDispatchError(response(422, { message: 'Invalid', remote_status: 422, remote_details: { errors: { task_queue: ['The task queue is too long.'] } } })),
        { kind: 'validation', message: 'The task queue is too long.' });
    assert.equal(dispatch.activityDispatchError(response(501, { reason: 'backend_capability_unavailable', message: 'No' })).kind, 'unsupported');
    assert.equal(dispatch.activityDispatchError(response(502, { error: 'remote_transport_failure' })).kind, 'unknown');
    assert.equal(dispatch.activityDispatchError(new Error('Network Error')).kind, 'unknown');
    assert.equal(dispatch.activityDispatchError(response(409, { message: 'Conflict' })).kind, 'failed');
});

function actionContext(options, healthData, responses) {
    const dialogs = [];
    const posts = [];
    const context = {
        $t: createWaterlineI18n('en').global.t,
        $i18n: { locale: 'en' },
        $root: { theme: 'dark' },
        healthData,
        pendingQueueActions: {},
        loads: 0,
        async $dialog(dialog) {
            dialogs.push(dialog);
            return responses.shift() || { isConfirmed: true };
        },
    };
    for (const [name, method] of Object.entries(options.methods)) context[name] = method.bind(context);
    context.loadData = async () => { context.loads += 1; };
    return { context, dialogs, posts };
}

test('pause posts the reason once and updates the card from the answer', async () => {
    const posts = [];
    const options = component({
        axios: {
            async post(url, body) {
                posts.push([url, body]);
                return { data: { outcome: 'paused', task_queue: 'payments', activity_dispatch: PAUSED } };
            },
        },
    });
    const taskQueue = queue(RESUMED);
    const { context, dialogs } = actionContext(options, health([taskQueue], AVAILABLE), [{ isConfirmed: true, value: { reason: 'outage' } }]);

    await context.pauseActivityDispatch(taskQueue);

    assert.deepEqual(posts, [['/api/v2/task-queues/payments/activity-dispatch/pause', { reason: 'outage' }]]);
    assert.equal(taskQueue.activity_dispatch, PAUSED);
    assert.match(dialogs[0].html, /maxlength="1000"/);
    assert.match(dialogs[0].html, /stops new activity leases/);
    assert.equal(dialogs[1].title, 'Activity dispatch paused');
    assert.equal(dialogs[1].icon, 'success');
    assert.deepEqual(context.pendingQueueActions, {});
    assert.equal(context.loads, 0);
});

test('a cancelled confirmation sends nothing and an empty reason sends no reason', async () => {
    const posts = [];
    const options = component({ axios: { async post(url, body) { posts.push(body); return { data: { outcome: 'already_paused', activity_dispatch: PAUSED } }; } } });
    const taskQueue = queue(PAUSED);

    let { context } = actionContext(options, health([taskQueue], AVAILABLE), [{ isConfirmed: false }, { isConfirmed: false }]);
    await context.pauseActivityDispatch(taskQueue);
    await context.resumeActivityDispatch(taskQueue);
    assert.deepEqual(posts, []);

    let dialogs;
    ({ context, dialogs } = actionContext(options, health([taskQueue], AVAILABLE), [{ isConfirmed: true, value: { reason: null } }]));
    await context.pauseActivityDispatch(taskQueue);
    assert.deepEqual(posts, [{}]);
    assert.equal(dialogs[1].title, 'Already paused');
    assert.equal(dialogs[1].icon, 'info');
});

test('resume reports not_paused as information', async () => {
    const options = component({ axios: { async post() { return { data: { outcome: 'not_paused', activity_dispatch: NEVER_PAUSED } }; } } });
    const taskQueue = queue(NEVER_PAUSED);
    const { context, dialogs } = actionContext(options, health([taskQueue], AVAILABLE), []);

    await context.resumeActivityDispatch(taskQueue);

    assert.match(dialogs[0].text, /leased again/);
    assert.equal(dialogs[1].title, 'Not paused');
    assert.equal(dialogs[1].icon, 'info');
});

test('a refused pause shows why and reads the queues again', async () => {
    for (const [status, data, title, text] of [
        [403, { error: 'remote_authorization_failed', reason: 'forbidden' }, 'Not authorized', /operator or admin role/],
        [403, { reason: 'waterline_read_only' }, 'Not authorized', /configured read-only/],
        [422, { message: 'The given data was invalid.', errors: { reason: ['Too long.'] } }, 'The request was not accepted', /^Too long\.$/],
        [501, { reason: 'backend_capability_unavailable', message: 'The connected Server does not advertise it.' }, 'Activity dispatch pause unavailable', /does not advertise/],
        [504, { error: 'remote_transport_failure' }, 'Command outcome unknown', /Inspect the task queue/],
    ]) {
        const options = component({ axios: { async post() { throw { response: { status, data } }; } } });
        const taskQueue = queue(RESUMED);
        const { context, dialogs } = actionContext(options, health([taskQueue], AVAILABLE), [{ isConfirmed: true, value: { reason: null } }]);

        await context.pauseActivityDispatch(taskQueue);

        assert.equal(dialogs[1].title, title, `${status}`);
        assert.equal(dialogs[1].icon, 'error');
        assert.match(dialogs[1].text, text);
        assert.equal(taskQueue.activity_dispatch, RESUMED);
        assert.equal(context.loads, 1);
        assert.deepEqual(context.pendingQueueActions, {});
    }
});

test('every new message is translated', async () => {
    const html = await render(health([queue(PAUSED)], AVAILABLE), 'uk');
    assert.match(html, /Activity призупинено/);
    assert.match(html, /Поновити activity/);
});
