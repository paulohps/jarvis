package app.jarvis.android.push

import app.jarvis.android.JarvisApplication
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import kotlinx.coroutines.launch

/**
 * The FCM entry point (US-5.2). In the background FCM shows the push itself
 * and its tap brings the `deep_link` in the launch intent's extras; in the
 * foreground the push comes here and is shown by [NotificationPresenter].
 */
class JarvisMessagingService : FirebaseMessagingService() {
    private val container
        get() = (application as JarvisApplication).container

    /**
     * Deprecated since FCM 25.1 in favour of the installation id (FID), but the
     * registration token is what the server sends to (FCM HTTP v1, `FcmClient`).
     */
    @Suppress("OVERRIDE_DEPRECATION")
    override fun onNewToken(token: String) {
        container.scope.launch { container.push.didReceive(token) }
    }

    override fun onMessageReceived(message: RemoteMessage) {
        val notification = message.notification
        val thread = message.data[NotificationPresenter.EXTRA_THREAD]

        // In the app with Reverb on, the in-app banner tells it (WhatsappLive): no second notice.
        if (thread != null && container.realtime.isConnected.value) {
            return
        }

        NotificationPresenter(this).show(
            id = message.messageId,
            title = notification?.title ?: message.data["title"],
            body = notification?.body ?: message.data["body"],
            deepLink = message.data[NotificationPresenter.EXTRA_DEEP_LINK],
            thread = thread,
        )

        container.scope.launch { container.notifications.reload() }
    }
}
