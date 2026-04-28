use serde::de::DeserializeOwned;
use tauri::{plugin::PluginApi, AppHandle, Runtime};

use crate::error::{Error, Result};
use crate::models::{PermissionStatus, PickOptions, PickResult, ReadAssetArgs, ReadAssetResult};

/// Desktop no-op stub. Media picking is mobile-only; on desktop use
/// tauri-plugin-dialog for file selection and pipe bytes through
/// whatever resize you want.
pub struct NativeBladeMedia<R: Runtime> {
    _app: AppHandle<R>,
}

impl<R: Runtime> NativeBladeMedia<R> {
    pub fn pick_from_camera(&self, _opts: PickOptions) -> Result<PickResult> {
        Err(Error::Unsupported)
    }

    pub fn pick_from_gallery(&self, _opts: PickOptions) -> Result<PickResult> {
        Err(Error::Unsupported)
    }

    pub fn pick_video(&self, _opts: PickOptions) -> Result<PickResult> {
        Err(Error::Unsupported)
    }

    pub fn check_permissions(&self) -> Result<PermissionStatus> {
        Err(Error::Unsupported)
    }

    pub fn request_permissions(&self) -> Result<PermissionStatus> {
        Err(Error::Unsupported)
    }

    pub fn read_asset(&self, _args: ReadAssetArgs) -> Result<ReadAssetResult> {
        Err(Error::Unsupported)
    }
}

pub fn init<R: Runtime, C: DeserializeOwned>(
    app: &AppHandle<R>,
    _api: PluginApi<R, C>,
) -> Result<NativeBladeMedia<R>> {
    Ok(NativeBladeMedia { _app: app.clone() })
}
