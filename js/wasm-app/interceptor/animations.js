export const code = `
    function __nbInitAnimations() {
        document.querySelectorAll('[nb-animation]').forEach(function(el) {
            var name = el.getAttribute('nb-animation');
            if (!name) return;

            var delay = el.getAttribute('nb-animation-delay');
            var repeat = el.getAttribute('nb-animation-repeat');
            var speed = el.getAttribute('nb-animation-speed');

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
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', __nbInitAnimations);
    } else {
        __nbInitAnimations();
    }

    new MutationObserver(function(mutations) {
        mutations.forEach(function(m) {
            m.addedNodes.forEach(function(node) {
                if (node.nodeType !== 1) return;
                if (node.hasAttribute && node.hasAttribute('nb-animation')) {
                    __nbInitAnimations();
                } else if (node.querySelectorAll) {
                    var children = node.querySelectorAll('[nb-animation]');
                    if (children.length) __nbInitAnimations();
                }
            });
        });
    }).observe(document.documentElement, { childList: true, subtree: true });

    document.addEventListener('click', function(e) {
        var el = e.target.closest('[nb-feedback]');
        if (el) {
            window.parent.postMessage({ type: 'nativeblade-native', action: 'selection', payload: {} }, '*');
        }
    }, true);
`;
