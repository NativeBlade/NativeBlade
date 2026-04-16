# NativeBlade push plugin proguard rules.

# Keep the FirebaseMessagingService — Android's FCM integration looks it up
# reflectively by name based on the manifest declaration.
-keep class app.nativeblade.push.NativeBladeFirebaseService { *; }
