//! Camera + gallery picker plugin for NativeBlade.
//!
//! Native capture + resize on Android / iOS. Skips the WebView OOM
//! path (full-res RGBA decode + base64 dataURL in JS heap) by doing
//! the pipeline on the native side and returning an asset URL, a
//! pre-encoded dataURL, or both.

use tauri::{
    plugin::{Builder, TauriPlugin},
    Manager, Runtime,
};

pub use error::{Error, Result};
pub use models::{
    CameraFacing, MediaItem, OutputMode, PermissionState, PermissionStatus, PickOptions,
    PickResult, ReadAssetArgs, ReadAssetResult,
};

mod error;
mod models;

#[cfg(any(target_os = "android", target_os = "ios"))]
mod mobile;
#[cfg(not(any(target_os = "android", target_os = "ios")))]
mod desktop;

#[cfg(any(target_os = "android", target_os = "ios"))]
pub use mobile::NativeBladeMedia;
#[cfg(not(any(target_os = "android", target_os = "ios")))]
pub use desktop::NativeBladeMedia;

pub trait NativeBladeMediaExt<R: Runtime> {
    fn nativeblade_media(&self) -> &NativeBladeMedia<R>;
}

impl<R: Runtime, T: Manager<R>> NativeBladeMediaExt<R> for T {
    fn nativeblade_media(&self) -> &NativeBladeMedia<R> {
        self.state::<NativeBladeMedia<R>>().inner()
    }
}

pub fn init<R: Runtime>() -> TauriPlugin<R> {
    Builder::new("nativeblade-media")
        .setup(|app, api| {
            #[cfg(any(target_os = "android", target_os = "ios"))]
            let handle = mobile::init(app, api)?;
            #[cfg(not(any(target_os = "android", target_os = "ios")))]
            let handle = desktop::init(app, api)?;

            app.manage(handle);
            Ok(())
        })
        .build()
}
