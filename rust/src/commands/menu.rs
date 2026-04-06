use super::bridge;
use serde::Deserialize;
use tauri::menu::{Menu, MenuItemBuilder, SubmenuBuilder};
use tauri::{AppHandle, Emitter, Manager, Wry};

#[derive(Deserialize)]
#[serde(untagged)]
enum MenuItem {
    Separator {
        #[allow(dead_code)]
        separator: bool,
    },
    Action {
        label: String,
        action: String,
    },
    Submenu {
        label: String,
        items: Vec<MenuItem>,
    },
}

pub fn build_menu(app: &AppHandle<Wry>) -> Option<Menu<Wry>> {
    let menu_path = resolve_config_path(app, "menu.json")?;
    let menu_json = std::fs::read_to_string(menu_path).ok()?;
    let items: Vec<MenuItem> = serde_json::from_str(&menu_json).ok()?;

    if items.is_empty() {
        return None;
    }

    let menu = Menu::new(app).ok()?;

    for item in &items {
        if let MenuItem::Submenu { label, items } = item {
            if let Ok(built) = build_submenu(app, label, items) {
                let _ = menu.append(&built);
            }
        }
    }

    Some(menu)
}

fn build_submenu(
    app: &AppHandle<Wry>,
    label: &str,
    items: &[MenuItem],
) -> Result<tauri::menu::Submenu<Wry>, Box<dyn std::error::Error>> {
    let mut builder = SubmenuBuilder::new(app, label);

    for item in items {
        match item {
            MenuItem::Separator { .. } => {
                builder = builder.separator();
            }
            MenuItem::Action { label, action } => {
                let menu_item = MenuItemBuilder::new(label).id(action).build(app)?;
                builder = builder.item(&menu_item);
            }
            MenuItem::Submenu {
                label: sub_label,
                items: sub_items,
            } => {
                let submenu = build_submenu(app, sub_label, sub_items)?;
                builder = builder.item(&submenu);
            }
        }
    }

    Ok(builder.build()?)
}

pub fn handle_menu_event(app: &AppHandle<Wry>, event: &tauri::menu::MenuEvent) {
    let action = event.id().as_ref();

    if action.starts_with('/') {
        let _ = app.emit("nativeblade-menu", action);
    } else {
        let so_action = format!("so:{}", action);
        bridge::native_action(app.clone(), so_action, String::new());
    }
}

pub fn resolve_config_path(app: &AppHandle<Wry>, filename: &str) -> Option<std::path::PathBuf> {
    if let Ok(resource_dir) = app.path().resource_dir() {
        let path = resource_dir.join(filename);
        if path.exists() {
            return Some(path);
        }
    }

    let candidates = [
        std::env::current_exe()
            .ok()
            .and_then(|p| p.parent().map(|p| p.join(filename))),
        Some(std::path::PathBuf::from(format!("src-tauri/{}", filename))),
        Some(std::path::PathBuf::from(filename)),
    ];

    for path in candidates.into_iter().flatten() {
        if path.exists() {
            return Some(path);
        }
    }

    None
}
