use serde::{Deserialize, Serialize};
use std::collections::HashMap;

/// Lifecycle state of the app when a push was received.
#[derive(Debug, Clone, Copy, Serialize, Deserialize)]
#[serde(rename_all = "lowercase")]
pub enum AppState {
    /// App was open and visible when the push arrived.
    Foreground,
    /// App was running but backgrounded / minimized.
    Background,
    /// App was fully closed and the user tapped the push to open it.
    Cold,
}

impl Default for AppState {
    fn default() -> Self {
        AppState::Foreground
    }
}

/// Notification header (title + body) as sent by FCM/APNS.
///
/// Optional because silent data-only pushes don't include this.
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct NotificationData {
    pub title: Option<String>,
    pub body: Option<String>,
}

/// Complete push payload delivered to the JS layer.
///
/// The `data` map is the developer-controlled key/value payload that your
/// backend attached to the push — route on this, not on the notification
/// text.
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct PushPayload {
    /// Unique message id from FCM/APNS (used for deduplication).
    pub id: String,

    /// Developer data payload (e.g. `{"type": "new_lesson", "lesson_id": "42"}`).
    #[serde(default)]
    pub data: HashMap<String, String>,

    /// Optional `title` / `body` if the push included a notification object.
    #[serde(default)]
    pub notification: NotificationData,

    /// App state when the push was received.
    #[serde(default)]
    pub state: AppState,
}

/// Device token event payload.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct TokenPayload {
    /// The FCM registration token or APNS device token (hex string).
    pub token: String,
}
