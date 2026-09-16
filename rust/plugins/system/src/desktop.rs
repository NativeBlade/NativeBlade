use serde::de::DeserializeOwned;
use tauri::{plugin::PluginApi, AppHandle, Runtime};

use crate::error::{Error, Result};

/// Desktop stub. There is no per-app settings screen on desktop, so the JS
/// bridge treats `open_app_settings` as a no-op there and never invokes this.
pub struct NativeBladeSystem<R: Runtime> {
    _app: AppHandle<R>,
}

impl<R: Runtime> NativeBladeSystem<R> {
    pub fn open_app_settings(&self) -> Result<()> {
        Err(Error::Unsupported)
    }
}

pub fn init<R: Runtime, C: DeserializeOwned>(
    app: &AppHandle<R>,
    _api: PluginApi<R, C>,
) -> Result<NativeBladeSystem<R>> {
    Ok(NativeBladeSystem { _app: app.clone() })
}
