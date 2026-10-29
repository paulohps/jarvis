package app.jarvis.android.ui.whatsapp

import app.jarvis.android.network.ApiClient
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.WhatsappChatReminderInput
import app.jarvis.android.network.WhatsappConversation
import app.jarvis.android.network.WhatsappConversationDetail
import app.jarvis.android.network.WhatsappConversationMessage
import app.jarvis.android.network.WhatsappContactsInput
import app.jarvis.android.network.WhatsappConversationText
import app.jarvis.android.network.WhatsappDelivery
import app.jarvis.android.network.WhatsappDraftRequest
import app.jarvis.android.network.WhatsappForwardInput
import app.jarvis.android.network.WhatsappGroupMember
import app.jarvis.android.network.WhatsappLocationInput
import app.jarvis.android.network.WhatsappMessageEdit
import app.jarvis.android.network.WhatsappMessageInfo
import app.jarvis.android.network.WhatsappPollInput
import app.jarvis.android.network.WhatsappReaction
import app.jarvis.android.network.WhatsappReactionInput
import app.jarvis.android.network.WhatsappStarInput
import app.jarvis.android.network.WhatsappScheduleText
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.toRequestBody

/**
 * One chat as its screen shows it: the messages newest first, read again
 * every few seconds while the screen is shown ([refresh]; the older pages
 * read with [loadOlder] stay under the first one), what Jarvis is still
 * sending (`outgoing`), sending a text or a file, and telling the server the
 * chat was seen ([markRead], each time a newer message is on screen; the
 * read receipts reaching WhatsApp follow the chat's settings) and that the
 * user is typing ([typing], at most once every [TYPING_INTERVAL_MS]). The
 * chat tools of the web too: its details with what is scheduled to it and
 * its reminders ([loadDetail]), changing it ([apply]), syncing it, the
 * messages picked by a long press ([toggleSelection]) for the AI draft
 * ([draft]), a reminder ([remind]) or forwarding them ([forward]), the
 * user's reaction to a message ([react]), and scheduling a text ([schedule]).
 * As on WhatsApp: answering a message ([startReply], sent with what goes
 * next), editing ([edit]) or deleting for everyone ([deleteForEveryone]) what
 * the number sent, starring ([star]), its info ([messageInfo]), a poll, a
 * location, contact cards or a sticker, the group's members for mentions
 * ([loadMembers]), and reaching a message further up ([reveal]).
 *
 * @param now the clock of the typing throttle, in milliseconds.
 */
class WhatsappChatStore(
    val chatId: String,
    private val client: ApiClient,
    private val now: () -> Long = System::currentTimeMillis,
) {
    data class State(
        /** Newest first. */
        val messages: List<WhatsappConversationMessage> = emptyList(),
        val outgoing: List<WhatsappDelivery> = emptyList(),
        val hasMore: Boolean = false,
        val isLoaded: Boolean = false,
        val isLoadingOlder: Boolean = false,
        val isSending: Boolean = false,
        /** Why the last text or file was not sent (a 422 of the server, no network…). */
        val sendFailure: ApiException? = null,
        /** The failure of a first load. */
        val loadFailure: ApiException? = null,
        /** The chat with what is scheduled to it and its reminders, once read. */
        val detail: WhatsappConversationDetail? = null,
        /** The messages picked by a long press, by friendly id. */
        val selected: Set<String> = emptySet(),
        /** The message the next one answers (the bar over the composer). */
        val replyingTo: WhatsappConversationMessage? = null,
        /** The group's members, for the mentions; empty until read (or not a group). */
        val members: List<WhatsappGroupMember> = emptyList(),
    ) {
        val isSelecting: Boolean get() = selected.isNotEmpty()

        /** The one message picked, when only one is (and loaded). */
        val selectedMessage: WhatsappConversationMessage? get() = selected.singleOrNull()?.let { id -> messages.firstOrNull { it.friendlyId == id } }

        /** The picked messages, oldest first. */
        val selectedMessages: List<WhatsappConversationMessage> get() = messages.filter { it.friendlyId in selected }.reversed()

        /** Every picked message can be sent again (none deleted, none without text and file). */
        val canForward: Boolean get() = selected.isNotEmpty() && selectedMessages.size == selected.size && selectedMessages.all { it.isForwardable }
    }

    private val mutableState = MutableStateFlow(State())
    val state: StateFlow<State> = mutableState.asStateFlow()

    private val mutex = Mutex()
    private var lastReadId: String? = null
    private var lastTypingAt: Long? = null

    suspend fun refresh() = mutex.withLock {
        try {
            val fresh = client.call { whatsappConversationMessages(chatId) }

            mutableState.update {
                val merged = mergeFirstPage(fresh.data, it.messages)

                it.copy(
                    messages = merged,
                    outgoing = fresh.outgoing,
                    hasMore = if (merged.size > fresh.data.size) it.hasMore else fresh.meta.hasMore,
                    isLoaded = true,
                    loadFailure = null,
                )
            }
        } catch (_: ApiException.Unauthenticated) {
            // The shell already went back to the login.
        } catch (exception: ApiException) {
            mutableState.update { if (it.isLoaded) it else it.copy(loadFailure = exception) }
        }
    }

    suspend fun loadOlder() = mutex.withLock {
        val oldest = state.value.messages.lastOrNull() ?: return@withLock

        if (!state.value.hasMore || state.value.isLoadingOlder) {
            return@withLock
        }

        mutableState.update { it.copy(isLoadingOlder = true) }

        try {
            val older = client.call { whatsappConversationMessages(chatId, before = oldest.friendlyId) }

            mutableState.update { current ->
                val known = current.messages.map { it.friendlyId }.toSet()

                current.copy(messages = current.messages + older.data.filterNot { it.friendlyId in known }, hasMore = older.meta.hasMore)
            }
        } catch (_: ApiException) {
            // Scrolling up again tries again.
        } finally {
            mutableState.update { it.copy(isLoadingOlder = false) }
        }
    }

    /**
     * The chat itself (its name, picture and kind for the header) when the
     * screen was opened without it (a push): looked up on the first pages of
     * the chats, which start with the latest; null when not found there.
     */
    suspend fun findConversation(pages: Int = LOOKUP_PAGES): WhatsappConversation? {
        for (page in 1..pages) {
            val found = try {
                client.call { whatsappConversations(page = page, perPage = LOOKUP_PER_PAGE) }
            } catch (_: ApiException) {
                return null
            }

            found.data.firstOrNull { it.friendlyId == chatId }?.let { return it }

            if (!found.hasMorePages) {
                return null
            }
        }

        return null
    }

    /** Tells the server the chat was seen, once per newest message. */
    suspend fun markRead() {
        val newest = state.value.messages.firstOrNull()?.friendlyId ?: return

        if (newest == lastReadId) {
            return
        }

        try {
            client.call { readWhatsappConversation(chatId) }
            lastReadId = newest
        } catch (_: ApiException) {
            // The next refresh tries again.
        }
    }

    /** @return whether the text went out (answering [State.replyingTo]); a blank one is not sent. */
    suspend fun send(text: String): Boolean {
        val trimmed = text.trim()

        if (trimmed.isEmpty()) {
            return false
        }

        return sending { replyTo -> client.call { sendWhatsappConversationText(chatId, WhatsappConversationText(trimmed, replyTo)) } }
    }

    /** A file picked on the phone, with [caption]; an audio goes without the quote (WhatsApp takes none with a voice note). */
    suspend fun sendFile(name: String, mime: String?, bytes: ByteArray, caption: String): Boolean = sending { replyTo ->
        val part = MultipartBody.Part.createFormData("file", name, bytes.toRequestBody(mime?.toMediaTypeOrNull()))
        val quote = replyTo?.takeUnless { mime?.startsWith("audio/") == true }

        client.call { sendWhatsappConversationFile(chatId, caption.trim().toRequestBody(PLAIN), part, quote?.toRequestBody(PLAIN)) }
    }

    /** A poll of [question] with 2 to 10 [options]; [multiple], more than one answer per person. */
    suspend fun sendPoll(question: String, options: List<String>, multiple: Boolean): Boolean = sending { replyTo ->
        val answers = options.map { it.trim() }.filter { it.isNotEmpty() }

        client.call { sendWhatsappPoll(chatId, WhatsappPollInput(question.trim(), answers, multiple, replyTo)) }
    }

    /** A location, with its place's name and address when given. */
    suspend fun sendLocation(latitude: Double, longitude: Double, name: String?, address: String?): Boolean = sending { replyTo ->
        val input = WhatsappLocationInput(latitude, longitude, name?.trim()?.ifEmpty { null }, address?.trim()?.ifEmpty { null }, replyTo)

        client.call { sendWhatsappLocation(chatId, input) }
    }

    /** The cards of contact chats of the same number ([contactIds]); WhatsApp takes no quote with them. */
    suspend fun sendContacts(contactIds: List<String>): Boolean = sending { _ ->
        client.call { sendWhatsappContacts(chatId, WhatsappContactsInput(contactIds)) }
    }

    /** An image sent as a sticker (WebP, PNG or JPEG, up to [MAX_STICKER_BYTES]). */
    suspend fun sendSticker(name: String, mime: String?, bytes: ByteArray): Boolean = sending { replyTo ->
        val part = MultipartBody.Part.createFormData("file", name, bytes.toRequestBody(mime?.toMediaTypeOrNull()))

        client.call { sendWhatsappSticker(chatId, part, replyTo?.toRequestBody(PLAIN)) }
    }

    /** The next message answers [message]; the selection ends. */
    fun startReply(message: WhatsappConversationMessage) = mutableState.update { it.copy(replyingTo = message, selected = emptySet()) }

    fun cancelReply() = mutableState.update { it.copy(replyingTo = null) }

    /** The new text of a message the number sent (while [WhatsappConversationMessage.canEdit]). */
    suspend fun edit(messageId: String, text: String) {
        replaceMessage(client.call { editWhatsappMessage(chatId, messageId, WhatsappMessageEdit(text.trim())) }.data)
        clearSelection()
    }

    /** Deleted for everyone on WhatsApp; it stays marked as deleted. */
    suspend fun deleteForEveryone(messageId: String) {
        replaceMessage(client.call { deleteWhatsappMessageForEveryone(chatId, messageId) }.data)
        clearSelection()
    }

    /** Starred, or not any more (in Jarvis only). */
    suspend fun star(messageId: String, starred: Boolean) {
        replaceMessage(client.call { starWhatsappMessage(chatId, messageId, WhatsappStarInput(starred)) }.data)
        clearSelection()
    }

    suspend fun messageInfo(messageId: String): WhatsappMessageInfo = client.call { whatsappMessageInfo(chatId, messageId) }.data

    /** The group's members, for the mentions; once, and nothing when it fails (the mentions just do not show). */
    suspend fun loadMembers() {
        if (state.value.members.isNotEmpty()) {
            return
        }

        try {
            val members = client.call { whatsappGroup(chatId) }.data.members
            mutableState.update { it.copy(members = members) }
        } catch (_: ApiException) {
            // Typed by hand, `@` and the number still mentions.
        }
    }

    /**
     * Older pages read until [messageId] is among the messages (a search
     * result, a starred message, a quoted one), at most [pages] of them.
     * @return whether it is loaded now.
     */
    suspend fun reveal(messageId: String, pages: Int = REVEAL_PAGES): Boolean {
        repeat(pages) {
            if (state.value.messages.any { it.friendlyId == messageId }) {
                return true
            }

            if (!state.value.hasMore) {
                return false
            }

            loadOlder()
        }

        return state.value.messages.any { it.friendlyId == messageId }
    }

    private fun replaceMessage(message: WhatsappConversationMessage) = mutableState.update { current ->
        current.copy(messages = current.messages.map { if (it.friendlyId == message.friendlyId) message else it })
    }

    /** The user typed; at most one call every [TYPING_INTERVAL_MS]. @return whether the server was told. */
    suspend fun typing(): Boolean {
        val at = now()
        val last = lastTypingAt

        if (last != null && at - last < TYPING_INTERVAL_MS) {
            return false
        }

        lastTypingAt = at

        return try {
            client.call { whatsappTyping(chatId) }
            true
        } catch (_: ApiException) {
            false
        }
    }

    fun clearSendFailure() = mutableState.update { it.copy(sendFailure = null) }

    fun toggleSelection(messageId: String) = mutableState.update {
        it.copy(selected = if (messageId in it.selected) it.selected - messageId else it.selected + messageId)
    }

    fun clearSelection() = mutableState.update { it.copy(selected = emptySet()) }

    /** The chat, what is scheduled to it and its reminders. @return the chat, or null when it could not be read. */
    suspend fun loadDetail(): WhatsappConversation? = try {
        val detail = client.call { whatsappConversation(chatId) }
        mutableState.update { it.copy(detail = detail) }
        detail.data
    } catch (_: ApiException) {
        null
    }

    /** @return the chat as the server keeps it now. */
    suspend fun apply(change: WhatsappChatChange): WhatsappConversation {
        val chat = client.call { updateWhatsappConversation(chatId, change.body()) }.data
        mutableState.update { it.copy(detail = it.detail?.copy(data = chat)) }

        return chat
    }

    /** Fetches the chat again from WhatsApp. @return how many messages were new. */
    suspend fun sync(): Int {
        val added = client.call { syncWhatsappConversation(chatId) }.added
        refresh()

        return added
    }

    /**
     * An answer drafted by the AI to the picked messages, or to what the
     * contact sent since the user's last message, following [instruction]
     * when given; the selection ends.
     */
    suspend fun draft(instruction: String? = null, withoutContext: Boolean = false): String {
        val picked = state.value.selected
        val messages = if (withoutContext) emptyList() else state.value.messages.map { it.friendlyId }.filter { it in picked }.reversed()
        val request = WhatsappDraftRequest(messages, instruction?.trim()?.ifEmpty { null }, withoutContext.takeIf { it })
        val text = client.call { draftWhatsappConversation(chatId, request) }.text
        clearSelection()

        return text
    }

    /** A reminder about the chat, or quoting every picked message; [dueAt] on the user's wall clock. */
    suspend fun remind(title: String, dueAt: String) {
        val picked = state.value.selected
        val messages = state.value.messages.map { it.friendlyId }.filter { it in picked }.reversed()
        client.call { remindWhatsappConversation(chatId, WhatsappChatReminderInput(title.trim(), dueAt, messages)) }
        clearSelection()
        loadDetail()
    }

    /**
     * The user's [emoji] on a message, shown at once; the same one again (or
     * an empty one) removes it. Back as it was when WhatsApp refuses it.
     */
    suspend fun react(messageId: String, emoji: String) {
        val before = state.value.messages.firstOrNull { it.friendlyId == messageId }?.reactions ?: return
        setReactions(messageId, withMyReaction(before, emoji))

        try {
            client.call { reactToWhatsappMessage(chatId, messageId, WhatsappReactionInput(emoji)) }
        } catch (exception: ApiException) {
            setReactions(messageId, before)
            throw exception
        }

        refresh()
    }

    /** The picked messages, oldest first, sent again to [to] (chats of the same number); the selection ends. @return how many messages went out. */
    suspend fun forward(to: List<String>): Int {
        val messages = state.value.selectedMessages.map { it.friendlyId }
        val deliveries = client.call { forwardWhatsappMessages(chatId, WhatsappForwardInput(messages, to)) }.deliveries
        clearSelection()

        return deliveries
    }

    private fun setReactions(messageId: String, reactions: List<WhatsappReaction>) = mutableState.update { current ->
        current.copy(messages = current.messages.map { if (it.friendlyId == messageId) it.copy(reactions = reactions) else it })
    }

    /** [text] sent once at [at], on the user's wall clock. */
    suspend fun schedule(text: String, at: String) {
        client.call { scheduleWhatsappConversation(chatId, WhatsappScheduleText(text.trim(), at)) }
        loadDetail()
    }

    /** A file sent once at [at], [text] its caption (none for a voice note). */
    suspend fun scheduleFile(name: String, mime: String?, bytes: ByteArray, text: String, at: String) {
        val part = MultipartBody.Part.createFormData("file", name, bytes.toRequestBody(mime?.toMediaTypeOrNull()))
        val plain = "text/plain".toMediaTypeOrNull()

        client.call { scheduleWhatsappConversationFile(chatId, text.trim().toRequestBody(plain), at.toRequestBody(plain), part) }
        loadDetail()
    }

    suspend fun cancelScheduled(messageId: String) {
        client.call { cancelWhatsappMessage(messageId) }
        loadDetail()
    }

    /** Sends with the friendly id of the message it answers, if any; the answer ends once sent. */
    private suspend fun sending(request: suspend (String?) -> Unit): Boolean {
        val replyTo = state.value.replyingTo?.friendlyId
        mutableState.update { it.copy(isSending = true, sendFailure = null) }

        val sent = try {
            request(replyTo)
            mutableState.update { it.copy(replyingTo = null) }
            true
        } catch (exception: ApiException) {
            mutableState.update { it.copy(sendFailure = exception) }
            false
        } finally {
            mutableState.update { it.copy(isSending = false) }
        }

        if (sent) {
            refresh()
        }

        return sent
    }

    companion object {
        const val TYPING_INTERVAL_MS = 4_000L

        /** How far a chat opened from a push is looked for. */
        const val LOOKUP_PAGES = 4

        const val LOOKUP_PER_PAGE = 50

        /** How many older pages are read at most to reach a message. */
        const val REVEAL_PAGES = 10

        /** The biggest image the server takes as a sticker. */
        const val MAX_STICKER_BYTES = 1L * 1024 * 1024

        /** The answers a poll takes. */
        val POLL_OPTIONS = 2..10

        private val PLAIN = "text/plain".toMediaTypeOrNull()

        /** The quick reactions, as WhatsApp's. */
        val QUICK_REACTIONS = listOf("👍", "❤️", "😂", "😮", "😢", "🙏")

        /** At most this many chats get a forward at once (as the server takes). */
        const val MAX_FORWARD_CHATS = 5

        /**
         * [reactions] after the user gives [emoji]: their previous one goes
         * (one per person), and the same one again, or an empty one, leaves
         * none; the most given first.
         */
        fun withMyReaction(reactions: List<WhatsappReaction>, emoji: String): List<WhatsappReaction> {
            val previous = reactions.firstOrNull { it.mine }?.emoji
            val without = reactions.mapNotNull {
                when {
                    !it.mine -> it
                    it.count > 1 -> it.copy(count = it.count - 1, mine = false)
                    else -> null
                }
            }

            if (emoji.isEmpty() || emoji == previous) {
                return without
            }

            val existing = without.firstOrNull { it.emoji == emoji }
            val given = if (existing == null) without + WhatsappReaction(emoji, 1, true) else without.map { if (it.emoji == emoji) it.copy(count = it.count + 1, mine = true) else it }

            return given.sortedByDescending { it.count }
        }

        /**
         * The first page as it is now (new messages, new ticks), then the
         * older messages already read that it does not reach.
         */
        fun mergeFirstPage(fresh: List<WhatsappConversationMessage>, current: List<WhatsappConversationMessage>): List<WhatsappConversationMessage> {
            val oldestFresh = fresh.lastOrNull() ?: return fresh
            val freshIds = fresh.map { it.friendlyId }.toSet()
            val index = current.indexOfFirst { it.friendlyId == oldestFresh.friendlyId }

            if (index < 0) {
                return fresh
            }

            return fresh + current.drop(index + 1).filterNot { it.friendlyId in freshIds }
        }
    }
}
