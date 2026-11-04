export const TIME_WINDOW_FIELDS = ['started_time', 'started_within_seconds', 'started_from', 'started_before'];
export const TIME_WINDOW_UNITS = {seconds: 1, minutes: 60, hours: 3600, days: 86400, weeks: 604800};
export const MAX_WINDOW_SECONDS = 315360000;

export function timeWindowEditorState(filters = {}) {
    const seconds = Number(filters.started_within_seconds);
    const unit = ['weeks', 'days', 'hours', 'minutes', 'seconds']
        .find(unit => Number.isInteger(seconds) && seconds > 0 && seconds % TIME_WINDOW_UNITS[unit] === 0) || 'hours';
    return {
        mode: filters.started_time || 'any',
        duration: seconds > 0 ? seconds / TIME_WINDOW_UNITS[unit] : 24,
        unit,
        from: filters.started_from || '',
        before: filters.started_before || '',
    };
}

function instant(value) {
    const match = String(value).match(/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|[+-]\d{2}:\d{2})$/);
    if (!match) throw new Error('Enter valid start-time bounds with explicit UTC offsets.');
    const milliseconds = Date.parse(`${match[1]}T${match[2]}${match[4]}`);
    if (!Number.isFinite(milliseconds)) throw new Error('Enter valid start-time bounds with explicit UTC offsets.');
    return BigInt(milliseconds) * 1000n + BigInt((match[3] || '').padEnd(6, '0'));
}

export function timeWindowPayload({mode, duration, unit, from, before}) {
    if (mode === 'any') return {started_time: 'any'};
    if (mode === 'rolling') {
        const multiplier = TIME_WINDOW_UNITS[unit];
        const seconds = Number(duration) * multiplier;
        if (!/^[1-9][0-9]*$/.test(String(duration)) || !multiplier || !Number.isSafeInteger(seconds)
            || seconds < 1 || seconds > MAX_WINDOW_SECONDS) {
            throw new Error('Enter a whole rolling duration between 1 second and 3650 days.');
        }
        return {started_time: 'rolling', started_within_seconds: seconds};
    }
    if (mode !== 'fixed') throw new Error('Choose Any time, Rolling window, or Fixed range for the start time.');
    from = String(from || '').trim();
    before = String(before || '').trim();
    if (instant(from) >= instant(before)) throw new Error('The start of the fixed range must be earlier than its end.');
    return {started_time: 'fixed', started_from: from, started_before: before};
}

export function replaceTimeWindow(previous, next) {
    const merged = {...previous};
    if (Object.hasOwn(next, 'started_time')) TIME_WINDOW_FIELDS.forEach(field => delete merged[field]);
    return {...merged, ...next};
}
