import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { camera, gallery } from '../../../js/wasm-app/actions/camera.js';
import { makeCtx, spy } from '../helpers/ctx.js';

describe('actions/camera', () => {
    it('camera forwards payload to ctx.camera.open', () => {
        const open = spy();
        const ctx = makeCtx({ camera: { open, openGallery: spy() } });
        camera({ quality: 0.5 }, ctx);

        assert.deepEqual(open.calls[0], [{ quality: 0.5 }]);
    });

    it('gallery forwards payload to ctx.camera.openGallery', () => {
        const openGallery = spy();
        const ctx = makeCtx({ camera: { open: spy(), openGallery } });
        gallery({ quality: 0.9 }, ctx);

        assert.deepEqual(openGallery.calls[0], [{ quality: 0.9 }]);
    });
});
