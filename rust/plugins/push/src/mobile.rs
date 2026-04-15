use serde::de::DeserializeOwned;
use tauri::{
    plugin::{PluginApi, PluginHandle},
    AppHandle, Runtime,
};

use crate::error::Result;
use crate::models::PushPayload;

#[cfg(target_os = "android")]
const PLUGIN_IDENTIFIER: &str = "app.nativeblade.push";

#[cfg(target_os = "ios")]
tauri::ios_plugin_binding!(init_plugin_nativeblade_push);

/// Mobile entry point. Registers the native Android/iOS plugin class
/// with Tauri and exposes a Rust handle for invoking plugin methods
/// from `#[command]`s or other Rust code.
pub struct NativeBladePush<R: Runtime>(PluginHandle<R>);

impl<R: Runtime> NativeBladePush<R> {
    /// Returns the current FCM/APNS token if the device has already
    /// registered, or `None` if registration hasn't completed yet.
    /// Callers should also listen for the `nativeblade-push-token`
    /// event to receive the token once it arrives.
    pub fn get_token(&self) -> Result<Option<String>> {
        self.0
            .run_mobile_plugin::<serde_json::Value>("getToken", ())
            .map(|v| v.get("token").and_then(|t| t.as_str()).map(String::from))
            .map_err(Into::into)
    }

    /// Prompts the user for notification permission (iOS) or triggers
    /// the Android 13+ runtime permission dialog.
    pub fn request_permission(&self) -> Result<bool> {
        self.0
            .run_mobile_plugin::<serde_json::Value>("requestPermission", ())
            .map(|v| v.get("granted").and_then(|g| g.as_bool()).unwrap_or(false))
            .map_err(Into::into)
    }

    /// Drains pushes that were delivered before the JS layer attached
    /// its listener (e.g. cold start from a tapped notification).
    pub fn drain_pending(&self) -> Result<Vec<PushPayload>> {
        self.0
            .run_mobile_plugin::<Vec<PushPayload>>("drainPending", ())
            .map_err(Into::into)
    }
}

pub fn init<R: Runtime, C: DeserializeOwned>(
    _app: &AppHandle<R>,
    api: PluginApi<R, C>,
) -> Result<NativeBladePush<R>> {
    #[cfg(target_os = "android")]
    let handle = api.register_android_plugin(PLUGIN_IDENTIFIER, "NativeBladePushPlugin")?;

    #[cfg(target_os = "ios")]
    let handle = api.register_ios_plugin(init_plugin_nativeblade_push)?;

    Ok(NativeBladePush(handle))
}
