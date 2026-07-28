function isRecord(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

export function parseBootstrapConfig(serialized) {
    if (typeof serialized !== 'string' || serialized.trim() === '') {
        return null;
    }

    let config;

    try {
        config = JSON.parse(serialized);
    } catch {
        return null;
    }

    if (
        !isRecord(config)
        || typeof config.path !== 'string'
        || !isRecord(config.operator_scope)
        || !isRecord(config.backend)
        || typeof config.app_name !== 'string'
        || typeof config.assets_current !== 'boolean'
        || typeof config.maintenance !== 'boolean'
    ) {
        return null;
    }

    const path = config.path.trim().replace(/^\/+|\/+$/g, '');

    return {
        ...config,
        path,
        basePath: path === '' ? '' : `/${path}`,
    };
}

export function readBootstrapConfig(element) {
    if (!element || typeof element.getAttribute !== 'function') {
        return null;
    }

    return parseBootstrapConfig(element.getAttribute('data-waterline-config'));
}
