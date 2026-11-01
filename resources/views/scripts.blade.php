<script>
(() => {
    if (window.__nativewatch) return;
    window.__nativewatch = true;

    const endpoint = {{-- A relative URL: NativePHP mobile serves pages from php://127.0.0.1, so an absolute http:// URL would be cross-origin and blocked. --}}{!! json_encode('/'.ltrim((string) config('nativewatch.browser.path', '_nativewatch/browser-errors'), '/'), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!};
    const seen = new Set();

    const send = (error, rejection) => {
        const report = {
            type: (error && error.name) || 'Error',
            message: String((error && error.message) || error || 'Unknown error').slice(0, 8000),
            stack: String((error && error.stack) || '').slice(0, 16000),
            url: location.href,
            unhandled_rejection: rejection,
        };
        const key = report.type + report.message + report.stack.slice(0, 200);

        if (seen.has(key) || seen.size > 50) return;
        seen.add(key);

        try {
            fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(report),
            }).catch(() => {});
        } catch (e) {}
    };

    window.addEventListener('error', (event) => {
        if (event.error || event.message) send(event.error || { message: event.message }, false);
    });
    window.addEventListener('unhandledrejection', (event) => send(event.reason, true));
})();
</script>
