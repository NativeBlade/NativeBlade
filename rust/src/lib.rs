pub mod commands;

use tauri::Manager;

pub fn build() -> tauri::Builder<tauri::Wry> {
    tauri::Builder::default()
        .plugin(tauri_plugin_shell::init())
        .plugin(tauri_plugin_dialog::init())
        .plugin(tauri_plugin_notification::init())
        .plugin(tauri_plugin_store::Builder::default().build())
        .plugin(tauri_plugin_process::init())
        .invoke_handler(tauri::generate_handler![
            commands::bridge::native_action,
            commands::health::check_backend,
            commands::config::get_config,
        ])
        .setup(|app| {
            #[cfg(not(mobile))]
            {
                let handle = app.handle();
                if let Some(menu) = commands::menu::build_menu(handle) {
                    let _ = app.set_menu(menu);
                }
                commands::tray::setup(handle);
            }
            Ok(())
        })
        .on_menu_event(|app, event| {
            commands::menu::handle_menu_event(app, &event);
        })
        .on_window_event(|window, event| {
            if let tauri::WindowEvent::CloseRequested { api, .. } = event {
                if commands::tray::should_hide_on_close(window.app_handle()) {
                    api.prevent_close();
                    let _ = window.hide();
                }
            }
        })
}
