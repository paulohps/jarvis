package app.jarvis.android.ui.shopping

import androidx.compose.foundation.background
import androidx.compose.foundation.gestures.detectDragGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Autorenew
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.CloudOff
import androidx.compose.material.icons.filled.DragHandle
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material.icons.filled.RadioButtonUnchecked
import androidx.compose.material.icons.filled.SearchOff
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.filled.WifiOff
import androidx.compose.material.icons.outlined.Archive
import androidx.compose.material.icons.outlined.ShoppingCart
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilledIconButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TextField
import androidx.compose.material3.TextFieldDefaults
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.onSizeChanged
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.unit.dp
import androidx.compose.ui.zIndex
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import app.jarvis.android.R
import app.jarvis.android.app.AppContainer
import app.jarvis.android.app.AppModel
import app.jarvis.android.navigation.AppTab
import app.jarvis.android.navigation.Destination
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.ShoppingList
import app.jarvis.android.network.ShoppingListChangedEvent
import app.jarvis.android.network.ShoppingListFilter
import app.jarvis.android.network.ShoppingListInput
import app.jarvis.android.network.ShoppingListItem
import app.jarvis.android.network.ModuleSlug
import app.jarvis.android.network.UserWorkspace
import app.jarvis.android.network.workspacePickerOptions
import app.jarvis.android.network.defaultWorkspace
import app.jarvis.android.ui.components.ActionFailureDialog
import app.jarvis.android.ui.components.ConfirmDialog
import app.jarvis.android.ui.components.ContentUnavailable
import app.jarvis.android.ui.components.FilterRow
import app.jarvis.android.ui.components.GroupedList
import app.jarvis.android.ui.components.JarvisScreen
import app.jarvis.android.ui.components.ListRow
import app.jarvis.android.ui.components.Section
import app.jarvis.android.ui.components.TextFieldDialog
import app.jarvis.android.ui.components.FormError
import app.jarvis.android.ui.components.FormSheet
import app.jarvis.android.ui.components.SectionTextField
import app.jarvis.android.ui.components.WorkspaceBadge
import app.jarvis.android.ui.components.WorkspaceDot
import app.jarvis.android.ui.components.WorkspacePickerSection
import app.jarvis.android.ui.components.observedUser
import app.jarvis.android.ui.components.formatDate
import app.jarvis.android.ui.components.formatDateTime
import app.jarvis.android.ui.components.userMessage
import app.jarvis.android.ui.shell.NoAccessScreen
import app.jarvis.android.ui.shell.RemoteContent
import kotlinx.coroutines.flow.conflate
import kotlinx.coroutines.flow.drop
import kotlinx.coroutines.flow.filter
import kotlinx.coroutines.launch

/**
 * The household's shopping lists (US-8.1): the active ones by name, the
 * recurring ones (made again on a schedule) or the archived ones; a new list,
 * archive, unarchive — complete, for a list with a deadline — the default list
 * and delete. A list opens from a push too (`jarvis://shopping/lists/{id}`).
 * For a user in two or more workspaces with shopping, each list shows its
 * workspace's color and a new one asks where it goes.
 */
@Composable
fun ShoppingHomeScreen(model: AppModel) {
    var section by rememberSaveable { mutableStateOf(ShoppingSection.Active) }
    var isCreatingList by remember { mutableStateOf(false) }
    var isCreatingSchedule by remember { mutableStateOf(false) }
    var listToDelete by remember { mutableStateOf<ShoppingList?>(null) }
    var actionFailure by remember { mutableStateOf<ApiException?>(null) }
    val scope = rememberCoroutineScope()
    val user = model.observedUser()
    val workspaceOptions = user?.workspacePickerOptions(ModuleSlug.SHOPPING).orEmpty()

    // Makes the list (in the user's default workspace without one) and opens it; answers the failure, if any.
    suspend fun createList(name: String, workspaceId: String?): ApiException? = try {
        val list = model.client.call { createShoppingList(ShoppingListInput(name, workspace = workspaceId)) }.data
        section = ShoppingSection.Active
        model.router.push(AppTab.Shopping, Destination.ShoppingList(list.friendlyId))
        null
    } catch (exception: ApiException) {
        exception
    }

    JarvisScreen(
        title = stringResource(R.string.tab_shopping),
        actions = {
            IconButton(
                onClick = { if (section == ShoppingSection.Recurring) isCreatingSchedule = true else isCreatingList = true },
                modifier = Modifier.testTag("shopping.new-list"),
            ) {
                Icon(
                    Icons.Filled.Add,
                    contentDescription = stringResource(if (section == ShoppingSection.Recurring) R.string.shopping_new_schedule else R.string.shopping_new_list),
                )
            }
        },
    ) {
        FilterRow(ShoppingSection.entries, section, { section = it }) { stringResource(it.title) }

        val filter = section.filter

        if (filter == null) {
            ShoppingSchedules(model, isCreating = isCreatingSchedule, onCreatingDone = { isCreatingSchedule = false }, showsWorkspace = workspaceOptions.isNotEmpty())
        } else {
            RemoteContent(key = filter, load = { model.client.call { shoppingLists(filter.apiValue) }.data }, onRetryAccess = model::refreshUser) { lists, reload ->
                val run: (suspend () -> Unit) -> Unit = { action ->
                    scope.launch {
                        try {
                            action()
                        } catch (exception: ApiException) {
                            actionFailure = exception
                        }

                        reload()
                    }
                }

                if (lists.isEmpty()) {
                    ContentUnavailable(
                        title = stringResource(if (filter == ShoppingListFilter.Active) R.string.shopping_empty_title else R.string.shopping_no_archived),
                        icon = if (filter == ShoppingListFilter.Active) Icons.Outlined.ShoppingCart else Icons.Outlined.Archive,
                    )
                } else {
                    GroupedList {
                        item {
                            Section {
                                lists.forEachIndexed { index, list ->
                                    ShoppingListRow(
                                        list = list,
                                        showDivider = index < lists.lastIndex,
                                        onClick = { model.router.push(AppTab.Shopping, Destination.ShoppingList(list.friendlyId)) },
                                        onArchive = {
                                            run {
                                                model.client.call {
                                                    when {
                                                        // A list of a recurring one is completed: the next one is made.
                                                        list.dueAt != null && !list.isArchived -> completeShoppingList(list.friendlyId)
                                                        list.isArchived -> unarchiveShoppingList(list.friendlyId)
                                                        else -> archiveShoppingList(list.friendlyId)
                                                    }
                                                }
                                            }
                                        },
                                        // The previous default stops being it: `run` reads the lists again.
                                        onToggleDefault = {
                                            run {
                                                model.client.call {
                                                    if (list.isDefault) unsetDefaultShoppingList(list.friendlyId) else makeDefaultShoppingList(list.friendlyId)
                                                }
                                            }
                                        },
                                        onDelete = { listToDelete = list },
                                        model = model,
                                        showsWorkspace = workspaceOptions.isNotEmpty(),
                                    )
                                }
                            }
                        }
                    }
                }

                listToDelete?.let { list ->
                    ConfirmDialog(
                        title = stringResource(R.string.shopping_delete_list_confirm),
                        confirmLabel = stringResource(R.string.delete),
                        onConfirm = { run { model.client.call { deleteShoppingList(list.friendlyId) } } },
                        onDismiss = { listToDelete = null },
                    )
                }
            }
        }
    }

    if (isCreatingList && workspaceOptions.isEmpty()) {
        TextFieldDialog(
            title = stringResource(R.string.shopping_new_list),
            label = stringResource(R.string.name),
            confirmLabel = stringResource(R.string.create),
            initialValue = "",
            onConfirm = { name ->
                scope.launch { createList(name, workspaceId = null)?.let { actionFailure = it } }
            },
            onDismiss = { isCreatingList = false },
        )
    } else if (isCreatingList) {
        NewShoppingListForm(
            workspaces = workspaceOptions,
            defaultWorkspaceId = user?.defaultWorkspace(ModuleSlug.SHOPPING)?.id,
            create = { name, workspaceId -> createList(name, workspaceId) },
            onDismiss = { isCreatingList = false },
        )
    }

    ActionFailureDialog(actionFailure) { actionFailure = null }
}

@Composable
private fun ShoppingListRow(
    list: ShoppingList,
    showDivider: Boolean,
    onClick: () -> Unit,
    onArchive: () -> Unit,
    onToggleDefault: () -> Unit,
    onDelete: () -> Unit,
    model: AppModel,
    showsWorkspace: Boolean,
) {
    var isMenuOpen by remember { mutableStateOf(false) }
    val archivedOn = list.archivedAt?.takeIf { list.isArchived }?.let { formatDate(it, model.user?.timezone ?: "UTC", model.language.locale) }
    val deadline = list.dueAt?.takeIf { !list.isArchived }?.let { deadlineText(it, list.isOverdue, model) }

    ListRow(
        title = list.name,
        subtitle = archivedOn?.let { stringResource(R.string.shopping_archived_on, it) }
            ?: deadline
            ?: stringResource(R.string.shopping_default_list).takeIf { list.isDefault },
        subtitleColor = MaterialTheme.colorScheme.error.takeIf { deadline != null && list.isOverdue },
        showDivider = showDivider,
        onClick = onClick,
        modifier = Modifier.testTag("shopping.list.${list.friendlyId}"),
        trailing = {
            if (list.isDefault) {
                Icon(Icons.Filled.Star, contentDescription = stringResource(R.string.shopping_default_list), tint = MaterialTheme.colorScheme.onSurfaceVariant)
            }

            list.uncheckedItemsCount?.let {
                Text(pluralStringResource(R.plurals.shopping_items_left, it, it), color = MaterialTheme.colorScheme.onSurfaceVariant)
            }

            list.workspace?.takeIf { showsWorkspace }?.let { workspace ->
                WorkspaceDot(workspace, Modifier.padding(start = 8.dp))
            }

            Box {
                IconButton(onClick = { isMenuOpen = true }) {
                    Icon(Icons.Filled.MoreVert, contentDescription = stringResource(R.string.more), tint = MaterialTheme.colorScheme.onSurfaceVariant)
                }

                DropdownMenu(expanded = isMenuOpen, onDismissRequest = { isMenuOpen = false }) {
                    if (!list.isArchived) {
                        DropdownMenuItem(
                            text = { Text(stringResource(if (list.isDefault) R.string.shopping_unset_default else R.string.shopping_make_default)) },
                            onClick = {
                                isMenuOpen = false
                                onToggleDefault()
                            },
                        )
                    }
                    if (list.completedAt == null) {
                        DropdownMenuItem(
                            text = {
                                Text(
                                    stringResource(
                                        when {
                                            list.dueAt != null && !list.isArchived -> R.string.shopping_complete
                                            list.isArchived -> R.string.shopping_unarchive
                                            else -> R.string.shopping_archive
                                        },
                                    ),
                                )
                            },
                            onClick = {
                                isMenuOpen = false
                                onArchive()
                            },
                        )
                    }
                    DropdownMenuItem(
                        text = { Text(stringResource(R.string.delete), color = MaterialTheme.colorScheme.error) },
                        onClick = {
                            isMenuOpen = false
                            onDelete()
                        },
                    )
                }
            }

            Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
        },
    )
}

/**
 * A shopping list in the store (US-8.1, US-8.2), made for one hand: the whole
 * row checks an item (with a haptic), the checked ones sink to the end, and the
 * quick add sits at the bottom, under the thumb. What anyone else checks shows
 * up through the list's Reverb channel; what is checked offline is kept in the
 * queue and sent when the network is back.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ShoppingListScreen(container: AppContainer, id: String, onBack: () -> Unit) {
    val model = container.model
    val store = remember(id) { ShoppingListStore(id, model.client, container.shoppingSync) }
    val state by store.state.collectAsStateWithLifecycle()
    val pendingByList by container.shoppingSync.pendingByList.collectAsStateWithLifecycle()
    // The workspaces the list may move to: with two or more with the shopping module only.
    val workspaceOptions = model.observedUser()?.workspacePickerOptions(ModuleSlug.SHOPPING).orEmpty()
    val rejection by container.shoppingSync.rejection.collectAsStateWithLifecycle()
    val scope = rememberCoroutineScope()
    var isRefreshing by remember { mutableStateOf(false) }

    LaunchedEffect(id) {
        store.reload()
    }

    // What anyone else changes: each `shopping-list.changed` reads the list again (a burst reads it once).
    LaunchedEffect(id) {
        container.realtime.events(ShoppingList.broadcastChannel(id), ShoppingListChangedEvent.NAME)
            .conflate()
            .collect { store.reload() }
    }

    // Back online: what changed while the socket was down is read now.
    LaunchedEffect(id) {
        container.realtime.isConnected.drop(1).filter { it }.collect { store.reload() }
    }

    // The changes made offline were sent (WorkManager, the foreground).
    LaunchedEffect(id) {
        container.shoppingSync.revision.drop(1).collect { store.reload() }
    }

    Box(Modifier.fillMaxSize()) {
        JarvisScreen(
            title = state.list?.name ?: stringResource(R.string.shopping_list),
            onBack = onBack,
            actions = {
                if (state.loadState == ShoppingListStore.LoadState.Loaded) {
                    ShoppingListMenu(store, state, workspaceOptions) { nextId ->
                        // The next list opens in its place; back to the lists when there is none yet.
                        model.router.pop(AppTab.Shopping)
                        nextId?.let { model.router.push(AppTab.Shopping, Destination.ShoppingList(it)) }
                    }
                }
            },
        ) {
            PullToRefreshBox(
                isRefreshing = isRefreshing,
                onRefresh = {
                    scope.launch {
                        isRefreshing = true
                        store.reload()
                        isRefreshing = false
                    }
                },
                modifier = Modifier.fillMaxSize(),
            ) {
                when (state.loadState) {
                    ShoppingListStore.LoadState.Loading -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator()
                    }

                    ShoppingListStore.LoadState.Forbidden -> NoAccessScreen(onRetry = { scope.launch { model.refreshUser() } })
                    ShoppingListStore.LoadState.NotFound -> ContentUnavailable(
                        title = stringResource(R.string.shopping_list_not_found),
                        icon = Icons.Filled.SearchOff,
                        description = stringResource(R.string.shopping_list_not_found_description),
                    )

                    ShoppingListStore.LoadState.Failed -> ContentUnavailable(
                        title = state.loadFailure?.userMessage() ?: stringResource(R.string.error_generic),
                        icon = Icons.Filled.WifiOff,
                        actionLabel = stringResource(R.string.try_again),
                        onAction = { scope.launch { store.reload() } },
                    )

                    ShoppingListStore.LoadState.Loaded -> ShoppingItems(model, store, state, pendingCount = pendingByList[id] ?: 0, showsWorkspace = workspaceOptions.isNotEmpty())
                }
            }
        }

        if (state.loadState == ShoppingListStore.LoadState.Loaded && !state.isArchived) {
            QuickAdd(store, Modifier.align(Alignment.BottomCenter))
        }
    }

    ActionFailureDialog(state.actionFailure, store::dismissActionFailure)
    // A change refused once the network was back (the item was removed, the list archived).
    ActionFailureDialog(rejection, container.shoppingSync::dismissRejection)
}

@Composable
private fun ShoppingItems(model: AppModel, store: ShoppingListStore, state: ShoppingListStore.State, pendingCount: Int, showsWorkspace: Boolean) {
    val scope = rememberCoroutineScope()
    val haptics = LocalHapticFeedback.current
    var itemToEdit by remember { mutableStateOf<ShoppingListItem?>(null) }
    var isConfirmingClear by remember { mutableStateOf(false) }
    val checked = state.checkedItems
    val unchecked = state.uncheckedItems

    val toggle: (ShoppingListItem) -> Unit = { item ->
        haptics.performHapticFeedback(if (item.isChecked) HapticFeedbackType.ToggleOff else HapticFeedbackType.ToggleOn)
        scope.launch { store.toggle(item) }
    }

    GroupedList(bottomPadding = if (state.isArchived) 16.dp else QuickAddHeight + 16.dp) {
        val list = state.list

        if (list?.completedAt != null) {
            item {
                Section {
                    ListRow(
                        title = stringResource(R.string.shopping_completed_on, formatDateTime(list.completedAt, model.user?.timezone ?: "UTC", model.language.locale).orEmpty()),
                        leading = { Icon(Icons.Filled.CheckCircle, null, tint = MaterialTheme.colorScheme.primary) },
                    )
                }
            }
        } else if (state.isArchived) {
            item {
                Section { ListRow(title = stringResource(R.string.shopping_archived_read_only), leading = { Icon(Icons.Outlined.Archive, null) }) }
            }
        } else if (list?.dueAt != null) {
            item {
                Section {
                    val color = if (list.isOverdue) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurfaceVariant

                    ListRow(
                        title = deadlineText(list.dueAt, list.isOverdue, model),
                        modifier = Modifier.testTag("shopping.deadline"),
                        leading = { Icon(Icons.Filled.Autorenew, null, tint = color) },
                        titleContent = { Text(deadlineText(list.dueAt, list.isOverdue, model), style = MaterialTheme.typography.bodyLarge, color = color) },
                    )
                }
            }
        }

        list?.workspace?.takeIf { showsWorkspace }?.let { workspace ->
            item {
                Section {
                    ListRow(
                        title = stringResource(R.string.workspace),
                        modifier = Modifier.testTag("shopping.list.workspace"),
                        trailing = { WorkspaceBadge(workspace) },
                    )
                }
            }
        }

        if (pendingCount > 0) {
            item {
                Section {
                    ListRow(
                        title = stringResource(R.string.shopping_pending_changes, pendingCount),
                        leading = { Icon(Icons.Filled.CloudOff, null, tint = MaterialTheme.colorScheme.onSurfaceVariant) },
                        modifier = Modifier.testTag("shopping.pending"),
                    )
                }
            }
        }

        if (state.items.isEmpty()) {
            item {
                Section { ListRow(title = stringResource(R.string.shopping_list_empty), leading = { Icon(Icons.Outlined.ShoppingCart, null) }) }
            }
        }

        if (unchecked.isNotEmpty()) {
            item {
                Section {
                    ReorderableItems(
                        items = unchecked,
                        isEnabled = !state.isArchived,
                        onReorder = { order -> scope.launch { store.reorderUnchecked(order) } },
                    ) { item, isLast, dragHandle ->
                        ItemRow(item, state.isArchived, !isLast, { toggle(item) }, { itemToEdit = item }, { scope.launch { store.remove(item) } }, dragHandle)
                    }
                }
            }
        }

        if (checked.isNotEmpty()) {
            item {
                Section(header = stringResource(R.string.shopping_checked, checked.size)) {
                    checked.forEachIndexed { index, item ->
                        ItemRow(item, state.isArchived, index < checked.lastIndex, { toggle(item) }, { itemToEdit = item }, { scope.launch { store.remove(item) } })
                    }
                }
            }

            if (!state.isArchived) {
                item {
                    Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) {
                        TextButton(
                            onClick = { isConfirmingClear = true },
                            modifier = Modifier.testTag("shopping.clear-checked"),
                        ) {
                            Text(stringResource(R.string.shopping_clear_checked), color = MaterialTheme.colorScheme.error)
                        }
                    }
                }
            }
        }
    }

    itemToEdit?.let { item ->
        ShoppingItemDialog(item, onDismiss = { itemToEdit = null }) { name, note ->
            scope.launch { store.update(item, name, note) }
        }
    }

    if (isConfirmingClear) {
        ConfirmDialog(
            title = stringResource(R.string.shopping_clear_checked_confirm),
            confirmLabel = stringResource(R.string.shopping_clear_checked),
            onConfirm = { scope.launch { store.clearChecked() } },
            onDismiss = { isConfirmingClear = false },
        )
    }
}

@Composable
private fun ItemRow(
    item: ShoppingListItem,
    isArchived: Boolean,
    showDivider: Boolean,
    onToggle: () -> Unit,
    onEdit: () -> Unit,
    onDelete: () -> Unit,
    dragHandle: Modifier? = null,
) {
    var isMenuOpen by remember { mutableStateOf(false) }
    val itemMenu: @Composable () -> Unit = {
        Box {
            IconButton(onClick = { isMenuOpen = true }) {
                Icon(Icons.Filled.MoreVert, contentDescription = stringResource(R.string.more), tint = MaterialTheme.colorScheme.onSurfaceVariant)
            }

            DropdownMenu(expanded = isMenuOpen, onDismissRequest = { isMenuOpen = false }) {
                DropdownMenuItem(
                    text = { Text(stringResource(R.string.edit)) },
                    onClick = {
                        isMenuOpen = false
                        onEdit()
                    },
                )
                DropdownMenuItem(
                    text = { Text(stringResource(R.string.delete), color = MaterialTheme.colorScheme.error) },
                    onClick = {
                        isMenuOpen = false
                        onDelete()
                    },
                )
            }
        }
    }

    ListRow(
        title = item.name,
        subtitle = when {
            item.isChecked && item.checkedBy != null -> stringResource(R.string.shopping_checked_by, item.checkedBy.name)
            else -> item.quantityNote?.takeIf { it.isNotBlank() }
        },
        showDivider = showDivider,
        onClick = if (isArchived) null else onToggle,
        modifier = Modifier.testTag("shopping.item.${item.friendlyId}"),
        leading = {
            if (item.isChecked) {
                Icon(Icons.Filled.CheckCircle, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
            } else {
                Icon(Icons.Filled.RadioButtonUnchecked, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        },
        titleContent = {
            Text(
                item.name,
                style = MaterialTheme.typography.bodyLarge,
                color = if (item.isChecked) MaterialTheme.colorScheme.onSurfaceVariant else MaterialTheme.colorScheme.onSurface,
                textDecoration = if (item.isChecked) TextDecoration.LineThrough else null,
            )
        },
        trailing = if (isArchived) {
            null
        } else {
            {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    if (dragHandle != null) {
                        Icon(
                            Icons.Filled.DragHandle,
                            contentDescription = stringResource(R.string.shopping_drag_to_reorder, item.name),
                            tint = MaterialTheme.colorScheme.onSurfaceVariant,
                            modifier = dragHandle.padding(12.dp).testTag("shopping.drag.${item.friendlyId}"),
                        )
                    }

                    itemMenu()
                }
            }
        },
    )
}

/**
 * The unchecked items, each dragged into a new place by its handle: the row
 * follows the finger, the others make room as it passes half of them, and the
 * new order (friendly ids) goes to [onReorder] when the finger lifts. The
 * dragged order stays on screen until the store shows the saved one.
 */
@Composable
private fun ReorderableItems(
    items: List<ShoppingListItem>,
    isEnabled: Boolean,
    onReorder: (List<String>) -> Unit,
    row: @Composable (item: ShoppingListItem, isLast: Boolean, dragHandle: Modifier?) -> Unit,
) {
    val haptics = LocalHapticFeedback.current
    val currentItems by rememberUpdatedState(items)
    var order by remember { mutableStateOf<List<ShoppingListItem>?>(null) }
    var draggedId by remember { mutableStateOf<String?>(null) }
    var dragOffset by remember { mutableFloatStateOf(0f) }
    val heights = remember { mutableStateMapOf<String, Int>() }

    // A new state from the store (the saved order, or a change made elsewhere) replaces the dragged one.
    LaunchedEffect(items) {
        if (draggedId == null) {
            order = null
        }
    }

    fun moveDraggedRow() {
        val current = order ?: return
        val index = current.indexOfFirst { it.friendlyId == draggedId }
        val neighbor = if (dragOffset > 0) index + 1 else index - 1

        if (index < 0 || neighbor !in current.indices) {
            return
        }

        val neighborHeight = heights[current[neighbor].friendlyId] ?: return

        if (kotlin.math.abs(dragOffset) > neighborHeight / 2f) {
            order = current.toMutableList().apply { add(neighbor, removeAt(index)) }
            dragOffset += if (dragOffset > 0) -neighborHeight.toFloat() else neighborHeight.toFloat()
            haptics.performHapticFeedback(HapticFeedbackType.TextHandleMove)
        }
    }

    fun endDrag() {
        val dragged = order?.map { it.friendlyId }
        draggedId = null
        dragOffset = 0f

        if (dragged != null && dragged != currentItems.map { it.friendlyId }) {
            onReorder(dragged)
        } else {
            order = null
        }
    }

    val shown = order ?: items

    shown.forEachIndexed { index, item ->
        val isDragged = item.friendlyId == draggedId

        Box(
            Modifier
                .onSizeChanged { heights[item.friendlyId] = it.height }
                .zIndex(if (isDragged) 1f else 0f)
                .graphicsLayer { translationY = if (isDragged) dragOffset else 0f }
                .let { if (isDragged) it.shadow(8.dp) else it },
        ) {
            val dragHandle = if (!isEnabled) {
                null
            } else {
                Modifier.pointerInput(item.friendlyId) {
                    detectDragGestures(
                        onDragStart = {
                            order = order ?: currentItems
                            draggedId = item.friendlyId
                            dragOffset = 0f
                            haptics.performHapticFeedback(HapticFeedbackType.LongPress)
                        },
                        onDrag = { change, amount ->
                            change.consume()
                            dragOffset += amount.y
                            moveDraggedRow()
                        },
                        onDragEnd = ::endDrag,
                        onDragCancel = ::endDrag,
                    )
                }
            }

            row(item, index == shown.lastIndex, dragHandle)
        }
    }
}

@Composable
private fun ShoppingListMenu(
    store: ShoppingListStore,
    state: ShoppingListStore.State,
    workspaceOptions: List<UserWorkspace>,
    onCompleted: (nextId: String?) -> Unit,
) {
    val scope = rememberCoroutineScope()
    var isMenuOpen by remember { mutableStateOf(false) }
    var isRenaming by remember { mutableStateOf(false) }
    var isMoving by remember { mutableStateOf(false) }
    var isConfirmingClear by remember { mutableStateOf(false) }

    Box {
        IconButton(onClick = { isMenuOpen = true }) {
            Icon(Icons.Filled.MoreVert, contentDescription = stringResource(R.string.more))
        }

        DropdownMenu(expanded = isMenuOpen, onDismissRequest = { isMenuOpen = false }) {
            if (!state.isArchived) {
                DropdownMenuItem(
                    text = { Text(stringResource(R.string.rename)) },
                    onClick = {
                        isMenuOpen = false
                        isRenaming = true
                    },
                )
                DropdownMenuItem(
                    text = { Text(stringResource(if (state.isDefault) R.string.shopping_unset_default else R.string.shopping_make_default)) },
                    onClick = {
                        isMenuOpen = false
                        scope.launch { store.setDefault(!state.isDefault) }
                    },
                    modifier = Modifier.testTag("shopping.list.default"),
                )

                if (workspaceOptions.isNotEmpty()) {
                    DropdownMenuItem(
                        text = { Text(stringResource(R.string.workspace_move_to)) },
                        onClick = {
                            isMenuOpen = false
                            isMoving = true
                        },
                        modifier = Modifier.testTag("shopping.list.move"),
                    )
                }
                DropdownMenuItem(
                    text = { Text(stringResource(R.string.shopping_clear_checked)) },
                    enabled = state.checkedItems.isNotEmpty(),
                    onClick = {
                        isMenuOpen = false
                        isConfirmingClear = true
                    },
                )
            }

            if (state.list?.dueAt != null && !state.isArchived) {
                // A list of a recurring one is completed, as on the web: the next one is made.
                DropdownMenuItem(
                    text = { Text(stringResource(R.string.shopping_complete)) },
                    onClick = {
                        isMenuOpen = false
                        scope.launch {
                            val nextId = store.complete()

                            if (store.state.value.actionFailure == null) {
                                onCompleted(nextId)
                            }
                        }
                    },
                    modifier = Modifier.testTag("shopping.complete"),
                )
            } else if (state.list?.completedAt == null) {
                DropdownMenuItem(
                    text = { Text(stringResource(if (state.isArchived) R.string.shopping_unarchive else R.string.shopping_archive)) },
                    onClick = {
                        isMenuOpen = false
                        scope.launch { store.setArchived(!state.isArchived) }
                    },
                )
            }
        }
    }

    if (isRenaming) {
        TextFieldDialog(
            title = stringResource(R.string.shopping_rename_list),
            label = stringResource(R.string.name),
            confirmLabel = stringResource(R.string.save),
            initialValue = state.list?.name.orEmpty(),
            onConfirm = { name -> scope.launch { store.rename(name) } },
            onDismiss = { isRenaming = false },
        )
    }

    if (isConfirmingClear) {
        ConfirmDialog(
            title = stringResource(R.string.shopping_clear_checked_confirm),
            confirmLabel = stringResource(R.string.shopping_clear_checked),
            onConfirm = { scope.launch { store.clearChecked() } },
            onDismiss = { isConfirmingClear = false },
        )
    }

    if (isMoving) {
        MoveToWorkspaceDialog(
            workspaces = workspaceOptions,
            currentId = state.list?.workspace?.id,
            onMove = { workspaceId -> scope.launch { store.moveToWorkspace(workspaceId) } },
            onDismiss = { isMoving = false },
        )
    }
}

/** The user's workspaces with shopping, the list's own checked; a tap moves the list there. */
@Composable
private fun MoveToWorkspaceDialog(workspaces: List<UserWorkspace>, currentId: String?, onMove: (String) -> Unit, onDismiss: () -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.workspace_move_to)) },
        text = {
            Column {
                workspaces.forEach { workspace ->
                    ListRow(
                        title = workspace.name,
                        onClick = {
                            onDismiss()
                            onMove(workspace.id)
                        },
                        modifier = Modifier.testTag("shopping.move.${workspace.id}"),
                        leading = { WorkspaceDot(workspace.colorHex, size = 14.dp) },
                        trailing = {
                            if (workspace.id == currentId) {
                                Icon(Icons.Filled.Check, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
                            }
                        },
                    )
                }
            }
        },
        confirmButton = {},
        dismissButton = {
            TextButton(onClick = onDismiss) { Text(stringResource(R.string.cancel)) }
        },
    )
}

/**
 * A new list for a user in two or more workspaces with shopping: its name and
 * where it goes, the user's default workspace picked at first. A refusal
 * shows under the form.
 */
@Composable
private fun NewShoppingListForm(
    workspaces: List<UserWorkspace>,
    defaultWorkspaceId: String?,
    create: suspend (name: String, workspaceId: String?) -> ApiException?,
    onDismiss: () -> Unit,
) {
    var name by rememberSaveable { mutableStateOf("") }
    var workspaceId by rememberSaveable { mutableStateOf(defaultWorkspaceId ?: workspaces.firstOrNull()?.id) }
    var isSaving by remember { mutableStateOf(false) }
    var failure by remember { mutableStateOf<ApiException?>(null) }
    val scope = rememberCoroutineScope()

    FormSheet(
        title = stringResource(R.string.shopping_new_list),
        confirmLabel = stringResource(R.string.create),
        isConfirmEnabled = name.isNotBlank() && !isSaving,
        onConfirm = {
            isSaving = true
            scope.launch {
                failure = create(name.trim(), workspaceId)
                isSaving = false

                if (failure == null) {
                    onDismiss()
                }
            }
        },
        onDismiss = onDismiss,
    ) {
        item {
            Section {
                SectionTextField(name, { name = it }, stringResource(R.string.name), Modifier.testTag("shopping.new-list.name"))
            }
        }

        item {
            WorkspacePickerSection(workspaces, workspaceId) { workspaceId = it }
        }

        failure?.let { error ->
            item { FormError(error.userMessage()) }
        }
    }
}

/** The height the quick add takes over the list. */
private val QuickAddHeight = 72.dp

/** The quick add, a raised bar under the thumb; the keyboard stays up for the next item. */
@Composable
private fun QuickAdd(store: ShoppingListStore, modifier: Modifier = Modifier) {
    var name by rememberSaveable { mutableStateOf("") }
    var isAdding by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val focusRequester = remember { FocusRequester() }

    val add: () -> Unit = {
        if (name.isNotBlank() && !isAdding) {
            isAdding = true
            scope.launch {
                if (store.addItem(name)) {
                    name = ""
                }

                isAdding = false
                focusRequester.requestFocus()
            }
        }
    }

    Row(
        modifier = modifier
            // Over the navigation bar, or right over the keyboard while it is up.
            .imePadding()
            .padding(horizontal = 16.dp, vertical = 8.dp)
            .widthIn(max = 560.dp)
            .fillMaxWidth()
            .shadow(3.dp, MaterialTheme.shapes.extraLarge)
            .background(MaterialTheme.colorScheme.surfaceContainerHigh, MaterialTheme.shapes.extraLarge)
            .padding(4.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        TextField(
            value = name,
            onValueChange = { name = it },
            placeholder = { Text(stringResource(R.string.shopping_add_item)) },
            singleLine = true,
            keyboardOptions = KeyboardOptions(imeAction = ImeAction.Done),
            keyboardActions = KeyboardActions(onDone = { add() }),
            colors = TextFieldDefaults.colors(
                focusedContainerColor = Color.Transparent,
                unfocusedContainerColor = Color.Transparent,
                focusedIndicatorColor = Color.Transparent,
                unfocusedIndicatorColor = Color.Transparent,
            ),
            modifier = Modifier.weight(1f).focusRequester(focusRequester).testTag("shopping.quick-add"),
        )

        FilledIconButton(onClick = add, enabled = name.isNotBlank() && !isAdding) {
            Icon(Icons.Filled.Add, contentDescription = stringResource(R.string.add))
        }
    }
}

/** Edits an item's name and quantity or note; saved through the offline queue. */
@Composable
private fun ShoppingItemDialog(item: ShoppingListItem, onDismiss: () -> Unit, onSave: (String, String?) -> Unit) {
    var name by rememberSaveable(item.friendlyId) { mutableStateOf(item.name) }
    var note by rememberSaveable(item.friendlyId) { mutableStateOf(item.quantityNote.orEmpty()) }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.shopping_edit_item)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(name, { name = it }, label = { Text(stringResource(R.string.name)) }, singleLine = true)
                OutlinedTextField(note, { note = it }, label = { Text(stringResource(R.string.shopping_quantity_or_note)) }, singleLine = true)
            }
        },
        confirmButton = {
            TextButton(
                enabled = name.isNotBlank(),
                onClick = {
                    onDismiss()
                    onSave(name.trim(), note.trim().ifEmpty { null })
                },
            ) {
                Text(stringResource(R.string.save))
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) { Text(stringResource(R.string.cancel)) }
        },
    )
}

/** "Recurring list, until …", or "Overdue since …" once it passed. */
@Composable
internal fun deadlineText(dueAt: String, isOverdue: Boolean, model: AppModel): String {
    val date = formatDateTime(dueAt, model.user?.timezone ?: "UTC", model.language.locale).orEmpty()

    return stringResource(if (isOverdue) R.string.shopping_overdue_since else R.string.shopping_recurring_until, date)
}

/** The segments of the shopping tab, as the web's tabs. */
enum class ShoppingSection(val filter: ShoppingListFilter?) {
    Active(ShoppingListFilter.Active),
    Recurring(null),
    Archived(ShoppingListFilter.Archived),
}

private val ShoppingSection.title: Int
    get() = when (this) {
        ShoppingSection.Active -> R.string.shopping_lists
        ShoppingSection.Recurring -> R.string.shopping_recurring
        ShoppingSection.Archived -> R.string.shopping_archived_lists
    }
