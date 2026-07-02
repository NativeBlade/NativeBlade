use serde::de::DeserializeOwned;
use tauri::{
    plugin::{PluginApi, PluginHandle},
    AppHandle, Runtime,
};

use crate::error::Result;

#[cfg(target_os = "android")]
const PLUGIN_IDENTIFIER: &str = "app.nativeblade.network";

#[cfg(target_os = "ios")]
tauri::ios_plugin_binding!(init_plugin_nativeblade_network);

pub struct NativeBladeNetwork<R: Runtime>(PluginHandle<R>);

impl<R: Runtime> NativeBladeNetwork<R> {
    /// Current connectivity: `{connected, type, metered}`. Live changes are
    /// delivered through the `network-changed` plugin event instead.
    pub fn get_status(&self) -> Result<serde_json::Value> {
        self.0
            .run_mobile_plugin::<serde_json::Value>("getStatus", serde_json::json!({}))
            .map_err(Into::into)
    }
}

pub fn init<R: Runtime, C: DeserializeOwned>(
    _app: &AppHandle<R>,
    api: PluginApi<R, C>,
) -> Result<NativeBladeNetwork<R>> {
    #[cfg(target_os = "android")]
    let handle = api.register_android_plugin(PLUGIN_IDENTIFIER, "NetworkPlugin")?;

    #[cfg(target_os = "ios")]
    let handle = api.register_ios_plugin(init_plugin_nativeblade_network)?;

    Ok(NativeBladeNetwork(handle))
}
