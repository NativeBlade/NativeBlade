use serde::{Deserialize, Serialize};

/// One background task as declared by `NativeBladeConfig::backgroundTasks()`.
/// The PHP generator validates names (unique, `[a-z0-9-_]`); they become
/// directory names in the parking store and unique work names in the OS
/// schedulers.
#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct TaskDef {
    pub name: String,
    /// "fetch" (GET, park the response) or "post" (fire-and-forget with outbox).
    pub kind: String,
    pub url: String,
    #[serde(default)]
    pub every_minutes: u64,
    #[serde(default)]
    pub latest_only: bool,
    /// Static headers. The bearer token is NOT here — it is collected from
    /// secure storage at send time by the platform adapter.
    #[serde(default)]
    pub headers: Vec<(String, String)>,
    /// Fixed body fields for post tasks (merged with collected data).
    #[serde(default)]
    pub body: serde_json::Value,
    #[serde(default)]
    pub requires_network: bool,
    #[serde(default)]
    pub requires_unmetered: bool,
    #[serde(default)]
    pub requires_charging: bool,
    #[serde(default)]
    pub with_location: bool,
    #[serde(default)]
    pub bearer_from_secure: Option<String>,
    #[serde(default)]
    pub handler: Option<String>,
    #[serde(default = "default_true")]
    pub run_while_open: bool,
    #[serde(default = "default_true")]
    pub catch_up_on_open: bool,
}

fn default_true() -> bool {
    true
}

/// Data gathered by the platform adapter right before a run: things Rust
/// cannot collect itself (a location fix, the bearer from secure storage).
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct Collected {
    #[serde(default)]
    pub bearer: Option<String>,
    #[serde(default)]
    pub location: Option<serde_json::Value>,
}

/// Bookkeeping written next to every task's results.
#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct TaskMeta {
    /// Unix seconds of the last run attempt.
    pub ran_at: u64,
    /// HTTP status, or 0 when the request never completed.
    pub status: u16,
    pub duration_ms: u64,
    #[serde(default)]
    pub error: Option<String>,
}
