import assert from 'node:assert/strict';
import test from 'node:test';
import {browserTimeoutMilliseconds, commandRequestOptions, ambiguousCommandError} from '../../resources/js/request-budget.mjs';

test('old bootstrap remains bounded and invalid values cannot disable browser timeouts', () => {
    for (const value of [undefined, null, 0, -1, Infinity, NaN, '200', 2_147_483_648]) {
        assert.equal(browserTimeoutMilliseconds({request_timeout_ms:value}), 65_000);
    }
    assert.equal(browserTimeoutMilliseconds({request_timeout_ms:200}), 200);
});

test('caller-selected update waits retain their allowance without changing the command', () => {
    const command = {waitFor:'completed',waitTimeoutSeconds:90,arguments:['unchanged']};
    assert.deepEqual(commandRequestOptions(command,65_000), {timeout:95_000});
    assert.deepEqual(command, {waitFor:'completed',waitTimeoutSeconds:90,arguments:['unchanged']});
    assert.deepEqual(commandRequestOptions({...command,waitFor:'accepted'},65_000), {});
    assert.deepEqual(commandRequestOptions(null,200), {});
});

test('uncertain writes remain unknown while read errors and definite refusals stay distinct', () => {
    for (const action of ['signal','update','repair','cancel','terminate','archive','schedule']) {
        assert.equal(ambiguousCommandError({code:'ECONNABORTED'},action),true);
        assert.equal(ambiguousCommandError({response:{status:502,data:{reason:'request_timeout'}}},action),true);
        assert.equal(ambiguousCommandError({response:{status:403,data:{reason:'waterline_action_forbidden'}}},action),false);
        assert.equal(ambiguousCommandError({response:{status:409,data:{reason:'run_not_active'}}},action),false);
        assert.equal(ambiguousCommandError({response:{status:501,data:{error:'waterline_request_configuration_invalid'}}},action),false);
    }
    assert.equal(ambiguousCommandError({code:'ECONNABORTED'},'query'),false);
});
