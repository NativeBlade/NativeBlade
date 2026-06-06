//! Secure storage plugin for NativeBlade.
//!
//! Encrypted key-value storage backed by the OS keystore: the iOS Keychain
//! and, on Android, Google Tink AEAD with the keyset sealed by the Android
//! Keystore. Mobile only; on desktop the calls are unsupported (the JS bridge
//! reports a null value).

use tauri::{
    plugin::{Builder, TauriPlugin},
    Manager, Runtime,
};

pub use error::{Error, Result};
pub use models::{GetItemResult, KeyArgs, SetItemArgs};

mod error;
mod models;

#[cfg(any(target_os = "android", target_os = "ios"))]
mod mobile;
#[cfg(not(any(target_os = "android", target_os = "ios")))]
mod desktop;

#[cfg(any(target_os = "android", target_os = "ios"))]
pub use mobile::NativeBladeSecureStorage;
#[cfg(not(any(target_os = "android", target_os = "ios")))]
pub use desktop::NativeBladeSecureStorage;

pub trait NativeBladeSecureStorageExt<R: Runtime> {
    fn nativeblade_secure_storage(&self) -> &NativeBladeSecureStorage<R>;
}

impl<R: Runtime, T: Manager<R>> NativeBladeSecureStorageExt<R> for T {
    fn nativeblade_secure_storage(&self) -> &NativeBladeSecureStorage<R> {
        self.state::<NativeBladeSecureStorage<R>>().inner()
    }
}

pub fn init<R: Runtime>() -> TauriPlugin<R> {
    Builder::new("nativeblade-secure-storage")
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
