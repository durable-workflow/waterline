import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import { parse } from '@vue/compiler-sfc';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';

const source = fs.readFileSync(new URL('../../resources/js/components/TimelineEventRenderer.vue', import.meta.url), 'utf8');
const { descriptor } = parse(source);
const component = Function('PayloadInspector', descriptor.script.content
    .replace(/import PayloadInspector[^;]+;/, '')
    .replace('export default', 'return'))({ template: '<button>Show payload</button>' });
component.template = descriptor.template.content;

test('timeline shows event identity and time without expanding its payload', async () => {
    const timestamp = '2026-07-22T12:00:00Z';
    const html = await renderToString(createSSRApp(component, { event: {
        id: 'run-1:history:5',
        sequence: 5,
        type: 'ActivityHeartbeatRecorded',
        recorded_at: timestamp,
        payload: { details: 'still working' },
    } }));

    assert.match(html, /ActivityHeartbeatRecorded/);
    assert.match(html, /#5/);
    assert.ok(html.includes(new Date(timestamp).toLocaleString()));
    assert.match(html, /Show payload/);
});

test('timeline escapes future event names and summaries', async () => {
    const html = await renderToString(createSSRApp(component, { event: {
        sequence: 0,
        type: '<script>future</script>',
        summary: '<img src=x onerror=alert(1)>',
    } }));

    assert.match(html, /#0/);
    assert.match(html, /&lt;script&gt;future&lt;\/script&gt;/);
    assert.match(html, /&lt;img/);
    assert.doesNotMatch(html, /<script>|<img/);
});
