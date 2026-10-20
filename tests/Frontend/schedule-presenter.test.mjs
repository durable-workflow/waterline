import assert from 'node:assert/strict'
import test from 'node:test'
import {
    OVERLAP_POLICIES,
    backfillCounts,
    formatIsoDuration,
    outcomeTone,
    scheduleCadence,
    scheduleLastResult,
    scheduleRequest,
    scheduleId,
    scheduleRowKey,
} from '../../resources/js/schedule-presenter.mjs'

const endpoint = '/waterline/api/v2/schedules'

test('row actions address the user-chosen schedule ID within its namespace', () => {
    const row = { id: 'nightly-invoice-sync', schedule_id: 'nightly-invoice-sync', namespace: 'tenant-b' }

    assert.deepEqual(scheduleRequest(endpoint, row, '/pause'), {
        url: '/waterline/api/v2/schedules/nightly-invoice-sync/pause',
        params: { namespace: 'tenant-b' },
    })
    assert.deepEqual(scheduleRequest(endpoint, { schedule_id: 'billing/daily run' }), {
        url: '/waterline/api/v2/schedules/billing%2Fdaily%20run',
        params: {},
    })
    assert.throws(() => scheduleRequest(endpoint, { namespace: 'default' }, '/trigger'), /schedule_id/)
})

test('the same schedule ID in two namespaces produces two distinct rows', () => {
    const first = scheduleRowKey({ schedule_id: 'nightly-invoice-sync', namespace: 'default' })
    const second = scheduleRowKey({ schedule_id: 'nightly-invoice-sync', namespace: 'tenant-b' })

    assert.notEqual(first, second)
    assert.equal(first, scheduleRowKey({ schedule_id: 'nightly-invoice-sync', namespace: 'default' }))
})

test('cadence lists every stored cron expression and interval with its timezone', () => {
    assert.deepEqual(scheduleCadence({
        cron_expressions: ['0 2 * * *', ' ', 7, '30 3 * * 1'],
        intervals: [{ every: 'PT1H' }, { every: 'P1D', offset: 'PT6H' }, { offset: 'PT1M' }, null],
        timezone: 'America/Chicago',
    }), {
        cron: ['0 2 * * *', '30 3 * * 1'],
        intervals: [{ every: 'PT1H', offset: null }, { every: 'P1D', offset: 'PT6H' }],
        timezone: 'America/Chicago',
        empty: false,
    })
    assert.equal(scheduleCadence(null).empty, true)
    assert.equal(scheduleCadence({ cron: '0 * * * *' }).empty, true)
})

test('ISO 8601 durations render with locale unit names and unknown values stay visible', () => {
    assert.equal(formatIsoDuration('PT1H', 'en'), '1 hr')
    assert.equal(formatIsoDuration('PT1H30M', 'en'), '1 hr 30 min')
    assert.equal(formatIsoDuration('P1DT12H', 'en'), '1 day 12 hr')
    assert.equal(formatIsoDuration('PT45S', 'en'), '45 sec')
    assert.equal(formatIsoDuration('PT1H', 'de'), '1 Std.')
    assert.equal(formatIsoDuration('PT1H', 'ja'), '1 時間')
    assert.equal(formatIsoDuration('every hour', 'en'), 'every hour')
    assert.equal(formatIsoDuration('P', 'en'), 'P')
    assert.equal(formatIsoDuration('PT0S', 'en'), 'PT0S')
    assert.equal(formatIsoDuration(null, 'en'), '')
})

test('the last result distinguishes a recorded outcome, an unreported fire and no fire', () => {
    const started = scheduleLastResult({
        last_fired_at: '2026-10-20T02:00:00+00:00',
        last_outcome: { outcome: 'scheduled', at: '2026-10-20T02:00:01+00:00', workflow_id: 'wf-1', reason: null },
    })
    assert.equal(started.state, 'recorded')
    assert.equal(started.label, 'Started')
    assert.equal(started.tone, 'success')
    assert.equal(started.at, '2026-10-20T02:00:01+00:00')
    assert.equal(started.workflowId, 'wf-1')

    const skipped = scheduleLastResult({ last_outcome: { outcome: 'skipped', at: '2026-10-20T03:00:00+00:00', reason: 'overlap_policy_skip' } })
    assert.equal(skipped.label, 'Skipped')
    assert.equal(skipped.tone, 'warning')
    assert.equal(skipped.reason, 'overlap_policy_skip')

    const failed = scheduleLastResult({ last_outcome: { outcome: 'failed', reason: 'Workflow class not found' }, last_fired_at: '2026-10-20T04:00:00+00:00' })
    assert.equal(failed.tone, 'danger')
    assert.equal(failed.at, '2026-10-20T04:00:00+00:00')

    const novel = scheduleLastResult({ last_outcome: { outcome: 'future_outcome' } })
    assert.equal(novel.label, null)
    assert.equal(novel.outcome, 'future_outcome')

    assert.equal(scheduleLastResult({ last_fired_at: '2026-10-20T02:00:00+00:00', last_outcome: null }).state, 'unreported')
    assert.equal(scheduleLastResult({ last_fired_at: null, last_outcome: null }).state, 'never')
})

test('backfill counts come from the normalized response and fall back to its rows', () => {
    assert.deepEqual(backfillCounts({ fires_attempted: 3, started_count: 2, skipped_count: 1, failed_count: 0, results: [] }), {
        attempted: 3, started: 2, skipped: 1, failed: 0,
    })
    assert.deepEqual(backfillCounts({ results: [{ outcome: 'started' }, { outcome: 'failed' }, { outcome: 'started' }] }), {
        attempted: 3, started: 2, skipped: 0, failed: 1,
    })
    assert.deepEqual(backfillCounts(null), { attempted: 0, started: 0, skipped: 0, failed: 0 })
})

test('backfill offers exactly the overlap policies both backends accept', () => {
    assert.deepEqual(OVERLAP_POLICIES.map((policy) => policy.value), [
        'skip', 'buffer_one', 'buffer_all', 'cancel_other', 'terminate_other', 'allow_all',
    ])
})

test('schedule identity is used exactly as stored', () => {
    assert.equal(scheduleId({ schedule_id: ' padded ' }), ' padded ')
    assert.deepEqual(scheduleRequest(endpoint, { schedule_id: ' padded ', namespace: 'Orders ' }), {
        url: '/waterline/api/v2/schedules/%20padded%20',
        params: { namespace: 'Orders ' },
    })
    assert.notEqual(scheduleRowKey({ schedule_id: 'a', namespace: 'b' }), scheduleRowKey({ schedule_id: 'a', namespace: 'b ' }))
})

test('outcome tones separate started, skipped and failed fires', () => {
    assert.equal(outcomeTone('scheduled'), 'success')
    assert.equal(outcomeTone('backfilled'), 'success')
    assert.equal(outcomeTone('skipped'), 'warning')
    assert.equal(outcomeTone('failed'), 'danger')
    assert.equal(outcomeTone('future_outcome'), 'muted')
})
