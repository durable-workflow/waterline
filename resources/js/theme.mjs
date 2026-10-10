export function applyThemeStylesheet(theme) {
    const link = document.getElementById('app-stylesheet');
    if (!link) return Promise.resolve();

    const filename = theme === 'light' ? 'app.css' : 'app-dark.css';
    const current = new URL(link.href, document.baseURI);
    // Preserve the already loaded, versioned stylesheet. Replacing its href
    // with the same unversioned file can briefly remove the dashboard layout.
    if (current.pathname.endsWith(`/${filename}`)) return Promise.resolve();

    return new Promise((resolve, reject) => {
        const cleanup = () => {
            link.removeEventListener('load', loaded);
            link.removeEventListener('error', failed);
        };
        const loaded = () => {
            cleanup();
            // Existing charts also need to remeasure after a theme toggle.
            window.dispatchEvent(new Event('resize'));
            resolve();
        };
        const failed = () => {
            cleanup();
            reject(new Error('Waterline theme stylesheet failed to load.'));
        };
        link.addEventListener('load', loaded);
        link.addEventListener('error', failed);
        link.href = new URL(filename, current).href;
    });
}
