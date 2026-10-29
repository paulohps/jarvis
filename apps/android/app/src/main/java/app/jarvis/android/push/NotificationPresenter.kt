package app.jarvis.android.push

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import app.jarvis.android.MainActivity
import app.jarvis.android.R

/**
 * The system notifications of the pushes that arrive while the app is open
 * (FCM shows the others itself). Tapping one opens its `deep_link`. A push
 * with a `thread` (a WhatsApp chat) goes to the WhatsApp channel, tagged with
 * it, so a new message of the chat replaces the one before, as FCM does in
 * the background (`android.notification.tag`); none is shown for the chat
 * on screen ([OpenWhatsappChat]).
 */
class NotificationPresenter(context: Context) {
    private val appContext = context.applicationContext
    private val manager = appContext.getSystemService(NotificationManager::class.java)

    /** The channel of the pushes, also FCM's default (`AndroidManifest.xml`). */
    fun createChannel() {
        manager.createNotificationChannel(
            NotificationChannel(CHANNEL_ID, appContext.getString(R.string.push_channel_name), NotificationManager.IMPORTANCE_HIGH),
        )
        manager.createNotificationChannel(
            NotificationChannel(WHATSAPP_CHANNEL_ID, appContext.getString(R.string.push_whatsapp_channel_name), NotificationManager.IMPORTANCE_HIGH),
        )
    }

    fun show(id: String?, title: String?, body: String?, deepLink: String?, thread: String? = null) {
        if (!manager.areNotificationsEnabled() || (title.isNullOrBlank() && body.isNullOrBlank())) {
            return
        }

        if (thread != null && OpenWhatsappChat.chatId?.let(OpenWhatsappChat::thread) == thread) {
            return
        }

        // A thread's notifications share its tag and id 0, as FCM posts them.
        val notificationId = if (thread != null) 0 else (id ?: "$title|$body|${System.currentTimeMillis()}").hashCode()
        val requestCode = (thread ?: id ?: "$title|$body|${System.currentTimeMillis()}").hashCode()
        val notification = Notification.Builder(appContext, if (thread != null) WHATSAPP_CHANNEL_ID else CHANNEL_ID)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(Notification.BigTextStyle().bigText(body))
            .setAutoCancel(true)
            .setContentIntent(
                PendingIntent.getActivity(
                    appContext,
                    requestCode,
                    openIntent(appContext, deepLink),
                    PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
                ),
            )
            .build()

        manager.notify(thread, notificationId, notification)
    }

    /** Takes the notifications of a chat away once it is open. */
    fun dismiss(thread: String) {
        manager.cancel(thread, 0)
    }

    companion object {
        const val CHANNEL_ID = "jarvis_notifications"

        /** The channel of the new WhatsApp messages (`WhatsappMessageReceived::ANDROID_CHANNEL` on the server). */
        const val WHATSAPP_CHANNEL_ID = "whatsapp_messages"

        /** The data key FCM carries a push's chat in (`PushMessage::data()` on the server). */
        const val EXTRA_THREAD = "thread"

        /** The data key FCM carries the deep link in (`PushMessage::data()` on the server). */
        const val EXTRA_DEEP_LINK = "deep_link"

        /** Opens the app on [deepLink] (or where it was, without one). */
        fun openIntent(context: Context, deepLink: String?): Intent =
            Intent(context, MainActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                .apply { deepLink?.let { putExtra(EXTRA_DEEP_LINK, it) } }
    }
}
