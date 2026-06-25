use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Serialize, Deserialize, Default)]
pub struct ProductsArgs {
    /// Store product identifiers to fetch localized price and metadata for.
    #[serde(default)]
    pub products: Vec<String>,
    #[serde(default)]
    pub id: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize, Default)]
pub struct PurchaseArgs {
    pub product: String,
    #[serde(default)]
    pub id: Option<String>,
    /// When true the purchase is consumed after it completes so it can be bought
    /// again (credits, coins). Otherwise it is acknowledged as a durable
    /// entitlement (subscriptions, premium unlocks).
    #[serde(default)]
    pub consumable: bool,
    /// Optional web checkout URL. Used only on desktop (mobile uses native
    /// billing); the JS bridge opens it in the system browser.
    #[serde(default)]
    pub external: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize, Default)]
pub struct RestoreArgs {
    #[serde(default)]
    pub id: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize, Default)]
pub struct StatusArgs {
    /// Restrict the report to these product ids; empty means every active
    /// entitlement.
    #[serde(default)]
    pub products: Vec<String>,
    #[serde(default)]
    pub id: Option<String>,
}
