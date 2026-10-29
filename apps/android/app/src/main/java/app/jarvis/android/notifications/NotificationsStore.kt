package app.jarvis.android.notifications

import app.jarvis.android.app.SessionListener
import app.jarvis.android.network.ApiClient
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.NotificationCreatedEvent
import app.jarvis.android.network.NotificationItem
import app.jarvis.android.network.User
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock

data class NotificationsState(
    val items: List<NotificationItem> = emptyList(),
    val unreadCount: Int = 0,
    val hasMorePages: Boolean = false,
    val isLoadingMore: Boolean = false,
    val hasLoaded: Boolean = false,
    val failure: ApiException? = null,
)

/**
 * The in-app notifications of the user (US-5.1): the pages of
 * `GET /notifications`, the unread badge, and the new ones from Reverb.
 */
class NotificationsStore(
    private val client: ApiClient,
    private val scope: CoroutineScope,
) : SessionListener {
    private val mutex = Mutex()
    private val mutableState = MutableStateFlow(NotificationsState())
    val state: StateFlow<NotificationsState> = mutableState.asStateFlow()

    private var currentPage = 1

    override fun onUserChanged(user: User, isNewSession: Boolean) {
        if (isNewSession) {
            scope.launch { reload() }
        }
    }

    override suspend fun onForeground(user: User) {
        reload()
    }

    override suspend fun onSessionEnded() {
        clear()
    }

    /** The first page again (pull to refresh, a new notification, back to the foreground). */
    suspend fun reload() = mutex.withLock {
        try {
            val page = client.call { notifications(page = 1) }
            currentPage = 1
            mutableState.update {
                it.copy(items = page.data, hasMorePages = page.hasMorePages, unreadCount = page.meta.unreadCount, failure = null, hasLoaded = true)
            }
        } catch (exception: ApiException) {
            mutableState.update { it.copy(failure = exception, hasLoaded = true) }
        }
    }

    suspend fun loadMore() {
        if (!state.value.hasMorePages || state.value.isLoadingMore) {
            return
        }

        mutableState.update { it.copy(isLoadingMore = true) }

        mutex.withLock {
            try {
                val page = client.call { notifications(page = currentPage + 1) }
                currentPage = page.meta.currentPage
                mutableState.update { state ->
                    val known = state.items.map { it.id }.toSet()
                    state.copy(
                        items = state.items + page.data.filterNot { it.id in known },
                        hasMorePages = page.hasMorePages,
                        unreadCount = page.meta.unreadCount,
                    )
                }
            } catch (exception: ApiException) {
                mutableState.update { it.copy(failure = exception) }
            }
        }

        mutableState.update { it.copy(isLoadingMore = false) }
    }

    suspend fun markRead(id: String) {
        val item = state.value.items.firstOrNull { it.id == id }?.takeIf { !it.isRead } ?: return

        mutableState.update { state ->
            state.copy(
                items = state.items.map { if (it.id == item.id) it.copy(isRead = true) else it },
                unreadCount = (state.unreadCount - 1).coerceAtLeast(0),
            )
        }

        try {
            val marked = client.call { markNotificationRead(id) }
            mutableState.update { it.copy(unreadCount = marked.unreadCount) }
        } catch (_: ApiException) {
            // The next reload shows the server's count.
        }
    }

    suspend fun markAllRead() {
        mutableState.update { state -> state.copy(items = state.items.map { it.copy(isRead = true) }, unreadCount = 0) }

        try {
            val marked = client.call { markAllNotificationsRead() }
            mutableState.update { it.copy(unreadCount = marked.unreadCount) }
        } catch (_: ApiException) {
            // The next reload shows the server's count.
        }
    }

    /** A `notification.created` of the user's channel: the badge right away, then the list. */
    suspend fun didReceive(event: NotificationCreatedEvent) {
        mutableState.update { it.copy(unreadCount = event.unreadCount) }
        reload()
    }

    fun clear() {
        currentPage = 1
        mutableState.value = NotificationsState()
    }
}
