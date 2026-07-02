const COMMANDS: &[&str] = &["is_available", "read_sensor", "watch_sensor", "stop_sensor"];

fn main() {
    let result = tauri_plugin::Builder::new(COMMANDS)
        .android_path("android")
        .ios_path("ios")
        .try_build();

    if !(cfg!(docsrs) && std::env::var("TARGET").unwrap_or_default().contains("android")) {
        if let Err(e) = result {
            println!("cargo:warning=tauri-plugin-nativeblade-sensors build skipped: {e}");
        }
    }
}
