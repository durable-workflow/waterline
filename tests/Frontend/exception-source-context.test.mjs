import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import { parse } from '@vue/compiler-sfc';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { createWaterlineI18n } from '../../resources/js/localization.mjs';

const source = fs.readFileSync(new URL('../../resources/js/components/ExceptionSourceContext.vue', import.meta.url), 'utf8');
const { descriptor } = parse(source);
const component = Function(descriptor.script.content.replace('export default', 'return'))();
component.template = descriptor.template.content;
const render = props => renderToString(createSSRApp(component, props).use(createWaterlineI18n('en')));

test('source highlights the actual throwing line at file start, middle and end', async () => {
    for (const [start, throwing, count] of [[1, 1, 4], [2, 5, 7], [7, 10, 4]]) {
        const html = await render({ context: {
            status: 'available', start_line: start, throw_line: throwing,
            excerpt: Array.from({ length: count }, (_, i) => 'source line ' + (start + i)).join('\n'),
        }, tracePreview: 'separate trace' });
        assert.equal((html.match(/aria-current="true"/g) || []).length, 1);
        assert.match(html, new RegExp('aria-current="true" data-line="' + throwing + '"'));
        assert.ok(html.includes('source line ' + throwing));
        assert.ok(html.includes('separate trace'));
    }
});

test('source and trace escape hostile markup', async () => {
    const hostile = '<script>alert(1)</script><img src=x onerror=alert(2)>';
    const html = await render({ context: {
        status: 'available', start_line: 1, throw_line: 1, excerpt: hostile,
    }, tracePreview: hostile });
    assert.doesNotMatch(html, /<script|<img| onerror="/);
    assert.equal((html.match(/&lt;script&gt;/g) || []).length, 2);
    assert.ok(html.includes('&lt;img'));
});

test('missing or malformed source leaves an explicit unavailable state and trace', async () => {
    for (const context of [null, { status: 'unavailable' }, {
        status: 'available', excerpt: 'source', start_line: '<img>', throw_line: 1,
    }]) {
        const html = await render({ context, tracePreview: 'preserved trace' });
        assert.ok(html.includes('Unavailable'));
        assert.ok(html.includes('preserved trace'));
        assert.doesNotMatch(html, /wl-exception-source__line--throw/);
    }
});
