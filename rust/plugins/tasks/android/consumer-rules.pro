# TaskWorker is instantiated reflectively by WorkManager and its native
# method must keep its exact name for the JNI symbol to resolve.
-keep class app.nativeblade.tasks.TaskWorker { *; }
