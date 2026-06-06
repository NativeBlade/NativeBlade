use serde::de::DeserializeOwned;
use tauri::{
    plugin::{PluginApi, PluginHandle},
    AppHandle, Runtime,
};

use crate::error::Result;
use crate::models::{GetItemResult, KeyArgs, SetItemArgs};

#[cfg(target_os = "android")]
const PLUGIN_IDENTIFIER: &str = "app.nativeblade.securestorage";

#[cfg(target_os = "ios")]
tauri::ios_plugin_binding!(init_plugin_nativeblade_secure_storage);

pub struct NativeBladeSecureStorage<R: Runtime>(PluginHandle<R>);

impl<R: Runtime> NativeBladeSecureStorage<R> {
    pub fn set_item(&self, args: SetItemArgs) -> Result<()> {
        self.0
            .run_mobile_plugin::<()>("setItem", args)
            .map_err(Into::into)
    }

    pub fn get_item(&self, args: KeyArgs) -> Result<GetItemResult> {
        self.0
            .run_mobile_plugin::<GetItemResult>("getItem", args)
            .map_err(Into::into)
    }

    pub fn remove_item(&self, args: KeyArgs) -> Result<()> {
        self.0
            .run_mobile_plugin::<()>("removeItem", args)
            .map_err(Into::into)
    }
}

pub fn init<R: Runtime, C: DeserializeOwned>(
    _app: &AppHandle<R>,
    api: PluginApi<R, C>,
) -> Result<NativeBladeSecureStorage<R>> {
    #[cfg(target_os = "android")]
    let handle = api.register_android_plugin(PLUGIN_IDENTIFIER, "SecureStoragePlugin")?;

    #[cfg(target_os = "ios")]
    let handle = api.register_ios_plugin(init_plugin_nativeblade_secure_storage)?;

    Ok(NativeBladeSecureStorage(handle))
}
