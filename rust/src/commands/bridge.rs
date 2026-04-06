use tauri_plugin_dialog::DialogExt;
use tauri_plugin_notification::NotificationExt;

#[tauri::command]
pub fn native_action(app: tauri::AppHandle, action: String, target: String) {
    match action.as_str() {
        "so:alert" => {
            app.dialog().message(&target).title("NativeBlade").blocking_show();
        }
        "so:notification" => {
            let _ = app.notification().builder().title("NativeBlade").body(&target).show();
        }
        "so:exit" => {
            app.exit(0);
        }
        _ => {}
    }
}
