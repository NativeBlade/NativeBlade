#[tauri::command]
pub async fn check_backend(url: String) -> bool {
    match reqwest::get(&url).await {
        Ok(res) => res.status().is_success(),
        Err(_) => false,
    }
}
