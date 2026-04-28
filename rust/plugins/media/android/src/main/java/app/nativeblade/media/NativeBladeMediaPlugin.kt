package app.nativeblade.media

import android.Manifest
import android.app.Activity
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.BitmapFactory
import android.net.Uri
import android.os.Build
import android.provider.MediaStore
import android.util.Base64
import android.webkit.MimeTypeMap
import android.webkit.WebView
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
import app.tauri.annotation.ActivityCallback
import app.tauri.annotation.Command
import app.tauri.annotation.Permission
import app.tauri.annotation.TauriPlugin
import app.tauri.plugin.Invoke
import app.tauri.plugin.JSArray
import app.tauri.plugin.JSObject
import app.tauri.plugin.Plugin
import java.io.ByteArrayOutputStream
import java.io.File
import java.io.FileOutputStream

@TauriPlugin(
    permissions = [
        Permission(strings = [Manifest.permission.CAMERA], alias = "camera")
    ]
)
class NativeBladeMediaPlugin(private val activity: Activity) : Plugin(activity) {

    companion object {
        private const val DEFAULT_MAX = 1200
        private const val DEFAULT_QUALITY = 0.7f
        private const val FP_AUTHORITY_SUFFIX = ".nbmedia.fileprovider"
    }

    private var pendingCameraFile: File? = null
    private var pendingOutputMode: String = "url"
    private var pendingMultiple: Boolean = false
    private var pendingMaxW: Int = DEFAULT_MAX
    private var pendingMaxH: Int = DEFAULT_MAX
    private var pendingQuality: Float = DEFAULT_QUALITY
    private var pendingId: String? = null
    private var pendingIsVideo: Boolean = false

    override fun load(webView: WebView) {
        super.load(webView)
    }

    @Command
    fun pickFromCamera(invoke: Invoke) {
        captureOpts(invoke)
        if (ContextCompat.checkSelfPermission(activity, Manifest.permission.CAMERA)
            != PackageManager.PERMISSION_GRANTED) {
            invoke.reject("camera permission denied")
            return
        }
        val cacheDir = File(activity.cacheDir, "nb_media").apply { mkdirs() }
        val file = File(cacheDir, "capture_${System.currentTimeMillis()}.jpg")
        pendingCameraFile = file

        val uri = FileProvider.getUriForFile(
            activity, "${activity.packageName}$FP_AUTHORITY_SUFFIX", file
        )
        val intent = Intent(MediaStore.ACTION_IMAGE_CAPTURE).apply {
            putExtra(MediaStore.EXTRA_OUTPUT, uri)
            addFlags(Intent.FLAG_GRANT_WRITE_URI_PERMISSION)
        }
        startActivityForResult(invoke, intent, "cameraCallback")
    }

    @Command
    fun pickFromGallery(invoke: Invoke) {
        captureOpts(invoke)
        pendingIsVideo = false
        val intent = pickerIntent(video = false, multiple = pendingMultiple)
        startActivityForResult(invoke, intent, "pickerCallback")
    }

    @Command
    fun pickVideo(invoke: Invoke) {
        captureOpts(invoke)
        pendingIsVideo = true
        val intent = pickerIntent(video = true, multiple = pendingMultiple)
        startActivityForResult(invoke, intent, "pickerCallback")
    }

    override fun checkPermissions(invoke: Invoke) {
        invoke.resolve(permissionStatus())
    }

    override fun requestPermissions(invoke: Invoke) {
        // The gallery picker is permission-free since API 33; only camera
        // capture needs CAMERA. Use Tauri's @Permission alias machinery.
        invoke.resolve(permissionStatus())
    }

    @Command
    fun readAsset(invoke: Invoke) {
        val args = invoke.getArgs()
        val urlOrPath = args.getString("url", "") ?: ""
        val path = urlOrPath
            .removePrefix("file://")
            .removePrefix("asset://localhost/")
        val file = File(path)
        if (!file.exists()) {
            invoke.reject("asset not found: $path")
            return
        }
        val bytes = file.readBytes()
        val b64 = Base64.encodeToString(bytes, Base64.NO_WRAP)
        val mime = mimeFromPath(path) ?: "application/octet-stream"
        val res = JSObject().apply {
            put("dataUrl", "data:$mime;base64,$b64")
            put("mime", mime)
            put("size", bytes.size.toLong())
        }
        invoke.resolve(res)
    }

    @ActivityCallback
    fun cameraCallback(invoke: Invoke, result: androidx.activity.result.ActivityResult) {
        val file = pendingCameraFile
        pendingCameraFile = null
        if (result.resultCode != Activity.RESULT_OK || file == null || !file.exists()) {
            invoke.reject("cancelled")
            return
        }
        val item = processImage(Uri.fromFile(file), null)
        if (item == null) {
            invoke.reject("failed to process capture")
            return
        }
        invoke.resolve(envelope(listOf(item)))
    }

    @ActivityCallback
    fun pickerCallback(invoke: Invoke, result: androidx.activity.result.ActivityResult) {
        if (result.resultCode != Activity.RESULT_OK) {
            invoke.reject("cancelled")
            return
        }
        val data = result.data
        val uris = mutableListOf<Uri>()
        val clip = data?.clipData
        if (clip != null) {
            for (i in 0 until clip.itemCount) clip.getItemAt(i)?.uri?.let { uris.add(it) }
        } else {
            data?.data?.let { uris.add(it) }
        }
        if (uris.isEmpty()) {
            invoke.reject("no items selected")
            return
        }
        val items = mutableListOf<JSObject>()
        for (uri in uris) {
            if (pendingIsVideo) {
                copyVideo(uri)?.let { items.add(it) }
            } else {
                processImage(uri, displayName(uri))?.let { items.add(it) }
            }
        }
        if (items.isEmpty()) {
            invoke.reject("failed to process picked items")
            return
        }
        invoke.resolve(envelope(items))
    }

    private fun captureOpts(invoke: Invoke) {
        val args = invoke.getArgs()
        pendingOutputMode = args.getString("output", "url") ?: "url"
        pendingMultiple = args.getBoolean("multiple", false)
        pendingMaxW = args.getInteger("maxWidth", DEFAULT_MAX)
        pendingMaxH = args.getInteger("maxHeight", DEFAULT_MAX)
        val qRaw = if (args.has("quality") && !args.isNull("quality")) {
            args.optDouble("quality", DEFAULT_QUALITY.toDouble()).toFloat()
        } else {
            DEFAULT_QUALITY
        }
        pendingQuality = qRaw.coerceIn(0.1f, 1.0f)
        pendingId = args.getString("id", null)
    }

    private fun pickerIntent(video: Boolean, multiple: Boolean): Intent {
        // Modern photo picker on API 33+, GET_CONTENT fallback otherwise.
        val mime = if (video) "video/*" else "image/*"
        return if (Build.VERSION.SDK_INT >= 33) {
            Intent(MediaStore.ACTION_PICK_IMAGES).apply {
                type = mime
                if (multiple) putExtra(MediaStore.EXTRA_PICK_IMAGES_MAX, 10)
            }
        } else {
            Intent(Intent.ACTION_GET_CONTENT).apply {
                type = mime
                addCategory(Intent.CATEGORY_OPENABLE)
                if (multiple) putExtra(Intent.EXTRA_ALLOW_MULTIPLE, true)
            }
        }
    }

    private fun permissionStatus(): JSObject {
        val cam = if (ContextCompat.checkSelfPermission(activity, Manifest.permission.CAMERA)
            == PackageManager.PERMISSION_GRANTED) "granted" else "prompt"
        return JSObject().apply {
            put("camera", cam)
            put("photos", "granted")
        }
    }

    private fun processImage(source: Uri, name: String?): JSObject? {
        val maxW = pendingMaxW
        val maxH = pendingMaxH
        val quality = (pendingQuality * 100).toInt().coerceIn(10, 100)

        // Two-pass decode: bounds first to compute inSampleSize, then real decode.
        val boundsOpts = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        activity.contentResolver.openInputStream(source)?.use {
            BitmapFactory.decodeStream(it, null, boundsOpts)
        } ?: return null

        val sample = calcInSampleSize(boundsOpts.outWidth, boundsOpts.outHeight, maxW, maxH)
        val decodeOpts = BitmapFactory.Options().apply { inSampleSize = sample }
        var bitmap = activity.contentResolver.openInputStream(source)?.use {
            BitmapFactory.decodeStream(it, null, decodeOpts)
        } ?: return null

        // Final precise scale to fit maxW/maxH while keeping aspect.
        val ratio = minOf(maxW.toFloat() / bitmap.width, maxH.toFloat() / bitmap.height)
        if (ratio < 1.0f) {
            val newW = (bitmap.width * ratio).toInt()
            val newH = (bitmap.height * ratio).toInt()
            val scaled = android.graphics.Bitmap.createScaledBitmap(bitmap, newW, newH, true)
            if (scaled !== bitmap) bitmap.recycle()
            bitmap = scaled
        }

        val outDir = File(activity.cacheDir, "nb_media").apply { mkdirs() }
        val outFile = File(outDir, "img_${System.currentTimeMillis()}_${(0..9999).random()}.jpg")
        FileOutputStream(outFile).use { fos ->
            bitmap.compress(android.graphics.Bitmap.CompressFormat.JPEG, quality, fos)
        }
        val w = bitmap.width
        val h = bitmap.height
        bitmap.recycle()

        return buildItem(outFile, "image/jpeg", w, h, name)
    }

    private fun copyVideo(source: Uri): JSObject? {
        val outDir = File(activity.cacheDir, "nb_media").apply { mkdirs() }
        val outFile = File(outDir, "vid_${System.currentTimeMillis()}.mp4")
        activity.contentResolver.openInputStream(source)?.use { input ->
            FileOutputStream(outFile).use { output -> input.copyTo(output) }
        } ?: return null
        return buildItem(outFile, mimeFromUri(source) ?: "video/mp4", 0, 0, displayName(source))
    }

    private fun buildItem(file: File, mime: String, w: Int, h: Int, name: String?): JSObject {
        val item = JSObject()
        val emitDataUrl = pendingOutputMode == "dataurl" || pendingOutputMode == "both"
        val emitUrl = pendingOutputMode == "url" || pendingOutputMode == "both"

        if (emitUrl) {
            item.put("path", file.absolutePath)
            // JS converts to asset:// via convertFileSrc()
            item.put("url", "file://${file.absolutePath}")
        } else {
            item.put("path", "")
            item.put("url", "")
        }
        if (emitDataUrl) {
            val bytes = file.readBytes()
            val b64 = Base64.encodeToString(bytes, Base64.NO_WRAP)
            item.put("dataUrl", "data:$mime;base64,$b64")
        } else {
            item.put("dataUrl", "")
        }
        item.put("mime", mime)
        item.put("size", file.length())
        item.put("width", w)
        item.put("height", h)
        item.put("name", name ?: file.name)
        return item
    }

    private fun envelope(items: List<JSObject>): JSObject {
        val arr = JSArray()
        for (i in items) arr.put(i)
        return JSObject().apply {
            put("items", arr)
            put("id", pendingId)
        }
    }

    private fun calcInSampleSize(srcW: Int, srcH: Int, reqW: Int, reqH: Int): Int {
        var sample = 1
        if (srcH > reqH || srcW > reqW) {
            val halfH = srcH / 2
            val halfW = srcW / 2
            while ((halfH / sample) >= reqH && (halfW / sample) >= reqW) sample *= 2
        }
        return sample
    }

    private fun mimeFromUri(uri: Uri): String? {
        return activity.contentResolver.getType(uri) ?: mimeFromPath(uri.path ?: "")
    }

    private fun mimeFromPath(path: String): String? {
        val ext = MimeTypeMap.getFileExtensionFromUrl(path)?.lowercase() ?: return null
        return MimeTypeMap.getSingleton().getMimeTypeFromExtension(ext)
    }

    private fun displayName(uri: Uri): String? {
        return try {
            activity.contentResolver.query(uri, arrayOf(android.provider.OpenableColumns.DISPLAY_NAME),
                null, null, null)?.use { c ->
                if (c.moveToFirst()) c.getString(0) else null
            }
        } catch (_: Throwable) { null }
    }
}
