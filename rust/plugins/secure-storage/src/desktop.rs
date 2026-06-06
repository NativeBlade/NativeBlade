use serde::de::DeserializeOwned;
use tauri::{plugin::PluginApi, AppHandle, Runtime};

use crate::error::{Error, Result};
use crate::models::{GetItemResult, KeyArgs, SetItemArgs};

/// Desktop stub. Secure storage is mobile-only in v1; the JS bridge treats a
/// missing value as null on desktop. A future version could back this with the
/// OS credential store (Keychain / Credential Manager / Secret Service) via the
/// `keyring` crate.
pub struct NativeBladeSecureStorage<R: Runtime> {
    _app: AppHandle<R>,
}

impl<R: Runtime> NativeBladeSecureStorage<R> {
    pub fn set_item(&self, _args: SetItemArgs) -> Result<()> {
        Err(Error::Unsupported)
    }

    pub fn get_item(&self, _args: KeyArgs) -> Result<GetItemResult> {
        Ok(GetItemResult::default())
    }

    pub fn remove_item(&self, _args: KeyArgs) -> Result<()> {
        Err(Error::Unsupported)
    }
}

pub fn init<R: Runtime, C: DeserializeOwned>(
    app: &AppHandle<R>,
    _api: PluginApi<R, C>,
) -> Result<NativeBladeSecureStorage<R>> {
    Ok(NativeBladeSecureStorage { _app: app.clone() })
}
