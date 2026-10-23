export const DEFAULT_BROWSER_TIMEOUT_MS = 65_000;
export const MAX_BROWSER_TIMEOUT_MS = 2_147_483_647;

export function browserTimeoutMilliseconds(config = {}) {
    const value = config.request_timeout_ms;
    return Number.isInteger(value) && value > 0 && value <= MAX_BROWSER_TIMEOUT_MS
        ? value : DEFAULT_BROWSER_TIMEOUT_MS;
}

// A caller-selected update wait is not a business deadline and must not be
// shortened by the ordinary browser request budget. Leave the request body intact.
export function commandRequestOptions(command, ordinaryTimeout = DEFAULT_BROWSER_TIMEOUT_MS) {
    const wait = Number(command?.waitTimeoutSeconds);
    if (command?.waitFor !== 'accepted' && Number.isFinite(wait) && wait > 0) {
        return {timeout: Math.min(MAX_BROWSER_TIMEOUT_MS, Math.max(ordinaryTimeout, Math.ceil(wait * 1000) + 5000))};
    }
    return {};
}

export function ambiguousCommandError(error, commandType) {
    if (error?.response?.data?.error === 'waterline_request_configuration_invalid') return false;
    return commandType !== 'query' && (!error?.response
        || error.response.status >= 500
        || error.response.data?.error === 'remote_transport_failure');
}
