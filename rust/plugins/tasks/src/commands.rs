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

/// Task names become path segments under the parking store, so anything a
/// webview hands us is validated against the same `[a-z0-9][a-z0-9_-]*`
/// grammar the PHP builder enforces — no separators, no `..`, no absolute
/// paths escaping `<app_data_dir>/nativeblade/tasks/`.
fn validate_name(name: &str) -> Result<(), String> {
    let mut chars = name.chars();
    let valid = matches!(chars.next(), Some(c) if c.is_ascii_lowercase() || c.is_ascii_digit())
        && chars.all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || c == '_' || c == '-');
    if valid {
        Ok(())
    } else {
        Err(format!("invalid task name: {name:?}"))
    }
}

/// Latest parked result + meta for one task (idempotent read — nothing is
/// consumed). Powers `NativeBlade::getTask($name)`.
#[tauri::command]
pub fn get_task<R: Runtime>(app: AppHandle<R>, name: String) -> Result<TaskAnswer, String> {
    validate_name(&name)?;
    let dir = store::task_dir(&data_dir(&app)?, &name);
    let meta = store::read_meta(&dir);
    // Non-JSON responses (a text/plain endpoint) surface as a string payload
    // instead of silently degrading to null.
    let payload = store::read_latest(&dir).map(|bytes| {
        serde_json::from_slice(&bytes).unwrap_or_else(|_| {
            serde_json::Value::String(String::from_utf8_lossy(&bytes).into_owned())
        })
    });

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
        validate_name(&name)?;
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

/// Park a runtime payload in a task's outbox. Parking is ALL this does —
/// "send now" is what Laravel's Http is for; the task manager owns the
/// not-now. Delivery happens on the queue's clock: the open-app timer, the
/// catch-up at open (a non-empty outbox counts as overdue), or a WorkManager
/// wake (which, with requiresNetwork, fires when connectivity returns even
/// with the app closed).
#[tauri::command]
pub fn enqueue_task<R: Runtime>(
    app: AppHandle<R>,
    name: String,
    payload: serde_json::Value,
    id: Option<String>,
) -> Result<(), String> {
    validate_name(&name)?;
    let serde_json::Value::Object(mut obj) = payload else {
        return Err("payload must be a JSON object".into());
    };
    obj.insert("queuedAt".into(), serde_json::json!(now_secs()));
    // The dispatch id rides inside the payload: it makes the entry targetable
    // by clear_queue and doubles as an idempotency key on the server.
    if let Some(id) = id {
        obj.insert("id".into(), serde_json::json!(id));
    }
    let bytes = serde_json::to_vec(&serde_json::Value::Object(obj)).map_err(|e| e.to_string())?;
    if bytes.len() > store::MAX_PAYLOAD_BYTES {
        return Err(format!(
            "payload too large: {} bytes (max {})",
            bytes.len(),
            store::MAX_PAYLOAD_BYTES
        ));
    }

    let base = data_dir(&app)?;
    store::outbox_push(&store::task_dir(&base, &name), &bytes, now_secs())
        .map_err(|e| e.to_string())
}

/// Peek at a queue's pending entries (oldest first) without consuming them.
/// Powers `NativeBlade::getTaskOnQueue($name)` — "what is still waiting to
/// go out". Entries disappear from here as runs deliver them.
#[tauri::command]
pub fn get_queue<R: Runtime>(
    app: AppHandle<R>,
    name: String,
) -> Result<Vec<serde_json::Value>, String> {
    validate_name(&name)?;
    let dir = store::task_dir(&data_dir(&app)?, &name);
    Ok(store::outbox_entries(&dir)
        .into_iter()
        .filter_map(|path| std::fs::read(path).ok())
        .filter_map(|bytes| serde_json::from_slice(&bytes).ok())
        .collect())
}

/// Drop pending entries of a queue (dispatched but not yet delivered) —
/// all of them, or only those dispatched with a matching `id`. Returns how
/// many were removed. Does not touch results or meta.
#[tauri::command]
pub fn clear_queue<R: Runtime>(
    app: AppHandle<R>,
    name: String,
    id: Option<String>,
) -> Result<usize, String> {
    validate_name(&name)?;
    let dir = store::task_dir(&data_dir(&app)?, &name);
    Ok(store::clear_outbox(&dir, id.as_deref()))
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

            let dir = crate::store::task_dir(&base, &def.name);
            let overdue = crate::store::read_meta(&dir)
                .map(|m| now_secs().saturating_sub(m.ran_at) >= secs)
                .unwrap_or(true); // never ran = overdue
            // A non-empty outbox counts as overdue regardless of the clock:
            // payloads dispatched offline must flush at the first open, not
            // wait out the interval because the last (failed) attempt was
            // recent.
            let pending_outbox = !crate::store::outbox_entries(&dir).is_empty();
            if def.catch_up_on_open && (overdue || pending_outbox) {
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
    // Serialize per task: two rapid enqueues (or a timer overlapping an
    // enqueue flush) must not both read the same outbox mid-send, or the
    // server receives duplicates.
    let locks = app.state::<crate::TaskLocks>();
    let lock = {
        let mut map = locks.0.lock().unwrap();
        map.entry(def.name.clone())
            .or_insert_with(|| std::sync::Arc::new(tokio::sync::Mutex::new(())))
            .clone()
    };
    let _guard = lock.lock().await;

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

#[cfg(test)]
mod tests {
    use super::validate_name;

    #[test]
    fn names_cannot_traverse_paths() {
        assert!(validate_name("photo-sync").is_ok());
        assert!(validate_name("utc_time2").is_ok());
        assert!(validate_name("../secrets").is_err());
        assert!(validate_name("a/b").is_err());
        assert!(validate_name("a\\b").is_err());
        assert!(validate_name("").is_err());
        assert!(validate_name("-starts-wrong").is_err());
        assert!(validate_name("UPPER").is_err());
    }
}
