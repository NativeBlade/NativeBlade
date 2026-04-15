//! Push notifications (FCM / APNS) plugin for NativeBlade.
//!
//! Wires the Android and iOS native sides (Firebase Cloud Messaging and
//! Apple Push Notification Service) into Tauri events that the JS layer
//! can listen for. The plugin itself is passive: it registers for push
//! at app boot, emits `nativeblade-push-token` when the device token
//! arrives, and emits `nativeblade-push` every time a push is delivered.
//!
//! PHP-level routing to developer-provided callbacks is done higher up
//! by the NativeBlade framework, not by this crate.

use tauri::{
    plugin::{Builder, TauriPlugin},
    Manager, Runtime,
};

pub use error::{Error, Result};
pub use models::{AppState, NotificationData, PushPayload, TokenPayload};

mod error;
mod models;

#[cfg(any(target_os = "android", target_os = "ios"))]
mod mobile;
#[cfg(not(any(target_os = "android", target_os = "ios")))]
mod desktop;

#[cfg(any(target_os = "android", target_os = "ios"))]
pub use mobile::NativeBladePush;
#[cfg(not(any(target_os = "android", target_os = "ios")))]
pub use desktop::NativeBladePush;

/// Event name emitted when a push notification is delivered.
pub const EVENT_PUSH: &str = "nativeblade-push";

/// Event name emitted when the device token is registered or refreshed.
pub const EVENT_TOKEN: &str = "nativeblade-push-token";

/// Extension trait that makes the plugin available as `app.nativeblade_push()`.
pub trait NativeBladePushExt<R: Runtime> {
    fn nativeblade_push(&self) -> &NativeBladePush<R>;
}

impl<R: Runtime, T: Manager<R>> NativeBladePushExt<R> for T {
    fn nativeblade_push(&self) -> &NativeBladePush<R> {
        self.state::<NativeBladePush<R>>().inner()
    }
}

/// Initialize the plugin. On desktop this is a no-op stub that fails
/// with [`Error::Unsupported`] when any method is called; push
/// notifications only make sense on mobile platforms where the OS
/// itself (FCM / APNS) handles delivery.
pub fn init<R: Runtime>() -> TauriPlugin<R> {
    Builder::new("nativeblade-push")
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
