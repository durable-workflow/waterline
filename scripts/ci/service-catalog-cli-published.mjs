import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

export function parseExactJson(text) {
    return JSON.parse(text, (_key, value, context) => {
        if (typeof value === 'number' && Number.isInteger(value) && !Number.isSafeInteger(value)) {
            assert.equal(typeof context?.source, 'string', 'Large JSON integers require their original source');
            return {$number: context.source};
        }
        return value;
    });
}

function historyProjection(document) {
    return document.events.map(({sequence, event_type, timestamp, principal, payload}) =>
        ({sequence, event_type, timestamp, principal, payload}));
}

export function requireOriginalHistory(actual, expected, original) {
    for (const document of [actual, expected]) {
        assert.equal(document.workflow_id, original.caller_workflow_instance_id);
        assert.equal(document.run_id, original.caller_workflow_run_id);
        const starts = document.events.filter(row => row.event_type === 'ServiceCallStarted');
        const terminal = document.events.filter(row => ['ServiceCallCompleted', 'ServiceCallFailed', 'ServiceCallCancelled'].includes(row.event_type));
        assert.equal(starts.length, 1);
        assert.equal(terminal.length, 1);
        assert.equal(starts[0].payload.service_call_id, original.id || original.service_call_id);
        assert.equal(terminal[0].payload.service_call_id, original.id || original.service_call_id);
        assert.equal(terminal[0].event_type, {completed: 'ServiceCallCompleted', failed: 'ServiceCallFailed', cancelled: 'ServiceCallCancelled'}[original.status]);
    }
    assert.deepEqual(historyProjection(actual), historyProjection(expected));
}

export function requireOriginalCall(actual, expected, original) {
    for (const document of [actual, expected]) {
        assert.equal(document.id || document.service_call_id, original.id || original.service_call_id);
        for (const field of ['namespace', 'caller_namespace', 'caller_workflow_instance_id', 'caller_workflow_run_id',
            'target_namespace', 'linked_workflow_instance_id', 'linked_workflow_run_id', 'status', 'call_deadline_at']) {
            assert.equal(document[field], original[field], `Call inspection changed the original ${field}`);
        }
        assert.ok(Array.isArray(document.service_call_attempts));
        assert.equal(document.retry_attempt_count, document.service_call_attempts.length);
    }
    assert.deepEqual(actual, expected);
}

function argument(name) {
    const index = process.argv.indexOf(name);
    assert.ok(index > 0 && process.argv[index + 1], `${name} is required`);
    return path.resolve(process.argv[index + 1]);
}

function docker(args) {
    const result = spawnSync('/usr/bin/docker', args, {encoding: 'utf8', timeout: 15000});
    assert.equal(result.status, 0, 'Cannot inspect the owned test Server');
    return result.stdout;
}

function serverConnection() {
    const ids = docker(['ps', '--filter', `label=com.docker.compose.project.working_dir=${process.env.DW_NEXUS_RUN_ROOT}`,
        '--filter', 'label=com.docker.compose.service=server', '--format', '{{.ID}}']).trim().split('\n').filter(Boolean);
    assert.equal(ids.length, 1, 'Exactly one Server from this producer is required');
    const server = JSON.parse(docker(['inspect', ids[0]]))[0];
    assert.equal(server.Config.Image, process.env.DW_SERVER_IMAGE);
    const ports = server.NetworkSettings.Ports['8080/tcp'];
    assert.equal(ports.length, 1);
    assert.equal(ports[0].HostIp, '127.0.0.1');
    const token = server.Config.Env.find(row => row.startsWith('DW_AUTH_TOKEN='))?.slice('DW_AUTH_TOKEN='.length);
    assert.ok(token, 'The owned fixture token is required');
    return {base: `http://127.0.0.1:${ports[0].HostPort}`, token};
}

function collectOriginals(root) {
    const load = name => JSON.parse(fs.readFileSync(path.join(root, name), 'utf8'));
    const originals = [];
    const collect = probe => {
        assert.equal(probe.ok, true);
        assert.equal(probe.status, 0);
        assert.equal(probe.body.outcome, 'pass');
        for (const row of probe.body.observations) originals.push(row.durable_service_call.body);
    };
    for (const name of ['nexus-php-workflow-probe.json', 'nexus-python-workflow-probe.json',
        'nexus-php-php-workflow-probe.json', 'nexus-python-python-workflow-probe.json']) collect(load(name));
    for (const name of ['nexus-typed-failure-probes.json', 'nexus-cancellation-probes.json']) {
        for (const row of load(name).reports) collect(row.observation);
    }
    assert.equal(originals.length, 24);
    assert.equal(new Set(originals.map(row => row.id || row.service_call_id)).size, 24);
    return originals;
}

function invokeCli(connection, args) {
    return spawnSync(process.env.DW_NEXUS_CLI_BINARY, args, {
        encoding: 'utf8', timeout: 15000, maxBuffer: 2 * 1024 * 1024,
        env: {...process.env, HOME: process.env.DW_NEXUS_CLI_HOME,
            DURABLE_WORKFLOW_SERVER_URL: connection.base, DURABLE_WORKFLOW_AUTH_TOKEN: connection.token},
    });
}

function successfulCli(connection, args) {
    const result = invokeCli(connection, args);
    const diagnostic = String(result.stderr || result.error?.message || '').replaceAll(connection.token, '[REDACTED]').slice(-2000);
    assert.equal(result.status, 0, `Published CLI refused the original: ${diagnostic}`);
    return parseExactJson(result.stdout);
}

function requireNotFound(connection, args) {
    const result = invokeCli(connection, args);
    assert.ok(Number.isInteger(result.status) && result.status > 0 && /404|not found|run_not_found|workflow_not_found/i.test(`${result.stdout || ''} ${result.stderr || ''}`),
        'The published CLI must refuse the wrong original selector');
}

async function api(connection, namespace, suffix) {
    const response = await fetch(`${connection.base}/api/${suffix}`, {redirect: 'error', signal: AbortSignal.timeout(10000),
        headers: {Authorization: `Bearer ${connection.token}`, 'X-Namespace': namespace,
            'X-Durable-Workflow-Protocol-Version': '1.0', 'X-Durable-Workflow-Control-Plane-Version': '2'}});
    assert.equal(response.status, 200, `Original API selector failed: ${response.status}`);
    return parseExactJson(await response.text());
}

async function history(connection, original) {
    const suffix = `workflows/${encodeURIComponent(original.caller_workflow_instance_id)}/runs/${encodeURIComponent(original.caller_workflow_run_id)}/history`;
    const events = [];
    let document;
    let cursor = null;
    let pages = 0;
    do {
        // The next request depends on the cursor returned by this page.
        assert.ok(pages++ < 20, 'Unexpected history pagination');
        const query = new URLSearchParams({page_size: '100'});
        if (cursor) query.set('next_page_token', cursor);
        document = await api(connection, original.caller_namespace, `${suffix}?${query}`);
        events.push(...document.events);
        cursor = document.next_page_token;
    } while (cursor);
    return {...document, events};
}

async function inspectOriginal(connection, original) {
    const namespace = original.caller_namespace;
    const workflow = original.caller_workflow_instance_id;
    const run = original.caller_workflow_run_id;
    const before = await history(connection, original);
    const apiDescription = await api(connection, namespace, `workflows/${encodeURIComponent(workflow)}/runs/${encodeURIComponent(run)}`);
    const describe = successfulCli(connection, ['workflow:describe', workflow, '--run-id', run, '--namespace', namespace, '--json']);
    for (const field of ['workflow_id', 'run_id', 'namespace', 'status', 'execution_deadline_at', 'run_deadline_at', 'input_envelope', 'output_envelope', 'output']) {
        assert.deepEqual(describe[field], apiDescription[field], `CLI describe changed ${field}`);
    }
    const cliHistory = successfulCli(connection, ['workflow:history', workflow, run, '--namespace', namespace, '--json']);
    requireOriginalHistory(cliHistory, before, original);
    requireNotFound(connection, ['workflow:history', workflow, original.linked_workflow_run_id, '--namespace', namespace, '--json']);
    const callId = original.id || original.service_call_id;
    const callPath = ['service-endpoints', original.endpoint_name, 'services', original.service_name,
        'operations', original.operation_name, 'service-calls', callId].map(encodeURIComponent).join('/');
    const call = await api(connection, original.namespace, callPath);
    const callArgs = ['service-call:describe', original.endpoint_name, original.service_name, original.operation_name];
    const detail = successfulCli(connection, [...callArgs, callId, '--namespace', original.namespace, '--json']);
    requireOriginalCall(detail, call, original);
    requireNotFound(connection, [...callArgs, run, '--namespace', original.namespace, '--json']);
    const after = await history(connection, original);
    requireOriginalHistory(after, before, original);
    return {call_id: original.id || original.service_call_id, namespace, workflow_id: workflow, run_id: run,
        target_run_id: original.linked_workflow_run_id, status: original.status, events: before.events.length,
        wrong_selector_refused: true, call_detail_verified: true, wrong_call_refused: true};
}

async function main() {
    const browser = spawnSync(process.execPath, [fileURLToPath(new URL('./service-catalog-published.mjs', import.meta.url)), ...process.argv.slice(2)],
        {stdio: 'inherit', timeout: 600000});
    assert.equal(browser.status, 0, 'The existing API/browser observer must pass unchanged');
    const root = argument('--nexus-evidence-dir');
    const output = argument('--output-dir');
    const connection = serverConnection();
    fs.mkdirSync(process.env.DW_NEXUS_CLI_HOME, {recursive: true});
    const version = invokeCli(connection, ['--version']);
    assert.equal(version.status, 0);
    assert.ok(version.stdout.includes(process.env.DW_CLI_VERSION), 'Wrong published CLI version');
    const cases = [];
    // Keep each before/CLI/after span together without multiplying backend load.
    for (const original of collectOriginals(root)) cases.push(await inspectOriginal(connection, original));
    fs.writeFileSync(path.join(output, 'cli-report.json'), `${JSON.stringify({outcome: 'pass', classification: 'published_cli',
        version: process.env.DW_CLI_VERSION, original_calls: cases.length, cases, history_mutations: 0}, null, 2)}\n`);
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    try {
        await main();
    } catch (error) {
        console.error(error.message);
        process.exitCode = 1;
    }
}
