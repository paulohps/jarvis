package app.jarvis.android.ui.shopping

import app.jarvis.android.network.ApiClient
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.ShoppingList
import app.jarvis.android.network.ShoppingListInput
import app.jarvis.android.network.ShoppingItemInput
import app.jarvis.android.network.ShoppingItemsOrder
import app.jarvis.android.network.ShoppingListItem
import app.jarvis.android.network.reordered
import app.jarvis.android.network.sortedForDisplay
import app.jarvis.android.offline.MutationOutcome
import app.jarvis.android.offline.PendingMutation
import app.jarvis.android.offline.ShoppingItemChange
import app.jarvis.android.offline.ShoppingSync
import app.jarvis.android.offline.applying
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import java.time.Instant

/**
 * One shopping list as a screen shows it (US-8.1, US-8.2): its items with the
 * checked ones at the end, the changes made on this phone, and the ones made by
 * anyone else through the list's Reverb channel — each `shopping-list.changed`
 * reads the list again.
 *
 * Checking, editing and removing an item go through the offline queue
 * ([ShoppingSync]): the screen shows them at once, with or without network,
 * as the server's state with the waiting changes on top.
 */
class ShoppingListStore(
    val listId: String,
    private val client: ApiClient,
    private val sync: ShoppingSync,
) {
    enum class LoadState { Loading, Loaded, Forbidden, NotFound, Failed }

    data class State(
        val list: ShoppingList? = null,
        val items: List<ShoppingListItem> = emptyList(),
        val loadState: LoadState = LoadState.Loading,
        /** The failure of a first load. */
        val loadFailure: ApiException? = null,
        /** The failure of the last change, for an alert. */
        val actionFailure: ApiException? = null,
    ) {
        val isArchived: Boolean
            get() = list?.isArchived ?: false

        /** The household's default list: a voice command that names no list adds here. */
        val isDefault: Boolean
            get() = list?.isDefault ?: false

        val uncheckedItems: List<ShoppingListItem>
            get() = items.filterNot { it.isChecked }

        val checkedItems: List<ShoppingListItem>
            get() = items.filter { it.isChecked }
    }

    private val mutableState = MutableStateFlow(State())
    val state: StateFlow<State> = mutableState.asStateFlow()

    /** The items as the server last told them. */
    private var serverItems: List<ShoppingListItem> = emptyList()
    private var isReloading = false
    private var needsReload = false

    /** Reads the list; a reload asked meanwhile (a burst of events) runs once after it. */
    suspend fun reload() {
        if (isReloading) {
            needsReload = true

            return
        }

        isReloading = true

        try {
            do {
                needsReload = false
                load()
            } while (needsReload)
        } finally {
            isReloading = false
        }
    }

    /**
     * The quick add: an item at the end of the unchecked ones (its note is edited
     * afterwards). It needs the network: an add sent twice would add the item
     * twice, so it is not queued.
     *
     * @return whether it was added (the field is cleared then).
     */
    suspend fun addItem(rawName: String): Boolean {
        val name = rawName.trim()

        if (name.isEmpty()) {
            return false
        }

        return try {
            val item = client.call { addShoppingItem(listId, ShoppingItemInput(name, null)) }.data

            if (serverItems.none { it.friendlyId == item.friendlyId }) {
                serverItems = serverItems + item
            }

            showItems()
            true
        } catch (exception: ApiException) {
            fail(exception)
            false
        }
    }

    /** Checks or unchecks an item: shown right away, sent now or when the network is back. */
    suspend fun toggle(item: ShoppingListItem) = record(ShoppingItemChange.Toggle(!item.isChecked), item)

    suspend fun update(item: ShoppingListItem, name: String, quantityNote: String?) =
        record(ShoppingItemChange.Update(name, quantityNote), item)

    suspend fun remove(item: ShoppingListItem) = record(ShoppingItemChange.Remove, item)

    /**
     * The unchecked items dragged into a new order (friendly ids): shown right
     * away and sent to the server. It needs the network, like the quick add; a
     * failure shows the list as the server has it.
     */
    suspend fun reorderUnchecked(order: List<String>) {
        serverItems = serverItems.reordered(order)
        mutableState.update { it.copy(items = it.items.reordered(order).sortedForDisplay()) }

        try {
            show(client.call { reorderShoppingItems(listId, ShoppingItemsOrder(order)) }.data)
        } catch (exception: ApiException) {
            fail(exception)
            reload()
        }
    }

    suspend fun clearChecked() {
        try {
            show(client.call { clearCheckedItems(listId) }.data)
        } catch (exception: ApiException) {
            fail(exception)
        }
    }

    suspend fun setArchived(archived: Boolean) {
        try {
            val saved = client.call { if (archived) archiveShoppingList(listId) else unarchiveShoppingList(listId) }.data
            mutableState.update { it.copy(list = saved.copy(items = it.list?.items.orEmpty())) }
        } catch (exception: ApiException) {
            fail(exception)
        }
    }

    /**
     * Completes a list of a recurring one, as the web does: archived as done,
     * and the next list made at once.
     *
     * @return the next list's id, to open it; `null` when there is none (it
     * waits) or it failed.
     */
    suspend fun complete(): String? {
        return try {
            val completed = client.call { completeShoppingList(listId) }.data
            mutableState.update { it.copy(list = completed.copy(items = it.list?.items.orEmpty())) }
            val scheduleId = completed.scheduleId ?: return null
            val next = client.call { shoppingSchedule(scheduleId) }.data.openList

            next?.friendlyId?.takeIf { it != listId }
        } catch (exception: ApiException) {
            fail(exception)

            null
        }
    }

    suspend fun setDefault(isDefault: Boolean) {
        try {
            val saved = client.call { if (isDefault) makeDefaultShoppingList(listId) else unsetDefaultShoppingList(listId) }.data
            mutableState.update { it.copy(list = saved.copy(items = it.list?.items.orEmpty())) }
        } catch (exception: ApiException) {
            fail(exception)
        }
    }

    suspend fun rename(rawName: String): Boolean {
        val name = rawName.trim()

        if (name.isEmpty()) {
            return false
        }

        return try {
            val saved = client.call { renameShoppingList(listId, ShoppingListInput(name)) }.data
            mutableState.update { it.copy(list = saved.copy(items = it.list?.items.orEmpty())) }
            true
        } catch (exception: ApiException) {
            fail(exception)
            false
        }
    }

    /** Moves the list to another of the user's workspaces (its name stays). */
    suspend fun moveToWorkspace(workspaceId: String) {
        val list = state.value.list ?: return

        if (list.workspace?.id == workspaceId) {
            return
        }

        try {
            val saved = client.call { renameShoppingList(listId, ShoppingListInput(list.name, workspace = workspaceId)) }.data
            mutableState.update { it.copy(list = saved.copy(items = it.list?.items.orEmpty())) }
        } catch (exception: ApiException) {
            fail(exception)
        }
    }

    fun dismissActionFailure() {
        mutableState.update { it.copy(actionFailure = null) }
    }

    /** What the server answered to the queued changes of this list. */
    suspend fun apply(outcomes: List<MutationOutcome>) {
        for (outcome in outcomes) {
            if (outcome.mutation.listId != listId) {
                continue
            }

            when (outcome) {
                is MutationOutcome.Applied -> {
                    val saved = outcome.item

                    serverItems = when {
                        saved != null -> serverItems.map { if (it.friendlyId == saved.friendlyId) saved else it }
                        outcome.mutation.change == ShoppingItemChange.Remove -> serverItems.filterNot { it.friendlyId == outcome.mutation.itemId }
                        else -> serverItems
                    }
                }

                // The list as it really is (the item may be gone, the list archived).
                is MutationOutcome.Rejected -> needsReload = true
            }
        }

        if (needsReload && !isReloading) {
            reload()
        }
    }

    private suspend fun record(change: ShoppingItemChange, item: ShoppingListItem) {
        val optimistic = PendingMutation(listId = listId, itemId = item.friendlyId, change = change, clientUpdatedAt = Instant.now())
        mutableState.update { it.copy(items = it.items.applying(listOf(optimistic)).sortedForDisplay()) }

        apply(sync.record(change, item.friendlyId, listId))
        showItems()
    }

    private suspend fun load() {
        try {
            show(client.call { shoppingList(listId) }.data)
        } catch (_: ApiException.Forbidden) {
            mutableState.update { it.copy(loadState = LoadState.Forbidden) }
        } catch (_: ApiException.NotFound) {
            mutableState.update { it.copy(loadState = LoadState.NotFound) }
        } catch (_: ApiException.Unauthenticated) {
            // The shell already went back to the login.
        } catch (exception: ApiException) {
            // What is on screen stays (offline, the queue keeps the changes); only a first load shows the failure.
            if (state.value.list == null) {
                mutableState.update { it.copy(loadState = LoadState.Failed, loadFailure = exception) }
            }
        }
    }

    private suspend fun show(loaded: ShoppingList) {
        serverItems = loaded.items
        val pending = sync.pending(listId)
        mutableState.update {
            it.copy(list = loaded, items = serverItems.applying(pending).sortedForDisplay(), loadState = LoadState.Loaded, loadFailure = null)
        }
    }

    /** The server's items with the waiting changes on top. */
    private suspend fun showItems() {
        val pending = sync.pending(listId)
        mutableState.update { it.copy(items = serverItems.applying(pending).sortedForDisplay()) }
    }

    private fun fail(exception: ApiException) {
        if (exception !is ApiException.Unauthenticated) {
            mutableState.update { it.copy(actionFailure = exception) }
        }
    }
}
