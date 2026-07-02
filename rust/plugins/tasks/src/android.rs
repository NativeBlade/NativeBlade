//! The app-closed entry point on Android. WorkManager wakes the process with
//! NO Activity and NO Tauri runtime; the TaskWorker loads the app's shared
//! library and calls straight into this function. Everything it needs comes
//! as arguments — the task definition, the data collected by the worker
//! (location, bearer) and the data dir the parking store lives under.

use jni::objects::{JClass, JObject, JString};
use jni::sys::jboolean;
use jni::JNIEnv;
use std::path::Path;

use crate::model::{Collected, TaskDef};

fn read_string(env: &mut JNIEnv, s: &JString) -> String {
    env.get_string(s).map(|v| v.into()).unwrap_or_default()
}

/// `TaskWorker.runTaskNative(def, collected, dataDir)` — instance method, so
/// the second parameter is the receiver object.
#[no_mangle]
pub extern "system" fn Java_app_nativeblade_tasks_TaskWorker_runTaskNative(
    mut env: JNIEnv,
    _this: JObject,
    def: JString,
    collected: JString,
    data_dir: JString,
) -> jboolean {
    let def_s = read_string(&mut env, &def);
    let collected_s = read_string(&mut env, &collected);
    let dir_s = read_string(&mut env, &data_dir);

    let Ok(task) = serde_json::from_str::<TaskDef>(&def_s) else {
        return 0;
    };
    let collected = serde_json::from_str::<Collected>(&collected_s).unwrap_or_default();

    let outcome = crate::courier::run_task(&task, &collected, Path::new(&dir_s));
    outcome.ok as jboolean
}

// Keep the static-method variant callable too, in case the worker is ever
// refactored to a companion external.
#[no_mangle]
pub extern "system" fn Java_app_nativeblade_tasks_TaskWorker_00024Companion_runTaskNative(
    env: JNIEnv,
    _class: JClass,
    def: JString,
    collected: JString,
    data_dir: JString,
) -> jboolean {
    let mut env = env;
    let def_s = read_string(&mut env, &def);
    let collected_s = read_string(&mut env, &collected);
    let dir_s = read_string(&mut env, &data_dir);

    let Ok(task) = serde_json::from_str::<TaskDef>(&def_s) else {
        return 0;
    };
    let collected = serde_json::from_str::<Collected>(&collected_s).unwrap_or_default();
    crate::courier::run_task(&task, &collected, Path::new(&dir_s)).ok as jboolean
}
