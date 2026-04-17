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

#[cfg(test)]
mod tests {
    use super::*;

    // ---------------- ScheduleEntry serde ----------------

    #[test]
    fn schedule_entry_deserializes_with_camelcase_last_run() {
        let json = r#"{"name":"cleanup","cron":"*/5 * * * *","lastRun":1700000000}"#;
        let entry: ScheduleEntry = serde_json::from_str(json).expect("deserialize");
        assert_eq!(entry.name, "cleanup");
        assert_eq!(entry.cron, "*/5 * * * *");
        assert_eq!(entry.last_run, Some(1700000000));
    }

    #[test]
    fn schedule_entry_accepts_null_last_run() {
        let json = r#"{"name":"n","cron":"* * * * *","lastRun":null}"#;
        let entry: ScheduleEntry = serde_json::from_str(json).unwrap();
        assert!(entry.last_run.is_none());
    }

    #[test]
    fn schedule_entry_rejects_snake_case_last_run_field() {
        // Without #[serde(default)] or an alias, the snake_case form is a foreign field.
        // Since lastRun is Option, serde allows missing; so "last_run" is simply ignored
        // and last_run defaults to None. This test pins that behavior.
        let json = r#"{"name":"n","cron":"* * * * *","last_run":123}"#;
        let entry: ScheduleEntry = serde_json::from_str(json).unwrap();
        assert_eq!(entry.last_run, None, "snake_case key must not populate last_run");
    }

    #[test]
    fn schedule_entry_requires_name_and_cron() {
        // Missing "cron" → error.
        let res: Result<ScheduleEntry, _> = serde_json::from_str(r#"{"name":"only"}"#);
        assert!(res.is_err());
    }

    // ---------------- ScheduleEvent serde ----------------

    #[test]
    fn schedule_event_serializes_to_json_with_name_field() {
        let ev = ScheduleEvent { name: "nightly".into() };
        let s = serde_json::to_string(&ev).unwrap();
        assert_eq!(s, r#"{"name":"nightly"}"#);
    }

    // ---------------- SchedulerState ----------------

    #[test]
    fn scheduler_state_new_starts_with_empty_handles_vec() {
        let s = SchedulerState::new();
        let handles = s.handles.lock().unwrap();
        assert!(handles.is_empty());
    }

    // ---------------- Cron parsing (croner smoke) ----------------
    //
    // schedule_loop bails early on invalid cron. Smoke-test the parser so we
    // notice immediately if an upstream change breaks the cron format we rely on.

    #[test]
    fn croner_parses_standard_5_field_expressions() {
        assert!(croner::Cron::new("*/5 * * * *").parse().is_ok());
        assert!(croner::Cron::new("0 * * * *").parse().is_ok());
        assert!(croner::Cron::new("0 2 * * *").parse().is_ok());
    }

    #[test]
    fn croner_rejects_obviously_malformed_cron_strings() {
        // Wrong number of fields plus nonsense tokens — must not parse.
        assert!(croner::Cron::new("this is not cron").parse().is_err());
    }

    #[test]
    fn croner_iter_after_produces_monotonic_future_times() {
        let cron = croner::Cron::new("* * * * *").parse().unwrap();
        let now = chrono::Utc::now();
        let next: Vec<_> = cron.iter_after(now).take(3).collect();
        assert_eq!(next.len(), 3);
        assert!(next[0] > now);
        assert!(next[1] > next[0]);
        assert!(next[2] > next[1]);
    }
}
