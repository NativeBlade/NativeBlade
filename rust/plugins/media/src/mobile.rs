use serde::de::DeserializeOwned;
use tauri::{
    plugin::{PluginApi, PluginHandle},
    AppHandle, Runtime,
};

use crate::error::Result;
use crate::models::{PermissionStatus, PickOptions, PickResult, ReadAssetArgs, ReadAssetResult};

#[cfg(target_os = "android")]
const PLUGIN_IDENTIFIER: &str = "app.nativeblade.media";

#[cfg(target_os = "ios")]
tauri::ios_plugin_binding!(init_plugin_nativeblade_media);

pub struct NativeBladeMedia<R: Runtime>(PluginHandle<R>);

impl<R: Runtime> NativeBladeMedia<R> {
    pub fn pick_from_camera(&self, opts: PickOptions) -> Result<PickResult> {
        self.0
            .run_mobile_plugin::<PickResult>("pickFromCamera", opts)
            .map_err(Into::into)
    }

    pub fn pick_from_gallery(&self, opts: PickOptions) -> Result<PickResult> {
        self.0
            .run_mobile_plugin::<PickResult>("pickFromGallery", opts)
            .map_err(Into::into)
    }

    pub fn pick_video(&self, opts: PickOptions) -> Result<PickResult> {
        self.0
            .run_mobile_plugin::<PickResult>("pickVideo", opts)
            .map_err(Into::into)
    }

    pub fn check_permissions(&self) -> Result<PermissionStatus> {
        self.0
            .run_mobile_plugin::<PermissionStatus>("checkPermissions", ())
            .map_err(Into::into)
    }

    pub fn request_permissions(&self) -> Result<PermissionStatus> {
        self.0
            .run_mobile_plugin::<PermissionStatus>("requestPermissions", ())
            .map_err(Into::into)
    }

    pub fn read_asset(&self, args: ReadAssetArgs) -> Result<ReadAssetResult> {
        self.0
            .run_mobile_plugin::<ReadAssetResult>("readAsset", args)
            .map_err(Into::into)
    }
}

pub fn init<R: Runtime, C: DeserializeOwned>(
    _app: &AppHandle<R>,
    api: PluginApi<R, C>,
) -> Result<NativeBladeMedia<R>> {
    #[cfg(target_os = "android")]
    let handle = api.register_android_plugin(PLUGIN_IDENTIFIER, "NativeBladeMediaPlugin")?;

    #[cfg(target_os = "ios")]
    let handle = api.register_ios_plugin(init_plugin_nativeblade_media)?;

    Ok(NativeBladeMedia(handle))
}
