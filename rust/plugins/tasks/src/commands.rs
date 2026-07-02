//! Tauri commands — the app-open side of the courier. Pure Rust on every
//! platform (no Kotlin/Swift passthrough): the plugin owns the parking store,
//! so it answers directly from disk.

use serde::Serialize;
use tauri::{AppHandle, Manager, Runtime};

use crate::model::TaskDef;
use crate::store;

#[derive(Serialize)]
#[serde(rename_all = "camelCase")]
pub struct TaskAnswer {
    found: bool,
    #[serde(skip_serializing_if = "Option::is_none")]
    payload: Option<serde_json::Value>,
    #[serde(skip_serializing_if = "Option::is_none")]
    ran_at: Option<u64>,
    #[serde(skip_serializing_if = "Option::is_none")]
    status: Option<u16>,
    #[serde(skip_serializing_if = "Option::is_none")]
    error: Option<String>,
}

#[derive(Serialize)]
#[serde(rename_all = "camelCase")]
pub struct DrainedResult {
    name: String,
    ran_at: u64,
    payload: serde_json::Value,
}

fn data_dir<R: Runtime>(app: &AppHandle<R>) -> Result<std::path::PathBuf, String> {
    app.path().app_data_dir().map_err(|e| e.to_string())
}

/// Latest parked result + meta for one task (idempotent read — nothing is
/// consumed). Powers `NativeBlade::getTask($name)`.
#[tauri::command]
pub fn get_task<R: Runtime>(app: AppHandle<R>, name: String) -> Result<TaskAnswer, String> {
    let dir = store::task_dir(&data_dir(&app)?, &name);
    let meta = store::read_meta(&dir);
    let payload = store::read_latest(&dir)
        .and_then(|bytes| serde_json::from_slice(&bytes).ok().or(Some(serde_json::Value::Null)));

    Ok(TaskAnswer {
        found: payload.is_some() || meta.is_some(),
        payload,
        ran_at: meta.as_ref().map(|m| m.ran_at),
        status: meta.as_ref().map(|m| m.status),
        error: meta.and_then(|m| m.error),
    })
}

/// Queued results for the given tasks (handler mode), oldest first, consumed
/// on read. The JS boot drains only tasks that declared a handler.
#[tauri::command]
pub fn drain_results<R: Runtime>(
    app: AppHandle<R>,
    names: Vec<String>,
) -> Result<Vec<DrainedResult>, String> {
    let base = data_dir(&app)?;
    let mut out = Vec::new();
    for name in names {
        let dir = store::task_dir(&base, &name);
        for (ran_at, bytes) in store::drain_queued(&dir) {
            let payload = serde_json::from_slice(&bytes).unwrap_or(serde_json::Value::Null);
            out.push(DrainedResult { name: name.clone(), ran_at, payload });
        }
    }
    out.sort_by_key(|r| r.ran_at);
    Ok(out)
}

/// Persist the task manifest where every executor can read it, and hand it
/// to the platform adapter so the OS scheduler gets (re-)enqueued. Runs at
/// every app boot — WorkManager updates in place (UPDATE policy), so config
/// changes propagate on the next open.
#[tauri::command]
pub fn register_tasks<R: Runtime>(app: AppHandle<R>, tasks: Vec<TaskDef>) -> Result<(), String> {
    let base = data_dir(&app)?;
    let path = base.join("nativeblade").join("tasks").join("manifest.json");
    let bytes = serde_json::to_vec(&tasks).map_err(|e| e.to_string())?;
    store::atomic_write(&path, &bytes).map_err(|e| e.to_string())?;

    #[cfg(any(target_os = "android", target_os = "ios"))]
    {
        let handle = app.state::<crate::AdapterHandle<R>>();
        handle
            .0
            .run_mobile_plugin::<serde_json::Value>(
                "registerTasks",
                serde_json::json!({
                    // JSON string: org.json on the Kotlin side parses it without
                    // needing typed @InvokeArg nesting.
                    "tasksJson": serde_json::to_string(&tasks).map_err(|e| e.to_string())?,
                    "dataDir": base.to_string_lossy(),
                }),
            )
            .map_err(|e| e.to_string())?;
    }

    start_open_executors(&app, &tasks, &base);
    Ok(())
}

/// The app-open side of the schedule: catch-up for overdue tasks right away,
/// then a timer per run_while_open task. The courier is blocking reqwest, so
/// each run goes through spawn_blocking and never parks the async runtime.
fn start_open_executors<R: Runtime>(app: &AppHandle<R>, tasks: &[TaskDef], base: &std::path::Path) {
    use std::sync::atomic::Ordering;
    if app.state::<crate::TimersStarted>().0.swap(true, Ordering::SeqCst) {
        return;
    }

    for def in tasks {
        if !def.run_while_open && !def.catch_up_on_open {
            continue;
        }
        let def = def.clone();
        let base = base.to_path_buf();
        let app = app.clone();
        tauri::async_runtime::spawn(async move {
            let secs = def.every_minutes.max(15) * 60;

            let overdue = crate::store::read_meta(&crate::store::task_dir(&base, &def.name))
                .map(|m| now_secs().saturating_sub(m.ran_at) >= secs)
                .unwrap_or(true); // never ran = overdue
            if def.catch_up_on_open && overdue {
                run_open(&app, &def, &base).await;
            }

            if !def.run_while_open {
                return;
            }
            loop {
                tokio::time::sleep(std::time::Duration::from_secs(secs)).await;
                run_open(&app, &def, &base).await;
            }
        });
    }
}

async fn run_open<R: Runtime>(app: &AppHandle<R>, def: &TaskDef, base: &std::path::Path) {
    let collected = collect(app, def);
    let def = def.clone();
    let base = base.to_path_buf();
    let _ = tauri::async_runtime::spawn_blocking(move || {
        crate::courier::run_task(&def, &collected, &base)
    })
    .await;
}

/// Platform-collected data for an in-app run. On Android the adapter answers
/// (location fix, secure-storage bearer); on desktop there is nothing to
/// collect and the task runs with defaults.
fn collect<R: Runtime>(_app: &AppHandle<R>, _def: &TaskDef) -> crate::model::Collected {
    #[cfg(any(target_os = "android", target_os = "ios"))]
    {
        if _def.with_location || _def.bearer_from_secure.is_some() {
            let handle = _app.state::<crate::AdapterHandle<R>>();
            if let Ok(v) = handle.0.run_mobile_plugin::<serde_json::Value>(
                "collect",
                serde_json::json!({
                    "withLocation": _def.with_location,
                    "bearerFromSecure": _def.bearer_from_secure,
                }),
            ) {
                return serde_json::from_value(v).unwrap_or_default();
            }
        }
    }
    crate::model::Collected::default()
}

fn now_secs() -> u64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_secs())
        .unwrap_or(0)
}
