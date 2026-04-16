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
