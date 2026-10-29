package app.jarvis.android.push

import android.content.Context
import app.jarvis.android.app.SessionListener
import app.jarvis.android.network.ApiClient
import app.jarvis.android.network.User
import com.google.firebase.FirebaseApp
import com.google.firebase.messaging.FirebaseMessaging
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlin.coroutines.resume

/**
 * Push on this device (US-5.2): after the sign-in the app asks
 * `POST_NOTIFICATIONS` once ([shouldAskPermission], answered by the activity),
 * reads the FCM token and keeps `POST /devices` up to date. Without
 * `google-services.json` in the build the app runs without push.
 */
class PushCoordinator(
    context: Context,
    client: ApiClient,
    deviceName: () -> String,
    private val scope: CoroutineScope,
) : SessionListener {
    private val appContext = context.applicationContext
    private val permission = AndroidPushPermission(appContext)
    private val registration = PushRegistration(client, deviceName, permission)

    val authorization: StateFlow<PushAuthorization> = registration.authorization

    private val mutableShouldAskPermission = MutableStateFlow(false)

    /** The activity shows the system prompt while this is `true`. */
    val shouldAskPermission: StateFlow<Boolean> = mutableShouldAskPermission.asStateFlow()

    val isAvailable: Boolean
        get() = FirebaseApp.getApps(appContext).isNotEmpty()

    init {
        NotificationPresenter(appContext).createChannel()
    }

    override fun onUserChanged(user: User, isNewSession: Boolean) {
        if (isNewSession) {
            scope.launch { start() }
        }
    }

    override suspend fun onForeground(user: User) {
        registration.refreshAuthorization()
    }

    override suspend fun onSessionEnded() {
        mutableShouldAskPermission.value = false
        registration.reset()
    }

    /**
     * The system prompt is on screen. Its answer reaches [didAnswerPermission]
     * even if the activity is recreated meanwhile (the language of `/me`), so
     * it is not shown twice.
     */
    fun didShowPermissionPrompt() {
        permission.markAsked()
        mutableShouldAskPermission.value = false
    }

    /** The answer of the system prompt. */
    suspend fun didAnswerPermission(granted: Boolean) {
        registration.didAnswerPermission(granted)
    }

    suspend fun didReceive(fcmToken: String) {
        registration.didReceive(fcmToken)
    }

    private suspend fun start() {
        registration.refreshAuthorization()
        mutableShouldAskPermission.value = authorization.value == PushAuthorization.NotDetermined

        fcmToken()?.let { registration.didReceive(it) }
    }

    /** The FCM registration token (deprecated since FCM 25.1, see [JarvisMessagingService.onNewToken]). */
    @Suppress("DEPRECATION")
    private suspend fun fcmToken(): String? {
        if (!isAvailable) {
            return null
        }

        return suspendCancellableCoroutine { continuation ->
            FirebaseMessaging.getInstance().token.addOnCompleteListener { task ->
                continuation.resume(if (task.isSuccessful) task.result else null)
            }
        }
    }
}
