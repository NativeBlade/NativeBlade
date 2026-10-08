// Problems found while serving a request (a bridge that gave up, PHP output on
// stderr, a replay divergence) go through the `log` action so they reach every
// place a developer looks: the WebView console, the log file, the dev server
// terminal and, in development, the on-screen overlay. The runtime lives in the
// shell window, so window.parent is the shell itself; when no window exists
// (tests, workers) the message falls back to the console.
//
// Leaf module: no runtime imports.

export function createReporter({
    consoleObj = console,
    getTarget = () => (typeof window !== 'undefined' ? window.parent : null),
} = {}) {
    return function report(level, message, context = {}, source = 'shell') {
        const target = safeTarget(getTarget);
        if (target) {
            try {
                target.postMessage({
                    type: 'nativeblade-native',
                    action: 'log',
                    payload: { level, message, context, source, at: new Date().toISOString() },
                }, '*');
                return;
            } catch {}
        }
        const fn = level === 'error' ? 'error' : 'warn';
        if (context && Object.keys(context).length > 0) consoleObj[fn](message, context);
        else consoleObj[fn](message);
    };
}

function safeTarget(getTarget) {
    try {
        const target = getTarget();
        return target && typeof target.postMessage === 'function' ? target : null;
    } catch {
        return null;
    }
}

export const reportProblem = createReporter();
