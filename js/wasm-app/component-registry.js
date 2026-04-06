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

async function tryLoadCustom(name) {
    try {
        const mod = await import(`../nativeblade-components/${name}/${name}.js`);
        registry[name] = mod;
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
