//! System utilities plugin for NativeBlade.
//!
//! Always-on plugin for small OS-level helpers that don't belong to any
//! feature plugin. Currently exposes a single command, `open_app_settings`,
//! which sends the user to this app's settings screen (the natural next step
//! after a permission was permanently denied).
//!
//! Android: Settings.ACTION_APPLICATION_DETAILS_SETTINGS for the package.
//! iOS: UIApplication.openSettingsURLString.
//! Desktop: unsupported (there is no per-app settings screen).

use tauri::{
    plugin::{Builder, TauriPlugin},
    Manager, Runtime,
};

pub use error::{Error, Result};

mod error;

#[cfg(any(target_os = "android", target_os = "ios"))]
mod mobile;
#[cfg(not(any(target_os = "android", target_os = "ios")))]
mod desktop;

#[cfg(any(target_os = "android", target_os = "ios"))]
pub use mobile::NativeBladeSystem;
#[cfg(not(any(target_os = "android", target_os = "ios")))]
pub use desktop::NativeBladeSystem;

pub trait NativeBladeSystemExt<R: Runtime> {
    fn nativeblade_system(&self) -> &NativeBladeSystem<R>;
}

impl<R: Runtime, T: Manager<R>> NativeBladeSystemExt<R> for T {
    fn nativeblade_system(&self) -> &NativeBladeSystem<R> {
        self.state::<NativeBladeSystem<R>>().inner()
    }
}

pub fn init<R: Runtime>() -> TauriPlugin<R> {
    Builder::new("nativeblade-system")
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
