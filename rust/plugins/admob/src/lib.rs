//! AdMob rewarded/interstitial ads and consent flow for NativeBlade.
//!
//! The PHP/JS layers talk to this mobile plugin to request ATT/UMP consent
//! and to show full-screen rewarded/interstitial ads. Desktop is unsupported;
//! the JS bridge reports a failure event there so app code can stay shared.

use tauri::{
    plugin::{Builder, TauriPlugin},
    Manager, Runtime,
};

pub use error::{Error, Result};
pub use models::{InterstitialAdArgs, RewardedAdArgs};

mod error;
mod models;

#[cfg(any(target_os = "android", target_os = "ios"))]
mod mobile;
#[cfg(not(any(target_os = "android", target_os = "ios")))]
mod desktop;

#[cfg(any(target_os = "android", target_os = "ios"))]
pub use mobile::NativeBladeAdMob;
#[cfg(not(any(target_os = "android", target_os = "ios")))]
pub use desktop::NativeBladeAdMob;

pub trait NativeBladeAdMobExt<R: Runtime> {
    fn nativeblade_admob(&self) -> &NativeBladeAdMob<R>;
}

impl<R: Runtime, T: Manager<R>> NativeBladeAdMobExt<R> for T {
    fn nativeblade_admob(&self) -> &NativeBladeAdMob<R> {
        self.state::<NativeBladeAdMob<R>>().inner()
    }
}

pub fn init<R: Runtime>() -> TauriPlugin<R> {
    Builder::new("nativeblade-admob")
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
