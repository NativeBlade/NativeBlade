const COMMANDS: &[&str] = &["get_task", "drain_results", "register_tasks", "enqueue_task"];

fn main() {
    let result = tauri_plugin::Builder::new(COMMANDS)
        .android_path("android")
        .ios_path("ios")
        .try_build();

    if let Err(e) = result {
        println!("cargo:warning=tauri-plugin-nativeblade-tasks build skipped: {e}");
    }
}
