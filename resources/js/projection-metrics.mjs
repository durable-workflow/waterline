export function projectionMetric(metrics, group, key = null) {
    const value = key === null
        ? metrics?.projections?.run_summaries?.[group]
        : metrics?.projections?.[group]?.[key];

    if (value === null || value === undefined || (typeof value === 'string' && value.trim() === '')) {
        return null;
    }

    const numeric = typeof value === 'number' || typeof value === 'string'
        ? Number(value)
        : NaN;

    return Number.isFinite(numeric) && numeric >= 0 ? numeric : null;
}

export function projectionMetricLabel(value, locale = 'en') {
    return value === null ? 'Unknown' : value.toLocaleString(locale);
}

export function projectionRebuildTotal(metrics) {
    const counts = [
        'run_summaries',
        'run_waits',
        'run_timeline_entries',
        'run_timer_entries',
        'run_lineage_entries',
    ].map(group => projectionMetric(metrics, group, 'needs_rebuild'));

    return counts.includes(null) ? null : counts.reduce((total, count) => total + count, 0);
}
