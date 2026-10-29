package app.jarvis.android.push

import app.jarvis.android.network.ApiClient
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.DeviceRegistration
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock

/** What the user answered to the notification permission (`POST_NOTIFICATIONS`). */
enum class PushAuthorization {
    NotDetermined,
    Denied,
    Authorized,
}

/** The system's notification permission, as the app last saw it. */
fun interface PushPermission {
    fun current(): PushAuthorization
}

/**
 * Registers this device for push (US-5.2): the app asks the permission once,
 * after the sign-in, and the FCM token goes to `POST /devices`, bound to the
 * device's Sanctum token.
 *
 * A refused permission never breaks the app: the device is still registered,
 * with no push token, so the server stops sending to an old one.
 */
class PushRegistration(
    private val client: ApiClient,
    private val deviceName: () -> String,
    private val permission: PushPermission,
) {
    private val mutex = Mutex()
    private val mutableAuthorization = MutableStateFlow(PushAuthorization.NotDetermined)
    val authorization: StateFlow<PushAuthorization> = mutableAuthorization.asStateFlow()

    private var latestToken: String? = null

    /** The push token the server has, wrapped so `null` (cleared) differs from nothing sent yet. */
    private var sentToken: Sent? = null

    private data class Sent(val token: String?)

    /** After the sign-in and back in the foreground (the permission may have changed in the system settings). */
    suspend fun refreshAuthorization() {
        mutableAuthorization.value = permission.current()
        sync()
    }

    /** The answer of the system prompt. */
    suspend fun didAnswerPermission(granted: Boolean) {
        mutableAuthorization.value = if (granted) PushAuthorization.Authorized else PushAuthorization.Denied
        sync()
    }

    /** A new FCM token (at launch and whenever it rotates). */
    suspend fun didReceive(fcmToken: String?) {
        latestToken = fcmToken
        sync()
    }

    /** After the sign-out: the next session registers its own device. The FCM token belongs to the install, so it is kept. */
    suspend fun reset() = mutex.withLock {
        sentToken = null
        mutableAuthorization.value = PushAuthorization.NotDetermined
    }

    /** Sends what the server should know, once per value. */
    private suspend fun sync() = mutex.withLock {
        if (client.tokenStore.token() == null) {
            return@withLock
        }

        when (authorization.value) {
            PushAuthorization.Authorized -> latestToken?.let { send(it) }
            PushAuthorization.Denied -> send(null)
            PushAuthorization.NotDetermined -> Unit
        }
    }

    private suspend fun send(pushToken: String?) {
        if (sentToken == Sent(pushToken)) {
            return
        }

        try {
            client.call { registerDevice(DeviceRegistration(name = deviceName(), pushToken = pushToken)) }
            sentToken = Sent(pushToken)
        } catch (_: ApiException) {
            // Tried again on the next token or foreground.
        }
    }
}
