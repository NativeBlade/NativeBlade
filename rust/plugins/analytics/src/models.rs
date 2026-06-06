use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Serialize, Deserialize, Default)]
pub struct ApplyArgs {
    /// Heterogeneous list of analytics ops; the native side switches on `op`.
    #[serde(default)]
    pub ops: Vec<serde_json::Value>,
}
