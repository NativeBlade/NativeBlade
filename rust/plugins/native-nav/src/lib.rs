//! Native page-transition compositor for NativeBlade.
//!
//! The JS router asks the platform to snapshot the current page as a native
//! overlay, swaps the DOM instantly beneath it, and the platform animates the
//! overlay away in its own idiom (Material on Android; iOS push/pop when the
//! Swift side lands). Android-only prototype — every other platform reports
//! Unsupported and the router falls back to CSS transitions.

use tauri::{
    plugin::{Builder, TauriPlugin},
    Manager, Runtime,
};

pub use error::{Error, Result};

mod error;

#[cfg(target_os = "android")]
mod mobile;
#[cfg(not(target_os = "android"))]
mod desktop;

#[cfg(target_os = "android")]
pub use mobile::NativeBladeNativeNav;
#[cfg(not(target_os = "android"))]
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
            #[cfg(target_os = "android")]
            let handle = mobile::init(app, api)?;
            #[cfg(not(target_os = "android"))]
            let handle = desktop::init(app, api)?;

            app.manage(handle);
            Ok(())
        })
        .build()
}
