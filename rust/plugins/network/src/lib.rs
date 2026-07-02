//! Network plugin for NativeBlade.
//!
//! Connectivity status (connected / type / metered) through
//! ConnectivityManager on Android and NWPathMonitor on iOS. The native side
//! watches the default network for the whole app lifetime and triggers a
//! `network-changed` plugin event on every real change; the JS layer
//! forwards it as the `nb:network-changed` Livewire event. On desktop and
//! web there is no native side — the JS layer falls back to
//! `navigator.onLine` with `type: "unknown"`.

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
pub use mobile::NativeBladeNetwork;
#[cfg(not(any(target_os = "android", target_os = "ios")))]
pub use desktop::NativeBladeNetwork;

pub trait NativeBladeNetworkExt<R: Runtime> {
    fn nativeblade_network(&self) -> &NativeBladeNetwork<R>;
}

impl<R: Runtime, T: Manager<R>> NativeBladeNetworkExt<R> for T {
    fn nativeblade_network(&self) -> &NativeBladeNetwork<R> {
        self.state::<NativeBladeNetwork<R>>().inner()
    }
}

pub fn init<R: Runtime>() -> TauriPlugin<R> {
    Builder::new("nativeblade-network")
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
