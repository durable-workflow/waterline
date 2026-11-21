import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parse } from '@vue/compiler-sfc';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { reportedTaskAttentionMetrics, workflowTaskAttention } from '../../resources/js/task-attention.mjs';
import { createWaterlineI18n } from '../../resources/js/localization.mjs';
import Base from '../../resources/js/base.js';

test('run badges require the supported reason and a count at the threshold', () => {
    const recorded = {
        reason: 'workflow_task_lease_lost',
        consecutive: 3,
        threshold: 3,
        workflow_task_id: 'task-1',
        last_lease_lost_at: '2026-11-21T10:00:00Z',
    };

    assert.equal(workflowTaskAttention({ task_attention: recorded }), recorded);
    assert.equal(workflowTaskAttention({ task_attention: { ...recorded, consecutive: 2 } }), null);
    assert.equal(workflowTaskAttention({ task_attention: { ...recorded, reason: 'other' } }), null);
    assert.equal(workflowTaskAttention({}), null);
});

test('the dashboard displays reported zero and leaves absent metrics hidden', () => {
    assert.deepEqual(reportedTaskAttentionMetrics({ runs: {}, tasks: {} }), []);
    assert.deepEqual(reportedTaskAttentionMetrics({
        runs: { task_attention: 1 },
        tasks: { lease_lost_repeatedly: 0, max_lost_lease_count: 3 },
    }).map(({ key, value }) => [key, value]), [['runs', 1], ['tasks', 0], ['max', 3]]);
});

test('the workflow row marks a Server-reported unsupported type by kind', async () => {
    const file = new URL('../../resources/js/screens/flows/flow-row.vue', import.meta.url);
    const { descriptor } = parse(readFileSync(file, 'utf8'));
    const script = descriptor.script.content
        .replace(/^\s*import .*;$/m, '')
        .replace('export default', 'return');
    const component = Function('workflowTaskAttention', script)(workflowTaskAttention);
    const render = async (unsupportedReadyTask, locale = 'en') => {
        const flow = {
            engine_source: 'service', run_id: 'run-1', instance_id: 'order-1',
            class: 'Order', status: 'running', unsupported_ready_task: unsupportedReadyTask,
        };
        const app = createSSRApp({ ...component, template: descriptor.template.content }, {
            flow,
            columns: ['flow'],
        }).mixin(Base).use(createWaterlineI18n(locale));
        app.config.globalProperties.$route = { params: { type: 'running' } };
        app.component('router-link', { props: ['to'], template: '<a><slot /></a>' });
        return renderToString(app);
    };

    const task = { task_kind: 'activity', type: 'Catalog<Order>', task_queue: 'orders' };
    const activity = await render(task);
    assert.match(activity, /Unsupported activity type/);
    assert.match(activity, /title="Catalog&lt;Order&gt; · orders"/);
    assert.match(await render({ ...task, task_kind: 'workflow' }, 'es'), /Tipo de flujo de trabajo no admitido/);
    assert.doesNotMatch(await render(undefined), /Unsupported (activity|workflow) type/);
    assert.doesNotMatch(await render({ ...task, task_kind: 'query' }), /Unsupported (activity|workflow) type/);
});
