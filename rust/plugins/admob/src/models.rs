use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Serialize, Deserialize, Default)]
#[serde(rename_all = "camelCase")]
pub struct RewardedAdArgs {
    pub unit: String,
    pub id: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize, Default)]
#[serde(rename_all = "camelCase")]
pub struct InterstitialAdArgs {
    pub unit: String,
    pub id: Option<String>,
    pub min_interval: Option<u64>,
}
