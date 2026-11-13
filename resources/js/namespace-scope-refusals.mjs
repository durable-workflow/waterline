// Presentation of Workflow's `namespace_scope_refusals` health check
// (Workflow 2.27.0): work this app left alone because its namespace is
// outside the app's allowlist (DW_V2_NAMESPACES). The check's data names the
// refused namespaces, so it is shown only on the authenticated health page.

export const NAMESPACE_SCOPE_REFUSALS_CHECK = 'namespace_scope_refusals';

// Paths Workflow records, in its order, with message keys for their labels.
export const REFUSAL_PATH_LABELS = {
    job: 'Queued job',
    repair: 'Repair pass',
    timeout: 'Timeout pass',
    schedule_tick: 'Schedule tick',
};

function isObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function text(value) {
    return typeof value === 'string' && value.trim() !== '' ? value : null;
}

function count(value) {
    const number = Number(value);
    return Number.isInteger(number) && number > 0 ? number : 0;
}

// The refusal details of a warning check, or null when there is nothing to
// show: another check, an `ok` check, or a check without data (older Workflow).
export function namespaceScopeRefusals(check) {
    if (check?.name !== NAMESPACE_SCOPE_REFUSALS_CHECK || check.status === 'ok' || !isObject(check.data)) {
        return null;
    }

    const data = check.data;
    const reported = isObject(data.by_path) ? data.by_path : {};
    const paths = [...Object.keys(REFUSAL_PATH_LABELS), ...Object.keys(reported).filter((path) => !(path in REFUSAL_PATH_LABELS))];
    const byPath = paths
        .map((path) => ({ path, label: REFUSAL_PATH_LABELS[path] || null, count: count(reported[path]) }))
        .filter((entry) => entry.count > 0);
    const rows = (Array.isArray(data.namespaces) ? data.namespaces : [])
        .filter((entry) => isObject(entry) && text(entry.namespace) !== null && text(entry.path) !== null && count(entry.count) > 0)
        .map((entry) => ({
            namespace: entry.namespace,
            path: entry.path,
            pathLabel: REFUSAL_PATH_LABELS[entry.path] || null,
            count: count(entry.count),
            lastSeenAt: text(entry.last_seen_at),
        }));
    const scope = isObject(data.scope) && data.scope.all !== true && Array.isArray(data.scope.namespaces)
        ? data.scope.namespaces.filter((namespace) => text(namespace) !== null)
        : null;
    const windowSeconds = count(data.window_seconds);

    return {
        total: count(data.total),
        byPath,
        rows,
        truncated: data.truncated === true,
        available: data.available !== false,
        // Waterline hides the names when it is open without sign-in.
        redacted: data.namespaces_redacted === true,
        windowMinutes: windowSeconds > 0 ? Math.max(1, Math.floor(windowSeconds / 60)) : null,
        servedNamespaces: scope,
    };
}
