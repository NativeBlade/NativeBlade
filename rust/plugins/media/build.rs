const COMMANDS: &[&str] = &[
    "pick_from_camera",
    "pick_from_gallery",
    "pick_video",
    "check_permissions",
    "request_permissions",
    "read_asset",
];

fn main() {
    let result = tauri_plugin::Builder::new(COMMANDS)
        .android_path("android")
        .ios_path("ios")
        .try_build();

    if !(cfg!(docsrs) && std::env::var("TARGET").unwrap_or_default().contains("android")) {
        if let Err(e) = result {
            println!("cargo:warning=tauri-plugin-nativeblade-media build skipped: {e}");
        }
    }
}
