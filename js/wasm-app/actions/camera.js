// Camera actions — camera, gallery
// Uses: ctx.camera (camera component module)

export function camera(payload, ctx) {
    ctx.camera.open(payload);
}

export function gallery(payload, ctx) {
    ctx.camera.openGallery(payload);
}
