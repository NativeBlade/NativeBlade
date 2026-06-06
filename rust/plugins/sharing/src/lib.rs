//! Native share-sheet plugin for NativeBlade.
//!
//! Opens the OS share sheet (UIActivityViewController on iOS,
//! Intent.ACTION_SEND on Android) for text and/or a URL. Mobile only; on
//! desktop the call is unsupported (the JS bridge makes it a no-op).

use tauri::{
    plugin::{Builder, TauriPlugin},
    Manager, Runtime,
};

pub use error::{Error, Result};
pub use models::ShareArgs;

mod error;
mod models;

#[cfg(any(target_os = "android", target_os = "ios"))]
mod mobile;
#[cfg(not(any(target_os = "android", target_os = "ios")))]
mod desktop;

#[cfg(any(target_os = "android", target_os = "ios"))]
pub use mobile::NativeBladeSharing;
#[cfg(not(any(target_os = "android", target_os = "ios")))]
pub use desktop::NativeBladeSharing;

pub trait NativeBladeSharingExt<R: Runtime> {
    fn nativeblade_sharing(&self) -> &NativeBladeSharing<R>;
}

impl<R: Runtime, T: Manager<R>> NativeBladeSharingExt<R> for T {
    fn nativeblade_sharing(&self) -> &NativeBladeSharing<R> {
        self.state::<NativeBladeSharing<R>>().inner()
    }
}

pub fn init<R: Runtime>() -> TauriPlugin<R> {
    Builder::new("nativeblade-sharing")
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
