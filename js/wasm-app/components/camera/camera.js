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
            const result = opts.compress
                ? await compressImage(file, opts)
                : await fileToBase64(file);

            appFrame?.contentWindow?.postMessage({
                type: 'nativeblade-camera-result',
                data: result.data,
                name: file.name,
                mime: result.mime || file.type,
                size: result.size || file.size,
                originalSize: file.size,
                id: currentOptions.id || null,
            }, '*');

            input.value = '';
        });
    }
}

export function open(options = {}) {
    if (!input) return;
    currentOptions = options;
    input.capture = options.facing === 'front' ? 'user' : 'environment';
    input.accept = options.accept || 'image/*';
    input.click();
}

export function openGallery(options = {}) {
    if (!input) return;
    currentOptions = options;
    input.removeAttribute('capture');
    input.accept = 'image/*';
    input.click();
}

function compressImage(file, opts) {
    return new Promise((resolve) => {
        const img = new Image();
        const url = URL.createObjectURL(file);

        img.onload = () => {
            URL.revokeObjectURL(url);

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

            const quality = opts.quality || 0.7;
            const data = canvas.toDataURL('image/jpeg', quality);
            const size = Math.round((data.length - 'data:image/jpeg;base64,'.length) * 0.75);

            resolve({ data, mime: 'image/jpeg', size });
        };

        img.onerror = () => {
            URL.revokeObjectURL(url);
            fileToBase64(file).then(data => resolve({ data, mime: file.type, size: file.size }));
        };

        img.src = url;
    });
}

function fileToBase64(file) {
    return new Promise((resolve) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result);
        reader.readAsDataURL(file);
    });
}
