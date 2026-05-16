use serde::de::DeserializeOwned;
use tauri::{plugin::PluginApi, AppHandle, Runtime};

use crate::error::{Error, Result};
use crate::models::PushPayload;

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
