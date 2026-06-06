use serde::de::DeserializeOwned;
use tauri::{plugin::PluginApi, AppHandle, Runtime};

use crate::error::{Error, Result};
use crate::models::ApplyArgs;

/// Desktop stub. Firebase Analytics is mobile-only here; the JS bridge makes
/// the call a no-op on desktop.
pub struct NativeBladeAnalytics<R: Runtime> {
    _app: AppHandle<R>,
}

impl<R: Runtime> NativeBladeAnalytics<R> {
    pub fn apply(&self, _args: ApplyArgs) -> Result<()> {
        Err(Error::Unsupported)
    }
}

pub fn init<R: Runtime, C: DeserializeOwned>(
    app: &AppHandle<R>,
    _api: PluginApi<R, C>,
) -> Result<NativeBladeAnalytics<R>> {
    Ok(NativeBladeAnalytics { _app: app.clone() })
}
