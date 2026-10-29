package app.jarvis.android.realtime

import app.jarvis.android.app.SessionListener
import app.jarvis.android.network.ApiClient
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.User
import app.jarvis.android.network.WhatsappChatPresenceEvent
import app.jarvis.android.network.WhatsappChatUpdatedEvent
import app.jarvis.android.network.WhatsappConversation
import app.jarvis.android.push.OpenWhatsappChat
import app.jarvis.android.ui.whatsapp.WhatsappPresenceStore
import java.time.OffsetDateTime
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.filter
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock

/**
 * The WhatsApp chats in realtime, from the Reverb events of the user's
 * private channel ([RealtimeCoordinator]):
 *
 * - [updates]: the chats that got a message or new ticks, for the open
 *   list and chat to read again at once (they still poll, slower, while
 *   [isConnected]).
 * - [presence]: who is typing, recording or online.
 * - [banner]: a message the contact just sent to a chat that is not on
 *   screen, shown in the app as WhatsApp does (found by reading the first
 *   page of chats again, [ARRIVAL_DEBOUNCE_MS] after the last update).
 *
 * @param connection the state of the Reverb connection.
 */
class WhatsappLive(
    private val client: ApiClient,
    private val scope: CoroutineScope,
    private val connection: () -> StateFlow<Boolean>,
    private val now: () -> Long = System::currentTimeMillis,
) : SessionListener {
    private val mutableUpdates = MutableSharedFlow<String>(extraBufferCapacity = 32)

    /** The ids of the chats that changed. */
    val updates: SharedFlow<String> = mutableUpdates.asSharedFlow()

    val presence = WhatsappPresenceStore(now)

    private val mutableBanner = MutableStateFlow<WhatsappConversation?>(null)
    val banner: StateFlow<WhatsappConversation?> = mutableBanner.asStateFlow()

    val isConnected: StateFlow<Boolean>
        get() = connection()

    private val mutex = Mutex()

    /** The latest message of each chat, as last read; null until the first read. */
    private var known: Map<String, String?>? = null
    private var arrivalJob: Job? = null

    private var connectionJob: Job? = null

    override fun onUserChanged(user: User, isNewSession: Boolean) = watchConnection()

    override suspend fun onForeground(user: User) = watchConnection()

    /** Back online: whatever came meanwhile is not news, only what comes next. */
    private fun watchConnection() {
        if (connectionJob != null) {
            return
        }

        connectionJob = scope.launch {
            connection().filter { it }.collect {
                mutex.withLock { known = null }
                checkArrivals()
            }
        }
    }

    /** An event of the user's channel; false when it is not a WhatsApp one. */
    fun receive(name: String, data: String): Boolean {
        when (name) {
            WhatsappChatUpdatedEvent.NAME -> {
                val event = decode<WhatsappChatUpdatedEvent>(data) ?: return true

                mutableUpdates.tryEmit(event.chatId)
                scheduleArrivalCheck()
            }

            WhatsappChatPresenceEvent.NAME -> decode<WhatsappChatPresenceEvent>(data)?.let(presence::receive)
            else -> return false
        }

        return true
    }

    fun dismissBanner(chat: WhatsappConversation) {
        if (mutableBanner.value?.friendlyId == chat.friendlyId) {
            mutableBanner.value = null
        }
    }

    /** Signed out: nothing of the last user stays. */
    override suspend fun onSessionEnded() {
        connectionJob?.cancel()
        connectionJob = null
        arrivalJob?.cancel()
        known = null
        mutableBanner.value = null
        presence.clear()
    }

    private fun scheduleArrivalCheck() {
        arrivalJob?.cancel()
        arrivalJob = scope.launch {
            delay(ARRIVAL_DEBOUNCE_MS)
            checkArrivals()
        }
    }

    private suspend fun checkArrivals() = mutex.withLock {
        val chats = try {
            client.call { whatsappConversations(page = 1, perPage = PER_PAGE) }.data
        } catch (_: ApiException) {
            return@withLock
        }

        newlyReceived(known, chats, OpenWhatsappChat.chatId, now()).firstOrNull()?.let { mutableBanner.value = it }
        known = chats.associate { it.friendlyId to it.lastMessage?.friendlyId }
    }

    private inline fun <reified T> decode(data: String): T? = runCatching { ApiClient.json.decodeFromString<T>(data) }.getOrNull()

    companion object {
        const val ARRIVAL_DEBOUNCE_MS = 300L

        const val PER_PAGE = 30

        /** A message older than this is not news (a history synced late). */
        const val RECENT_MS = 5 * 60_000L

        /**
         * The chats whose latest message is new since [known], sent by the
         * contact, recent and in a chat other than [openChatId], the latest
         * first; none on the first read ([known] null).
         */
        fun newlyReceived(known: Map<String, String?>?, chats: List<WhatsappConversation>, openChatId: String?, at: Long): List<WhatsappConversation> {
            known ?: return emptyList()

            return chats
                .filter { chat ->
                    val message = chat.lastMessage ?: return@filter false

                    chat.friendlyId != openChatId
                        && !message.fromMe
                        && !message.revoked
                        && known[chat.friendlyId] != message.friendlyId
                        && isRecent(message.sentAt, at)
                }
                .sortedByDescending { it.lastMessage?.sentAt?.let(::epochMillis) ?: 0L }
        }

        private fun isRecent(iso: String, at: Long): Boolean = epochMillis(iso)?.let { at - it < RECENT_MS } ?: false

        private fun epochMillis(iso: String): Long? = runCatching { OffsetDateTime.parse(iso).toInstant().toEpochMilli() }.getOrNull()
    }
}
