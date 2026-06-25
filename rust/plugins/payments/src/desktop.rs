use serde::de::DeserializeOwned;
use tauri::{plugin::PluginApi, AppHandle, Runtime};

use crate::error::{Error, Result};
use crate::models::{ProductsArgs, PurchaseArgs, RestoreArgs, StatusArgs};

/// Desktop stub. Native store billing is mobile-only; the JS bridge makes every
/// call a no-op that reports a failure event (or opens an external web checkout
/// when one is provided) so handler code runs unchanged everywhere.
pub struct NativeBladePayments<R: Runtime> {
    _app: AppHandle<R>,
}

impl<R: Runtime> NativeBladePayments<R> {
    pub fn query_products(&self, _args: ProductsArgs) -> Result<serde_json::Value> {
        Err(Error::Unsupported)
    }

    pub fn purchase(&self, _args: PurchaseArgs) -> Result<serde_json::Value> {
        Err(Error::Unsupported)
    }

    pub fn restore_purchases(&self, _args: RestoreArgs) -> Result<serde_json::Value> {
        Err(Error::Unsupported)
    }

    pub fn subscription_status(&self, _args: StatusArgs) -> Result<serde_json::Value> {
        Err(Error::Unsupported)
    }
}

pub fn init<R: Runtime, C: DeserializeOwned>(
    app: &AppHandle<R>,
    _api: PluginApi<R, C>,
) -> Result<NativeBladePayments<R>> {
    Ok(NativeBladePayments { _app: app.clone() })
}
