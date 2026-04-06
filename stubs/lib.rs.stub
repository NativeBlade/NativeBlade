#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    nativeblade::build()
        .run(tauri::generate_context!())
        .expect("error while running NativeBlade");
}