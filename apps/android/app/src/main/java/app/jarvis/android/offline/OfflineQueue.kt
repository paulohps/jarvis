package app.jarvis.android.offline

import app.jarvis.android.network.ApiClient
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.ShoppingListItem
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import java.time.Instant

/** What became of a queued change once the server answered. */
sealed interface MutationOutcome {
    val mutation: PendingMutation

    /** Saved; the item as the server keeps it (`null` after a removal). */
    data class Applied(override val mutation: PendingMutation, val item: ShoppingListItem?) : MutationOutcome

    /**
     * Refused for good (the item is gone, the list was archived, the module was
     * switched off): it is dropped and the screen reads the list again.
     */
    data class Rejected(override val mutation: PendingMutation, val error: ApiException) : MutationOutcome
}

/**
 * The offline queue of shopping item changes (US-8.2).
 *
 * A change is kept on disk the moment it is made, with the moment of the tap
 * (`client_updated_at`), so the screen shows it right away with or without
 * network. [flush] sends the queue in order, each change once: it is removed
 * only after the server answered it, and the sending stops at the first change
 * that could not reach the server, to be tried again when the network comes
 * back (WorkManager) — so the order is kept and nothing is lost. Conflicts are
 * settled by the server with last-write-wins.
 */
class OfflineQueue(
    private val client: ApiClient,
    private val store: MutationStore,
    private val now: () -> Instant = Instant::now,
) {
    private val lock = Mutex()
    private val flushLock = Mutex()
    private var loaded: MutableList<PendingMutation>? = null

    /** The changes still waiting, in order. */
    suspend fun pending(): List<PendingMutation> = lock.withLock { queue().toList() }

    /** The changes still waiting for one list. */
    suspend fun pending(listId: String): List<PendingMutation> = pending().filter { it.listId == listId }

    /** Keeps a change, stamped with the moment of the tap. */
    suspend fun enqueue(change: ShoppingItemChange, itemId: String, listId: String): PendingMutation = lock.withLock {
        val mutation = PendingMutation(listId = listId, itemId = itemId, change = change, clientUpdatedAt = now())
        queue() += mutation
        persist()

        mutation
    }

    /**
     * Sends the waiting changes in order (on each change, when the network comes
     * back, back to the foreground). A flush already running takes the changes
     * made meanwhile, so a second one returns at once.
     *
     * @return what the server did with each change sent now.
     */
    suspend fun flush(): List<MutationOutcome> {
        if (!flushLock.tryLock()) {
            return emptyList()
        }

        try {
            val outcomes = mutableListOf<MutationOutcome>()

            while (true) {
                val mutation = lock.withLock { queue().firstOrNull() } ?: break

                try {
                    val saved = mutation.send(client)
                    remove(mutation)
                    outcomes += MutationOutcome.Applied(mutation, saved)
                } catch (exception: ApiException) {
                    if (isRetryable(exception)) {
                        break
                    }

                    remove(mutation)
                    outcomes += if (wasApplied(exception, mutation)) {
                        MutationOutcome.Applied(mutation, null)
                    } else {
                        MutationOutcome.Rejected(mutation, exception)
                    }
                }
            }

            return outcomes
        } finally {
            flushLock.unlock()
        }
    }

    /** After the sign-out: nothing of the previous user is sent. */
    suspend fun clear() = lock.withLock {
        queue().clear()
        persist()
    }

    private suspend fun remove(mutation: PendingMutation) = lock.withLock {
        queue().removeAll { it.id == mutation.id }
        persist()
    }

    /** The queue, read from the disk on first use (as after a relaunch). Call under [lock]. */
    private suspend fun queue(): MutableList<PendingMutation> =
        loaded ?: store.load().toMutableList().also { loaded = it }

    private suspend fun persist() {
        store.save(queue().toList())
    }

    companion object {
        /** No answer, or one that may change later: the change is kept for the next flush. */
        fun isRetryable(exception: ApiException): Boolean = when (exception) {
            is ApiException.Transport, is ApiException.Unauthenticated, is ApiException.TooManyRequests -> true
            is ApiException.Server -> exception.status >= 500
            is ApiException.Forbidden, is ApiException.NotFound, is ApiException.Validation, is ApiException.Decoding -> false
        }

        /**
         * A removal answered 404 was removed already (by someone else, or by this
         * change on a try whose answer was lost); an answer that could not be read
         * was still saved.
         */
        fun wasApplied(exception: ApiException, mutation: PendingMutation): Boolean = when (exception) {
            is ApiException.NotFound -> mutation.change == ShoppingItemChange.Remove
            is ApiException.Decoding -> true
            else -> false
        }
    }
}
