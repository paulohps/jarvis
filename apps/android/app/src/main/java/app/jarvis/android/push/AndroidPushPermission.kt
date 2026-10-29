package app.jarvis.android.push

import android.Manifest
import android.app.NotificationManager
import android.content.Context
import android.content.pm.PackageManager
import androidx.core.content.edit

/**
 * `POST_NOTIFICATIONS` as [PushAuthorization]. Android does not tell "never
 * asked" from "refused", so the app keeps whether it already asked.
 */
class AndroidPushPermission(context: Context) : PushPermission {
    private val appContext = context.applicationContext
    private val preferences = appContext.getSharedPreferences("push", Context.MODE_PRIVATE)

    override fun current(): PushAuthorization {
        val isGranted = appContext.checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED
        val isEnabled = appContext.getSystemService(NotificationManager::class.java).areNotificationsEnabled()

        return when {
            isGranted && isEnabled -> PushAuthorization.Authorized
            isGranted || preferences.getBoolean(KEY_ASKED, false) -> PushAuthorization.Denied
            else -> PushAuthorization.NotDetermined
        }
    }

    /** The system prompt was shown once; afterwards only the system settings can turn push on. */
    fun markAsked() = preferences.edit { putBoolean(KEY_ASKED, true) }

    private companion object {
        const val KEY_ASKED = "permission_asked"
    }
}
