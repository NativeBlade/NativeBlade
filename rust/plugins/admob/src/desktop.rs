use serde::de::DeserializeOwned;
use tauri::{plugin::PluginApi, AppHandle, Runtime};

use crate::error::{Error, Result};
use crate::models::{BannerArgs, ConsentArgs, InterstitialArgs, RewardedArgs};

/// Desktop stub. AdMob is mobile-only; the JS bridge makes every call a no-op
/// that reports a failure event so handler code runs unchanged everywhere.
pub struct NativeBladeAdmob<R: Runtime> {
    _app: AppHandle<R>,
}

impl<R: Runtime> NativeBladeAdmob<R> {
    pub fn request_consent(&self, _args: ConsentArgs) -> Result<()> {
        Err(Error::Unsupported)
    }

    pub fn show_rewarded(&self, _args: RewardedArgs) -> Result<serde_json::Value> {
        Err(Error::Unsupported)
    }

    pub fn show_interstitial(&self, _args: InterstitialArgs) -> Result<serde_json::Value> {
        Err(Error::Unsupported)
    }

    pub fn show_banner(&self, _args: BannerArgs) -> Result<serde_json::Value> {
        Err(Error::Unsupported)
    }

    pub fn hide_banner(&self) -> Result<()> {
        Err(Error::Unsupported)
    }
}

pub fn init<R: Runtime, C: DeserializeOwned>(
    app: &AppHandle<R>,
    _api: PluginApi<R, C>,
) -> Result<NativeBladeAdmob<R>> {
    Ok(NativeBladeAdmob { _app: app.clone() })
}
