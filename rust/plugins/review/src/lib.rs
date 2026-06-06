//! In-app review plugin for NativeBlade.
//!
//! Triggers the native review prompt: SKStoreReviewController on iOS and
//! Google Play In-App Review on Android. The OS decides whether to actually
//! show the prompt (it is rate-limited) and returns no result.

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
pub use mobile::NativeBladeReview;
#[cfg(not(any(target_os = "android", target_os = "ios")))]
pub use desktop::NativeBladeReview;

pub trait NativeBladeReviewExt<R: Runtime> {
    fn nativeblade_review(&self) -> &NativeBladeReview<R>;
}

impl<R: Runtime, T: Manager<R>> NativeBladeReviewExt<R> for T {
    fn nativeblade_review(&self) -> &NativeBladeReview<R> {
        self.state::<NativeBladeReview<R>>().inner()
    }
}

pub fn init<R: Runtime>() -> TauriPlugin<R> {
    Builder::new("nativeblade-review")
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
