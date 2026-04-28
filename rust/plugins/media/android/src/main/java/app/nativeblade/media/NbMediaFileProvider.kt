package app.nativeblade.media

import androidx.core.content.FileProvider

/**
 * Thin FileProvider subclass so our manifest entry doesn't collide
 * with the one Tauri ships in the app manifest. The authority and
 * path config stay the same — only the android:name differs, which
 * is what Android's manifest merger keys on.
 */
class NbMediaFileProvider : FileProvider()
