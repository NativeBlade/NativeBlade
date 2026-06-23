const COMMANDS: &[&str] = &["request_ad_consent", "show_rewarded", "show_interstitial"];

fn main() {
    let result = tauri_plugin::Builder::new(COMMANDS)
        .android_path("android")
        .ios_path("ios")
        .try_build();

    if !(cfg!(docsrs) && std::env::var("TARGET").unwrap_or_default().contains("android")) {
        if let Err(e) = result {
            println!("cargo:warning=tauri-plugin-nativeblade-admob build skipped: {e}");
        }
    }
}
