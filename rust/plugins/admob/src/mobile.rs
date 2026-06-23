use serde::de::DeserializeOwned;
use tauri::{
    plugin::{PluginApi, PluginHandle},
    AppHandle, Runtime,
};

use crate::error::Result;
use crate::models::{ConsentArgs, InterstitialArgs, RewardedArgs};

#[cfg(target_os = "android")]
const PLUGIN_IDENTIFIER: &str = "app.nativeblade.admob";

#[cfg(target_os = "ios")]
tauri::ios_plugin_binding!(init_plugin_nativeblade_admob);

pub struct NativeBladeAdmob<R: Runtime>(PluginHandle<R>);

impl<R: Runtime> NativeBladeAdmob<R> {
    pub fn request_consent(&self, args: ConsentArgs) -> Result<()> {
        self.0
            .run_mobile_plugin::<()>("requestConsent", args)
            .map_err(Into::into)
    }

    pub fn show_rewarded(&self, args: RewardedArgs) -> Result<serde_json::Value> {
        self.0
            .run_mobile_plugin::<serde_json::Value>("showRewarded", args)
            .map_err(Into::into)
    }

    pub fn show_interstitial(&self, args: InterstitialArgs) -> Result<serde_json::Value> {
        self.0
            .run_mobile_plugin::<serde_json::Value>("showInterstitial", args)
            .map_err(Into::into)
    }
}

pub fn init<R: Runtime, C: DeserializeOwned>(
    _app: &AppHandle<R>,
    api: PluginApi<R, C>,
) -> Result<NativeBladeAdmob<R>> {
    #[cfg(target_os = "android")]
    let handle = api.register_android_plugin(PLUGIN_IDENTIFIER, "AdMobPlugin")?;

    #[cfg(target_os = "ios")]
    let handle = api.register_ios_plugin(init_plugin_nativeblade_admob)?;

    Ok(NativeBladeAdmob(handle))
}
