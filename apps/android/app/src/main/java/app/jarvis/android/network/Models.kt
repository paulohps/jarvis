package app.jarvis.android.network

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/** `components/schemas/User`: the signed-in user and their modules (`GET /me`). */
@Serializable
data class User(
    @SerialName("friendly_id") val friendlyId: String,
    val name: String,
    val email: String,
    @SerialName("avatar_url") val avatarUrl: String? = null,
    val locale: String = "en",
    val timezone: String = "UTC",
    @SerialName("is_super_admin") val isSuperAdmin: Boolean = false,
    val modules: List<UserModule> = emptyList(),
    /** The workspaces the user is in; empty from a server older than them (and from a `/me` cached before). */
    val workspaces: List<UserWorkspace> = emptyList(),
    /** The private Reverb channel of the user (`private-App.Models.User.{id}`). */
    @SerialName("broadcast_channel") val broadcastChannel: String? = null,
)

/** A module switched on for the user. */
@Serializable
data class UserModule(
    val slug: String,
    val name: String = slug,
    @SerialName("is_available_on_mobile") val isAvailableOnMobile: Boolean = false,
)

/** The `{ "data": … }` envelope of the API Resources. */
@Serializable
data class DataEnvelope<T>(val data: T)

/** `responses/TokenIssued`: the token of this device and the user. */
@Serializable
data class TokenResponse(val token: String, val user: User)

@Serializable
data class LoginRequest(
    val email: String,
    val password: String,
    @SerialName("device_name") val deviceName: String,
)

@Serializable
data class GoogleLoginRequest(
    @SerialName("id_token") val idToken: String,
    @SerialName("device_name") val deviceName: String,
)

@Serializable
data class PreferencesRequest(
    val locale: String? = null,
    val timezone: String? = null,
)

/** `components/schemas/Device`. */
@Serializable
data class Device(
    @SerialName("friendly_id") val friendlyId: String,
    val name: String,
    val platform: String,
    @SerialName("has_push_token") val hasPushToken: Boolean = false,
    @SerialName("is_current") val isCurrent: Boolean = false,
)

/**
 * The body of `POST /devices`. `push_token` is always sent: `null` clears the
 * saved token on the server (a refused notification permission).
 */
@Serializable
data class DeviceRegistration(
    val platform: String = "android",
    val name: String,
    @SerialName("push_token") val pushToken: String?,
)

/** `components/schemas/Notification`: an in-app notification. */
@Serializable
data class NotificationItem(
    val id: String,
    val title: String,
    val body: String? = null,
    val url: String? = null,
    val actions: List<NotificationAction> = emptyList(),
    @SerialName("is_read") val isRead: Boolean = false,
    @SerialName("created_at") val createdAt: String? = null,
) {
    /** The body without its HTML (it comes sanitized, for the web), as plain text. */
    val plainBody: String?
        get() {
            if (body.isNullOrBlank()) {
                return null
            }

            var text = body
                .replace(Regex("<br\\s*/?>", RegexOption.IGNORE_CASE), "\n")
                .replace(Regex("</p>\\s*<p[^>]*>", RegexOption.IGNORE_CASE), "\n\n")
                .replace(Regex("<[^>]+>"), "")

            listOf("&nbsp;" to " ", "&lt;" to "<", "&gt;" to ">", "&quot;" to "\"", "&#039;" to "'", "&#39;" to "'", "&amp;" to "&")
                .forEach { (entity, character) -> text = text.replace(entity, character) }

            return text.trim().ifEmpty { null }
        }
}

@Serializable
data class NotificationAction(val label: String, val url: String)

/** A page of `GET /notifications`. */
@Serializable
data class NotificationPage(
    val data: List<NotificationItem>,
    val meta: Meta,
) {
    @Serializable
    data class Meta(
        @SerialName("current_page") val currentPage: Int = 1,
        @SerialName("last_page") val lastPage: Int = 1,
        @SerialName("unread_count") val unreadCount: Int = 0,
    )

    val hasMorePages: Boolean
        get() = meta.currentPage < meta.lastPage
}

/** `responses/NotificationsMarked`. */
@Serializable
data class NotificationsMarked(
    val marked: Int = 0,
    @SerialName("unread_count") val unreadCount: Int = 0,
)

@Serializable
data class BroadcastAuthRequest(
    @SerialName("socket_id") val socketId: String,
    @SerialName("channel_name") val channelName: String,
)

/** The answer of `POST /broadcasting/auth`. */
@Serializable
data class BroadcastAuthorization(
    val auth: String,
    @SerialName("channel_data") val channelData: String? = null,
)

/** The `notification.created` event of the user's private channel. */
@Serializable
data class NotificationCreatedEvent(
    val id: String,
    val title: String = "",
    @SerialName("unread_count") val unreadCount: Int = 0,
) {
    companion object {
        const val NAME = "notification.created"
    }
}

/** The error body of the API: `message` and, on a 422, `errors`. */
@Serializable
internal data class ErrorBody(
    val message: String? = null,
    val errors: Map<String, List<String>>? = null,
)
