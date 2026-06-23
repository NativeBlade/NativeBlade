//! AdMob plugin for NativeBlade.
//!
//! Rewarded and interstitial ads through the Google Mobile Ads SDK, plus the
//! consent layer ads require (UMP on both platforms, ATT on iOS). Mobile only;
//! on desktop every call is unsupported (the JS bridge reports a failure event
//! so handler code runs unchanged). The AdMob app id is wired by
//! `NativeBladeConfig::admob(...)`.

use tauri::{
    plugin::{Builder, TauriPlugin},
    Manager, Runtime,
};

pub use error::{Error, Result};
pub use models::{ConsentArgs, InterstitialArgs, RewardedArgs};

mod error;
mod models;

#[cfg(any(target_os = "android", target_os = "ios"))]
mod mobile;
#[cfg(not(any(target_os = "android", target_os = "ios")))]
mod desktop;

#[cfg(any(target_os = "android", target_os = "ios"))]
pub use mobile::NativeBladeAdmob;
#[cfg(not(any(target_os = "android", target_os = "ios")))]
pub use desktop::NativeBladeAdmob;

pub trait NativeBladeAdmobExt<R: Runtime> {
    fn nativeblade_admob(&self) -> &NativeBladeAdmob<R>;
}

impl<R: Runtime, T: Manager<R>> NativeBladeAdmobExt<R> for T {
    fn nativeblade_admob(&self) -> &NativeBladeAdmob<R> {
        self.state::<NativeBladeAdmob<R>>().inner()
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
