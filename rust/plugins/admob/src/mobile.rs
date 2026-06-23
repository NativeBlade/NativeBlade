use serde::de::DeserializeOwned;
use tauri::{
    plugin::{PluginApi, PluginHandle},
    AppHandle, Runtime,
};

use crate::error::Result;
use crate::models::{InterstitialAdArgs, RewardedAdArgs};

#[cfg(target_os = "android")]
const PLUGIN_IDENTIFIER: &str = "app.nativeblade.admob";

#[cfg(target_os = "ios")]
tauri::ios_plugin_binding!(init_plugin_nativeblade_admob);

pub struct NativeBladeAdMob<R: Runtime>(PluginHandle<R>);

impl<R: Runtime> NativeBladeAdMob<R> {
    pub fn request_ad_consent(&self) -> Result<()> {
        self.0
            .run_mobile_plugin::<()>("requestAdConsent", ())
            .map_err(Into::into)
    }

    pub fn show_rewarded(&self, args: RewardedAdArgs) -> Result<()> {
        self.0
            .run_mobile_plugin::<()>("showRewarded", args)
            .map_err(Into::into)
    }

    pub fn show_interstitial(&self, args: InterstitialAdArgs) -> Result<()> {
        self.0
            .run_mobile_plugin::<()>("showInterstitial", args)
            .map_err(Into::into)
    }
}

pub fn init<R: Runtime, C: DeserializeOwned>(
    _app: &AppHandle<R>,
    api: PluginApi<R, C>,
) -> Result<NativeBladeAdMob<R>> {
    #[cfg(target_os = "android")]
    let handle = api.register_android_plugin(PLUGIN_IDENTIFIER, "AdMobPlugin")?;

    #[cfg(target_os = "ios")]
    let handle = api.register_ios_plugin(init_plugin_nativeblade_admob)?;

    Ok(NativeBladeAdMob(handle))
}
