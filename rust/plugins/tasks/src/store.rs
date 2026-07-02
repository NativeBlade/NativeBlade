//! The parking store: one directory per task under
//! `<app_data_dir>/nativeblade/tasks/`, written only with atomic renames so a
//! process killed mid-write never leaves a torn file. The background courier
//! is the writer; the open app reads (and drains queued results). Combined
//! with the "worker skips while the app is in foreground" rule there is never
//! more than one writer.
//!
//! Layout per task:
//!   latest.json    — last response payload (fetch tasks)
//!   meta.json      — {ranAt, status, durationMs, error?} for every attempt
//!   results/*.json — queued responses when latest_only is off (capped)
//!   outbox/*.json  — post payloads that failed to send, awaiting retry

use std::fs;
use std::path::{Path, PathBuf};

use crate::model::TaskMeta;

/// Queued results kept per task; oldest evicted beyond this.
const QUEUE_CAP: usize = 20;
/// Outbox entries kept per task; oldest evicted beyond this.
const OUTBOX_CAP: usize = 100;
/// Payloads above this are not parked (meta records the error instead).
pub const MAX_PAYLOAD_BYTES: usize = 1024 * 1024;

pub fn task_dir(base: &Path, name: &str) -> PathBuf {
    base.join("nativeblade").join("tasks").join(name)
}

/// All-or-nothing write: temp file in the same directory, then rename.
///
/// Overwriting works cross-platform: Rust's `fs::rename` maps to
/// `MoveFileExW(MOVEFILE_REPLACE_EXISTING)` on Windows (covered by the
/// `latest_overwrites` test). Do NOT "fix" this by removing the destination
/// first — that reopens the torn-state window this function exists to close.
pub fn atomic_write(path: &Path, bytes: &[u8]) -> std::io::Result<()> {
    if let Some(parent) = path.parent() {
        fs::create_dir_all(parent)?;
    }
    let tmp = path.with_extension("tmp");
    fs::write(&tmp, bytes)?;
    fs::rename(&tmp, path)
}

pub fn write_meta(dir: &Path, meta: &TaskMeta) -> std::io::Result<()> {
    let bytes = serde_json::to_vec(meta).expect("meta serializes");
    atomic_write(&dir.join("meta.json"), &bytes)
}

pub fn read_meta(dir: &Path) -> Option<TaskMeta> {
    let bytes = fs::read(dir.join("meta.json")).ok()?;
    serde_json::from_slice(&bytes).ok()
}

pub fn park_latest(dir: &Path, payload: &[u8]) -> std::io::Result<()> {
    atomic_write(&dir.join("latest.json"), payload)
}

pub fn read_latest(dir: &Path) -> Option<Vec<u8>> {
    fs::read(dir.join("latest.json")).ok()
}

/// Queue a result (non-latest_only tasks); evicts the oldest beyond the cap.
pub fn park_queued(dir: &Path, payload: &[u8], ran_at: u64) -> std::io::Result<()> {
    let results = dir.join("results");
    // Millisecond-ish uniqueness: same-second runs get a numeric suffix.
    let mut path = results.join(format!("{ran_at}.json"));
    let mut n = 1;
    while path.exists() {
        path = results.join(format!("{ran_at}-{n}.json"));
        n += 1;
    }
    atomic_write(&path, payload)?;
    evict_oldest(&results, QUEUE_CAP);
    Ok(())
}

/// Read every queued result (oldest first) and delete them — the drain the
/// app runs at boot to feed PHP handlers. `latest.json` is untouched.
pub fn drain_queued(dir: &Path) -> Vec<(u64, Vec<u8>)> {
    let mut out = Vec::new();
    for (stamp, path) in sorted_entries(&dir.join("results")) {
        if let Ok(bytes) = fs::read(&path) {
            out.push((stamp, bytes));
        }
        let _ = fs::remove_file(&path);
    }
    out
}

/// Park a post payload that could not be sent.
pub fn outbox_push(dir: &Path, payload: &[u8], ran_at: u64) -> std::io::Result<()> {
    let outbox = dir.join("outbox");
    let mut path = outbox.join(format!("{ran_at}.json"));
    let mut n = 1;
    while path.exists() {
        path = outbox.join(format!("{ran_at}-{n}.json"));
        n += 1;
    }
    atomic_write(&path, payload)?;
    evict_oldest(&outbox, OUTBOX_CAP);
    Ok(())
}

/// Drop pending outbox entries; returns how many were removed. With an id,
/// only entries whose payload carries that `id` fall (several can share one);
/// without, everything goes.
pub fn clear_outbox(dir: &Path, id: Option<&str>) -> usize {
    let mut removed = 0;
    for path in outbox_entries(dir) {
        if let Some(id) = id {
            let matches = fs::read(&path)
                .ok()
                .and_then(|b| serde_json::from_slice::<serde_json::Value>(&b).ok())
                .and_then(|v| v.get("id").and_then(|i| i.as_str()).map(|i| i == id))
                .unwrap_or(false);
            if !matches {
                continue;
            }
        }
        if fs::remove_file(path).is_ok() {
            removed += 1;
        }
    }
    removed
}

/// Pending outbox entries, oldest first. Entries are removed by the caller
/// one by one as each send succeeds, so a failure keeps the rest queued.
pub fn outbox_entries(dir: &Path) -> Vec<PathBuf> {
    sorted_entries(&dir.join("outbox"))
        .into_iter()
        .map(|(_, p)| p)
        .collect()
}

fn sorted_entries(dir: &Path) -> Vec<(u64, PathBuf)> {
    let mut entries: Vec<(u64, PathBuf)> = fs::read_dir(dir)
        .map(|rd| {
            rd.filter_map(|e| e.ok())
                .map(|e| e.path())
                .filter(|p| p.extension().is_some_and(|x| x == "json"))
                .map(|p| (stamp_of(&p), p))
                .collect()
        })
        .unwrap_or_default();
    entries.sort();
    entries
}

fn stamp_of(path: &Path) -> u64 {
    path.file_stem()
        .and_then(|s| s.to_str())
        .and_then(|s| s.split('-').next())
        .and_then(|s| s.parse().ok())
        .unwrap_or(0)
}

fn evict_oldest(dir: &Path, cap: usize) {
    let entries = sorted_entries(dir);
    if entries.len() > cap {
        for (_, path) in &entries[..entries.len() - cap] {
            let _ = fs::remove_file(path);
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use tempfile::tempdir;

    #[test]
    fn atomic_write_roundtrip_and_no_tmp_left() {
        let tmp = tempdir().unwrap();
        let path = tmp.path().join("a/b/latest.json");
        atomic_write(&path, b"{\"x\":1}").unwrap();
        assert_eq!(fs::read(&path).unwrap(), b"{\"x\":1}");
        assert!(!path.with_extension("tmp").exists());
    }

    #[test]
    fn latest_overwrites() {
        let tmp = tempdir().unwrap();
        park_latest(tmp.path(), b"one").unwrap();
        park_latest(tmp.path(), b"two").unwrap();
        assert_eq!(read_latest(tmp.path()).unwrap(), b"two");
    }

    #[test]
    fn queue_caps_and_drains_in_order() {
        let tmp = tempdir().unwrap();
        for i in 0..(QUEUE_CAP as u64 + 5) {
            park_queued(tmp.path(), format!("p{i}").as_bytes(), 1000 + i).unwrap();
        }
        let drained = drain_queued(tmp.path());
        assert_eq!(drained.len(), QUEUE_CAP);
        // Oldest five evicted; order preserved oldest→newest.
        assert_eq!(drained.first().unwrap().0, 1005);
        assert_eq!(drained.last().unwrap().0, 1000 + QUEUE_CAP as u64 + 4);
        // Drain empties the queue.
        assert!(drain_queued(tmp.path()).is_empty());
    }

    #[test]
    fn same_stamp_entries_do_not_collide() {
        let tmp = tempdir().unwrap();
        park_queued(tmp.path(), b"a", 42).unwrap();
        park_queued(tmp.path(), b"b", 42).unwrap();
        assert_eq!(drain_queued(tmp.path()).len(), 2);
    }

    #[test]
    fn clear_outbox_drops_everything_and_reports_count() {
        let tmp = tempdir().unwrap();
        outbox_push(tmp.path(), b"a", 1).unwrap();
        outbox_push(tmp.path(), b"b", 2).unwrap();
        assert_eq!(clear_outbox(tmp.path(), None), 2);
        assert!(outbox_entries(tmp.path()).is_empty());
        assert_eq!(clear_outbox(tmp.path(), None), 0); // idempotent
    }

    #[test]
    fn upsert_by_id_is_clear_then_push() {
        // The enqueue command composes these two calls; pinned here so the
        // replace-same-id semantics never regress at the store level.
        let tmp = tempdir().unwrap();
        outbox_push(tmp.path(), br#"{"id":"photo-1","v":1}"#, 1).unwrap();
        outbox_push(tmp.path(), br#"{"id":"other","v":1}"#, 2).unwrap();

        clear_outbox(tmp.path(), Some("photo-1"));
        outbox_push(tmp.path(), br#"{"id":"photo-1","v":2}"#, 3).unwrap();

        let entries = outbox_entries(tmp.path());
        assert_eq!(entries.len(), 2);
        // The replaced entry moved to the END of the queue (newest state
        // ships last), and only v2 survived.
        let last = fs::read(entries.last().unwrap()).unwrap();
        assert!(String::from_utf8_lossy(&last).contains("\"v\":2"));
    }

    #[test]
    fn clear_outbox_by_id_removes_only_matching_entries() {
        let tmp = tempdir().unwrap();
        outbox_push(tmp.path(), br#"{"id":"photo-1","x":1}"#, 1).unwrap();
        outbox_push(tmp.path(), br#"{"id":"photo-2","x":2}"#, 2).unwrap();
        outbox_push(tmp.path(), br#"{"id":"photo-1","x":3}"#, 3).unwrap();
        outbox_push(tmp.path(), br#"{"x":4}"#, 4).unwrap(); // no id

        assert_eq!(clear_outbox(tmp.path(), Some("photo-1")), 2);
        assert_eq!(outbox_entries(tmp.path()).len(), 2);
        assert_eq!(clear_outbox(tmp.path(), Some("missing")), 0);
    }

    #[test]
    fn outbox_removes_only_sent_entries() {
        let tmp = tempdir().unwrap();
        outbox_push(tmp.path(), b"p1", 1).unwrap();
        outbox_push(tmp.path(), b"p2", 2).unwrap();
        let entries = outbox_entries(tmp.path());
        assert_eq!(entries.len(), 2);
        fs::remove_file(&entries[0]).unwrap(); // "sent" the first
        assert_eq!(outbox_entries(tmp.path()).len(), 1);
    }

    #[test]
    fn meta_roundtrip() {
        let tmp = tempdir().unwrap();
        let meta = TaskMeta { ran_at: 7, status: 200, duration_ms: 12, error: None };
        write_meta(tmp.path(), &meta).unwrap();
        let read = read_meta(tmp.path()).unwrap();
        assert_eq!(read.ran_at, 7);
        assert_eq!(read.status, 200);
    }
}
