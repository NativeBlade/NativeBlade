use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Serialize, Deserialize, Default)]
pub struct ConsentArgs {
    /// Test device hashed ids that should be treated as EEA for the consent
    /// form during development.
    #[serde(default)]
    pub test_device_ids: Vec<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize, Default)]
pub struct RewardedArgs {
    pub unit: String,
    #[serde(default)]
    pub id: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize, Default)]
pub struct InterstitialArgs {
    pub unit: String,
    #[serde(default)]
    pub id: Option<String>,
    /// Minimum seconds between two interstitials for this unit; the native side
    /// returns `status: "capped"` without showing when called too soon.
    #[serde(default)]
    pub min_interval: Option<u64>,
}
