import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import {
    request_ad_consent,
    rewarded_ad,
    interstitial_ad,
} from '../../../js/wasm-app/actions/admob.js';
import { makeCtx, Recorder, spy } from '../helpers/ctx.js';

describe('actions/admob', () => {
    it('requests consent only on mobile', async () => {
        const invokeTauri = spy(() => Promise.resolve());

        await request_ad_consent({}, makeCtx({ isMobile: false, invokeTauri }));
        assert.equal(invokeTauri.callCount, 0);

        await request_ad_consent({}, makeCtx({ isMobile: true, invokeTauri }));
        assert.deepEqual(invokeTauri.calls[0], ['plugin:nativeblade-admob|request_ad_consent', {}]);
    });

    it('dispatches rewarded result and reward events on mobile success', async () => {
        const rec = new Recorder();
        const invokeTauri = spy(() => Promise.resolve({
            status: 'dismissed',
            id: 'coins',
            reward: { earned: true, amount: 50, type: 'coins' },
        }));

        await rewarded_ad(
            { unit: 'ca-app-pub-xxx/rewarded', id: 'coins' },
            makeCtx({ isMobile: true, invokeTauri, post: rec.fn() })
        );

        assert.deepEqual(invokeTauri.calls[0], [
            'plugin:nativeblade-admob|show_rewarded',
            { unit: 'ca-app-pub-xxx/rewarded', id: 'coins' },
        ]);
        assert.deepEqual(rec.calls[0], {
            type: 'nativeblade-ad-reward',
            data: { earned: true, amount: 50, type: 'coins', id: 'coins' },
        });
        assert.deepEqual(rec.calls[1], {
            type: 'nativeblade-ad-result',
            data: { status: 'dismissed', error: null, id: 'coins' },
        });
    });

    it('reports rewarded as failed on desktop', async () => {
        const rec = new Recorder();
        await rewarded_ad(
            { unit: 'ca-app-pub-xxx/rewarded', id: 'coins' },
            makeCtx({ isMobile: false, post: rec.fn() })
        );

        assert.deepEqual(rec.calls[0], {
            type: 'nativeblade-ad-result',
            data: { status: 'failed', error: 'admob is not supported on this platform', id: 'coins' },
        });
    });

    it('reports rewarded invoke errors as failed results', async () => {
        const rec = new Recorder();
        const invokeTauri = spy(() => Promise.reject(new Error('load failed')));

        await rewarded_ad(
            { unit: 'ca-app-pub-xxx/rewarded', id: 'coins' },
            makeCtx({ isMobile: true, invokeTauri, post: rec.fn() })
        );

        assert.deepEqual(rec.calls[0], {
            type: 'nativeblade-ad-result',
            data: { status: 'failed', error: 'load failed', id: 'coins' },
        });
    });

    it('dispatches interstitial results on success', async () => {
        const rec = new Recorder();
        const invokeTauri = spy(() => Promise.resolve({
            status: 'capped',
            id: 'level-break',
        }));

        await interstitial_ad(
            { unit: 'ca-app-pub-xxx/interstitial', id: 'level-break', minInterval: 120 },
            makeCtx({ isMobile: true, invokeTauri, post: rec.fn() })
        );

        assert.deepEqual(invokeTauri.calls[0], [
            'plugin:nativeblade-admob|show_interstitial',
            { unit: 'ca-app-pub-xxx/interstitial', id: 'level-break', minInterval: 120 },
        ]);
        assert.deepEqual(rec.calls[0], {
            type: 'nativeblade-ad-result',
            data: { status: 'capped', error: null, id: 'level-break' },
        });
    });

    it('reports interstitial as failed on desktop', async () => {
        const rec = new Recorder();
        await interstitial_ad(
            { unit: 'ca-app-pub-xxx/interstitial', id: 'level-break' },
            makeCtx({ isMobile: false, post: rec.fn() })
        );

        assert.deepEqual(rec.calls[0], {
            type: 'nativeblade-ad-result',
            data: { status: 'failed', error: 'admob is not supported on this platform', id: 'level-break' },
        });
    });
});
