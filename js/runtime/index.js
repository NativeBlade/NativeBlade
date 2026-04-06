import bridge from './bridge.js';
import { init as initHtmxBridge } from './htmx-bridge.js';

initHtmxBridge();

window.NativeBlade = { bridge };
