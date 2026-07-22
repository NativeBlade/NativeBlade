//! Multi-window support (WINDOWS.md spike). Opens real OS windows that load the
//! same frontend as the main window; the JS boot reads its own window LABEL
//! (`nb-window-{id}`) to enter satellite/relay mode instead of starting a second
//! php-wasm runtime. Identity travels by label, NOT a URL query string — Tauri
//! resolves `WebviewUrl::App` as a file path and would 404 on `index.html?...`.
//!
//! Commands are ASYNC on purpose: a SYNC command creating a window deadlocks —
//! it runs on the main thread, and `build()` waits for the event loop that the
//! blocked main thread can't pump. Async commands run off the main thread, so
//! `build()` can dispatch window creation to a free event loop.

use serde::Deserialize;
use tauri::{AppHandle, Manager, WebviewUrl, WebviewWindowBuilder};

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct WindowConfig {
    pub id: String,
    #[serde(default)]
    pub width: Option<f64>,
    #[serde(default)]
    pub height: Option<f64>,
    #[serde(default)]
    pub min_width: Option<f64>,
    #[serde(default)]
    pub min_height: Option<f64>,
    #[serde(default)]
    pub x: Option<f64>,
    #[serde(default)]
    pub y: Option<f64>,
    #[serde(default)]
    pub always_on_top: Option<bool>,
    #[serde(default)]
    pub frameless: Option<bool>,
    #[serde(default)]
    pub resizable: Option<bool>,
}

/// The Tauri window label. Prefixed so a future capability can grant satellite
/// windows their permissions with a single `nb-window-*` glob.
fn label(id: &str) -> String {
    format!("nb-window-{id}")
}

#[tauri::command]
pub async fn open_window(app: AppHandle, config: WindowConfig) -> Result<(), String> {
    let lbl = label(&config.id);

    // Already open: focus it instead of stacking a duplicate.
    if let Some(win) = app.get_webview_window(&lbl) {
        let _ = win.set_focus();
        return Ok(());
    }

    let url = WebviewUrl::App("index.html".into());
    let mut builder = WebviewWindowBuilder::new(&app, lbl.as_str(), url)
        .title(config.id.as_str())
        .resizable(config.resizable.unwrap_or(true))
        .decorations(!config.frameless.unwrap_or(false))
        .always_on_top(config.always_on_top.unwrap_or(false));

    if let (Some(w), Some(h)) = (config.width, config.height) {
        builder = builder.inner_size(w, h);
    }
    if let (Some(w), Some(h)) = (config.min_width, config.min_height) {
        builder = builder.min_inner_size(w, h);
    }
    if let (Some(x), Some(y)) = (config.x, config.y) {
        builder = builder.position(x, y);
    }

    builder.build().map_err(|e| e.to_string())?;
    Ok(())
}

#[tauri::command]
pub async fn close_window(app: AppHandle, id: String) -> Result<(), String> {
    if let Some(win) = app.get_webview_window(&label(&id)) {
        win.close().map_err(|e| e.to_string())?;
    }
    Ok(())
}

#[tauri::command]
pub async fn focus_window(app: AppHandle, id: String) -> Result<(), String> {
    if let Some(win) = app.get_webview_window(&label(&id)) {
        win.set_focus().map_err(|e| e.to_string())?;
    }
    Ok(())
}
