pub mod commands;

#[cfg(not(any(target_os = "android", target_os = "ios")))]
use tauri::Manager;

/// Returns a `tauri::Builder` pre-configured with NativeBlade's always-on
/// plugins (dialog, os, process, store, fs, opener), the framework
/// commands (scheduler, database, fileops), and desktop window/menu
/// handlers.
///
/// Optional plugins (haptics, geolocation, push, media, etc.) are
/// registered in the user's `src-tauri/src/lib.rs` after this call,
/// gated by Cargo features that NativeBladeConfig::plugins([...]) toggles.
pub fn build() -> tauri::Builder<tauri::Wry> {
    let builder = tauri::Builder::default()
        .plugin(tauri_plugin_dialog::init())
        .plugin(tauri_plugin_os::init())
        .plugin(tauri_plugin_process::init())
        .plugin(tauri_plugin_store::Builder::default().build())
        .plugin(tauri_plugin_fs::init())
        .plugin(tauri_plugin_opener::init());

    let builder = builder
        .manage(commands::scheduler::SchedulerState::new())
        .manage(commands::database::DatabaseState::new())
        .invoke_handler(tauri::generate_handler![
            commands::health::check_backend,
            commands::config::get_config,
            commands::scheduler::register_schedules,
            commands::database::db_query,
            commands::fileops::nb_copy_file,
            commands::fileops::nb_move_file,
        ])
        .setup(|_app| {
            #[cfg(not(any(target_os = "android", target_os = "ios")))]
            {
                let handle = _app.handle();
                if let Some(menu) = commands::menu::build_menu(handle) {
                    let _ = _app.set_menu(menu);
                }
                commands::tray::setup(handle);
            }
            Ok(())
        });

    #[cfg(not(any(target_os = "android", target_os = "ios")))]
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
