use serde::Serialize;
use std::collections::HashMap;
#[cfg(not(mobile))]
use std::path::PathBuf;

#[cfg(mobile)]
const EMBEDDED_ENV: &str = include_str!("../../../.env");
#[cfg(mobile)]
const EMBEDDED_LANG_PT_BR: &str = include_str!("../../../lang/pt_BR.json");
#[cfg(mobile)]
const EMBEDDED_LANG_EN: &str = include_str!("../../../lang/en.json");

#[derive(Serialize)]
pub struct AppConfig {
    pub app_url: String,
    pub app_name: String,
    pub platform: String,
    pub mode: String,
    pub health_endpoint: String,
    pub poll_interval: String,
    pub max_attempts: String,
    pub translations: HashMap<String, String>,
}

fn is_mobile() -> bool {
    cfg!(mobile)
}

fn load_env_from_string(content: &str) -> HashMap<String, String> {
    content
        .lines()
        .filter(|line| !line.starts_with('#') && line.contains('='))
        .filter_map(|line| {
            let mut parts = line.splitn(2, '=');
            let key = parts.next()?.trim().to_string();
            let val = parts.next()?.trim().to_string();
            if key.is_empty() { return None; }
            Some((key, val))
        })
        .collect()
}

fn load_env() -> HashMap<String, String> {
    #[cfg(mobile)]
    {
        load_env_from_string(EMBEDDED_ENV)
    }

    #[cfg(not(mobile))]
    {
        let candidates = vec![
            std::env::current_exe()
                .ok()
                .and_then(|p| p.parent().map(|p| p.join("../.env"))),
            Some(PathBuf::from("../.env")),
            Some(PathBuf::from(".env")),
        ];

        for path in candidates.into_iter().flatten() {
            if let Ok(content) = std::fs::read_to_string(&path) {
                return load_env_from_string(&content);
            }
        }

        HashMap::new()
    }
}

fn load_translations(locale: &str) -> HashMap<String, String> {
    #[cfg(mobile)]
    {
        let content = match locale {
            "pt_BR" => EMBEDDED_LANG_PT_BR,
            _ => EMBEDDED_LANG_EN,
        };
        serde_json::from_str(content).unwrap_or_default()
    }

    #[cfg(not(mobile))]
    {
        let filename = format!("{}.json", locale);
        let candidates = vec![
            std::env::current_exe()
                .ok()
                .and_then(|p| p.parent().map(|p| p.join("../.env"))),
            Some(PathBuf::from("../.env")),
            Some(PathBuf::from(".env")),
        ];

        for path in candidates.into_iter().flatten() {
            if let Some(root) = path.parent() {
                let lang_path = root.join("lang").join(&filename);
                if let Ok(content) = std::fs::read_to_string(&lang_path) {
                    return serde_json::from_str(&content).unwrap_or_default();
                }
            }
        }

        HashMap::new()
    }
}

fn env_or(env: &HashMap<String, String>, key: &str, default: &str) -> String {
    env.get(key)
        .filter(|v| !v.is_empty())
        .cloned()
        .unwrap_or(default.into())
}

#[tauri::command]
pub fn get_config() -> AppConfig {
    let env = load_env();
    let locale = env_or(&env, "APP_LOCALE", "en");
    let translations = load_translations(&locale);

    let mode = env_or(&env, "NATIVEBLADE_MODE", "local");
    let platform = if is_mobile() { "mobile" } else { "desktop" };

    let app_url = if is_mobile() {
        env_or(&env, "NATIVEBLADE_MOBILE_URL", "http://127.0.0.1:8000")
    } else if mode == "remote" {
        env_or(&env, "NATIVEBLADE_REMOTE_URL", "http://127.0.0.1:8000")
    } else {
        env_or(&env, "APP_URL", "http://127.0.0.1:8000")
    };

    AppConfig {
        app_url,
        app_name: env_or(&env, "APP_NAME", "NativeBlade"),
        platform: platform.into(),
        mode: mode.clone(),
        health_endpoint: env_or(&env, "NATIVEBLADE_HEALTH_ENDPOINT", "/up"),
        poll_interval: env_or(&env, "NATIVEBLADE_POLL_INTERVAL", "500"),
        max_attempts: env_or(&env, "NATIVEBLADE_MAX_ATTEMPTS", "60"),
        translations,
    }
}
