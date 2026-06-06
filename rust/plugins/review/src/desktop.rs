use serde::de::DeserializeOwned;
use tauri::{plugin::PluginApi, AppHandle, Runtime};

use crate::error::{Error, Result};

/// Desktop stub. There is no OS-level in-app review on desktop; the JS
/// bridge opens the store URL fallback (when provided) instead.
pub struct NativeBladeReview<R: Runtime> {
    _app: AppHandle<R>,
}

impl<R: Runtime> NativeBladeReview<R> {
    pub fn request_review(&self) -> Result<()> {
        Err(Error::Unsupported)
    }
}

pub fn init<R: Runtime, C: DeserializeOwned>(
    app: &AppHandle<R>,
    _api: PluginApi<R, C>,
) -> Result<NativeBladeReview<R>> {
    Ok(NativeBladeReview { _app: app.clone() })
}
