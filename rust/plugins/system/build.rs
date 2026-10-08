const COMMANDS: &[&str] = &["open_app_settings", "set_status_bar_style"];

fn main() {
    let result = tauri_plugin::Builder::new(COMMANDS)
        .android_path("android")
        .ios_path("ios")
        .try_build();

    if !(cfg!(docsrs) && std::env::var("TARGET").unwrap_or_default().contains("android")) {
        if let Err(e) = result {
            println!("cargo:warning=tauri-plugin-nativeblade-system build skipped: {e}");
        }
    }
}
