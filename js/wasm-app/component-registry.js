const registry = {};

export function register(name, component) {
    registry[name] = component;
}

export async function renderAll(components, activePath, appFrame) {
    const found = new Set(Object.keys(components || {}));

    for (const [name, component] of Object.entries(registry)) {
        if (!found.has(name) && name !== 'header' && component.render) {
            component.render(null, activePath, appFrame);
        }
    }

    for (const [name, data] of Object.entries(components || {})) {
        if (name === 'header') continue;

        if (!registry[name]) {
            await tryLoadCustom(name);
        }

        const component = registry[name];
        if (component?.render) {
            component.render(data, activePath, appFrame);
        }
    }

    if (!found.has('header') && registry['header']?.render) {
        registry['header'].render(null, activePath, appFrame);
    }

    if (components?.header && registry['header']?.render) {
        registry['header'].render(components.header, activePath, appFrame);
    }
}

// Resolve an app component module. A built app bundles nativeblade-components/
// through the @components alias at ITS vite build — but in the Portal the shell
// was compiled before this app existed, so fall back to the single-file bundle
// bundle-laravel.js ships at /__nb-components/{name}.js and blob-import it.
export async function importAppComponent(name) {
    try {
        return await import(`@components/${name}/${name}.js`);
    } catch (buildTimeError) {
        try {
            const { getInstance } = await import('../runtime/php-runtime.js');
            const src = getInstance()?.readFileAsText(`/app/__nb-components/${name}.js`);
            if (!src) throw buildTimeError;
            const url = URL.createObjectURL(new Blob([src], { type: 'text/javascript' }));
            try {
                return await import(/* @vite-ignore */ url);
            } finally {
                URL.revokeObjectURL(url);
            }
        } catch {
            throw buildTimeError;
        }
    }
}

async function tryLoadCustom(name) {
    try {
        registry[name] = await importAppComponent(name);
    } catch {}
}

export function getComponent(name) {
    return registry[name] || null;
}

export function updateActive(path) {
    for (const component of Object.values(registry)) {
        if (component.updateActive) component.updateActive(path);
    }
}
