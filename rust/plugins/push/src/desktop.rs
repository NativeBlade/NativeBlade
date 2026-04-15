use serde::de::DeserializeOwned;
use tauri::{plugin::PluginApi, AppHandle, Runtime};

use crate::error::{Error, Result};
use crate::models::PushPayload;

/// Desktop no-op stub — push notifications are a mobile-only feature.
///
/// On desktop, use [`tauri-plugin-notification`] for local notifications
/// and the Rust tokio scheduler for timed reminders. Real server-pushed
/// notifications only make sense on mobile where the OS (FCM / APNS)
/// handles delivery even when the app is closed.
pub struct NativeBladePush<R: Runtime> {
    _app: AppHandle<R>,
}

impl<R: Runtime> NativeBladePush<R> {
    pub fn get_token(&self) -> Result<Option<String>> {
        Err(Error::Unsupported)
    }

    pub fn request_permission(&self) -> Result<bool> {
        Err(Error::Unsupported)
    }

    pub fn drain_pending(&self) -> Result<Vec<PushPayload>> {
        Ok(Vec::new())
    }
}

pub fn init<R: Runtime, C: DeserializeOwned>(
    app: &AppHandle<R>,
    _api: PluginApi<R, C>,
) -> Result<NativeBladePush<R>> {
    Ok(NativeBladePush { _app: app.clone() })
}
