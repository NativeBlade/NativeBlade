use serde::de::DeserializeOwned;
use tauri::{plugin::PluginApi, AppHandle, Runtime};

use crate::error::{Error, Result};
use crate::SnapshotRect;

/// Non-Android stub: every command reports Unsupported, and the JS router
/// falls back to CSS transitions after its first failed probe.
pub struct NativeBladeNativeNav<R: Runtime> {
    _app: AppHandle<R>,
}

impl<R: Runtime> NativeBladeNativeNav<R> {
    pub fn snapshot(&self, _rect: SnapshotRect) -> Result<()> {
        Err(Error::Unsupported)
    }

    pub fn animate(&self, _direction: &str, _duration_ms: u64) -> Result<()> {
        Err(Error::Unsupported)
    }

    pub fn cancel(&self) -> Result<()> {
        Err(Error::Unsupported)
    }
}

pub fn init<R: Runtime, C: DeserializeOwned>(
    app: &AppHandle<R>,
    _api: PluginApi<R, C>,
) -> Result<NativeBladeNativeNav<R>> {
    Ok(NativeBladeNativeNav { _app: app.clone() })
}
