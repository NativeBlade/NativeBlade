use serde::{Deserialize, Serialize};
use std::sync::Mutex;
use std::time::Duration;
use tauri::{AppHandle, Emitter, Manager};
use tokio::task::JoinHandle;

#[derive(Debug, Deserialize, Clone)]
pub struct ScheduleEntry {
    pub name: String,
    pub cron: String,
    #[serde(rename = "lastRun")]
    pub last_run: Option<i64>,
}

#[derive(Debug, Serialize, Clone)]
pub struct ScheduleEvent {
    pub name: String,
}

pub struct SchedulerState {
    handles: Mutex<Vec<JoinHandle<()>>>,
}

impl SchedulerState {
    pub fn new() -> Self {
        Self {
            handles: Mutex::new(Vec::new()),
        }
    }
}

#[tauri::command]
pub async fn register_schedules(app: AppHandle, schedules: Vec<ScheduleEntry>) {
    let state = app.state::<SchedulerState>();

    {
        let mut handles = state.handles.lock().unwrap();
        for handle in handles.drain(..) {
            handle.abort();
        }
    }

    let mut new_handles = Vec::new();

    for entry in schedules {
        let app_handle = app.clone();
        let handle = tokio::spawn(async move {
            schedule_loop(app_handle, entry).await;
        });
        new_handles.push(handle);
    }

    let mut handles = state.handles.lock().unwrap();
    *handles = new_handles;
}

async fn schedule_loop(app: AppHandle, entry: ScheduleEntry) {
    let cron = match croner::Cron::new(&entry.cron).parse() {
        Ok(c) => c,
        Err(e) => {
            eprintln!("[scheduler] invalid cron '{}' for '{}': {}", entry.cron, entry.name, e);
            return;
        }
    };

    if let Some(last_run_ts) = entry.last_run {
        let last_run = chrono::DateTime::from_timestamp(last_run_ts, 0)
            .unwrap_or_else(chrono::Utc::now);
        let now = chrono::Utc::now();

        if let Some(next_after_last) = cron.iter_after(last_run).next() {
            if next_after_last <= now {
                let _ = app.emit("nativeblade-schedule", ScheduleEvent {
                    name: entry.name.clone(),
                });
                tokio::time::sleep(Duration::from_secs(2)).await;
            }
        }
    }

    let mut last_fired: Option<chrono::DateTime<chrono::Utc>> = None;

    loop {
        let reference = last_fired.unwrap_or_else(chrono::Utc::now);
        let mut iter = cron.iter_after(reference);

        let next = match iter.next() {
            Some(n) => n,
            None => break,
        };

        let now = chrono::Utc::now();
        let delay_secs = (next - now).num_seconds().max(1) as u64;
        tokio::time::sleep(Duration::from_secs(delay_secs)).await;

        let _ = app.emit("nativeblade-schedule", ScheduleEvent {
            name: entry.name.clone(),
        });

        last_fired = Some(next);
    }
}
