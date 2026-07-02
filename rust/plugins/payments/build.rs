const COMMANDS: &[&str] = &[
    "query_products",
    "purchase",
    "restore_purchases",
    "subscription_status",
    "drain_pending",
];

fn main() {
    let result = tauri_plugin::Builder::new(COMMANDS)
        .android_path("android")
        .ios_path("ios")
        .try_build();

    if !(cfg!(docsrs) && std::env::var("TARGET").unwrap_or_default().contains("android")) {
        if let Err(e) = result {
            println!("cargo:warning=tauri-plugin-nativeblade-payments build skipped: {e}");
        }
    }
}
