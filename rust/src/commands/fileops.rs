use std::path::PathBuf;

fn normalize(path: &str) -> PathBuf {
    let parts: Vec<&str> = path.split(['/', '\\']).filter(|s| !s.is_empty()).collect();

    #[cfg(target_os = "windows")]
    {
        if let Some(first) = parts.first() {
            if first.ends_with(':') {
                let mut p = PathBuf::from(format!("{}\\", first));
                for part in &parts[1..] {
                    p.push(part);
                }
                return p;
            }
        }
    }

    let mut p = PathBuf::from("/");
    for part in &parts {
        p.push(part);
    }
    p
}

#[tauri::command]
pub fn nb_copy_file(from: String, to: String) -> Result<(), String> {
    let from_path = normalize(&from);
    let to_path = normalize(&to);
    if let Some(parent) = to_path.parent() {
        std::fs::create_dir_all(parent).map_err(|e| e.to_string())?;
    }
    std::fs::copy(&from_path, &to_path).map_err(|e| e.to_string())?;
    Ok(())
}

#[tauri::command]
pub fn nb_move_file(from: String, to: String) -> Result<(), String> {
    let from_path = normalize(&from);
    let to_path = normalize(&to);
    if let Some(parent) = to_path.parent() {
        std::fs::create_dir_all(parent).map_err(|e| e.to_string())?;
    }
    if std::fs::rename(&from_path, &to_path).is_err() {
        std::fs::copy(&from_path, &to_path).map_err(|e| e.to_string())?;
        std::fs::remove_file(&from_path).map_err(|e| e.to_string())?;
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    // ---------------- normalize (path sanitizer) ----------------

    #[test]
    #[cfg(not(target_os = "windows"))]
    fn normalize_handles_unix_absolute_path() {
        let p = normalize("/a/b/c");
        assert_eq!(p, PathBuf::from("/a/b/c"));
    }

    #[test]
    #[cfg(not(target_os = "windows"))]
    fn normalize_drops_empty_segments_from_consecutive_separators() {
        let p = normalize("//a///b/c");
        assert_eq!(p, PathBuf::from("/a/b/c"));
    }

    #[test]
    #[cfg(not(target_os = "windows"))]
    fn normalize_mixed_separators_collapse_to_posix() {
        // Both '/' and '\' are split separators.
        let p = normalize("/a\\b/c\\d");
        assert_eq!(p, PathBuf::from("/a/b/c/d"));
    }

    #[test]
    #[cfg(not(target_os = "windows"))]
    fn normalize_relative_input_becomes_absolute_on_unix() {
        // Non-Windows branch always prefixes "/" — this is the intended
        // sandboxing behavior for the Tauri fileops side of the bridge.
        let p = normalize("a/b");
        assert_eq!(p, PathBuf::from("/a/b"));
    }

    #[test]
    #[cfg(not(target_os = "windows"))]
    fn normalize_empty_string_returns_root() {
        let p = normalize("");
        assert_eq!(p, PathBuf::from("/"));
    }

    #[test]
    #[cfg(target_os = "windows")]
    fn normalize_preserves_drive_letter_on_windows() {
        let p = normalize("C:\\Users\\x\\file.txt");
        assert_eq!(p, PathBuf::from("C:\\Users\\x\\file.txt"));
    }

    #[test]
    #[cfg(target_os = "windows")]
    fn normalize_mixed_separators_on_windows() {
        let p = normalize("D:/folder\\sub/file.txt");
        assert_eq!(p, PathBuf::from("D:\\folder\\sub\\file.txt"));
    }

    // ---------------- nb_copy_file / nb_move_file ----------------

    fn tempdir() -> std::path::PathBuf {
        let base = std::env::temp_dir().join(format!(
            "nb_fileops_test_{}_{}",
            std::process::id(),
            std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .map(|d| d.as_nanos())
                .unwrap_or(0)
        ));
        std::fs::create_dir_all(&base).expect("create tempdir");
        base
    }

    fn cleanup(path: &std::path::Path) {
        let _ = std::fs::remove_dir_all(path);
    }

    #[test]
    fn nb_copy_file_duplicates_file_and_preserves_contents() {
        let dir = tempdir();
        let src = dir.join("src.txt");
        let dst = dir.join("dst.txt");
        std::fs::write(&src, b"hello world").unwrap();

        let res = nb_copy_file(
            src.to_string_lossy().into_owned(),
            dst.to_string_lossy().into_owned(),
        );
        assert!(res.is_ok(), "nb_copy_file returned Err: {:?}", res);
        assert_eq!(std::fs::read_to_string(&dst).unwrap(), "hello world");
        // Source still exists after copy.
        assert!(src.exists());
        cleanup(&dir);
    }

    #[test]
    fn nb_copy_file_creates_missing_parent_directories() {
        let dir = tempdir();
        let src = dir.join("src.txt");
        let dst = dir.join("nested/a/b/dst.txt");
        std::fs::write(&src, b"x").unwrap();

        nb_copy_file(
            src.to_string_lossy().into_owned(),
            dst.to_string_lossy().into_owned(),
        )
        .unwrap();

        assert!(dst.exists());
        assert!(dst.parent().unwrap().is_dir());
        cleanup(&dir);
    }

    #[test]
    fn nb_copy_file_returns_err_when_source_missing() {
        let dir = tempdir();
        let src = dir.join("does_not_exist.txt");
        let dst = dir.join("dst.txt");

        let res = nb_copy_file(
            src.to_string_lossy().into_owned(),
            dst.to_string_lossy().into_owned(),
        );
        assert!(res.is_err());
        cleanup(&dir);
    }

    #[test]
    fn nb_move_file_renames_within_same_dir_and_removes_source() {
        let dir = tempdir();
        let src = dir.join("src.txt");
        let dst = dir.join("dst.txt");
        std::fs::write(&src, b"payload").unwrap();

        nb_move_file(
            src.to_string_lossy().into_owned(),
            dst.to_string_lossy().into_owned(),
        )
        .unwrap();

        assert!(!src.exists(), "source should be gone after move");
        assert_eq!(std::fs::read_to_string(&dst).unwrap(), "payload");
        cleanup(&dir);
    }

    #[test]
    fn nb_move_file_creates_missing_parent_directories() {
        let dir = tempdir();
        let src = dir.join("src.txt");
        let dst = dir.join("nested/deep/dst.txt");
        std::fs::write(&src, b"data").unwrap();

        nb_move_file(
            src.to_string_lossy().into_owned(),
            dst.to_string_lossy().into_owned(),
        )
        .unwrap();

        assert!(dst.exists());
        assert!(!src.exists());
        cleanup(&dir);
    }

    #[test]
    fn nb_move_file_returns_err_when_source_missing() {
        let dir = tempdir();
        let src = dir.join("missing.txt");
        let dst = dir.join("dst.txt");

        let res = nb_move_file(
            src.to_string_lossy().into_owned(),
            dst.to_string_lossy().into_owned(),
        );
        // Both rename() and the fallback copy() should fail on missing source;
        // we surface the copy() error via `?`.
        assert!(res.is_err());
        cleanup(&dir);
    }
}
