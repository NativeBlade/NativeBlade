//! Payments plugin for NativeBlade.
//!
//! In-app purchases and subscriptions through StoreKit 2 (iOS) and Google Play
//! Billing (Android), the billing systems Apple and Google require for digital
//! goods. The native side starts the flow and hands back the store receipt; the
//! Laravel side validates it on a server before granting entitlement. Mobile
//! only; on desktop every call is unsupported (the JS bridge falls back to
//! opening a web checkout when an external link is provided, otherwise reports a
//! failure event so handler code runs unchanged).

use tauri::{
    plugin::{Builder, TauriPlugin},
    Manager, Runtime,
};

pub use error::{Error, Result};
pub use models::{ProductsArgs, PurchaseArgs, RestoreArgs, StatusArgs};

mod error;
mod models;

#[cfg(any(target_os = "android", target_os = "ios"))]
mod mobile;
#[cfg(not(any(target_os = "android", target_os = "ios")))]
mod desktop;

#[cfg(any(target_os = "android", target_os = "ios"))]
pub use mobile::NativeBladePayments;
#[cfg(not(any(target_os = "android", target_os = "ios")))]
pub use desktop::NativeBladePayments;

pub trait NativeBladePaymentsExt<R: Runtime> {
    fn nativeblade_payments(&self) -> &NativeBladePayments<R>;
}

impl<R: Runtime, T: Manager<R>> NativeBladePaymentsExt<R> for T {
    fn nativeblade_payments(&self) -> &NativeBladePayments<R> {
        self.state::<NativeBladePayments<R>>().inner()
    }
}

pub fn init<R: Runtime>() -> TauriPlugin<R> {
    Builder::new("nativeblade-payments")
        .setup(|app, api| {
            #[cfg(any(target_os = "android", target_os = "ios"))]
            let handle = mobile::init(app, api)?;
            #[cfg(not(any(target_os = "android", target_os = "ios")))]
            let handle = desktop::init(app, api)?;

            app.manage(handle);
            Ok(())
        })
        .build()
}
