use super::menu::resolve_config_path;
use serde::Deserialize;
use tauri::image::Image;
use tauri::menu::{Menu, MenuItemBuilder, PredefinedMenuItem, SubmenuBuilder};
use tauri::tray::{MouseButton, MouseButtonState, TrayIconBuilder, TrayIconEvent};
use tauri::{AppHandle, Emitter, Manager, Wry};

#[derive(Deserialize)]
struct TrayConfig {
    enabled: bool,
    tooltip: String,
    #[serde(rename = "hideOnClose")]
    hide_on_close: bool,
    #[serde(rename = "customIcon", default)]
    custom_icon: bool,
    #[serde(default)]
    menu: Vec<TrayMenuItem>,
}

#[derive(Deserialize)]
#[serde(untagged)]
enum TrayMenuItem {
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
        items: Vec<TrayMenuItem>,
    },
}

fn get_config(app: &AppHandle<Wry>) -> TrayConfig {
    resolve_config_path(app, "tray.json")
        .and_then(|p| std::fs::read_to_string(p).ok())
        .and_then(|s| serde_json::from_str(&s).ok())
        .unwrap_or(TrayConfig {
            enabled: false,
            tooltip: "NativeBlade".into(),
            hide_on_close: false,
            custom_icon: false,
            menu: vec![],
        })
}

pub fn should_hide_on_close(app: &AppHandle<Wry>) -> bool {
    get_config(app).hide_on_close
}

fn build_tray_menu(app: &AppHandle<Wry>, items: &[TrayMenuItem]) -> Menu<Wry> {
    let menu = Menu::new(app).unwrap();

    for item in items {
        match item {
            TrayMenuItem::Separator { .. } => {
                let _ = menu.append(&PredefinedMenuItem::separator(app).unwrap());
            }
            TrayMenuItem::Action { label, action } => {
                if let Ok(mi) = MenuItemBuilder::new(label).id(action).build(app) {
                    let _ = menu.append(&mi);
                }
            }
            TrayMenuItem::Submenu { label, items } => {
                let mut builder = SubmenuBuilder::new(app, label);
                for sub_item in items {
                    if let TrayMenuItem::Action {
                        label: l,
                        action: a,
                    } = sub_item
                    {
                        if let Ok(mi) = MenuItemBuilder::new(l).id(a).build(app) {
                            builder = builder.item(&mi);
                        }
                    }
                }
                if let Ok(built) = builder.build() {
                    let _ = menu.append(&built);
                }
            }
        }
    }

    menu
}

fn default_tray_menu(app: &AppHandle<Wry>) -> Menu<Wry> {
    let menu = Menu::new(app).unwrap();
    let _ = menu.append(&MenuItemBuilder::new("Show").id("show").build(app).unwrap());
    let _ = menu.append(&MenuItemBuilder::new("Quit").id("exit").build(app).unwrap());
    menu
}

fn handle_tray_action(app: &AppHandle<Wry>, action: &str) {
    match action {
        "show" => {
            if let Some(window) = app.get_webview_window("main") {
                let _ = window.show();
                let _ = window.set_focus();
            }
        }
        "exit" | "quit" => {
            app.exit(0);
        }
        a if a.starts_with('/') => {
            let _ = app.emit("nativeblade-menu", a);
        }
        a => {
            let _ = app.emit("nativeblade-menu", a);
        }
    }
}

pub fn setup(app: &AppHandle<Wry>) {
    let config = get_config(app);
    if !config.enabled {
        return;
    }

    let menu = if config.menu.is_empty() {
        default_tray_menu(app)
    } else {
        build_tray_menu(app, &config.menu)
    };

    let icon = if config.custom_icon {
        resolve_config_path(app, "icons/tray.png")
            .and_then(|p| std::fs::read(&p).ok())
            .and_then(|bytes| Image::from_bytes(&bytes).ok())
            .unwrap_or_else(|| app.default_window_icon().unwrap().clone())
    } else {
        app.default_window_icon().unwrap().clone()
    };

    let app_handle = app.clone();
    let app_handle2 = app.clone();

    let _ = TrayIconBuilder::new()
        .icon(icon)
        .tooltip(&config.tooltip)
        .menu(&menu)
        .on_menu_event(move |_app, event: tauri::menu::MenuEvent| {
            handle_tray_action(&app_handle, event.id().as_ref());
        })
        .on_tray_icon_event(move |_tray, event| {
            if let TrayIconEvent::Click {
                button: MouseButton::Left,
                button_state: MouseButtonState::Up,
                ..
            } = event
            {
                if let Some(window) = app_handle2.get_webview_window("main") {
                    let _ = window.show();
                    let _ = window.set_focus();
                }
            }
        })
        .build(app);
}
