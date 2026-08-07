import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

import { anchorToPosition } from '../../../js/wasm-app/actions/window.js';

// A 1920x1040 work area at the origin (a 1080 screen minus a 40px taskbar).
const AREA = { x: 0, y: 0, width: 1920, height: 1040 };
const WIN = { w: 620, h: 60 };

describe('window/anchorToPosition', () => {
    it('centers along the bottom edge', () => {
        assert.deepEqual(anchorToPosition('bottom-center', AREA, WIN.w, WIN.h), [650, 980]);
    });

    it('applies a margin as an inset from the anchored edges', () => {
        assert.deepEqual(anchorToPosition('bottom-center', AREA, WIN.w, WIN.h, 12), [650, 968]);
    });

    it('resolves every corner', () => {
        assert.deepEqual(anchorToPosition('top-left', AREA, WIN.w, WIN.h), [0, 0]);
        assert.deepEqual(anchorToPosition('top-right', AREA, WIN.w, WIN.h), [1300, 0]);
        assert.deepEqual(anchorToPosition('bottom-left', AREA, WIN.w, WIN.h), [0, 980]);
        assert.deepEqual(anchorToPosition('bottom-right', AREA, WIN.w, WIN.h), [1300, 980]);
    });

    it('centers on both axes for "center"', () => {
        assert.deepEqual(anchorToPosition('center', AREA, WIN.w, WIN.h), [650, 490]);
    });

    it('honors a non-zero work-area origin (a second monitor)', () => {
        const second = { x: 1920, y: 0, width: 1920, height: 1080 };
        assert.deepEqual(anchorToPosition('top-left', second, WIN.w, WIN.h, 10), [1930, 10]);
    });

    it('returns null for an unknown anchor', () => {
        assert.equal(anchorToPosition('nowhere', AREA, WIN.w, WIN.h), null);
    });
});
