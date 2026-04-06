import * as camera from './components/camera/camera.js';
import { getComponent } from './component-registry.js';

let appFrameRef = null;
let dialogApi = null;
let notificationApi = null;
let isTauri = false;

export async function init(appFrame) {
    appFrameRef = appFrame;
    camera.init(appFrame);

    try {
        dialogApi = await import('@tauri-apps/plugin-dialog');
        notificationApi = await import('@tauri-apps/plugin-notification');
        isTauri = true;
    } catch {
    }
}

export function handleNativeAction(action, payload, appFrame) {
    const title = payload.title || 'NativeBlade';

    switch (action) {
        case 'alert':
            if (isTauri) {
                dialogApi.message(payload.message, { title, kind: payload.kind || 'info' });
            } else {
                appFrame?.contentWindow?.postMessage({ type: 'nativeblade-alert', message: payload.message }, '*');
            }
            break;

        case 'notification':
            if (isTauri) {
                notificationApi.sendNotification({ title, body: payload.body });
            } else {
                appFrame?.contentWindow?.postMessage({ type: 'nativeblade-alert', message: payload.body }, '*');
            }
            break;

        case 'confirm':
            if (isTauri) {
                dialogApi.confirm(payload.message, { title, kind: payload.kind || 'warning' })
                    .then(confirmed => {
                        appFrame?.contentWindow?.postMessage({ type: 'nativeblade-confirm-result', confirmed }, '*');
                    });
            } else {
                const confirmed = confirm(payload.message);
                appFrame?.contentWindow?.postMessage({ type: 'nativeblade-confirm-result', confirmed }, '*');
            }
            break;

        case 'camera':
            camera.open(payload);
            break;

        case 'gallery':
            camera.openGallery();
            break;

        case 'navigate':
            window.postMessage({ type: 'nativeblade-navigate', path: payload.path }, '*');
            break;

        case 'exit':
            try {
                import('@tauri-apps/plugin-process').then(m => m.exit(0));
            } catch {}
            break;

        default: {
            const comp = getComponent(action);
            if (comp?.render) {
                comp.render(payload);
            } else {
                import(`../nativeblade-components/${action}/${action}.js`)
                    .then(mod => { if (mod.render) mod.render(payload); })
                    .catch(() => {});
            }
            break;
        }
    }
}
