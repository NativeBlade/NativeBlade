use serde::de::DeserializeOwned;
use tauri::{plugin::PluginApi, AppHandle, Runtime};

use crate::error::{Error, Result};
use crate::models::{InterstitialAdArgs, RewardedAdArgs};

pub struct NativeBladeAdMob<R: Runtime> {
    _app: AppHandle<R>,
}

impl<R: Runtime> NativeBladeAdMob<R> {
    pub fn request_ad_consent(&self) -> Result<()> {
        Err(Error::Unsupported)
    }

    pub fn show_rewarded(&self, _args: RewardedAdArgs) -> Result<()> {
        Err(Error::Unsupported)
    }

    pub fn show_interstitial(&self, _args: InterstitialAdArgs) -> Result<()> {
        Err(Error::Unsupported)
    }
}

pub fn init<R: Runtime, C: DeserializeOwned>(
    app: &AppHandle<R>,
    _api: PluginApi<R, C>,
) -> Result<NativeBladeAdMob<R>> {
    Ok(NativeBladeAdMob { _app: app.clone() })
}
