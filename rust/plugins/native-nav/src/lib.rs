//! Native page-transition compositor for NativeBlade.
//!
//! The JS router asks the platform to snapshot the current page as a native
//! overlay, swaps the DOM instantly beneath it, and the platform animates the
//! overlay away in its own idiom (Material shared-axis on Android, a push/pop
//! slide on iOS). Desktop reports Unsupported and the router falls back to CSS
//! transitions there.

use tauri::{
    plugin::{Builder, TauriPlugin},
    Manager, Runtime,
};

pub use error::{Error, Result};

mod error;

/// The page region to freeze, in CSS pixels plus the device pixel ratio —
/// exactly what the JS router sends from `getBoundingClientRect()`.
#[derive(Debug, Clone, serde::Serialize)]
#[serde(rename_all = "camelCase")]
pub struct SnapshotRect {
    pub x: f32,
    pub y: f32,
    pub width: f32,
    pub height: f32,
    pub dpr: f32,
}

#[cfg(any(target_os = "android", target_os = "ios"))]
mod mobile;
#[cfg(not(any(target_os = "android", target_os = "ios")))]
mod desktop;

#[cfg(any(target_os = "android", target_os = "ios"))]
pub use mobile::NativeBladeNativeNav;
#[cfg(not(any(target_os = "android", target_os = "ios")))]
pub use desktop::NativeBladeNativeNav;

pub trait NativeBladeNativeNavExt<R: Runtime> {
    fn nativeblade_native_nav(&self) -> &NativeBladeNativeNav<R>;
}

impl<R: Runtime, T: Manager<R>> NativeBladeNativeNavExt<R> for T {
    fn nativeblade_native_nav(&self) -> &NativeBladeNativeNav<R> {
        self.state::<NativeBladeNativeNav<R>>().inner()
    }
}

pub fn init<R: Runtime>() -> TauriPlugin<R> {
    Builder::new("nativeblade-native-nav")
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
