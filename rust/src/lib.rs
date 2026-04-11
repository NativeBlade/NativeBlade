pub mod commands;

pub fn build() -> tauri::Builder<tauri::Wry> {
    let builder = tauri::Builder::default()
        .plugin(tauri_plugin_shell::init())
        .plugin(tauri_plugin_dialog::init())
        .plugin(tauri_plugin_notification::init())
        .plugin(tauri_plugin_store::Builder::default().build())
        .plugin(tauri_plugin_process::init())
        .plugin(tauri_plugin_clipboard_manager::init())
        .plugin(tauri_plugin_fs::init())
        .plugin(tauri_plugin_geolocation::init())
        .plugin(tauri_plugin_haptics::init())
        .plugin(tauri_plugin_opener::init())
        .plugin(tauri_plugin_os::init())
        .plugin(tauri_plugin_http::init())
        .plugin(tauri_plugin_deep_link::init())
        .plugin(tauri_plugin_upload::init());

    #[cfg(mobile)]
    let builder = builder
        .plugin(tauri_plugin_biometric::init())
        .plugin(tauri_plugin_barcode_scanner::init())
        .plugin(tauri_plugin_nfc::init());

    let builder = builder
        .manage(commands::scheduler::SchedulerState::new())
        .invoke_handler(tauri::generate_handler![
            commands::bridge::native_action,
            commands::health::check_backend,
            commands::config::get_config,
            commands::scheduler::register_schedules,
        ])
        .setup(|_app| {
            #[cfg(desktop)]
            {
                let handle = _app.handle();
                if let Some(menu) = commands::menu::build_menu(handle) {
                    let _ = _app.set_menu(menu);
                }
                commands::tray::setup(handle);
            }
            Ok(())
        });

    #[cfg(desktop)]
    let builder = builder
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
        });

    builder
}