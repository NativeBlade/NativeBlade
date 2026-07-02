//! The app-closed entry point on iOS. The BGTask handler (Swift) calls this
//! C function directly — the staticlib is already linked into the binary, so
//! unlike Android there is nothing to load. Strings are UTF-8 C strings owned
//! by the caller.

use std::ffi::CStr;
use std::os::raw::c_char;
use std::path::Path;

use crate::model::{Collected, TaskDef};

fn read(ptr: *const c_char) -> String {
    if ptr.is_null() {
        return String::new();
    }
    unsafe { CStr::from_ptr(ptr) }.to_string_lossy().into_owned()
}

/// Returns 1 on success, 0 on failure (the Swift side maps it to
/// setTaskCompleted(success:)).
#[no_mangle]
pub extern "C" fn nativeblade_tasks_run(
    def: *const c_char,
    collected: *const c_char,
    data_dir: *const c_char,
) -> i32 {
    let Ok(task) = serde_json::from_str::<TaskDef>(&read(def)) else {
        return 0;
    };
    let collected = serde_json::from_str::<Collected>(&read(collected)).unwrap_or_default();
    crate::courier::run_task(&task, &collected, Path::new(&read(data_dir))).ok as i32
}
