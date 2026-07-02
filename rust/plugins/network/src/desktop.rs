use serde::de::DeserializeOwned;
use tauri::{plugin::PluginApi, AppHandle, Runtime};

use crate::error::{Error, Result};

/// Desktop stub. On desktop and web the JS layer answers from
/// `navigator.onLine` (with `type: "unknown"`) without crossing the bridge,
/// so this crate is never invoked there.
pub struct NativeBladeNetwork<R: Runtime> {
    _app: AppHandle<R>,
}

impl<R: Runtime> NativeBladeNetwork<R> {
    pub fn get_status(&self) -> Result<serde_json::Value> {
        Err(Error::Unsupported)
    }
}

pub fn init<R: Runtime, C: DeserializeOwned>(
    app: &AppHandle<R>,
    _api: PluginApi<R, C>,
) -> Result<NativeBladeNetwork<R>> {
    Ok(NativeBladeNetwork { _app: app.clone() })
}
