package app.jarvis.android.offline

import app.jarvis.android.app.SessionListener
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.User
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update

/** Asks the system to send the queue once the network is there (WorkManager in the app). */
interface SyncScheduler {
    fun schedule()

    fun cancel()
}

/**
 * The app side of the offline queue (US-8.2): item changes are kept on disk
 * and sent right away; what could not be sent is handed to WorkManager, which
 * sends it when the network comes back — even with the app closed. Back in
 * the foreground the queue is sent too.
 */
class ShoppingSync(
    private val queue: OfflineQueue,
    private val scheduler: SyncScheduler,
) : SessionListener {
    private val mutablePendingByList = MutableStateFlow<Map<String, Int>>(emptyMap())

    /** The changes not yet confirmed by the server, by list. */
    val pendingByList: StateFlow<Map<String, Int>> = mutablePendingByList.asStateFlow()

    private val mutableRevision = MutableStateFlow(0)

    /** Bumped after a sending not asked by a screen, so the open list reads itself again. */
    val revision: StateFlow<Int> = mutableRevision.asStateFlow()

    private val mutableRejection = MutableStateFlow<ApiException?>(null)

    /** The last change the server refused for good, for an alert. */
    val rejection: StateFlow<ApiException?> = mutableRejection.asStateFlow()

    /**
     * Keeps a change with the moment of the tap and tries to send it.
     *
     * @return what the server did with what was sent now.
     */
    suspend fun record(change: ShoppingItemChange, itemId: String, listId: String): List<MutationOutcome> {
        queue.enqueue(change, itemId, listId)
        updateCounts()

        return send(isBackground = false)
    }

    suspend fun pending(listId: String): List<PendingMutation> = queue.pending(listId)

    /**
     * Sends what is waiting, in order (the network is back, the app is in the
     * foreground again); the open list reads itself again afterwards.
     *
     * @return whether changes are still waiting (no network yet).
     */
    suspend fun flush(): Boolean {
        send(isBackground = true)

        return queue.pending().isNotEmpty()
    }

    fun dismissRejection() {
        mutableRejection.value = null
    }

    override suspend fun onForeground(user: User) {
        flush()
    }

    /** After the sign-out: nothing of the previous user is sent. */
    override suspend fun onSessionEnded() {
        scheduler.cancel()
        queue.clear()
        updateCounts()
    }

    private suspend fun send(isBackground: Boolean): List<MutationOutcome> {
        val outcomes = queue.flush()
        updateCounts()

        outcomes.filterIsInstance<MutationOutcome.Rejected>().lastOrNull()?.let { mutableRejection.value = it.error }

        if (isBackground && outcomes.isNotEmpty()) {
            mutableRevision.update { it + 1 }
        }

        if (queue.pending().isNotEmpty()) {
            scheduler.schedule()
        }

        return outcomes
    }

    private suspend fun updateCounts() {
        mutablePendingByList.value = queue.pending().groupingBy { it.listId }.eachCount()
    }
}
