import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import vm from 'node:vm'
import test from 'node:test'
import { parse } from '@vue/compiler-sfc'
import { escapeDialogHtml, showDialogValidationText } from '../../resources/js/dialogs.mjs'
import { operatorPermission } from '../../resources/js/operator-permissions.mjs'
import { ambiguousCommandError } from '../../resources/js/request-budget.mjs'
import { createWaterlineI18n } from '../../resources/js/localization.mjs'

const payload = '<img src=x onerror="alert(1)">'
const escaped = '&lt;img src=x onerror=&quot;alert(1)&quot;&gt;'

const source = fs.readFileSync(new URL('../../resources/js/screens/flows/flow.vue', import.meta.url), 'utf8')
const script = parse(source).descriptor.script.content
    .replace(/^import .*$/gm, '').replace('export default', 'globalThis.component =')

function detail(overrides = {}) {
    const validationMessages = []
    const sandbox = {
        Waterline: { basePath: '/waterline', backend: { mode: 'embedded' } },
        TimelineEventRenderer: {}, SearchAttributeRenderer: {}, CancellationCascadeView: {}, ExceptionSourceContext: {},
        Swal: { isLoading: () => false, showValidationMessage: (html) => validationMessages.push(html) },
        showDialogValidationText,
        operatorPermission,
        ambiguousCommandError,
        commandRequestOptions: () => ({}),
        browserTimeoutMilliseconds: () => 0,
        crypto: globalThis.crypto,
        document: { getElementById: (id) => ({ value: id === 'waterline-signal-target' ? 'approve' : '{}', addEventListener() {} }) },
    }
    vm.runInNewContext(script, sandbox)
    const dialogs = []
    const context = {
        $t: createWaterlineI18n('en').global.t,
        flow: { instance_id: 'order', selected_run_id: 'run', run_id: 'run', engine_source: 'v2', declared_signal_targets: [{ name: 'approve' }] },
        $dialog: async (options) => {
            dialogs.push(options)
            const value = options.preConfirm ? await options.preConfirm() : true
            return { isConfirmed: value !== false, value }
        },
        ...overrides,
    }
    for (const [name, method] of Object.entries(sandbox.component.methods)) {
        if (!(name in context)) context[name] = method.bind(context)
    }
    return { context, dialogs, validationMessages }
}

function rejectWith(status, data) {
    return { post: async () => { throw { response: { status, data } } } }
}

test('dialog validation text is escaped before SweetAlert inserts it as HTML', () => {
    const shown = []
    showDialogValidationText({ showValidationMessage: (html) => shown.push(html) }, payload)
    assert.deepEqual(shown, [escaped])
    assert.equal(escapeDialogHtml("a & b's"), 'a &amp; b&#039;s')
    assert.equal(escapeDialogHtml(null), '')
})

test('an unknown refusal reason from the backend renders as text for every command', async () => {
    for (const commandType of ['repair', 'cancel', 'terminate', 'archive', 'redrive']) {
        const { context, validationMessages } = detail({ $http: rejectWith(409, { rejection_reason: payload }) })
        await context.issueCommand(commandType)
        assert.deepEqual(validationMessages, [escaped], commandType)
    }
})

test('a backend message without a known reason renders as text', async () => {
    const { context, validationMessages } = detail({ $http: rejectWith(422, { message: payload }) })
    await context.issueCommand('cancel')
    assert.deepEqual(validationMessages, [escaped])
})

test('a signal refused by the backend renders the refusal as text', async () => {
    const { context, validationMessages } = detail({ $http: rejectWith(409, { blocked_reason: payload }) })
    await context.issueCommand('signal')
    assert.deepEqual(validationMessages, [escaped])
})

test('failure and result dialogs pass backend strings as text options only', async () => {
    const failed = detail({ $http: rejectWith(409, { rejection_reason: payload }) })
    await failed.context.issueInteractiveCommand('cancel')
    assert.equal(failed.dialogs.length, 1)
    assert.equal(failed.dialogs[0].text, payload)
    assert.equal(failed.dialogs[0].html, undefined)

    const result = detail()
    result.context.showResult('{}', 'Query Result: ' + payload)
    assert.equal(result.dialogs[0].titleText, 'Query Result: ' + payload)
    assert.equal(result.dialogs[0].title, undefined)
    assert.equal(result.dialogs[0].html, undefined)
})

test('no screen calls SweetAlert validation directly', () => {
    const root = new URL('../../resources/js/', import.meta.url)
    const offenders = []
    const walk = (directory) => {
        for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
            const file = path.join(directory, entry.name)
            if (entry.isDirectory()) walk(file)
            else if (/\.(vue|m?js)$/.test(entry.name) && !file.endsWith(path.join('js', 'dialogs.mjs'))
                && fs.readFileSync(file, 'utf8').includes('.showValidationMessage(')) offenders.push(file)
        }
    }
    walk(root.pathname)
    assert.deepEqual(offenders, [])
})
