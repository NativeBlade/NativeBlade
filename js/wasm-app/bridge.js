import * as camera from './components/camera/camera.js';
import { getComponent } from './component-registry.js';

let appFrameRef = null;
let isTauri = false;

let dialogApi = null;
let notificationApi = null;
let clipboardApi = null;
let geolocationApi = null;
let hapticsApi = null;
let biometricApi = null;
let barcodeApi = null;
let nfcApi = null;
let openerApi = null;
let osApi = null;

export async function init(appFrame) {
    appFrameRef = appFrame;
    camera.init(appFrame);

    try {
        dialogApi = await import('@tauri-apps/plugin-dialog');
        notificationApi = await import('@tauri-apps/plugin-notification');
        isTauri = true;
    } catch {}

    if (isTauri) {
        try {
            const os = await import('@tauri-apps/plugin-os');
            const platform = await os.platform();
            isMobile = platform === 'android' || platform === 'ios';
        } catch {}
        try { clipboardApi = await import('@tauri-apps/plugin-clipboard-manager'); } catch {}
        try { geolocationApi = await import('@tauri-apps/plugin-geolocation'); } catch {}
        try { hapticsApi = await import('@tauri-apps/plugin-haptics'); } catch {}
        try { biometricApi = await import('@tauri-apps/plugin-biometric'); } catch {}
        try { barcodeApi = await import('@tauri-apps/plugin-barcode-scanner'); } catch {}
        try { nfcApi = await import('@tauri-apps/plugin-nfc'); } catch {}
        try { openerApi = await import('@tauri-apps/plugin-opener'); } catch {}
        try { osApi = await import('@tauri-apps/plugin-os'); } catch {}
    }
}

let isMobile = false;

export function hapticSelection() {
    if (!hapticsApi || !isMobile) return;
    try { hapticsApi.selectionFeedback().catch(() => {}); } catch {}
}

const __nbCreatedChannels = new Set();

async function __nbEnsureChannel(channelId) {
    if (!channelId || !notificationApi || __nbCreatedChannels.has(channelId)) return;
    __nbCreatedChannels.add(channelId);
    try {
        await notificationApi.createChannel({
            id: channelId,
            name: channelId,
            importance: 3,
            visibility: 0,
            lights: true,
            vibration: true,
        });
    } catch {}
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

        case 'confirm':
            if (isTauri) {
                dialogApi.confirm(payload.message, { title, kind: payload.kind || 'warning' })
                    .then(confirmed => {
                        appFrame?.contentWindow?.postMessage({ type: 'nativeblade-confirm-result', confirmed, id: payload.id || null }, '*');
                    });
            } else {
                const confirmed = confirm(payload.message);
                appFrame?.contentWindow?.postMessage({ type: 'nativeblade-confirm-result', confirmed, id: payload.id || null }, '*');
            }
            break;

        case 'notification':
            if (isTauri && notificationApi) {
                (async () => {
                    let granted = await notificationApi.isPermissionGranted();
                    if (!granted) {
                        const perm = await notificationApi.requestPermission();
                        granted = perm === 'granted';
                    }
                    if (!granted) return;
                    const opts = { title, body: payload.body || '' };
                    if (payload.sound) opts.sound = payload.sound;
                    if (payload.icon) opts.icon = payload.icon;
                    if (payload.channel) {
                        opts.channelId = payload.channel;
                        await __nbEnsureChannel(payload.channel);
                    }
                    notificationApi.sendNotification(opts);
                })();
            } else {
                appFrame?.contentWindow?.postMessage({ type: 'nativeblade-alert', message: payload.body }, '*');
            }
            break;

        case 'clipboard_write':
            if (clipboardApi) {
                clipboardApi.writeText(payload.text || '');
            }
            break;

        case 'clipboard_read':
            if (clipboardApi) {
                clipboardApi.readText().then(text => {
                    appFrame?.contentWindow?.postMessage({ type: 'nativeblade-clipboard', text, id: payload.id || null }, '*');
                });
            }
            break;

        case 'geolocation':
            if (geolocationApi) {
                (async () => {
                    let state = await geolocationApi.checkPermissions();
                    if (state.location !== 'granted') {
                        state = await geolocationApi.requestPermissions(['location']);
                    }
                    if (state.location !== 'granted') return;
                    const pos = await geolocationApi.getCurrentPosition();
                    appFrame?.contentWindow?.postMessage({ type: 'nativeblade-geolocation', position: pos, id: payload.id || null }, '*');
                })().catch(() => {});
            }
            break;

        case 'vibrate':
            if (hapticsApi) {
                hapticsApi.vibrate(payload.duration || 100);
            }
            break;

        case 'impact':
            if (hapticsApi) {
                hapticsApi.impactFeedback(payload.style || 'medium');
            }
            break;

        case 'selection':
            if (hapticsApi) {
                hapticsApi.selectionFeedback();
            }
            break;

        case 'biometric':
            if (biometricApi) {
                (async () => {
                    const status = await biometricApi.checkStatus();
                    if (!status.isAvailable) {
                        appFrame?.contentWindow?.postMessage({ type: 'nativeblade-biometric', success: false, error: 'Biometric not available', id: payload.id || null }, '*');
                        return;
                    }
                    await biometricApi.authenticate(payload.reason || 'Authenticate', {
                        allowDeviceCredential: payload.allowDeviceCredential ?? true,
                    });
                    appFrame?.contentWindow?.postMessage({ type: 'nativeblade-biometric', success: true, id: payload.id || null }, '*');
                })().catch(err => {
                    appFrame?.contentWindow?.postMessage({ type: 'nativeblade-biometric', success: false, error: err.message || String(err), id: payload.id || null }, '*');
                });
            }
            break;

        case 'scan':
            if (barcodeApi) {
                (async () => {
                    let state = await barcodeApi.checkPermissions();
                    if (state !== 'granted') {
                        state = await barcodeApi.requestPermissions();
                    }
                    if (state !== 'granted') return;
                    const result = await barcodeApi.scan({ formats: payload.formats || [] });
                    appFrame?.contentWindow?.postMessage({ type: 'nativeblade-scan', result, id: payload.id || null }, '*');
                })().catch(() => {});
            }
            break;

        case 'nfc_read':
            if (nfcApi) {
                (async () => {
                    const available = await nfcApi.isAvailable();
                    if (!available) return;
                    const tag = await nfcApi.scan({ type: 'ndef' });
                    appFrame?.contentWindow?.postMessage({ type: 'nativeblade-nfc', tag, id: payload.id || null }, '*');
                })().catch(() => {});
            }
            break;

        case 'open_url':
            if (openerApi) {
                openerApi.openUrl(payload.url || '');
            }
            break;

        case 'open_file':
            if (openerApi) {
                openerApi.openPath(payload.path || '');
            }
            break;

        case 'os_info':
            if (osApi) {
                Promise.all([
                    osApi.platform(),
                    osApi.version(),
                    osApi.arch(),
                    osApi.locale(),
                ]).then(([platform, version, arch, locale]) => {
                    appFrame?.contentWindow?.postMessage({
                        type: 'nativeblade-os-info',
                        info: { platform, version, arch, locale }
                    }, '*');
                });
            }
            break;

        case 'camera':
            camera.open(payload);
            break;

        case 'gallery':
            camera.openGallery(payload);
            break;

        case 'navigate':
            window.postMessage({ type: 'nativeblade-navigate', path: payload.path, replace: !!payload.replace, transition: payload.transition }, '*');
            break;

        case 'showModal': {
            const modal = getComponent('modal');
            if (modal?.show) modal.show();
            break;
        }

        case 'hideModal': {
            const modal = getComponent('modal');
            if (modal?.hide) modal.hide();
            break;
        }

        case 'exit':
            try {
                import('@tauri-apps/plugin-process').then(m => m.exit(0));
            } catch {}
            break;

        case 'log': {
            const level = payload.level || 'info';
            const message = payload.message || '';
            const context = payload.context || {};
            const fn = { info: 'log', warn: 'warn', error: 'error', debug: 'debug' }[level] || 'log';
            const color = { info: '#3498db', warn: '#f39c12', error: '#e74c3c', debug: '#9b59b6' }[level] || '#3498db';
            const style = `color:${color};font-weight:bold`;
            const prefix = `%c[NB:${level}]`;
            if (context && Object.keys(context).length > 0) {
                console[fn](prefix, style, message, context);
            } else {
                console[fn](prefix, style, message);
            }
            break;
        }

        default: {
            const comp = getComponent(action);
            if (comp?.render) {
                comp.render(payload);
            } else {
                import(`@components/${action}/${action}.js`)
                    .then(mod => { if (mod.render) mod.render(payload); })
                    .catch(() => {});
            }
            break;
        }
    }
}
