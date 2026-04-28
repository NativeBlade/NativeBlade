use serde::{Deserialize, Serialize};

/// How the plugin should return the captured/picked media.
///
/// `Url` writes to a temp file and returns an asset URL (no bytes in JS
/// heap). `DataUrl` encodes as base64 (compatibility with the legacy
/// camera.js API). `Both` returns both so the consumer can pick.
#[derive(Debug, Clone, Copy, Serialize, Deserialize, Default)]
#[serde(rename_all = "lowercase")]
pub enum OutputMode {
    #[default]
    Url,
    DataUrl,
    Both,
}

#[derive(Debug, Clone, Copy, Serialize, Deserialize, Default)]
#[serde(rename_all = "lowercase")]
pub enum CameraFacing {
    #[default]
    Back,
    Front,
}

/// Options passed from JS on pickFromCamera / pickFromGallery.
///
/// All fields optional; defaults match the old camera.js behaviour
/// (1200x1200, 0.7 quality, JPEG, URL output).
#[derive(Debug, Clone, Serialize, Deserialize, Default)]
#[serde(rename_all = "camelCase")]
pub struct PickOptions {
    pub max_width: Option<u32>,
    pub max_height: Option<u32>,
    pub quality: Option<f32>,
    pub facing: Option<CameraFacing>,
    pub output: Option<OutputMode>,
    pub multiple: Option<bool>,
    pub max: Option<u32>,
    /// Arbitrary id echoed back on the result — lets the JS side match
    /// a result to the call that produced it when multiple pickers are
    /// in flight.
    pub id: Option<String>,
}

/// One captured/picked media item.
#[derive(Debug, Clone, Serialize, Deserialize, Default)]
#[serde(rename_all = "camelCase")]
pub struct MediaItem {
    /// Tauri asset URL (empty when output = DataUrl).
    pub url: String,
    /// Native filesystem path to the temp file (empty when output = DataUrl).
    pub path: String,
    /// Base64 dataURL (empty when output = Url).
    pub data_url: String,
    pub mime: String,
    pub size: u64,
    pub width: u32,
    pub height: u32,
    /// Original file name when the source was the gallery; empty for camera.
    pub name: String,
}

/// Response envelope for pickFromCamera / pickFromGallery.
#[derive(Debug, Clone, Serialize, Deserialize, Default)]
#[serde(rename_all = "camelCase")]
pub struct PickResult {
    pub items: Vec<MediaItem>,
    pub id: Option<String>,
}

#[derive(Debug, Clone, Copy, Serialize, Deserialize, Default, PartialEq, Eq)]
#[serde(rename_all = "lowercase")]
pub enum PermissionState {
    #[default]
    Unknown,
    Granted,
    Denied,
    /// iOS-only: the user granted access to a subset of their library.
    Limited,
    /// User hasn't been asked yet.
    Prompt,
}

#[derive(Debug, Clone, Serialize, Deserialize, Default)]
#[serde(rename_all = "camelCase")]
pub struct PermissionStatus {
    pub camera: PermissionState,
    pub photos: PermissionState,
}

#[derive(Debug, Clone, Serialize, Deserialize, Default)]
#[serde(rename_all = "camelCase")]
pub struct ReadAssetArgs {
    pub url: String,
}

#[derive(Debug, Clone, Serialize, Deserialize, Default)]
#[serde(rename_all = "camelCase")]
pub struct ReadAssetResult {
    pub data_url: String,
    pub mime: String,
    pub size: u64,
}
