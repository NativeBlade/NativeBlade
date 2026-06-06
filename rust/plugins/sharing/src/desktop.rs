use serde::de::DeserializeOwned;
use tauri::{plugin::PluginApi, AppHandle, Runtime};

use crate::error::{Error, Result};
use crate::models::ShareArgs;

/// Desktop stub. The share sheet is mobile-only in v1; the JS bridge makes the
/// call a no-op on desktop.
pub struct NativeBladeSharing<R: Runtime> {
    _app: AppHandle<R>,
}

impl<R: Runtime> NativeBladeSharing<R> {
    pub fn share(&self, _args: ShareArgs) -> Result<()> {
        Err(Error::Unsupported)
    }
}

pub fn init<R: Runtime, C: DeserializeOwned>(
    app: &AppHandle<R>,
    _api: PluginApi<R, C>,
) -> Result<NativeBladeSharing<R>> {
    Ok(NativeBladeSharing { _app: app.clone() })
}
