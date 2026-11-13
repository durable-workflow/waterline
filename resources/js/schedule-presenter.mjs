// Presentation rules for the Schedules page. Rows come from either backend in
// the same shape: identified by namespace and schedule_id, with the stored
// spec (cron_expressions, intervals, timezone) and the last recorded outcome.

export const OVERLAP_POLICIES = [
    { value: 'skip', label: 'Skip' },
    { value: 'buffer_one', label: 'Buffer one' },
    { value: 'buffer_all', label: 'Buffer all' },
    { value: 'cancel_other', label: 'Cancel running workflow' },
    { value: 'terminate_other', label: 'Terminate running workflow' },
    { value: 'allow_all', label: 'Allow overlapping runs' },
];

const OUTCOMES = {
    scheduled: { label: 'Started', tone: 'success' },
    triggered: { label: 'Started', tone: 'success' },
    started: { label: 'Started', tone: 'success' },
    drained: { label: 'Started from buffer', tone: 'success' },
    backfilled: { label: 'Backfilled', tone: 'success' },
    buffered: { label: 'Buffered', tone: 'warning' },
    skipped: { label: 'Skipped', tone: 'warning' },
    buffer_full: { label: 'Skipped', tone: 'warning' },
    failed: { label: 'Failed', tone: 'danger' },
};

const DATE_UNITS = { Y: 'year', M: 'month', W: 'week', D: 'day' };
const TIME_UNITS = { H: 'hour', M: 'minute', S: 'second' };
const DURATION_PART = /^(\d+(?:\.\d+)?)([A-Z])/;

// Reads "1Y2M" or "1H30M" into [{unit, amount}], or null when a designator is
// unknown or out of order.
function durationParts(section, units) {
    const order = Object.keys(units);
    const parts = [];
    let rest = section;
    let last = -1;

    while (rest !== '') {
        const match = DURATION_PART.exec(rest);
        const position = match ? order.indexOf(match[2]) : -1;

        if (position <= last) {
            return null;
        }

        parts.push({ unit: units[match[2]], amount: Number(match[1]) });
        last = position;
        rest = rest.slice(match[0].length);
    }

    return parts;
}

function text(value) {
    return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
}

// Identity is used exactly as stored; a trimmed ID would name another schedule.
function identity(value) {
    return typeof value === 'string' && value !== '' ? value : null;
}

export function scheduleId(schedule) {
    return identity(schedule?.schedule_id) ?? identity(schedule?.id);
}

// Schedule IDs are unique only within a namespace.
export function scheduleRowKey(schedule) {
    return JSON.stringify([identity(schedule?.namespace), scheduleId(schedule)]);
}

export function scheduleRequest(endpoint, schedule, suffix = '') {
    const id = scheduleId(schedule);

    if (id === null) {
        throw new Error('The schedule row has no schedule_id.');
    }

    const namespace = identity(schedule?.namespace);

    return {
        url: `${endpoint}/${encodeURIComponent(id)}${suffix}`,
        params: namespace === null ? {} : { namespace },
    };
}

// Formats an ISO 8601 duration such as PT1H30M with the locale's unit names.
// Anything that is not a duration is returned unchanged so it stays visible.
export function formatIsoDuration(value, locale) {
    const source = text(value);

    if (source === null) {
        return '';
    }

    const normalized = source.toUpperCase();
    const sections = normalized.startsWith('P') ? normalized.slice(1).split('T') : [];

    if (sections.length === 0 || sections.length > 2 || (sections.length === 2 && sections[1] === '')) {
        return source;
    }

    const dateParts = durationParts(sections[0], DATE_UNITS);
    const timeParts = sections.length === 2 ? durationParts(sections[1], TIME_UNITS) : [];

    if (dateParts === null || timeParts === null) {
        return source;
    }

    const parts = [...dateParts, ...timeParts]
        .filter((part) => part.amount !== 0)
        .map((part) => new Intl.NumberFormat(locale, { style: 'unit', unit: part.unit, unitDisplay: 'short' }).format(part.amount));

    return parts.length > 0 ? parts.join(' ') : source;
}

export function scheduleCadence(spec) {
    const source = spec && typeof spec === 'object' && !Array.isArray(spec) ? spec : {};
    const cron = (Array.isArray(source.cron_expressions) ? source.cron_expressions : [])
        .map(text)
        .filter((expression) => expression !== null);
    const intervals = (Array.isArray(source.intervals) ? source.intervals : [])
        .filter((interval) => interval && text(interval.every) !== null)
        .map((interval) => ({ every: text(interval.every), offset: text(interval.offset) }));

    return {
        cron,
        intervals,
        timezone: text(source.timezone),
        empty: cron.length === 0 && intervals.length === 0,
    };
}

function emptyLastResult(state, at = null) {
    return { state, at, outcome: null, label: null, tone: 'muted', reason: null, workflowId: null };
}

// An explicit summary state is authoritative. Legacy fire/skip fields cannot
// fill in an unavailable summary or turn it into an observed "never".
export function scheduleLastResult(schedule) {
    const outcome = schedule?.last_outcome;
    const lastFiredAt = text(schedule?.last_fired_at);
    const hasSummary = Object.hasOwn(schedule ?? {}, 'last_outcome_state');

    if (hasSummary) {
        if (schedule.last_outcome_state === 'never' && outcome === null && schedule.buffered_actions_count === 0) {
            return emptyLastResult('never');
        }

        if (schedule.last_outcome_state !== 'known'
            || !Number.isSafeInteger(schedule.buffered_actions_count) || schedule.buffered_actions_count < 0
            || !['started', 'buffered', 'skipped', 'failed'].includes(outcome?.outcome)
            || text(outcome?.at) === null) {
            return emptyLastResult('unavailable');
        }
    }

    if (outcome && typeof outcome === 'object' && text(outcome.outcome) !== null) {
        const known = OUTCOMES[outcome.outcome];

        return {
            state: 'recorded',
            at: text(outcome.at) ?? lastFiredAt,
            outcome: outcome.outcome,
            label: known ? known.label : null,
            tone: known ? known.tone : 'muted',
            reason: typeof outcome.reason === 'string' ? outcome.reason : null,
            workflowId: text(outcome.workflow_id),
        };
    }

    const hasSkip = text(schedule?.last_skipped_at) !== null
        || typeof schedule?.last_skip_reason === 'string'
        || (Number.isInteger(schedule?.skipped_trigger_count) && schedule.skipped_trigger_count > 0);

    if (lastFiredAt !== null || hasSkip) {
        return emptyLastResult('unreported', lastFiredAt);
    }

    return emptyLastResult('never');
}

export function scheduleCurrentRun(schedule) {
    const unavailable = {state: 'unavailable', status: null, bucket: null};
    if (schedule?.current_run_state === 'none' && schedule.current_run === null && schedule.latest_workflow_instance_id === null) {
        return {state: 'none', status: null, bucket: null};
    }
    const run = schedule?.current_run;
    if (schedule?.current_run_state !== 'known' || identity(schedule.latest_workflow_instance_id) === null
        || identity(run?.run_id) === null) return unavailable;
    const bucket = {
        pending: 'running', running: 'running', waiting: 'running',
        completed: 'completed', failed: 'failed', cancelled: 'failed', terminated: 'failed',
    }[run.status];
    if (typeof bucket !== 'string' || run.status_bucket !== bucket || run.is_terminal !== (bucket !== 'running')) return unavailable;

    return {state: 'known', status: run.status, bucket};
}

export function outcomeLabel(outcome) {
    return OUTCOMES[outcome]?.label ?? null;
}

export function outcomeTone(outcome) {
    return OUTCOMES[outcome]?.tone ?? 'muted';
}

export function backfillCounts(response) {
    const results = Array.isArray(response?.results) ? response.results : [];
    const count = (outcome) => results.filter((result) => result?.outcome === outcome).length;

    return {
        attempted: Number.isInteger(response?.fires_attempted) ? response.fires_attempted : results.length,
        started: Number.isInteger(response?.started_count) ? response.started_count : count('started'),
        skipped: Number.isInteger(response?.skipped_count) ? response.skipped_count : count('skipped'),
        failed: Number.isInteger(response?.failed_count) ? response.failed_count : count('failed'),
    };
}

// A failed fire is a warning. A window where nothing started is information,
// not success.
export function backfillIcon(counts) {
    if (counts.failed > 0) {
        return 'warning';
    }

    return counts.started > 0 ? 'success' : 'info';
}
