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
            __nbBridge(a.action, a.data);
        });
    });

    window.addEventListener('message', function(e) {
        if (!e.data || !e.data.type) return;
        var t = e.data.type;

        if (t.indexOf('nativeblade-') !== 0) return;
        var event = t.replace('nativeblade-', 'nb:');
        var payload = Object.assign({}, e.data);
        delete payload.type;

        // JS-only delivery: a realtime connection declared deliver:'js' tags its
        // events so they bypass Livewire/PHP entirely and surface as a DOM
        // CustomEvent for public/js to consume. This is the path high-frequency
        // feeds (game state, cursors) MUST take — one PHP request per frame would
        // exhaust the runtime. (No backticks/dollar-braces here: this block is
        // authored inside a template literal via buildMessageListener.)
        if (payload.__nbDeliver === 'js') {
            delete payload.__nbDeliver;
            window.dispatchEvent(new CustomEvent(event, { detail: payload }));
            return;
        }

        if (window.Livewire) {
            window.Livewire.dispatch(event, payload);
        }
    });

    // Finds "wire:nb-*" attributes by prefix so modifiers (wire:nb-navigate.fade) still match.
    function __nbFindAttr(el, name) {
        for (var i = 0; i < el.attributes.length; i++) {
            var a = el.attributes[i];
            if (a.name === name || a.name.indexOf(name + '.') === 0) return a;
        }
        return null;
    }

    // Click handling is delegated to the document instead of bound per element:
    // Livewire directives only initialize when an element enters the DOM, so an
    // attribute added later by a morph (e.g. conditional wire:nb-navigate) would
    // never get a listener. Delegation resolves the attribute at click time.
    document.addEventListener('click', function(e) {
        var el = e.target;
        while (el && el.attributes) {
            var nav = __nbFindAttr(el, 'wire:nb-navigate');
            if (nav) {
                e.preventDefault();
                var mods = nav.name.split('.').slice(1);
                var msg = { type: 'nativeblade-navigate', path: nav.value, replace: mods.indexOf('replace') !== -1 };
                var transition = mods.filter(function (m) { return m === 'none' || m === 'slide' || m === 'fade'; })[0];
                if (transition) msg.transition = transition;
                window.parent.postMessage(msg, '*');
                return;
            }
            var bridge = __nbFindAttr(el, 'wire:nb-bridge');
            if (bridge) {
                var p = el.getAttribute('wire:nb-payload');
                window.parent.postMessage({ type: 'nativeblade-native', action: bridge.value, payload: p ? JSON.parse(p) : {} }, '*');
                return;
            }
            el = el.parentElement;
        }
    });

    function __nbRegisterDirectives() {
        if (!window.Livewire) return;

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
