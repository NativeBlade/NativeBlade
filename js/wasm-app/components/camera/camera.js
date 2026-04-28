let input = null;
let appFrame = null;
let currentOptions = {};

const DEFAULTS = {
    maxWidth: 800,
    maxHeight: 800,
    quality: 0.6,
    compress: true,
};

export function init(frame) {
    appFrame = frame;

    if (!input) {
        input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*';
        input.id = 'nb-camera-input';
        document.body.appendChild(input);

        input.addEventListener('change', async () => {
            const file = input.files?.[0];
            if (!file) return;

            const opts = { ...DEFAULTS, ...currentOptions };
            let result;
            try {
                result = opts.compress
                    ? await compressImage(file, opts)
                    : { data: await fileToBase64(file), mime: file.type, size: file.size };
            } catch (err) {
                console.warn('[nativeblade] camera compress failed, sending raw:', err);
                result = { data: await fileToBase64(file), mime: file.type, size: file.size };
            }

            sendResult({
                data: result.data,
                name: file.name,
                mime: result.mime || file.type,
                size: result.size || file.size,
                originalSize: file.size,
            });

            input.value = '';
        });
    }
}

function sendResult(extra) {
    appFrame?.contentWindow?.postMessage({
        type: 'nativeblade-camera-result',
        ...extra,
        id: currentOptions.id || null,
    }, '*');
}

async function tryNative(source, options) {
    if (!window.nbMedia?.available) return false;
    try {
        const fn = source === 'camera' ? window.nbMedia.pickFromCamera : window.nbMedia.pickFromGallery;
        const payload = await fn({
            maxWidth: options.maxWidth || DEFAULTS.maxWidth,
            maxHeight: options.maxHeight || DEFAULTS.maxHeight,
            quality: options.quality || DEFAULTS.quality,
            facing: options.facing || 'back',
            output: 'both',
            id: options.id || null,
        });
        const item = payload?.items?.[0];
        if (!item) return true;
        sendResult({
            data: item.dataUrl || item.assetUrl || item.url,
            assetUrl: item.assetUrl || item.url,
            path: item.path,
            name: item.name,
            mime: item.mime,
            size: item.size,
            originalSize: item.size,
            width: item.width,
            height: item.height,
        });
        return true;
    } catch (e) {
        if (/cancel/i.test(e?.message || '')) return true;
        console.warn('[nativeblade] native media failed, falling back to JS:', e);
        return false;
    }
}

export async function open(options = {}) {
    currentOptions = options;
    if (await tryNative('camera', options)) return;
    if (!input) return;
    input.capture = options.facing === 'front' ? 'user' : 'environment';
    input.accept = options.accept || 'image/*';
    input.click();
}

export async function openGallery(options = {}) {
    currentOptions = options;
    if (await tryNative('gallery', options)) return;
    if (!input) return;
    input.removeAttribute('capture');
    input.accept = 'image/*';
    input.click();
}

// Streaming decode + Blob output. Avoids loading the full RGBA bitmap and a
// multi-MB base64 string into the JS heap, which OOMs WKWebView next to the
// PHP-WASM heap.
async function compressImage(file, opts) {
    const maxW = opts.maxWidth || 1200;
    const maxH = opts.maxHeight || 1200;
    const quality = opts.quality || 0.7;

    if (typeof createImageBitmap !== 'function') {
        return await legacyCompressImage(file, opts);
    }

    let bitmap;
    try {
        bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch (_) {
        bitmap = await createImageBitmap(file);
    }

    let width = bitmap.width;
    let height = bitmap.height;
    if (width > maxW || height > maxH) {
        const ratio = Math.min(maxW / width, maxH / height);
        width = Math.round(width * ratio);
        height = Math.round(height * ratio);
    }

    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(bitmap, 0, 0, width, height);

    if (typeof bitmap.close === 'function') bitmap.close();

    const blob = await new Promise((resolve, reject) => {
        canvas.toBlob(
            (b) => (b ? resolve(b) : reject(new Error('canvas.toBlob returned null'))),
            'image/jpeg',
            quality
        );
    });

    canvas.width = 0;
    canvas.height = 0;

    const data = await blobToDataURL(blob);
    return { data, mime: 'image/jpeg', size: blob.size };
}

function legacyCompressImage(file, opts) {
    return new Promise((resolve, reject) => {
        const img = new Image();
        const url = URL.createObjectURL(file);

        img.onload = () => {
            try {
                let { width, height } = img;
                const maxW = opts.maxWidth || 1200;
                const maxH = opts.maxHeight || 1200;
                if (width > maxW || height > maxH) {
                    const ratio = Math.min(maxW / width, maxH / height);
                    width = Math.round(width * ratio);
                    height = Math.round(height * ratio);
                }
                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0, width, height);

                URL.revokeObjectURL(url);
                img.src = '';

                canvas.toBlob(async (blob) => {
                    canvas.width = 0;
                    canvas.height = 0;
                    if (!blob) return reject(new Error('toBlob null'));
                    const data = await blobToDataURL(blob);
                    resolve({ data, mime: 'image/jpeg', size: blob.size });
                }, 'image/jpeg', opts.quality || 0.7);
            } catch (e) {
                URL.revokeObjectURL(url);
                reject(e);
            }
        };

        img.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('image load failed'));
        };

        img.src = url;
    });
}

function blobToDataURL(blob) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result);
        reader.onerror = () => reject(reader.error || new Error('FileReader error'));
        reader.readAsDataURL(blob);
    });
}

function fileToBase64(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result);
        reader.onerror = () => reject(reader.error || new Error('FileReader error'));
        reader.readAsDataURL(file);
    });
}
