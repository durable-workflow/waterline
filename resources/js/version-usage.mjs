export const usageStates = ['legacy', 'current', 'unknown'];
export const usageReasons = {
    marker_absent: 'No version marker recorded',
    history_unavailable: 'History or provenance unavailable',
    history_invalid: 'Invalid or conflicting version history',
};

const encoder = new TextEncoder();
const text = (value, maximum = Number.MAX_SAFE_INTEGER) => typeof value === 'string' && value.trim() !== '' && encoder.encode(value).length <= maximum;
const integer = (value, min, max) => Number.isSafeInteger(value) && value >= min && value <= max;
const keys = (value, expected) => value !== null && typeof value === 'object' && !Array.isArray(value)
    && Object.keys(value).length === expected.length && expected.every(key => Object.hasOwn(value, key));
function requireValid(valid) {
    if (!valid) throw new Error('Invalid version usage response');
}

function byteCompare(left, right) {
    const a = encoder.encode(left), b = encoder.encode(right);
    for (let index = 0; index < Math.min(a.length, b.length); index++) {
        if (a[index] !== b[index]) return a[index] - b[index];
    }
    return a.length - b.length;
}

export function usageQuery(draft, namespace) {
    const minimum = typeof draft.minimum === 'number' ? draft.minimum
        : typeof draft.minimum === 'string' && /^[+-]?\d+$/.test(draft.minimum) ? Number(draft.minimum) : NaN;
    if (!text(draft.workflowType, 255) || !text(draft.changeId, 1024)
        || !integer(minimum, -2147483648, 2147483647)
        || !['', ...usageStates].includes(draft.state)
        || (namespace !== '' && namespace !== null && !text(namespace, 255))) {
        throw new Error('Enter a workflow type, change point and signed 32-bit minimum version.');
    }
    return {
        namespace: namespace === '' ? null : namespace,
        workflow_type: draft.workflowType, change_id: draft.changeId, min_version: minimum,
        state: draft.state === '' ? null : draft.state, limit: 50, cursor: null,
    };
}

// No inference from deployed definitions, fingerprints or response defaults.
export function validateUsagePage(page, query, boundary = null) {
    requireValid(keys(page, ['schema_version', 'namespace', 'workflow_type', 'change_id', 'min_version', 'state', 'consistency',
        'through_run_id', 'counts', 'runs', 'limit', 'returned_count', 'has_more', 'next_cursor']));
    requireValid(page.schema_version === 1 && page.consistency === 'live');
    for (const key of ['namespace', 'workflow_type', 'change_id', 'min_version', 'state', 'limit']) requireValid(page[key] === query[key]);
    requireValid(keys(page.counts, ['total', ...usageStates]));
    requireValid(Object.values(page.counts).every(count => integer(count, 0, Number.MAX_SAFE_INTEGER)));
    requireValid(Number.isSafeInteger(page.counts.legacy + page.counts.current + page.counts.unknown)
        && page.counts.total === page.counts.legacy + page.counts.current + page.counts.unknown);
    requireValid(Array.isArray(page.runs) && page.runs.length <= page.limit && page.returned_count === page.runs.length);
    requireValid(typeof page.has_more === 'boolean' && (page.has_more ? text(page.next_cursor, 8192) && page.runs.length > 0 : page.next_cursor === null));
    requireValid(page.through_run_id === null || text(page.through_run_id));
    requireValid(page.counts.total === 0 || page.through_run_id !== null);
    const selectedCount = page.state === null ? page.counts.total : page.counts[page.state];
    if (page.has_more) {
        requireValid(page.runs.length === page.limit && selectedCount > page.runs.length && page.next_cursor !== query.cursor);
    } else if (query.cursor === null) {
        requireValid(selectedCount === page.runs.length);
    }
    if (boundary) requireValid(page.through_run_id === boundary.through);
    let previous = boundary?.after ?? null;
    const seen = {legacy: 0, current: 0, unknown: 0};
    for (const run of page.runs) {
        requireValid(keys(run, ['instance_id', 'run_id', 'status', 'state', 'recorded_version', 'unknown_reason']));
        requireValid(text(run.instance_id) && text(run.run_id) && ['pending', 'running', 'waiting'].includes(run.status));
        requireValid(usageStates.includes(run.state) && (page.state === null || run.state === page.state));
        requireValid(typeof page.through_run_id === 'string' && byteCompare(run.run_id, page.through_run_id) <= 0);
        requireValid(previous === null || byteCompare(previous, run.run_id) < 0);
        if (run.state === 'unknown') {
            requireValid(run.recorded_version === null && Object.hasOwn(usageReasons, run.unknown_reason));
        } else {
            requireValid(integer(run.recorded_version, -2147483648, 2147483647) && run.unknown_reason === null);
            requireValid((run.recorded_version < page.min_version) === (run.state === 'legacy'));
        }
        seen[run.state]++;
        previous = run.run_id;
    }
    for (const state of usageStates) requireValid(seen[state] <= page.counts[state]);
    return page;
}

export function usageParams(query) {
    return Object.fromEntries(Object.entries(query).filter(([, value]) => value !== null));
}
