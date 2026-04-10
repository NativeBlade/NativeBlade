export const code = `
    var __nbAnimatedSet = new WeakSet();

    function __nbInitAnimations() {
        document.querySelectorAll('[nb-animation]').forEach(function(el) {
            if (__nbAnimatedSet.has(el)) return;

            var name = el.getAttribute('nb-animation');
            if (!name) return;

            __nbAnimatedSet.add(el);

            var delay = el.getAttribute('nb-animation-delay');
            var repeat = el.getAttribute('nb-animation-repeat');
            var speed = el.getAttribute('nb-animation-speed');
            var outName = el.getAttribute('nb-animation-out');
            var dismiss = el.getAttribute('nb-animation-dismiss');

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

            if (outName && dismiss) {
                var ms = parseFloat(dismiss) * (dismiss.includes('ms') ? 1 : 1000);
                setTimeout(function() {
                    el.classList.remove('animate__' + name);
                    el.classList.add('animate__' + outName);
                    el.addEventListener('animationend', function handler() {
                        el.removeEventListener('animationend', handler);
                        el.style.display = 'none';
                    });
                }, ms);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', __nbInitAnimations);
    } else {
        __nbInitAnimations();
    }

    new MutationObserver(function(mutations) {
        var hasNew = false;
        mutations.forEach(function(m) {
            m.addedNodes.forEach(function(node) {
                if (node.nodeType !== 1) return;
                if (node.hasAttribute && node.hasAttribute('nb-animation') && !__nbAnimatedSet.has(node)) {
                    hasNew = true;
                } else if (node.querySelectorAll) {
                    node.querySelectorAll('[nb-animation]').forEach(function(child) {
                        if (!__nbAnimatedSet.has(child)) hasNew = true;
                    });
                }
            });
        });
        if (hasNew) __nbInitAnimations();
    }).observe(document.documentElement, { childList: true, subtree: true });

    document.addEventListener('click', function(e) {
        var el = e.target.closest('[nb-feedback]');
        if (el) {
            window.parent.postMessage({ type: 'nativeblade-native', action: 'selection', payload: {} }, '*');
        }
    }, true);
`;
