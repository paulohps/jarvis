package app.jarvis.android.ui.whatsapp

import app.jarvis.android.network.WhatsappChatPresenceEvent
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update

/** What a chat's header says about who is in it now. */
sealed interface WhatsappPresenceStatus {
    /** Typing; [who] is the group member. */
    data class Typing(val who: String?) : WhatsappPresenceStatus

    /** Recording an audio; [who] is the group member. */
    data class Recording(val who: String?) : WhatsappPresenceStatus

    data object Online : WhatsappPresenceStatus

    /** Last online at [iso]. */
    data class LastSeen(val iso: String) : WhatsappPresenceStatus
}

/**
 * Who is typing, recording or online in each chat, as the
 * `whatsapp.chat.presence` broadcasts tell it; nothing is kept on disk.
 * Typing and recording fade after [ACTIVITY_MS] without news (WhatsApp keeps
 * repeating them while they go on), online after [ONLINE_MS]; the last seen
 * time stays.
 *
 * @param now the clock, in milliseconds.
 */
class WhatsappPresenceStore(private val now: () -> Long = System::currentTimeMillis) {
    data class Entry(val presence: String, val who: String?, val at: Long, val lastSeen: String?)

    private val mutableEntries = MutableStateFlow<Map<String, Entry>>(emptyMap())
    val entries: StateFlow<Map<String, Entry>> = mutableEntries.asStateFlow()

    fun receive(event: WhatsappChatPresenceEvent) = mutableEntries.update { entries ->
        val lastSeen = event.lastSeen ?: entries[event.chatId]?.lastSeen

        entries + (event.chatId to Entry(event.presence, event.who, now(), lastSeen))
    }

    fun clear() = mutableEntries.update { emptyMap() }

    /** What to show for the chat at [at], or null for nothing. */
    fun status(chatId: String, at: Long = now()): WhatsappPresenceStatus? = status(entries.value[chatId], at)

    companion object {
        const val ACTIVITY_MS = 10_000L

        const val ONLINE_MS = 5 * 60_000L

        fun status(entry: Entry?, at: Long): WhatsappPresenceStatus? {
            entry ?: return null

            val age = at - entry.at

            return when {
                entry.presence == "composing" && age < ACTIVITY_MS -> WhatsappPresenceStatus.Typing(entry.who)
                entry.presence == "recording" && age < ACTIVITY_MS -> WhatsappPresenceStatus.Recording(entry.who)
                // In a group only who types counts, as WhatsApp shows it.
                entry.who == null && entry.presence in ONLINE_PRESENCES && age < ONLINE_MS -> WhatsappPresenceStatus.Online
                entry.who == null && entry.lastSeen != null -> WhatsappPresenceStatus.LastSeen(entry.lastSeen)
                else -> null
            }
        }

        private val ONLINE_PRESENCES = setOf("available", "composing", "recording", "paused")
    }
}
