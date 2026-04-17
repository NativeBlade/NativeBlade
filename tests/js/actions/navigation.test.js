import { describe, it, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { navigate, showModal, hideModal } from '../../../js/wasm-app/actions/navigation.js';
import { register } from '../../../js/wasm-app/component-registry.js';
import { spy } from '../helpers/ctx.js';

describe('actions/navigation', () => {
    let posts;

    beforeEach(() => {
        posts = [];
        globalThis.window = {
            postMessage: (msg, target) => posts.push({ msg, target }),
        };
        // Clear modal registry to avoid bleed-over between tests.
        register('modal', null);
    });

    afterEach(() => {
        delete globalThis.window;
    });

    describe('navigate', () => {
        it('posts a nativeblade-navigate message with path, replace=false, transition', () => {
            navigate({ path: '/home', transition: 'slide' });

            assert.equal(posts.length, 1);
            assert.equal(posts[0].target, '*');
            assert.deepEqual(posts[0].msg, {
                type: 'nativeblade-navigate',
                path: '/home',
                replace: false,
                transition: 'slide',
            });
        });

        it('coerces replace to boolean', () => {
            navigate({ path: '/x', replace: 1 });
            assert.equal(posts[0].msg.replace, true);

            navigate({ path: '/y' });
            assert.equal(posts[1].msg.replace, false);
        });
    });

    describe('showModal', () => {
        it('calls modal.show when the modal component is registered', () => {
            const show = spy();
            register('modal', { show, hide: spy() });

            showModal();
            assert.equal(show.callCount, 1);
        });

        it('is a no-op when no modal is registered', () => {
            register('modal', null);
            assert.doesNotThrow(() => showModal());
        });

        it('is a no-op when modal has no show method', () => {
            register('modal', {});
            assert.doesNotThrow(() => showModal());
        });
    });

    describe('hideModal', () => {
        it('calls modal.hide when the modal component is registered', () => {
            const hide = spy();
            register('modal', { show: spy(), hide });

            hideModal();
            assert.equal(hide.callCount, 1);
        });

        it('is a no-op when no modal is registered', () => {
            register('modal', null);
            assert.doesNotThrow(() => hideModal());
        });
    });
});
