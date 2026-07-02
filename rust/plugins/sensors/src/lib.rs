//! Sensors plugin for NativeBlade.
//!
//! Raw device sensors — accelerometer (g), gyroscope (rad/s), magnetometer
//! (μT), barometer (hPa) and ambient light (lux, Android only) — via
//! SensorManager on Android and CoreMotion on iOS. Two consumption modes:
//! a one-shot `read_sensor`, and a throttled `watch_sensor` stream (100ms
//! floor) delivered through the `sensor-changed` plugin event. Mobile only;
//! no permissions required (raw readings are unrestricted on both platforms).

use tauri::{
    plugin::{Builder, TauriPlugin},
    Runtime,
};

pub use error::{Error, Result};

mod error;

#[cfg(target_os = "ios")]
tauri::ios_plugin_binding!(init_plugin_nativeblade_sensors);

/// Handle to the native side. Mobile only — on desktop the JS layer answers
/// `unsupported` without crossing the bridge, so nothing is managed there.
#[cfg(any(target_os = "android", target_os = "ios"))]
pub struct NativeBladeSensors<R: Runtime>(pub tauri::plugin::PluginHandle<R>);

pub fn init<R: Runtime>() -> TauriPlugin<R> {
    Builder::new("nativeblade-sensors")
        .setup(|_app, _api| {
            #[cfg(target_os = "android")]
            {
                use tauri::Manager;
                let handle =
                    _api.register_android_plugin("app.nativeblade.sensors", "SensorsPlugin")?;
                _app.manage(NativeBladeSensors(handle));
            }
            #[cfg(target_os = "ios")]
            {
                use tauri::Manager;
                let handle = _api.register_ios_plugin(init_plugin_nativeblade_sensors)?;
                _app.manage(NativeBladeSensors(handle));
            }
            Ok(())
        })
        .build()
}
