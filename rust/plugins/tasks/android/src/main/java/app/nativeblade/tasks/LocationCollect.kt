package app.nativeblade.tasks

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import androidx.core.content.ContextCompat
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import com.google.android.gms.tasks.CancellationTokenSource
import com.google.android.gms.tasks.Tasks
import org.json.JSONObject
import java.util.concurrent.TimeUnit

/**
 * One balanced-power location fix, time-boxed to 15s. Blocking — call from a
 * worker or a dedicated thread, never the main thread. Null (the task
 * proceeds without a location) when the permission is missing or no fix
 * arrives in time: a missing field beats a blown background window.
 */
object LocationCollect {

    fun oneFix(context: Context): JSONObject? {
        val fine = ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION)
        val coarse = ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION)
        if (fine != PackageManager.PERMISSION_GRANTED && coarse != PackageManager.PERMISSION_GRANTED) {
            return null
        }

        return try {
            val client = LocationServices.getFusedLocationProviderClient(context)
            val task = client.getCurrentLocation(
                Priority.PRIORITY_BALANCED_POWER_ACCURACY,
                CancellationTokenSource().token
            )
            val location = Tasks.await(task, 15, TimeUnit.SECONDS) ?: return null
            JSONObject()
                .put("lat", location.latitude)
                .put("lng", location.longitude)
                .put("accuracy", location.accuracy.toDouble())
                .put("timestamp", location.time / 1000)
        } catch (t: Throwable) {
            null
        }
    }
}
