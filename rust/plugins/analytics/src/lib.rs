//! Firebase Analytics plugin for NativeBlade.
//!
//! Applies a batch of analytics ops (event, screen, user id/property, consent)
//! to the native Firebase Analytics SDK. Mobile only; on desktop the call is
//! unsupported (the JS bridge makes it a no-op). Relies on the Firebase project
//! config wired by `NativeBladeConfig::firebase(...)`.

use tauri::{
    plugin::{Builder, TauriPlugin},
    Manager, Runtime,
};

pub use error::{Error, Result};
pub use models::ApplyArgs;

mod error;
mod models;

#[cfg(any(target_os = "android", target_os = "ios"))]
mod mobile;
#[cfg(not(any(target_os = "android", target_os = "ios")))]
mod desktop;

#[cfg(any(target_os = "android", target_os = "ios"))]
pub use mobile::NativeBladeAnalytics;
#[cfg(not(any(target_os = "android", target_os = "ios")))]
pub use desktop::NativeBladeAnalytics;

pub trait NativeBladeAnalyticsExt<R: Runtime> {
    fn nativeblade_analytics(&self) -> &NativeBladeAnalytics<R>;
}

impl<R: Runtime, T: Manager<R>> NativeBladeAnalyticsExt<R> for T {
    fn nativeblade_analytics(&self) -> &NativeBladeAnalytics<R> {
        self.state::<NativeBladeAnalytics<R>>().inner()
    }
}

pub fn init<R: Runtime>() -> TauriPlugin<R> {
    Builder::new("nativeblade-analytics")
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
