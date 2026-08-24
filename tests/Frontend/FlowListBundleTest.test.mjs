import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { JSDOM } from 'jsdom';

const bundle = await readFile(new URL('../../public/app.js', import.meta.url), 'utf8');

const buckets = [
    { route: 'running', status: 'running' },
    { route: 'completed', status: 'completed' },
    { route: 'failed', status: 'failed' },
];

for (const bucket of buckets) {
    test(`${bucket.route} workflows render without a Lodash global`, async () => {
        const errors = [];
        const workflow = {
            id: `${bucket.route}-workflow`,
            class: `App\\Workflows\\${capitalize(bucket.route)}Workflow`,
            arguments: 'N;',
            output: 'N;',
            status: bucket.status,
            created_at: '2026-08-24T09:00:00.000000Z',
            updated_at: '2026-08-24T09:01:00.000000Z',
        };
        const dom = new JSDOM(
            '<!doctype html><html><head><meta name="csrf-token" content="test"></head>'
                + '<body><div id="waterline"><router-view></router-view></div></body></html>',
            {
                pretendToBeVisual: true,
                runScripts: 'outside-only',
                url: `http://waterline.test/waterline/${bucket.route}`,
            },
        );

        try {
            dom.window.Waterline = { path: 'waterline' };
            dom.window.XMLHttpRequest = createFakeXMLHttpRequest((url) => {
                if (url.includes('/api/saved-views')) {
                    return { data: [] };
                }

                if (url.includes(`/api/flows/${bucket.route}`)) {
                    return { data: [workflow], current_page: 1, last_page: 1 };
                }

                throw new Error(`Unexpected request: ${url}`);
            });
            dom.window.addEventListener('error', (event) => errors.push(event.error || event.message));
            dom.window.addEventListener('unhandledrejection', (event) => errors.push(event.reason));

            assert.equal('_' in dom.window, false);
            dom.window.eval(bundle);

            await waitFor(() => dom.window.document.querySelector('tbody tr'));

            const row = dom.window.document.querySelector('tbody tr');
            assert.match(row.textContent, new RegExp(`${capitalize(bucket.route)}Workflow`));
            assert.match(row.textContent, new RegExp(workflow.id));
            assert.deepEqual(errors, []);
        } finally {
            dom.window.close();
        }
    });
}

function capitalize(value) {
    return value.charAt(0).toUpperCase() + value.slice(1);
}

function createFakeXMLHttpRequest(responseFor) {
    return class FakeXMLHttpRequest {
        constructor() {
            this.onloadend = null;
            this.readyState = 0;
            this.responseText = '';
            this.responseURL = '';
            this.status = 0;
            this.statusText = '';
            this.timeout = 0;
            this.upload = { addEventListener() {} };
        }

        open(method, url) {
            this.method = method;
            this.url = url;
            this.readyState = 1;
        }

        setRequestHeader() {}

        addEventListener() {}

        getAllResponseHeaders() {
            return 'content-type: application/json\r\n';
        }

        send() {
            queueMicrotask(() => {
                try {
                    this.responseText = JSON.stringify(responseFor(this.url));
                    this.responseURL = this.url;
                    this.status = 200;
                    this.statusText = 'OK';
                } catch (error) {
                    this.status = 500;
                    this.statusText = error.message;
                }

                this.readyState = 4;
                this.onloadend?.();
            });
        }

        abort() {}
    };
}

async function waitFor(predicate, timeout = 2000) {
    const startedAt = Date.now();

    while (!predicate()) {
        if (Date.now() - startedAt > timeout) {
            throw new Error('Timed out waiting for a rendered flow row.');
        }

        await new Promise((resolve) => setTimeout(resolve, 10));
    }
}
