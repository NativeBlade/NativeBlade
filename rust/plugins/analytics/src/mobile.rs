use serde::de::DeserializeOwned;
use tauri::{
    plugin::{PluginApi, PluginHandle},
    AppHandle, Runtime,
};

use crate::error::Result;
use crate::models::ApplyArgs;

#[cfg(target_os = "android")]
const PLUGIN_IDENTIFIER: &str = "app.nativeblade.analytics";

#[cfg(target_os = "ios")]
tauri::ios_plugin_binding!(init_plugin_nativeblade_analytics);

pub struct NativeBladeAnalytics<R: Runtime>(PluginHandle<R>);

impl<R: Runtime> NativeBladeAnalytics<R> {
    pub fn apply(&self, args: ApplyArgs) -> Result<()> {
        self.0
            .run_mobile_plugin::<()>("apply", args)
            .map_err(Into::into)
    }
}

pub fn init<R: Runtime, C: DeserializeOwned>(
    _app: &AppHandle<R>,
    api: PluginApi<R, C>,
) -> Result<NativeBladeAnalytics<R>> {
    #[cfg(target_os = "android")]
    let handle = api.register_android_plugin(PLUGIN_IDENTIFIER, "AnalyticsPlugin")?;

    #[cfg(target_os = "ios")]
    let handle = api.register_ios_plugin(init_plugin_nativeblade_analytics)?;

    Ok(NativeBladeAnalytics(handle))
}
