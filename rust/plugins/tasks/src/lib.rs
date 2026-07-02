//! TaskManager plugin for NativeBlade — the background task courier.
//!
//! The courier model: PHP declares tasks (`fetch` parks a response for the
//! app to consume, `post` fires payloads with an outbox for failures) and the
//! work itself is pure Rust — reqwest + atomic files — so the exact same
//! `courier::run_task` runs from a Tauri command with the app open, from a
//! WorkManager JNI call on Android with the app closed, or from a BGTask C
//! call on iOS. No WebView, no JS, no PHP in the background path.
//!
//! Phase 1 (this crate today): the courier + parking store with tests, and
//! the app-open commands (`get_task`, `drain_results`, `register_tasks`).
//! Phase 2: platform wake adapters (WorkManager / BGTaskScheduler), the
//! collect step (location, secure-storage bearer) and open-app timers.

use tauri::{
    plugin::{Builder, TauriPlugin},
    Runtime,
};

pub use courier::{run_task, RunOutcome};
pub use error::{Error, Result};
pub use model::{Collected, TaskDef, TaskMeta};

mod commands;
mod courier;
mod error;
mod model;
mod store;

#[cfg(target_os = "android")]
mod android;

#[cfg(target_os = "ios")]
mod ios_entry;

/// Handle to the platform adapter: the thin native side that enqueues OS
/// schedules and collects platform data. Absent on desktop.
#[cfg(any(target_os = "android", target_os = "ios"))]
pub struct AdapterHandle<R: Runtime>(pub tauri::plugin::PluginHandle<R>);

#[cfg(target_os = "ios")]
tauri::ios_plugin_binding!(init_plugin_nativeblade_tasks);

/// Guards the open-app executors against a double register (e.g. a dev
/// reload calling register_tasks twice in one process).
pub(crate) struct TimersStarted(pub std::sync::atomic::AtomicBool);

pub fn init<R: Runtime>() -> TauriPlugin<R> {
    Builder::new("nativeblade-tasks")
        .invoke_handler(tauri::generate_handler![
            commands::get_task,
            commands::drain_results,
            commands::register_tasks,
            commands::enqueue_task,
        ])
        .setup(|_app, _api| {
            use tauri::Manager;
            _app.manage(TimersStarted(std::sync::atomic::AtomicBool::new(false)));
            #[cfg(target_os = "android")]
            {
                let handle =
                    _api.register_android_plugin("app.nativeblade.tasks", "TasksPlugin")?;
                _app.manage(AdapterHandle(handle));
            }
            #[cfg(target_os = "ios")]
            {
                let handle = _api.register_ios_plugin(init_plugin_nativeblade_tasks)?;
                _app.manage(AdapterHandle(handle));
            }
            Ok(())
        })
        .build()
}
