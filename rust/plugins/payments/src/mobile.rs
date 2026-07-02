use serde::de::DeserializeOwned;
use tauri::{
    plugin::{PluginApi, PluginHandle},
    AppHandle, Runtime,
};

use crate::error::Result;
use crate::models::{ProductsArgs, PurchaseArgs, RestoreArgs, StatusArgs};

#[cfg(target_os = "android")]
const PLUGIN_IDENTIFIER: &str = "app.nativeblade.payments";

#[cfg(target_os = "ios")]
tauri::ios_plugin_binding!(init_plugin_nativeblade_payments);

pub struct NativeBladePayments<R: Runtime>(PluginHandle<R>);

impl<R: Runtime> NativeBladePayments<R> {
    pub fn query_products(&self, args: ProductsArgs) -> Result<serde_json::Value> {
        self.0
            .run_mobile_plugin::<serde_json::Value>("queryProducts", args)
            .map_err(Into::into)
    }

    pub fn purchase(&self, args: PurchaseArgs) -> Result<serde_json::Value> {
        self.0
            .run_mobile_plugin::<serde_json::Value>("purchase", args)
            .map_err(Into::into)
    }

    pub fn restore_purchases(&self, args: RestoreArgs) -> Result<serde_json::Value> {
        self.0
            .run_mobile_plugin::<serde_json::Value>("restorePurchases", args)
            .map_err(Into::into)
    }

    pub fn subscription_status(&self, args: StatusArgs) -> Result<serde_json::Value> {
        self.0
            .run_mobile_plugin::<serde_json::Value>("subscriptionStatus", args)
            .map_err(Into::into)
    }

    /// Purchase outcomes settled outside a purchase() call (pending payments
    /// that cleared, Ask to Buy approvals, renewals), queued by the native
    /// side at boot. Draining clears the queue.
    pub fn drain_pending(&self) -> Result<serde_json::Value> {
        self.0
            .run_mobile_plugin::<serde_json::Value>("drainPending", serde_json::json!({}))
            .map_err(Into::into)
    }
}

pub fn init<R: Runtime, C: DeserializeOwned>(
    _app: &AppHandle<R>,
    api: PluginApi<R, C>,
) -> Result<NativeBladePayments<R>> {
    #[cfg(target_os = "android")]
    let handle = api.register_android_plugin(PLUGIN_IDENTIFIER, "PaymentsPlugin")?;

    #[cfg(target_os = "ios")]
    let handle = api.register_ios_plugin(init_plugin_nativeblade_payments)?;

    Ok(NativeBladePayments(handle))
}
