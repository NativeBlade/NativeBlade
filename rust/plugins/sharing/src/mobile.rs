use serde::de::DeserializeOwned;
use tauri::{
    plugin::{PluginApi, PluginHandle},
    AppHandle, Runtime,
};

use crate::error::Result;
use crate::models::ShareArgs;

#[cfg(target_os = "android")]
const PLUGIN_IDENTIFIER: &str = "app.nativeblade.sharing";

#[cfg(target_os = "ios")]
tauri::ios_plugin_binding!(init_plugin_nativeblade_sharing);

pub struct NativeBladeSharing<R: Runtime>(PluginHandle<R>);

impl<R: Runtime> NativeBladeSharing<R> {
    pub fn share(&self, args: ShareArgs) -> Result<()> {
        self.0
            .run_mobile_plugin::<()>("share", args)
            .map_err(Into::into)
    }
}

pub fn init<R: Runtime, C: DeserializeOwned>(
    _app: &AppHandle<R>,
    api: PluginApi<R, C>,
) -> Result<NativeBladeSharing<R>> {
    #[cfg(target_os = "android")]
    let handle = api.register_android_plugin(PLUGIN_IDENTIFIER, "SharePlugin")?;

    #[cfg(target_os = "ios")]
    let handle = api.register_ios_plugin(init_plugin_nativeblade_sharing)?;

    Ok(NativeBladeSharing(handle))
}
