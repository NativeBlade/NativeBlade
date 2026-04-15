const COMMANDS: &[&str] = &[
    "get_token",
    "request_permission",
    "drain_pending",
];

fn main() {
    // Phase 1 scaffolding — android/ios paths will be wired in Phase 2/3
    // once the Kotlin/Swift sources exist in this crate.
    let result = tauri_plugin::Builder::new(COMMANDS).try_build();

    if !(cfg!(docsrs) && std::env::var("TARGET").unwrap_or_default().contains("android")) {
        if let Err(e) = result {
            println!("cargo:warning=tauri-plugin-nativeblade-push build skipped: {e}");
        }
    }
}
