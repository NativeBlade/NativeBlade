use serde::de::DeserializeOwned;
use serde::Serialize;
use tauri::{
    plugin::{PluginApi, PluginHandle},
    AppHandle, Runtime,
};

use crate::error::Result;
use crate::SnapshotRect;

const PLUGIN_IDENTIFIER: &str = "app.nativeblade.nativenav";

pub struct NativeBladeNativeNav<R: Runtime>(PluginHandle<R>);

impl<R: Runtime> NativeBladeNativeNav<R> {
    /// Freeze the given page region as a native overlay above the webview.
    pub fn snapshot(&self, rect: SnapshotRect) -> Result<()> {
        self.0.run_mobile_plugin::<()>("snapshot", rect).map_err(Into::into)
    }

    /// Animate the overlay away (`direction`: "forward" | "back") and remove it.
    pub fn animate(&self, direction: &str, duration_ms: u64) -> Result<()> {
        #[derive(Serialize)]
        #[serde(rename_all = "camelCase")]
        struct Args<'a> {
            direction: &'a str,
            duration: u64,
        }
        self.0
            .run_mobile_plugin::<()>("animate", Args { direction, duration: duration_ms })
            .map_err(Into::into)
    }

    /// Remove the overlay without animating (failure/cleanup path).
    pub fn cancel(&self) -> Result<()> {
        self.0.run_mobile_plugin::<()>("cancel", ()).map_err(Into::into)
    }
}

pub fn init<R: Runtime, C: DeserializeOwned>(
    _app: &AppHandle<R>,
    api: PluginApi<R, C>,
) -> Result<NativeBladeNativeNav<R>> {
    let handle = api.register_android_plugin(PLUGIN_IDENTIFIER, "NativeNavPlugin")?;
    Ok(NativeBladeNativeNav(handle))
}
