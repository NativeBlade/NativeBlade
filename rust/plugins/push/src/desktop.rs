use serde::de::DeserializeOwned;
use serde::Deserialize;
use tauri::{plugin::PluginApi, AppHandle, Runtime};

use crate::error::{Error, Result};
use crate::models::PushPayload;

pub struct NativeBladePush<R: Runtime> {
    _app: AppHandle<R>,
}

impl<R: Runtime> NativeBladePush<R> {
    pub fn get_token(&self) -> Result<Option<String>> {
        Err(Error::Unsupported)
    }

    pub fn request_permission(&self) -> Result<bool> {
        Err(Error::Unsupported)
    }

    pub fn drain_pending(&self) -> Result<Vec<PushPayload>> {
        Ok(Vec::new())
    }
}

pub fn init<R: Runtime, C: DeserializeOwned>(
    app: &AppHandle<R>,
    _api: PluginApi<R, C>,
) -> Result<NativeBladePush<R>> {
    Ok(NativeBladePush { _app: app.clone() })
}

#[derive(Debug, Deserialize)]
pub struct NotifyArgs {
    pub title: Option<String>,
    pub body: Option<String>,
    pub icon: Option<String>,
    pub sound: Option<String>,
    /// Ignored on desktop — fires immediately.
    pub schedule: Option<serde_json::Value>,
    pub id: Option<String>,
    pub channel: Option<String>,
}

#[tauri::command]
pub async fn notify(args: NotifyArgs) -> std::result::Result<serde_json::Value, String> {
    let title = args.title.unwrap_or_else(|| "NativeBlade".to_string());
    let body = args.body.unwrap_or_default();

    let mut n = notify_rust::Notification::new();
    n.summary(&title).body(&body);

    if let Some(icon) = args.icon.as_ref() {
        n.icon(icon);
    }
    if let Some(sound) = args.sound.as_ref() {
        n.sound_name(sound);
    }

    n.show().map_err(|e| e.to_string())?;

    Ok(serde_json::json!({ "id": args.id.unwrap_or_default() }))
}

#[tauri::command]
pub async fn cancel(_id: Option<String>) -> std::result::Result<(), String> {
    Ok(())
}

#[tauri::command]
pub async fn cancel_all() -> std::result::Result<(), String> {
    Ok(())
}
