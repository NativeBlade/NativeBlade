const COMMANDS: &[&str] = &[
    "get_token",
    "request_permission",
    "drain_pending",
];

fn main() {
    let result = tauri_plugin::Builder::new(COMMANDS)
        .android_path("android")
        .ios_path("ios")
        .try_build();

    if !(cfg!(docsrs) && std::env::var("TARGET").unwrap_or_default().contains("android")) {
        if let Err(e) = result {
            println!("cargo:warning=tauri-plugin-nativeblade-push build skipped: {e}");
        }
    }
}
