import { code as fetchOverride } from './interceptor/fetch-override.js';
import { code as linkIntercept } from './interceptor/link-intercept.js';
import { code as animations } from './interceptor/animations.js';
import { handlers } from './interceptor/message-handlers.js';

export function extractShellConfig(html) {
    const config = {};

    const configMatch = html.match(/<script[^>]*id="__nb-shell-config"[^>]*>([\s\S]*?)<\/script>/);
    if (configMatch) {
        try { Object.assign(config, JSON.parse(configMatch[1])); } catch {}
    }

    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');

    const components = {};
    doc.querySelectorAll('[data-nb]').forEach(el => {
        const type = el.dataset.nb;
        const data = { ...el.dataset };
        delete data.nb;

        const children = [];
        el.querySelectorAll('[data-nb]').forEach(child => {
            const childType = child.dataset.nb;
            const childData = { ...child.dataset };
            delete childData.nb;
            children.push({ type: childType, ...childData });
        });

        if (children.length) data.children = children;

        const slotHtml = el.innerHTML.trim();
        if (slotHtml && !children.length) {
            data.slotHtml = slotHtml;
        }

        if (!components[type]) components[type] = data;
        else if (Array.isArray(components[type])) components[type].push(data);
        else components[type] = [components[type], data];
    });

    config.components = components;
    return config;
}

function buildMessageListener() {
    const cases = Object.entries(handlers).map(([type, code]) =>
        `if (e.data && e.data.type === '${type}') { ${code} }`
    ).join(' else ');

    return `
    window.addEventListener('message', function(e) {
        ${cases}
    });`;
}

export function inject(html) {
    const script = `<script>
(function() {
    var _pending = {}, _id = 1;
    ${buildMessageListener()}
    ${fetchOverride}
    ${linkIntercept}

    window.__nbBridge = function(action, payload) {
        window.parent.postMessage({ type: 'nativeblade-native', action: action, payload: payload || {} }, '*');
    };

    window.__nbAction = function(url, method, body) {
        fetch(url, {
            method: method || 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: body ? JSON.stringify(body) : undefined
        }).catch(function(e) { console.error('nbAction error', e); });
    };

    window.addEventListener('__nativeblade', function(e) {
        var actions = (e.detail && e.detail.actions) || [];
        actions.forEach(function(a) {
            __nbBridge(a.action.replace('so:', ''), a.data);
        });
    });

    window.addEventListener('message', function(e) {
        if (!e.data || !e.data.type) return;
        var t = e.data.type;

        if (t.indexOf('nativeblade-') !== 0) return;
        var event = t.replace('nativeblade-', 'nb:');
        var payload = Object.assign({}, e.data);
        delete payload.type;
        if (window.Livewire) {
            window.Livewire.dispatch(event, payload);
        }
    });

    function __nbRegisterDirectives() {
        if (!window.Livewire) return;

        Livewire.directive('nb-bridge', function(ctx) {
            var action = ctx.directive.expression;
            var handler = function() {
                var p = ctx.el.getAttribute('wire:nb-payload');
                var payload = p ? JSON.parse(p) : {};
                window.parent.postMessage({ type: 'nativeblade-native', action: action, payload: payload }, '*');
            };
            ctx.el.addEventListener('click', handler);
            ctx.cleanup(function() { ctx.el.removeEventListener('click', handler); });
        });

        Livewire.directive('nb-navigate', function(ctx) {
            var path = ctx.directive.expression;
            var replace = ctx.directive.modifiers.includes('replace');
            var handler = function(e) {
                e.preventDefault();
                window.parent.postMessage({ type: 'nativeblade-navigate', path: path, replace: replace }, '*');
            };
            ctx.el.addEventListener('click', handler);
            ctx.cleanup(function() { ctx.el.removeEventListener('click', handler); });
        });

        Livewire.directive('nb-asset', function(ctx) {
            ctx.el.setAttribute('wire:ignore.self', '');
        });
    }

    if (document.readyState === 'complete') {
        __nbRegisterDirectives();
    } else {
        document.addEventListener('DOMContentLoaded', __nbRegisterDirectives);
    }

    ${animations}
})();
<\/script>`;
    return html.replace('<head>', '<head><base href="http://localhost/">' + script);
}
