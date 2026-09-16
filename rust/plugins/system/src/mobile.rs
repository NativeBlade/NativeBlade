use serde::de::DeserializeOwned;
use tauri::{
    plugin::{PluginApi, PluginHandle},
    AppHandle, Runtime,
};

use crate::error::Result;

#[cfg(target_os = "android")]
const PLUGIN_IDENTIFIER: &str = "app.nativeblade.system";

#[cfg(target_os = "ios")]
tauri::ios_plugin_binding!(init_plugin_nativeblade_system);

pub struct NativeBladeSystem<R: Runtime>(PluginHandle<R>);

impl<R: Runtime> NativeBladeSystem<R> {
    pub fn open_app_settings(&self) -> Result<()> {
        self.0
            .run_mobile_plugin::<()>("openAppSettings", ())
            .map_err(Into::into)
    }
}

pub fn init<R: Runtime, C: DeserializeOwned>(
    _app: &AppHandle<R>,
    api: PluginApi<R, C>,
) -> Result<NativeBladeSystem<R>> {
    #[cfg(target_os = "android")]
    let handle = api.register_android_plugin(PLUGIN_IDENTIFIER, "NativeBladeSystemPlugin")?;

    #[cfg(target_os = "ios")]
    let handle = api.register_ios_plugin(init_plugin_nativeblade_system)?;

    Ok(NativeBladeSystem(handle))
}
