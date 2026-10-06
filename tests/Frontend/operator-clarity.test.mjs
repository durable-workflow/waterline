import assert from 'node:assert/strict'
import test from 'node:test'

import { runDetailFixture } from '../../scripts/ci/run-detail-visual.mjs'

test('browser qualification covers planned, unknown, expired and unavailable waits', () => {
    const fixture = runDetailFixture('embedded-populated')
    assert.deepEqual(fixture.current_waits.map((wait) => wait.state),
        ['planned', 'resume_time_unknown', 'deadline_elapsed', 'unavailable'])
    assert.equal(fixture.current_waits[0].attempt_number, 3)
    assert.equal(fixture.current_waits[0].attempt_limit, 5)
    assert.equal(fixture.current_waits[0].next_scheduled_resume_at, '2030-01-01T18:00:00Z')
    assert.equal(runDetailFixture('service-populated').current_waits_state, 'unavailable')
})

test('both observer presentations cover coordinator boundaries, safe context and pruned history', () => {
    for (const presentation of ['embedded', 'service']) {
        const coordinator = runDetailFixture(`${presentation}-supported-empty`)
        assert.equal(coordinator.workflow_classification, 'coordinator')
        assert.equal(coordinator.status, 'completed')
        assert.equal(coordinator.continuedWorkflows[0].status, 'waiting')
        const context = runDetailFixture(`${presentation}-populated`).application_context
        assert.equal(context.fields[0].value, 'order<42>')
        assert.equal(context.fields[1].state, 'unavailable')
        assert.equal(context.links[0].url, 'https://app.example/orders/order%3C42%3E')
        assert.equal(runDetailFixture(`${presentation}-degraded`).current_waits_state, 'pruned')
    }
})
