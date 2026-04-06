const IS_TAURI = '__TAURI__' in window;

async function handle(action, target) {
    if (!action) return;

    if (IS_TAURI) {
        const { invoke } = window.__TAURI__.core;
        await invoke('native_action', { action, target: target || '' });
    } else {
        handleWeb(action, target);
    }
}

function handleWeb(action, target) {
    switch (action) {
        case 'so:alert':
            alert(target);
            break;
        case 'so:notification':
            if ('Notification' in window && Notification.permission === 'granted') {
                new Notification('NativeBlade', { body: target });
            } else {
                alert(target);
            }
            break;
        case 'so:navigate':
            window.location.href = target;
            break;
        case 'so:exit':
            window.close();
            break;
    }
}

export default { handle, IS_TAURI };
