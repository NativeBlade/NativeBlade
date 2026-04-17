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

#[cfg(test)]
mod tests {
    use super::*;

    // ---------------- load_env_from_string ----------------

    #[test]
    fn load_env_from_string_parses_simple_key_value_lines() {
        let env = load_env_from_string("FOO=bar\nBAZ=qux");
        assert_eq!(env.get("FOO"), Some(&"bar".to_string()));
        assert_eq!(env.get("BAZ"), Some(&"qux".to_string()));
        assert_eq!(env.len(), 2);
    }

    #[test]
    fn load_env_from_string_skips_comment_lines() {
        let env = load_env_from_string("# this is a comment\nFOO=bar\n#ANOTHER=ignored");
        assert_eq!(env.get("FOO"), Some(&"bar".to_string()));
        assert!(!env.contains_key("ANOTHER"));
        assert_eq!(env.len(), 1);
    }

    #[test]
    fn load_env_from_string_skips_lines_without_equals() {
        let env = load_env_from_string("FOO=bar\nnot_a_kv_line\nBAZ=qux");
        assert_eq!(env.len(), 2);
        assert_eq!(env.get("FOO"), Some(&"bar".to_string()));
        assert_eq!(env.get("BAZ"), Some(&"qux".to_string()));
    }

    #[test]
    fn load_env_from_string_trims_whitespace_around_key_and_value() {
        let env = load_env_from_string("  FOO  =  bar  ");
        assert_eq!(env.get("FOO"), Some(&"bar".to_string()));
    }

    #[test]
    fn load_env_from_string_keeps_empty_values() {
        // splitn(2, '=') on "KEY=" yields ["KEY", ""]; trimmed val is "".
        // We accept this — env_or's filter(!empty) handles blank values.
        let env = load_env_from_string("EMPTY=");
        assert_eq!(env.get("EMPTY"), Some(&"".to_string()));
    }

    #[test]
    fn load_env_from_string_handles_equals_in_value() {
        // splitn(2) ensures we split only on the first '='.
        let env = load_env_from_string("URL=http://a.test?x=1&y=2");
        assert_eq!(env.get("URL"), Some(&"http://a.test?x=1&y=2".to_string()));
    }

    #[test]
    fn load_env_from_string_empty_returns_empty_map() {
        let env = load_env_from_string("");
        assert!(env.is_empty());
    }

    #[test]
    fn load_env_from_string_drops_lines_with_empty_key() {
        // Line "=value" → key is empty → filter_map yields None.
        let env = load_env_from_string("=orphan\nFOO=bar");
        assert_eq!(env.len(), 1);
        assert_eq!(env.get("FOO"), Some(&"bar".to_string()));
    }

    // ---------------- env_or ----------------

    #[test]
    fn env_or_returns_value_when_present_and_non_empty() {
        let mut env = HashMap::new();
        env.insert("KEY".to_string(), "value".to_string());
        assert_eq!(env_or(&env, "KEY", "default"), "value");
    }

    #[test]
    fn env_or_falls_back_to_default_when_key_missing() {
        let env = HashMap::new();
        assert_eq!(env_or(&env, "MISSING", "fallback"), "fallback");
    }

    #[test]
    fn env_or_falls_back_to_default_when_value_is_empty_string() {
        // The .filter(|v| !v.is_empty()) branch — blank env vars should
        // behave like unset ones. Matches get_config's user-facing intent.
        let mut env = HashMap::new();
        env.insert("KEY".to_string(), "".to_string());
        assert_eq!(env_or(&env, "KEY", "default"), "default");
    }

    // ---------------- is_mobile ----------------

    #[test]
    fn is_mobile_returns_false_in_desktop_test_context() {
        // Tests run on the host (desktop). cfg!(mobile) is controlled by the
        // `mobile` custom cfg set by tauri's platform-specific builds, which
        // is not active in our test target.
        assert!(!is_mobile());
    }
}
