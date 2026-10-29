package app.jarvis.android.ui.whatsapp

import app.jarvis.android.network.ApiClient
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.JarvisApi
import app.jarvis.android.network.WhatsappConversation
import app.jarvis.android.network.WhatsappGroupInput
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock

/**
 * The chats of the user's numbers as the WhatsApp tab lists them, pinned
 * first, then the latest message first (`GET /whatsapp/conversations`):
 * read again every few seconds while shown ([refresh], the first page, with
 * the pages read further kept under it), more pages on scrolling
 * ([loadMore]), a search by name or number ([search]), the highest
 * priority first instead ([sortByPriority]), only the chats of a tag
 * ([filterByTag]) and only the archived ones ([showArchived]); each of these
 * starts again from the first page. The actions of a row ([apply]: archive,
 * mark unread, mute) and a new group ([createGroup]).
 */
class WhatsappConversationsStore(private val client: ApiClient) {
    data class State(
        val chats: List<WhatsappConversation> = emptyList(),
        val isLoaded: Boolean = false,
        val hasMore: Boolean = false,
        val search: String = "",
        val isSortedByPriority: Boolean = false,
        /** The friendly id of the tag the list is narrowed to. */
        val tag: String? = null,
        /** Only the archived chats (WhatsApp's "Archived"); otherwise they are left out. */
        val isShowingArchived: Boolean = false,
        /** The failure of a first load. */
        val loadFailure: ApiException? = null,
    )

    private val mutableState = MutableStateFlow(State())
    val state: StateFlow<State> = mutableState.asStateFlow()

    private val mutex = Mutex()
    private var page = 1

    /** Reads the first page again, keeping the further ones already read under it. */
    suspend fun refresh() = mutex.withLock {
        try {
            val fresh = client.call { query(page = 1) }

            mutableState.update {
                it.copy(
                    chats = if (page > 1) mergeFirstPage(fresh.data, it.chats) else fresh.data,
                    isLoaded = true,
                    hasMore = if (page > 1) it.hasMore else fresh.hasMorePages,
                    loadFailure = null,
                )
            }
        } catch (_: ApiException.Unauthenticated) {
            // The shell already went back to the login.
        } catch (exception: ApiException) {
            mutableState.update { if (it.isLoaded) it else it.copy(loadFailure = exception) }
        }
    }

    suspend fun loadMore() = mutex.withLock {
        if (!state.value.hasMore) {
            return@withLock
        }

        try {
            val next = client.call { query(page = page + 1) }
            page++
            mutableState.update { current ->
                val known = current.chats.map { it.friendlyId }.toSet()

                current.copy(chats = current.chats + next.data.filterNot { it.friendlyId in known }, hasMore = next.hasMorePages)
            }
        } catch (_: ApiException) {
            // The next scroll tries again.
        }
    }

    /** A new search starts from its first page. */
    suspend fun search(text: String) = restart { it.copy(search = text.trim()) }

    suspend fun sortByPriority(isOn: Boolean) = restart { it.copy(isSortedByPriority = isOn) }

    /** Only the chats of the tag; null for every chat. */
    suspend fun filterByTag(tagId: String?) = restart { it.copy(tag = tagId) }

    /** The archived chats only, or back to the others. */
    suspend fun showArchived(isOn: Boolean) = restart { it.copy(isShowingArchived = isOn) }

    /**
     * A change to a row's chat (archive, mark unread, mute…): the row as the
     * server answers it, or gone when it moved in or out of the archive
     * shown. @return the chat now.
     */
    suspend fun apply(chatId: String, change: WhatsappChatChange): WhatsappConversation {
        val chat = client.call { updateWhatsappConversation(chatId, change.body()) }.data

        mutableState.update { current ->
            val chats = if (chat.archived != current.isShowingArchived) current.chats.filterNot { it.friendlyId == chat.friendlyId } else current.chats.map { if (it.friendlyId == chat.friendlyId) chat else it }

            current.copy(chats = chats)
        }

        return chat
    }

    /**
     * WhatsApp's "Mark as unread", or read again: a chat with unread
     * messages is read (as opening it does), and the mark by hand is taken off.
     */
    suspend fun toggleUnread(chat: WhatsappConversation): WhatsappConversation {
        if (!chat.looksUnread) {
            return apply(chat.friendlyId, WhatsappChatChange.MarkedUnread(true))
        }

        if (chat.unreadCount > 0) {
            client.call { readWhatsappConversation(chat.friendlyId) }
        }

        if (chat.markedUnread) {
            return apply(chat.friendlyId, WhatsappChatChange.MarkedUnread(false))
        }

        return chat.copy(unreadCount = 0).also(::replace)
    }

    /** A WhatsApp group on [accountId] with contact chats of the number ([memberIds]); the number is its admin. */
    suspend fun createGroup(accountId: String, subject: String, memberIds: List<String>, description: String?): WhatsappConversation {
        val chat = client.call { createWhatsappGroup(WhatsappGroupInput(accountId, subject.trim(), memberIds, description?.trim()?.ifEmpty { null })) }.data
        refresh()

        return chat
    }

    /** Puts [chat] in place of its row, as the server answered a change to it. */
    fun replace(chat: WhatsappConversation) = mutableState.update { current ->
        current.copy(chats = current.chats.map { if (it.friendlyId == chat.friendlyId) chat else it })
    }

    private suspend fun restart(change: (State) -> State) {
        mutex.withLock {
            page = 1
            mutableState.update { change(it).copy(hasMore = false) }
        }

        refresh()
    }

    private suspend fun JarvisApi.query(page: Int) = state.value.let {
        whatsappConversations(
            search = it.search.ifBlank { null },
            tag = it.tag,
            sort = if (it.isSortedByPriority) "priority" else null,
            page = page,
            perPage = PER_PAGE,
            archived = if (it.isShowingArchived) 1 else null,
        )
    }

    companion object {
        const val PER_PAGE = 30

        /** The first page as it is now, then the chats of the further pages it no longer holds. */
        fun mergeFirstPage(fresh: List<WhatsappConversation>, current: List<WhatsappConversation>): List<WhatsappConversation> {
            val freshIds = fresh.map { it.friendlyId }.toSet()

            return fresh + current.drop(PER_PAGE).filterNot { it.friendlyId in freshIds }
        }
    }
}
