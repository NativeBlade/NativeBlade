export const code = `
    var __nbAnimatedElements = new WeakSet();
    var __nbDismissTimers = new WeakMap();

    function __nbClearAnimation(el) {
        var timer = __nbDismissTimers.get(el);
        if (timer) {
            clearTimeout(timer);
            __nbDismissTimers.delete(el);
        }

        __nbAnimatedElements.delete(el);
        el._x_transitioning = false;

        var name = el.getAttribute('nb-animation');
        if (name) el.classList.remove('animate__animated', 'animate__' + name);

        var outName = el.getAttribute('nb-animation-out');
        if (outName) el.classList.remove('animate__' + outName);

        el.style.display = '';
        void el.offsetWidth;
    }

    function __nbInitAnimations() {
        document.querySelectorAll('[nb-animation]').forEach(function(el) {
            if (__nbAnimatedElements.has(el)) return;

            var name = el.getAttribute('nb-animation');
            if (!name) return;

            if (el.style.display === 'none') el.style.display = '';

            __nbAnimatedElements.add(el);

            var delay = el.getAttribute('nb-animation-delay');
            var repeat = el.getAttribute('nb-animation-repeat');
            var speed = el.getAttribute('nb-animation-speed');
            var outName = el.getAttribute('nb-animation-out');
            var dismiss = el.getAttribute('nb-animation-dismiss');

            el._x_transitioning = true;
            el.classList.add('animate__animated', 'animate__' + name);

            if (delay) el.style.animationDelay = delay;

            if (repeat === 'infinite') {
                el.classList.add('animate__infinite');
            } else if (repeat) {
                el.style.animationIterationCount = repeat;
            }

            if (speed === 'slow') el.classList.add('animate__slow');
            else if (speed === 'slower') el.classList.add('animate__slower');
            else if (speed === 'fast') el.classList.add('animate__fast');
            else if (speed === 'faster') el.classList.add('animate__faster');

            el.addEventListener('animationend', function inHandler() {
                el._x_transitioning = false;
            }, { once: true });

            if (outName && dismiss) {
                var ms = parseFloat(dismiss) * (dismiss.includes('ms') ? 1 : 1000);
                var timer = setTimeout(function() {
                    __nbDismissTimers.delete(el);
                    el._x_transitioning = true;
                    el.classList.remove('animate__' + name);
                    el.classList.add('animate__animated', 'animate__' + outName);
                    el.addEventListener('animationend', function outHandler() {
                        el.removeEventListener('animationend', outHandler);
                        el.style.display = 'none';
                        el._x_transitioning = false;
                        __nbAnimatedElements.delete(el);
                    }, { once: true });
                }, ms);
                __nbDismissTimers.set(el, timer);
            }
        });
    }

    function __nbRegisterLivewireHook() {
        if (!window.Livewire || !window.Livewire.hook) return false;
        window.Livewire.hook('morphed', function() {
            __nbInitAnimations();
        });
        return true;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', __nbInitAnimations);
    } else {
        __nbInitAnimations();
    }

    if (!__nbRegisterLivewireHook()) {
        document.addEventListener('livewire:init', __nbRegisterLivewireHook);
    }

    new MutationObserver(function(mutations) {
        var needsInit = false;
        var toReanimate = new Set();

        mutations.forEach(function(m) {
            if (m.type === 'characterData') {
                var node = m.target;
                while (node && node.nodeType !== 1) node = node.parentNode;
                while (node && node.nodeType === 1) {
                    if (node.hasAttribute('nb-animation') && node.hasAttribute('nb-animation-dismiss')) {
                        toReanimate.add(node);
                        break;
                    }
                    node = node.parentElement;
                }
            } else if (m.type === 'childList') {
                m.addedNodes.forEach(function(node) {
                    if (node.nodeType !== 1) return;
                    if (node.hasAttribute && node.hasAttribute('nb-animation')) {
                        needsInit = true;
                    } else if (node.querySelectorAll) {
                        if (node.querySelectorAll('[nb-animation]').length > 0) needsInit = true;
                    }
                });
            }
        });

        toReanimate.forEach(__nbClearAnimation);
        if (toReanimate.size > 0 || needsInit) __nbInitAnimations();
    }).observe(document.documentElement, { childList: true, subtree: true, characterData: true });

    document.addEventListener('click', function(e) {
        var el = e.target.closest('[nb-feedback]');
        if (el) {
            window.parent.postMessage({ type: 'nativeblade-native', action: 'selection', payload: {} }, '*');
        }
    }, true);
`;
