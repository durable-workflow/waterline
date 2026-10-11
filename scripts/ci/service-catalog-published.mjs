import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

function argument(name) {
    const index = process.argv.indexOf(name);
    assert.ok(index > 0 && process.argv[index + 1], `${name} is required`);
    return process.argv[index + 1];
}

const owner = argument('--base-url').replace(/\/$/, '');
const foreign = argument('--foreign-base-url').replace(/\/$/, '');
const evidence = path.resolve(argument('--nexus-evidence-dir'));
const output = path.resolve(argument('--output-dir'));
fs.mkdirSync(output, { recursive: true });

function load(name) {
    return JSON.parse(fs.readFileSync(path.join(evidence, name), 'utf8'));
}

const cases = [];
function collect(probe, caller, service, status) {
    assert.equal(probe.status, 200, `${caller}/${service} ${status}: producer response`);
    assert.equal(probe.body.outcome, 'pass', `${caller}/${service} ${status}: actual worker outcome`);
    assert.equal(probe.body.observations.length, 2, 'same/cross-namespace executions are required');
    for (const observation of probe.body.observations) {
        assert.equal(observation.durable_service_call.status, 200);
        assert.equal(observation.result.status, status);
        const original = observation.durable_service_call.body;
        assert.equal(original.status, status);
        assert.equal(original.namespace, 'shared');
        const id = original.id || original.service_call_id;
        assert.equal(id, observation.result.service_call_id);
        assert.ok(id && original.call_deadline_at, 'original identity and deadline are required');
        cases.push({ caller, service, status, original, id, callerResult: observation.result });
    }
}

for (const [file, caller, service] of [
    ['nexus-php-workflow-probe.json', 'php', 'python'],
    ['nexus-python-workflow-probe.json', 'python', 'php'],
    ['nexus-php-php-workflow-probe.json', 'php', 'php'],
    ['nexus-python-python-workflow-probe.json', 'python', 'python'],
]) collect(load(file), caller, service, 'completed');
for (const [file, status] of [
    ['nexus-typed-failure-probes.json', 'failed'], ['nexus-cancellation-probes.json', 'cancelled'],
]) {
    const reports = load(file).reports;
    assert.equal(reports.length, 4, 'all PHP/Python caller/service pairs are required');
    for (const report of reports) collect(report.observation, report.caller, report.service, status);
}
assert.equal(cases.length, 24);
assert.equal(new Set(cases.map(row => row.id)).size, 24, 'each case needs its original distinct call');

async function api(origin, suffix) {
    const response = await fetch(`${origin}/waterline/api/v2/services/${suffix}`, {
        redirect: 'error', signal: AbortSignal.timeout(15000),
    });
    const body = await response.json();
    return { status: response.status, body };
}

function logicalPreview(value) {
    if (value?.$type === 'map') {
        return Object.fromEntries(value.entries.map(row => [row.key, logicalPreview(row.value)]));
    }
    if (Array.isArray(value)) return value.map(logicalPreview);
    return value;
}

function originalFields(detail, original) {
    for (const field of ['namespace', 'caller_namespace', 'target_namespace', 'endpoint_name',
        'service_name', 'operation_name', 'status', 'outcome', 'accepted_at', 'call_deadline_at',
        'caller_workflow_instance_id', 'caller_workflow_run_id', 'linked_workflow_instance_id',
        'linked_workflow_run_id', 'linked_workflow_update_id']) {
        assert.equal(detail[field], original[field], `original ${field}`);
    }
    assert.ok(Array.isArray(detail.service_call_attempts));
    assert.equal(detail.retry_attempt_count, detail.service_call_attempts.length);
    const attempts = original.metadata?.service_call_attempts || original.outcome_metadata?.service_call_attempts;
    if (attempts) assert.deepEqual(detail.service_call_attempts, attempts);
    assert.equal(detail.backend.read_only, true);
    assert.equal(detail.backend.capabilities.services, true);
}

const { chromium } = createRequire(path.join(path.resolve(argument('--playwright-root')), 'package.json'))('playwright');
const browser = await chromium.launch({ args: ['--no-sandbox'],
    ...(process.env.CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.CHROMIUM_EXECUTABLE_PATH } : {}),
});
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
const page = await context.newPage();
const mutations = [];
const errors = [];
page.on('request', request => {
    if (request.url().includes('/waterline/api/') && !['GET', 'HEAD'].includes(request.method())) {
        mutations.push({ method: request.method(), url: request.url() });
    }
});
page.on('pageerror', error => errors.push(error.message));
const reports = [];
try {
    for (const row of cases) {
        for (const [scope, origin, namespace] of [['owner', owner, 'shared'], ['foreign', foreign, 'tenant-a']]) {
            const response = await api(origin, `calls/${encodeURIComponent(row.id)}`);
            const relevant = scope === 'owner' || row.original.caller_namespace === namespace;
            if (!relevant) {
                assert.equal(response.status, 404, 'unrelated observer cannot inspect the call');
                reports.push({ ...row, original: undefined, callerResult: undefined, scope, api_status: 404 });
                continue;
            }
            assert.equal(response.status, 200);
            const detail = response.body;
            assert.equal(detail.id, row.id);
            originalFields(detail, row.original);
            assert.equal(detail.operator_scope.namespace, namespace);
            assert.equal(detail.payloads_in_observer_namespace, scope === 'owner');
            if (scope === 'foreign') {
                for (const field of ['input_payload_reference', 'output_payload_reference', 'failure_payload_reference']) {
                    assert.equal(detail[field], null);
                }
                assert.equal(detail.output, undefined);
                assert.equal(detail.metadata?.nexus_result, undefined);
                assert.equal(detail.linked_run_ref.in_observer_namespace, false);
            } else if (row.status === 'completed') {
                assert.equal(detail.output_available, true);
                assert.deepEqual(logicalPreview(detail.output), {
                    language: row.service, name: 'world', greeting: 'hello, world',
                });
            }
            if (row.status === 'failed') {
                assert.equal(detail.failure_type, row.original.failure_type);
                assert.equal(detail.failure_type, row.callerResult.failure_type);
                assert.ok(detail.failure_type);
            }
            const query = new URLSearchParams({ tab: 'calls', status: row.status,
                operation_id: detail.workflow_service_operation_id });
            await page.goto(`${origin}/waterline/services?${query}`, { waitUntil: 'networkidle' });
            const tableRow = page.locator('.service-catalog-view tbody tr').filter({ hasText: row.id });
            await tableRow.getByRole('button', { name: 'View', exact: true }).click();
            const panel = page.locator('.service-catalog-view__detail');
            const deadline = detail.call_deadline_at.replace('T', ' ').replace(/Z$/, '');
            await panel.getByText(deadline, { exact: true }).waitFor();
            const text = await panel.innerText();
            for (const value of [detail.endpoint_name, detail.service_name, detail.operation_name]) assert.ok(text.includes(value));
            assert.ok(text.includes('Inspect the caller and target run histories before starting replacement work.'));
            if (row.status === 'failed') assert.ok(text.includes(detail.failure_type));
            if (scope === 'owner' && row.status === 'completed') assert.ok(text.includes('hello, world'));
            if (scope === 'foreign') {
                const target = panel.locator('.service-catalog-view__reference').filter({ hasText: 'Target Run' });
                assert.equal(await target.locator('a').count(), 0);
                assert.ok(text.includes('The result belongs to another namespace.'));
            }
            const screenshot = `${row.caller}-${row.service}-${row.status}-${row.original.caller_namespace}-${scope}.png`;
            await page.screenshot({ path: path.join(output, screenshot), fullPage: true });
            reports.push({ caller: row.caller, service: row.service, status: row.status,
                id: row.id, scope, namespace, api_status: response.status, screenshot,
                call_deadline_at: detail.call_deadline_at, failure_type: detail.failure_type,
                output_available: detail.output_available === true });
        }
    }
    assert.deepEqual(mutations, []);
    assert.deepEqual(errors, []);
    fs.writeFileSync(path.join(output, 'report.json'), JSON.stringify({
        classification: 'published_api_browser', outcome: 'pass',
        cases: reports, original_calls: cases.length, mutations, page_errors: errors,
    }, null, 2) + '\n');
} finally {
    await browser.close();
}
