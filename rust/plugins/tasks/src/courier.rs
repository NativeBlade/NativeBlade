//! The courier: executes one task — a GET that parks its response, or a POST
//! that fires and forgets (with an outbox for failures). Pure Rust over
//! blocking reqwest; no Tauri runtime required, so the same function runs
//! from a Tauri command (app open), from a WorkManager JNI call (Android,
//! app closed) or from a BGTask C call (iOS, app closed).

use std::path::Path;
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

use crate::model::{Collected, TaskDef, TaskMeta};
use crate::store;

/// Hard ceiling per run; background windows (iOS ~30s) must never be blown
/// by a hung request.
const REQUEST_TIMEOUT: Duration = Duration::from_secs(20);

pub struct RunOutcome {
    pub ok: bool,
    pub status: u16,
    pub error: Option<String>,
}

pub fn run_task(def: &TaskDef, collected: &Collected, data_dir: &Path) -> RunOutcome {
    let dir = store::task_dir(data_dir, &def.name);
    let started = Instant::now();
    let ran_at = now();

    let outcome = match def.kind.as_str() {
        "fetch" => run_fetch(def, collected, &dir, ran_at),
        "post" => run_post(def, collected, &dir, ran_at),
        other => RunOutcome { ok: false, status: 0, error: Some(format!("unknown task kind: {other}")) },
    };

    let _ = store::write_meta(&dir, &TaskMeta {
        ran_at,
        status: outcome.status,
        duration_ms: started.elapsed().as_millis() as u64,
        error: outcome.error.clone(),
    });

    outcome
}

fn run_fetch(def: &TaskDef, collected: &Collected, dir: &Path, ran_at: u64) -> RunOutcome {
    let client = match client() {
        Ok(c) => c,
        Err(e) => return failed(0, e),
    };

    let mut req = client.get(&def.url);
    req = apply_headers(req, def, collected);

    let resp = match req.send() {
        Ok(r) => r,
        Err(e) => return failed(0, e.to_string()),
    };
    let status = resp.status().as_u16();
    let body = match resp.bytes() {
        Ok(b) => b,
        Err(e) => return failed(status, e.to_string()),
    };

    if !(200..300).contains(&status) {
        return failed(status, format!("http {status}"));
    }
    if body.len() > store::MAX_PAYLOAD_BYTES {
        return failed(status, format!("payload too large: {} bytes", body.len()));
    }

    let parked = if def.latest_only {
        store::park_latest(dir, &body)
    } else {
        // Queued for handlers AND mirrored to latest for getTask pulls.
        store::park_queued(dir, &body, ran_at)
            .and_then(|_| store::park_latest(dir, &body))
    };
    match parked {
        Ok(_) => RunOutcome { ok: true, status, error: None },
        Err(e) => failed(status, format!("park failed: {e}")),
    }
}

fn run_post(def: &TaskDef, collected: &Collected, dir: &Path, ran_at: u64) -> RunOutcome {
    let client = match client() {
        Ok(c) => c,
        Err(e) => return failed(0, e),
    };

    // Fixed body fields + collected data (location wins its own key).
    let mut body = match &def.body {
        serde_json::Value::Object(map) => map.clone(),
        _ => serde_json::Map::new(),
    };
    if let Some(location) = &collected.location {
        body.insert("location".into(), location.clone());
    }
    body.insert("sentAt".into(), serde_json::json!(ran_at));
    let payload = serde_json::Value::Object(body);
    let payload_bytes = serde_json::to_vec(&payload).unwrap_or_default();

    // Older stranded payloads go first so the server sees chronological order.
    let mut flushed_all = true;
    for entry in store::outbox_entries(dir) {
        let Ok(bytes) = std::fs::read(&entry) else { continue };
        if send_json(&client, def, collected, &bytes).is_some_and(|s| (200..300).contains(&s)) {
            let _ = std::fs::remove_file(&entry);
        } else {
            flushed_all = false;
            break; // keep order: stop at the first failure
        }
    }

    if !flushed_all {
        let _ = store::outbox_push(dir, &payload_bytes, ran_at);
        return failed(0, "offline or server unreachable; payload queued".into());
    }

    match send_json(&client, def, collected, &payload_bytes) {
        Some(status) if (200..300).contains(&status) => RunOutcome { ok: true, status, error: None },
        Some(status) => {
            let _ = store::outbox_push(dir, &payload_bytes, ran_at);
            failed(status, format!("http {status}; payload queued"))
        }
        None => {
            let _ = store::outbox_push(dir, &payload_bytes, ran_at);
            failed(0, "request failed; payload queued".into())
        }
    }
}

fn send_json(
    client: &reqwest::blocking::Client,
    def: &TaskDef,
    collected: &Collected,
    bytes: &[u8],
) -> Option<u16> {
    let mut req = client
        .post(&def.url)
        .header("Content-Type", "application/json")
        .body(bytes.to_vec());
    req = apply_headers(req, def, collected);
    req.send().ok().map(|r| r.status().as_u16())
}

fn apply_headers(
    mut req: reqwest::blocking::RequestBuilder,
    def: &TaskDef,
    collected: &Collected,
) -> reqwest::blocking::RequestBuilder {
    for (k, v) in &def.headers {
        req = req.header(k, v);
    }
    if let Some(bearer) = &collected.bearer {
        req = req.header("Authorization", format!("Bearer {bearer}"));
    }
    req
}

fn client() -> Result<reqwest::blocking::Client, String> {
    reqwest::blocking::Client::builder()
        .timeout(REQUEST_TIMEOUT)
        .build()
        .map_err(|e| e.to_string())
}

fn failed(status: u16, error: String) -> RunOutcome {
    RunOutcome { ok: false, status, error: Some(error) }
}

fn now() -> u64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_secs())
        .unwrap_or(0)
}

#[cfg(test)]
mod tests {
    use super::*;
    use tempfile::tempdir;

    fn def(kind: &str, url: String, latest_only: bool) -> TaskDef {
        serde_json::from_value(serde_json::json!({
            "name": "t1", "kind": kind, "url": url, "latestOnly": latest_only,
        }))
        .unwrap()
    }

    #[test]
    fn fetch_parks_latest_and_meta() {
        let mut server = mockito::Server::new();
        let m = server.mock("GET", "/prices").with_status(200).with_body("{\"btc\":1}").create();

        let tmp = tempdir().unwrap();
        let out = run_task(&def("fetch", format!("{}/prices", server.url()), true), &Collected::default(), tmp.path());

        m.assert();
        assert!(out.ok);
        let dir = store::task_dir(tmp.path(), "t1");
        assert_eq!(store::read_latest(&dir).unwrap(), b"{\"btc\":1}");
        let meta = store::read_meta(&dir).unwrap();
        assert_eq!(meta.status, 200);
        assert!(meta.error.is_none());
        // latest_only: nothing queued
        assert!(store::drain_queued(&dir).is_empty());
    }

    #[test]
    fn fetch_without_latest_only_queues_and_mirrors_latest() {
        let mut server = mockito::Server::new();
        server.mock("GET", "/n").with_status(200).with_body("a").create();

        let tmp = tempdir().unwrap();
        run_task(&def("fetch", format!("{}/n", server.url()), false), &Collected::default(), tmp.path());

        let dir = store::task_dir(tmp.path(), "t1");
        assert_eq!(store::drain_queued(&dir).len(), 1);
        assert_eq!(store::read_latest(&dir).unwrap(), b"a");
    }

    #[test]
    fn fetch_http_error_keeps_previous_latest() {
        let mut server = mockito::Server::new();
        let ok = server.mock("GET", "/p").with_status(200).with_body("good").create();
        let tmp = tempdir().unwrap();
        let d = def("fetch", format!("{}/p", server.url()), true);
        run_task(&d, &Collected::default(), tmp.path());
        ok.remove();

        server.mock("GET", "/p").with_status(500).with_body("boom").create();
        let out = run_task(&d, &Collected::default(), tmp.path());

        assert!(!out.ok);
        let dir = store::task_dir(tmp.path(), "t1");
        assert_eq!(store::read_latest(&dir).unwrap(), b"good"); // untouched
        assert_eq!(store::read_meta(&dir).unwrap().status, 500);
    }

    #[test]
    fn post_sends_bearer_and_collected_location() {
        let mut server = mockito::Server::new();
        let m = server
            .mock("POST", "/ping")
            .match_header("authorization", "Bearer tok123")
            .match_body(mockito::Matcher::PartialJsonString("{\"location\":{\"lat\":-23.5}}".into()))
            .with_status(201)
            .create();

        let tmp = tempdir().unwrap();
        let collected = Collected {
            bearer: Some("tok123".into()),
            location: Some(serde_json::json!({"lat": -23.5})),
        };
        let out = run_task(&def("post", format!("{}/ping", server.url()), false), &collected, tmp.path());

        m.assert();
        assert!(out.ok);
        assert_eq!(out.status, 201);
    }

    #[test]
    fn post_failure_queues_outbox_and_next_success_flushes_in_order() {
        let mut server = mockito::Server::new();
        let tmp = tempdir().unwrap();
        let d = def("post", format!("{}/ping", server.url()), false);

        // Server down (500) twice: both payloads must queue.
        let down = server.mock("POST", "/ping").with_status(500).expect(2).create();
        assert!(!run_task(&d, &Collected::default(), tmp.path()).ok);
        assert!(!run_task(&d, &Collected::default(), tmp.path()).ok);
        down.assert();
        let dir = store::task_dir(tmp.path(), "t1");
        assert_eq!(store::outbox_entries(&dir).len(), 2);

        // Server back: the run sends 2 stranded + 1 current = 3 POSTs.
        server.mock("POST", "/ping").with_status(200).expect(3).create();
        let out = run_task(&d, &Collected::default(), tmp.path());
        assert!(out.ok);
        assert!(store::outbox_entries(&dir).is_empty());
    }

    #[test]
    fn unknown_kind_reports_error_meta() {
        let tmp = tempdir().unwrap();
        let out = run_task(&def("dance", "http://x".into(), false), &Collected::default(), tmp.path());
        assert!(!out.ok);
        let meta = store::read_meta(&store::task_dir(tmp.path(), "t1")).unwrap();
        assert!(meta.error.unwrap().contains("unknown task kind"));
    }
}
