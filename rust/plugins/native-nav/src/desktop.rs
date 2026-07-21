use serde::de::DeserializeOwned;
use tauri::{plugin::PluginApi, AppHandle, Runtime};

use crate::error::Result;

/// Non-Android stub: the JS router probes the plugin once and falls back to
/// CSS transitions when it reports Unsupported.
pub struct NativeBladeNativeNav<R: Runtime> {
    _app: AppHandle<R>,
}

pub fn init<R: Runtime, C: DeserializeOwned>(
    app: &AppHandle<R>,
    _api: PluginApi<R, C>,
) -> Result<NativeBladeNativeNav<R>> {
    Ok(NativeBladeNativeNav { _app: app.clone() })
}
