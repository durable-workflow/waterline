export function operatorPermission(action, backend = {}) {
    const permission = backend.permissions?.[action];
    if (permission?.allowed === false) return permission;
    if (backend.read_only === true && action !== 'query') {
        return { allowed: false, reason: 'waterline_read_only' };
    }
    return { allowed: true, reason: null };
}
