package app.jarvis.android.navigation

import java.net.URI
import java.net.URISyntaxException
import java.net.URLDecoder

/** Where a deep link takes the app. */
sealed interface AppRoute {
    /** The tab the route lives in, `null` for the web. */
    val tab: AppTab?

    /** A tab at its root. */
    data class Tab(override val tab: AppTab) : AppRoute

    /** A reminder, by the friendly id of the reminder (`rem_…`) or of its occurrence (`remo_…`). */
    data class Reminder(val id: String) : AppRoute {
        override val tab: AppTab
            get() = AppTab.Reminders
    }

    /** A shopping list, by its friendly id (`shl_…`). */
    data class ShoppingList(val id: String) : AppRoute {
        override val tab: AppTab
            get() = AppTab.Shopping
    }

    /** A WhatsApp chat, by the friendly id of its contact or group (`wtgt_…`): the push of a new message. */
    data class WhatsappChat(val id: String) : AppRoute {
        override val tab: AppTab
            get() = AppTab.WhatsApp
    }

    /** A page that exists only on the web (an invoice, the admin area): opened in the browser. */
    data class Web(val url: String) : AppRoute {
        override val tab: AppTab?
            get() = null
    }
}

/**
 * Resolves a deep link (US-5.2): the `deep_link` of a push or a link of a
 * notification. It reads the app scheme (`jarvis://reminders/{id}`) and the
 * same paths on the server's web URL (`https://jarvis.example/reminders`),
 * which is what the notification actions carry.
 *
 * @param webBaseUrl the server; its links are read as app links.
 */
class DeepLinkRouter(webBaseUrl: String?) {
    private val webHost: String? = webBaseUrl?.let { parse(it)?.host?.lowercase() }

    fun route(link: String?): AppRoute? {
        val text = link?.trim().orEmpty()

        // `jarvis://` alone: the notifications.
        if (text.lowercase().trimEnd('/') == "$SCHEME:") {
            return AppRoute.Tab(AppTab.Notifications)
        }

        val uri = text.takeIf { it.isNotEmpty() }?.let(::parse) ?: return null
        val scheme = uri.scheme?.lowercase()

        val segments = when {
            // The authority, not the host: `jarvis://reminder-occurrences/…` is a path more than a host name.
            scheme == SCHEME -> listOfNotNull(uri.rawAuthority?.let { URLDecoder.decode(it, Charsets.UTF_8) }) + pathSegments(uri)
            (scheme == "https" || scheme == "http") && webHost != null && uri.host?.lowercase() == webHost -> pathSegments(uri)
            else -> return null
        }

        return route(segments, query(uri), text, isWeb = scheme != SCHEME)
    }

    private fun route(segments: List<String>, query: Map<String, String>, original: String, isWeb: Boolean): AppRoute? {
        val first = segments.firstOrNull()?.lowercase() ?: return if (isWeb) null else AppRoute.Tab(AppTab.Notifications)
        val rest = segments.drop(1)

        return when (first) {
            "reminders", "reminder-occurrences" -> rest.firstOrNull()?.let(AppRoute::Reminder) ?: AppRoute.Tab(AppTab.Reminders)
            "shopping" -> {
                // `shopping/lists/{id}` (the web's path) or `shopping/{id}`.
                val id = if (rest.firstOrNull()?.lowercase() == "lists") rest.getOrNull(1) else rest.firstOrNull()

                id?.let(AppRoute::ShoppingList) ?: AppRoute.Tab(AppTab.Shopping)
            }

            // `whatsapp/chat?chat={id}` (the web's chat screen, what the push of a new message carries).
            "whatsapp" -> if (rest.firstOrNull()?.lowercase() == "chat") {
                query["chat"]?.takeIf { it.isNotBlank() }?.let(AppRoute::WhatsappChat) ?: AppRoute.Tab(AppTab.WhatsApp)
            } else {
                AppRoute.Tab(AppTab.WhatsApp)
            }

            "notifications" -> AppRoute.Tab(AppTab.Notifications)
            "settings", "profile" -> AppRoute.Tab(AppTab.Settings)
            "invoices", "admin" -> if (isWeb) AppRoute.Web(original) else null
            else -> null
        }
    }

    private fun pathSegments(uri: URI): List<String> =
        (uri.rawPath ?: "").split('/').filter { it.isNotEmpty() }.map { URLDecoder.decode(it, Charsets.UTF_8) }

    private fun query(uri: URI): Map<String, String> =
        (uri.rawQuery ?: "").split('&').filter { it.isNotEmpty() }.associate { pair ->
            val (key, value) = pair.split('=', limit = 2).let { it[0] to it.getOrElse(1) { "" } }

            URLDecoder.decode(key, Charsets.UTF_8) to URLDecoder.decode(value, Charsets.UTF_8)
        }

    private fun parse(text: String): URI? = try {
        URI(text)
    } catch (_: URISyntaxException) {
        null
    }

    companion object {
        const val SCHEME = "jarvis"
    }
}
