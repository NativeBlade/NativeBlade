use serde::de::DeserializeOwned;
use tauri::{
    plugin::{PluginApi, PluginHandle},
    AppHandle, Runtime,
};

use crate::error::Result;

const PLUGIN_IDENTIFIER: &str = "app.nativeblade.nativenav";

pub struct NativeBladeNativeNav<R: Runtime>(#[allow(dead_code)] PluginHandle<R>);

pub fn init<R: Runtime, C: DeserializeOwned>(
    _app: &AppHandle<R>,
    api: PluginApi<R, C>,
) -> Result<NativeBladeNativeNav<R>> {
    let handle = api.register_android_plugin(PLUGIN_IDENTIFIER, "NativeNavPlugin")?;
    Ok(NativeBladeNativeNav(handle))
}
