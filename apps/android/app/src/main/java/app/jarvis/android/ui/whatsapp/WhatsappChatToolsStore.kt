package app.jarvis.android.ui.whatsapp

import app.jarvis.android.network.ApiClient
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.WhatsappAccount
import app.jarvis.android.network.WhatsappConversationTag
import app.jarvis.android.network.WhatsappPriorityColors
import app.jarvis.android.network.WhatsappPriorityColorsInput
import app.jarvis.android.network.WhatsappTagInput
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject

/**
 * What the WhatsApp chats share, as in the web chat settings: the user's
 * tags (created, renamed, recolored, deleted), the colors of the
 * priorities, and the numbers with the defaults of their chats. Read with
 * [load]; each change answers the server's refusal as an [ApiException].
 */
class WhatsappChatToolsStore(private val client: ApiClient) {
    data class State(
        /** By name. */
        val tags: List<WhatsappConversationTag> = emptyList(),
        val priorityColors: WhatsappPriorityColors = WhatsappPriorityColors(),
        val accounts: List<WhatsappAccount> = emptyList(),
    )

    private val mutableState = MutableStateFlow(State())
    val state: StateFlow<State> = mutableState.asStateFlow()

    /** The tags, the colors and the numbers; what fails keeps what was read before. */
    suspend fun load() {
        loadTags()
        loadPriorityColors()

        try {
            val accounts = client.call { whatsappAccounts() }.data
            mutableState.update { it.copy(accounts = accounts) }
        } catch (_: ApiException) {
            // The next look tries again.
        }
    }

    /** The user's color of each priority; a failure keeps the defaults until the next look. */
    suspend fun loadPriorityColors() {
        try {
            val colors = client.call { whatsappPriorityColors() }
            mutableState.update { it.copy(priorityColors = colors) }
        } catch (_: ApiException) {
            // The defaults of the server show until the next look.
        }
    }

    suspend fun loadTags() {
        try {
            val tags = client.call { whatsappTags() }.data
            mutableState.update { it.copy(tags = tags) }
        } catch (_: ApiException) {
            // The next look tries again.
        }
    }

    /** A new tag ([tag] null) or a change to one; the color by its palette name. */
    suspend fun saveTag(tag: WhatsappConversationTag?, name: String, color: String) {
        val input = WhatsappTagInput(name.trim(), color)

        if (tag == null) {
            client.call { createWhatsappTag(input) }
        } else {
            client.call { updateWhatsappTag(tag.friendlyId, input) }
        }

        loadTags()
    }

    suspend fun deleteTag(tag: WhatsappConversationTag) {
        client.call { deleteWhatsappTag(tag.friendlyId) }
        mutableState.update { current -> current.copy(tags = current.tags.filterNot { it.friendlyId == tag.friendlyId }) }
    }

    /** The color name of each priority, `{1: "red", …}`. */
    suspend fun savePriorityColors(colors: Map<Int, String>) {
        val saved = client.call { saveWhatsappPriorityColors(WhatsappPriorityColorsInput(colors.mapKeys { it.key.toString() })) }
        mutableState.update { it.copy(priorityColors = saved) }
    }

    /** The defaults of the chats of [account]. */
    suspend fun saveAccountDefaults(account: WhatsappAccount, sendsReadReceipts: Boolean, sendsTyping: Boolean, notifiesMessages: Boolean, syncsPhoneReads: Boolean = true) {
        val body = buildJsonObject {
            put("sends_read_receipts", JsonPrimitive(sendsReadReceipts))
            put("sends_typing", JsonPrimitive(sendsTyping))
            put("notifies_messages", JsonPrimitive(notifiesMessages))
            put("syncs_phone_reads", JsonPrimitive(syncsPhoneReads))
        }
        val saved = client.call { updateWhatsappChatSettings(account.friendlyId, body) }.data

        mutableState.update { current -> current.copy(accounts = current.accounts.map { if (it.friendlyId == saved.friendlyId) saved else it }) }
    }

    companion object {
        /** The priorities a chat takes, 1 the highest. */
        val PRIORITIES = 1..5

        /** How long a chat is muted for, as the server takes it. */
        val MUTE_DURATIONS = listOf("8h", "1w", "always")
    }
}

/**
 * A change to a chat (`PATCH /whatsapp/conversations/{chat}`): only the
 * fields set go to the server; a null value is sent as null (no priority, no
 * nickname, a setting following its number).
 */
sealed interface WhatsappChatChange {
    data class Pinned(val pinned: Boolean) : WhatsappChatChange

    data class Priority(val priority: Int?) : WhatsappChatChange

    data class Nickname(val nickname: String?) : WhatsappChatChange

    data class Tags(val tagIds: List<String>) : WhatsappChatChange

    /** Each null to follow the number. */
    data class Settings(val sendsReadReceipts: Boolean?, val sendsTyping: Boolean?, val notifiesMessages: Boolean?) : WhatsappChatChange

    /** Out of the list, under "Archived" (reaches WhatsApp while the number is connected). */
    data class Archived(val archived: Boolean) : WhatsappChatChange

    /** WhatsApp's "Mark as unread", or read again. */
    data class MarkedUnread(val markedUnread: Boolean) : WhatsappChatChange

    /** No pushes for [duration] (`8h`, `1w`, `always`); null unmutes. Jarvis' own. */
    data class Muted(val duration: String?) : WhatsappChatChange

    /** A contact blocked on WhatsApp, or unblocked. */
    data class Blocked(val blocked: Boolean) : WhatsappChatChange

    fun body(): JsonObject = buildJsonObject {
        when (val change = this@WhatsappChatChange) {
            is Pinned -> put("pinned", JsonPrimitive(change.pinned))
            is Priority -> put("priority", change.priority?.let(::JsonPrimitive) ?: JsonNull)
            is Nickname -> put("nickname", change.nickname?.trim()?.takeIf { it.isNotEmpty() }?.let(::JsonPrimitive) ?: JsonNull)
            is Tags -> put("tags", JsonArray(change.tagIds.map(::JsonPrimitive)))
            is Settings -> {
                put("sends_read_receipts", change.sendsReadReceipts?.let(::JsonPrimitive) ?: JsonNull)
                put("sends_typing", change.sendsTyping?.let(::JsonPrimitive) ?: JsonNull)
                put("notifies_messages", change.notifiesMessages?.let(::JsonPrimitive) ?: JsonNull)
            }

            is Archived -> put("archived", JsonPrimitive(change.archived))
            is MarkedUnread -> put("marked_unread", JsonPrimitive(change.markedUnread))
            is Muted -> put("muted", change.duration?.let(::JsonPrimitive) ?: JsonNull)
            is Blocked -> put("blocked", JsonPrimitive(change.blocked))
        }
    }
}
