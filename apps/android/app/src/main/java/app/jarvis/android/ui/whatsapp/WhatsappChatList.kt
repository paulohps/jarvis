package app.jarvis.android.ui.whatsapp

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.Sort
import androidx.compose.material.icons.filled.PushPin
import androidx.compose.material.icons.outlined.Archive
import androidx.compose.material.icons.outlined.NotificationsOff
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.WifiOff
import androidx.compose.material.icons.outlined.ChatBubbleOutline
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.IconButton
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.repeatOnLifecycle
import app.jarvis.android.R
import app.jarvis.android.app.AppModel
import app.jarvis.android.navigation.AppTab
import app.jarvis.android.navigation.Destination
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.WhatsappConversation
import app.jarvis.android.network.WhatsappConversationMessage
import app.jarvis.android.realtime.WhatsappLive
import app.jarvis.android.ui.components.ContentUnavailable
import app.jarvis.android.ui.components.FabClearance
import app.jarvis.android.ui.components.formatTime
import app.jarvis.android.ui.components.userMessage
import app.jarvis.android.ui.shell.NoAccessScreen
import kotlinx.coroutines.FlowPreview
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.debounce
import kotlinx.coroutines.launch

/** How often the list is read again while shown, without Reverb. */
private const val LIST_POLL_MS = 10_000L

/** How often the list is read again while Reverb tells each change. */
private const val LIST_POLL_CONNECTED_MS = 30_000L

/** How long the list waits for a burst of changes to end before reading again. */
private const val UPDATE_DEBOUNCE_MS = 300L

/**
 * The chats of the user's numbers, as on the web: picture, name, the latest
 * message, its time, the unread count, pinned, the priority in the user's
 * color and the tags. Read again as soon as Reverb tells a chat changed
 * ([WhatsappLive.updates]), and every few seconds as a fallback (slower
 * while Reverb is connected); who is typing shows in place of the latest
 * message. Tapping a chat opens it; a long press archives it, marks it
 * unread (or read) and mutes it, as on WhatsApp (muted and marked-unread
 * chats are marked). Chips above sort the highest priority first and narrow
 * the list to one tag; "Archived" on top lists the archived chats.
 */
@OptIn(ExperimentalMaterial3Api::class, FlowPreview::class)
@Composable
fun WhatsappChatList(model: AppModel, live: WhatsappLive, tools: WhatsappChatToolsStore, store: WhatsappConversationsStore, reloadKey: Int) {
    val state by store.state.collectAsState()
    val run = rememberToolRunner()
    var muting by remember { mutableStateOf<WhatsappConversation?>(null) }
    val toolsState by tools.state.collectAsState()
    val scope = rememberCoroutineScope()
    val lifecycle = LocalLifecycleOwner.current.lifecycle
    var query by rememberSaveable { mutableStateOf("") }
    var isRefreshing by remember { mutableStateOf(false) }
    val listState = rememberLazyListState()

    LaunchedEffect(store) {
        lifecycle.repeatOnLifecycle(Lifecycle.State.RESUMED) {
            launch {
                live.updates.debounce(UPDATE_DEBOUNCE_MS).collect { store.refresh() }
            }

            while (true) {
                store.refresh()
                delay(if (live.isConnected.value) LIST_POLL_CONNECTED_MS else LIST_POLL_MS)
            }
        }
    }

    // A tag or a priority color changed: the rows show it.
    LaunchedEffect(reloadKey) {
        if (reloadKey > 0) {
            store.refresh()
        }
    }

    // A deleted tag no longer narrows the list.
    LaunchedEffect(toolsState.tags) {
        val tag = state.tag

        if (tag != null && toolsState.tags.none { it.friendlyId == tag }) {
            store.filterByTag(null)
        }
    }

    LaunchedEffect(query) {
        if (query.trim() != state.search) {
            delay(300)
            store.search(query)
        }
    }

    val isNearEnd by remember {
        derivedStateOf {
            val last = listState.layoutInfo.visibleItemsInfo.lastOrNull()?.index ?: 0
            last >= listState.layoutInfo.totalItemsCount - 5
        }
    }

    LaunchedEffect(isNearEnd, state.hasMore) {
        if (isNearEnd && state.hasMore) {
            store.loadMore()
        }
    }

    BackHandler(enabled = state.isShowingArchived) { scope.launch { store.showArchived(false) } }

    /** A change from a row's menu; the row follows the server's answer (or leaves the list shown). */
    val change: (WhatsappConversation, WhatsappChatChange) -> Unit = { chat, change -> run { store.apply(chat.friendlyId, change) } }

    Column(Modifier.fillMaxSize()) {
        if (state.isShowingArchived) {
            Row(Modifier.fillMaxWidth().padding(end = 16.dp), verticalAlignment = Alignment.CenterVertically) {
                IconButton(onClick = { scope.launch { store.showArchived(false) } }, modifier = Modifier.testTag("whatsapp.chats.archived.back")) {
                    Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = stringResource(R.string.back))
                }
                Text(stringResource(R.string.whatsapp_archived), style = MaterialTheme.typography.titleMedium)
            }
        }

        OutlinedTextField(
            value = query,
            onValueChange = { query = it },
            placeholder = { Text(stringResource(R.string.whatsapp_search_chats)) },
            leadingIcon = { Icon(Icons.Filled.Search, contentDescription = null) },
            singleLine = true,
            shape = CircleShape,
            modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 8.dp).testTag("whatsapp.chats.search"),
        )

        LazyRow(
            contentPadding = PaddingValues(horizontal = 16.dp),
            horizontalArrangement = Arrangement.spacedBy(8.dp),
            modifier = Modifier.fillMaxWidth().padding(bottom = 4.dp),
        ) {
            item(key = "priority") {
                FilterChip(
                    selected = state.isSortedByPriority,
                    onClick = { scope.launch { store.sortByPriority(!state.isSortedByPriority) } },
                    label = { Text(stringResource(R.string.whatsapp_sort_priority)) },
                    leadingIcon = { Icon(Icons.AutoMirrored.Filled.Sort, contentDescription = null, modifier = Modifier.size(18.dp)) },
                    modifier = Modifier.testTag("whatsapp.chats.sort-priority"),
                )
            }

            if (toolsState.tags.isNotEmpty()) {
                item(key = "all") {
                    FilterChip(selected = state.tag == null, onClick = { scope.launch { store.filterByTag(null) } }, label = { Text(stringResource(R.string.whatsapp_all_chats)) })
                }
            }

            items(toolsState.tags, key = { it.friendlyId }) { tag ->
                FilterChip(
                    selected = state.tag == tag.friendlyId,
                    onClick = { scope.launch { store.filterByTag(if (state.tag == tag.friendlyId) null else tag.friendlyId) } },
                    label = { Text(tag.name) },
                    leadingIcon = { Box(Modifier.size(10.dp).background(hexColor(tag.color, MaterialTheme.colorScheme.outline), CircleShape)) },
                    modifier = Modifier.testTag("whatsapp.chats.tag.${tag.friendlyId}"),
                )
            }
        }

        PullToRefreshBox(
            isRefreshing = isRefreshing,
            onRefresh = {
                scope.launch {
                    isRefreshing = true
                    store.refresh()
                    isRefreshing = false
                }
            },
            modifier = Modifier.fillMaxSize(),
        ) {
            val failure = state.loadFailure

            when {
                state.isLoaded && state.chats.isEmpty() && state.isShowingArchived -> ContentUnavailable(
                    title = stringResource(R.string.whatsapp_no_archived),
                    icon = Icons.Outlined.Archive,
                )

                state.isLoaded && state.chats.isEmpty() && state.search.isNotEmpty() -> ContentUnavailable(
                    title = stringResource(R.string.whatsapp_no_chats),
                    icon = Icons.Outlined.ChatBubbleOutline,
                    description = stringResource(R.string.whatsapp_no_chats_description),
                )

                state.isLoaded -> LazyColumn(state = listState, contentPadding = PaddingValues(bottom = FabClearance), modifier = Modifier.fillMaxSize()) {
                    if (!state.isShowingArchived && state.search.isEmpty()) {
                        item(key = "archived") {
                            ArchivedRow { scope.launch { store.showArchived(true) } }
                        }
                    }

                    if (state.chats.isEmpty()) {
                        item(key = "empty") {
                            ContentUnavailable(
                                title = stringResource(R.string.whatsapp_no_chats),
                                icon = Icons.Outlined.ChatBubbleOutline,
                                description = stringResource(R.string.whatsapp_no_chats_description),
                                modifier = Modifier.padding(top = 48.dp),
                            )
                        }
                    }

                    items(state.chats, key = { it.friendlyId }) { chat ->
                        ChatRow(
                            model = model,
                            chat = chat,
                            presence = rememberPresence(live, chat.friendlyId),
                            onArchive = { change(chat, WhatsappChatChange.Archived(!chat.archived)) },
                            onMarkUnread = { run { store.toggleUnread(chat) } },
                            onMute = { if (chat.isMuted) change(chat, WhatsappChatChange.Muted(null)) else muting = chat },
                        ) {
                            model.router.push(AppTab.WhatsApp, Destination.WhatsappChat(chat.friendlyId, chat.name, chat))
                        }
                    }
                }

                failure is ApiException.Forbidden -> NoAccessScreen(onRetry = { scope.launch { model.refreshUser() } })
                failure != null -> ContentUnavailable(
                    title = failure.userMessage(),
                    icon = Icons.Filled.WifiOff,
                    actionLabel = stringResource(R.string.try_again),
                    onAction = { scope.launch { store.refresh() } },
                )

                else -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
            }
        }
    }

    muting?.let { chat ->
        MuteDialog(onPick = { duration ->
            muting = null
            change(chat, WhatsappChatChange.Muted(duration))
        }, onDismiss = { muting = null })
    }
}

/** WhatsApp's "Archived" row on top of the chats. */
@Composable
private fun ArchivedRow(onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().clickable(onClick = onClick).padding(horizontal = 28.dp, vertical = 12.dp).testTag("whatsapp.chats.archived"),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(24.dp),
    ) {
        Icon(Icons.Outlined.Archive, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
        Text(stringResource(R.string.whatsapp_archived), style = MaterialTheme.typography.titleMedium)
    }
}

@Composable
private fun ChatRow(
    model: AppModel,
    chat: WhatsappConversation,
    presence: WhatsappPresenceStatus?,
    onArchive: () -> Unit,
    onMarkUnread: () -> Unit,
    onMute: () -> Unit,
    onClick: () -> Unit,
) {
    val timezone = model.user?.timezone ?: "UTC"
    val locale = model.language.locale
    val hasUnread = chat.unreadCount > 0
    var isMenuOpen by remember { mutableStateOf(false) }

    Box {
        Column(Modifier.fillMaxWidth().combinedClickable(onLongClick = { isMenuOpen = true }, onClick = onClick).testTag("whatsapp.chat.${chat.friendlyId}")) {
            Row(
                Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 10.dp),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                Box {
                    ChatAvatar(chat.name, chat.pictureUrl, chat.isGroup)

                    chat.priority?.let { priority ->
                        Box(
                            Modifier
                                .align(Alignment.BottomEnd)
                                .size(20.dp)
                                .background(MaterialTheme.colorScheme.surface, CircleShape)
                                .padding(2.dp)
                                .background(hexColor(chat.priorityColor, MaterialTheme.colorScheme.primary), CircleShape),
                            contentAlignment = Alignment.Center,
                        ) {
                            Text(priority.toString(), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.surface, fontWeight = FontWeight.Bold)
                        }
                    }
                }

                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text(chat.name, style = MaterialTheme.typography.titleMedium, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))

                        val time = if (isToday(chat.lastMessageAt, timezone)) formatTime(chat.lastMessageAt, timezone, locale) else formatShortDate(chat.lastMessageAt, timezone, locale)

                        time?.let {
                            Text(
                                it,
                                style = MaterialTheme.typography.labelMedium,
                                color = if (chat.looksUnread) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurfaceVariant,
                                fontWeight = if (chat.looksUnread) FontWeight.SemiBold else null,
                            )
                        }
                    }

                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        val activity = presence.takeIf { it.isActivity() }?.let { presenceLabel(it, timezone, model) }

                        Text(
                            activity ?: chat.lastMessage?.let { previewText(it) }.orEmpty(),
                            style = MaterialTheme.typography.bodyMedium,
                            color = if (activity != null) WhatsappGreen else MaterialTheme.colorScheme.onSurfaceVariant,
                            fontWeight = if (activity != null) FontWeight.Medium else null,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                            modifier = Modifier.weight(1f).testTag("whatsapp.chat.${chat.friendlyId}.preview"),
                        )

                        chat.tags.take(3).forEach { tag ->
                            Box(Modifier.size(10.dp).background(hexColor(tag.color, MaterialTheme.colorScheme.outline), CircleShape))
                        }

                        if (chat.isMuted) {
                            Icon(Icons.Outlined.NotificationsOff, contentDescription = stringResource(R.string.whatsapp_muted), tint = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.size(16.dp).testTag("whatsapp.chat.${chat.friendlyId}.muted"))
                        }

                        if (chat.pinned) {
                            Icon(Icons.Filled.PushPin, contentDescription = stringResource(R.string.whatsapp_pinned), tint = MaterialTheme.colorScheme.primary, modifier = Modifier.size(16.dp))
                        }

                        // Marked unread by hand: WhatsApp's dot without a count.
                        if (chat.markedUnread && !hasUnread) {
                            val description = stringResource(R.string.whatsapp_marked_unread)

                            Box(
                                Modifier
                                    .size(14.dp)
                                    .background(MaterialTheme.colorScheme.primary, CircleShape)
                                    .semantics { contentDescription = description }
                                    .testTag("whatsapp.chat.${chat.friendlyId}.marked-unread"),
                            )
                        }

                        if (hasUnread) {
                            val description = pluralStringResource(R.plurals.whatsapp_unread_count, chat.unreadCount, chat.unreadCount)

                            Box(
                                Modifier.widthIn(min = 20.dp).background(MaterialTheme.colorScheme.primary, CircleShape).padding(horizontal = 6.dp, vertical = 1.dp),
                                contentAlignment = Alignment.Center,
                            ) {
                                Text(
                                    if (chat.unreadCount > 99) "99+" else chat.unreadCount.toString(),
                                    style = MaterialTheme.typography.labelSmall,
                                    color = MaterialTheme.colorScheme.onPrimary,
                                    modifier = Modifier.semantics { contentDescription = description },
                                )
                            }
                        }
                    }
                }
            }

            HorizontalDivider(Modifier.padding(start = 76.dp), color = MaterialTheme.colorScheme.outlineVariant)
        }

        DropdownMenu(expanded = isMenuOpen, onDismissRequest = { isMenuOpen = false }, modifier = Modifier.testTag("whatsapp.chat.${chat.friendlyId}.menu")) {
            val item: @Composable (Int, String, () -> Unit) -> Unit = { label, tag, action ->
                DropdownMenuItem(
                    text = { Text(stringResource(label)) },
                    onClick = {
                        isMenuOpen = false
                        action()
                    },
                    modifier = Modifier.testTag("whatsapp.chat.action.$tag"),
                )
            }

            item(if (chat.archived) R.string.whatsapp_unarchive else R.string.whatsapp_archive, "archive", onArchive)
            item(if (chat.looksUnread) R.string.whatsapp_mark_read else R.string.whatsapp_mark_unread, "unread", onMarkUnread)
            item(if (chat.isMuted) R.string.whatsapp_unmute else R.string.whatsapp_mute, "mute", onMute)
        }
    }
}

/** The line under a chat's name: who sent it ("You:", the group member), then the text or the kind of media. */
@Composable
internal fun previewText(message: WhatsappConversationMessage): String {
    val who = when {
        message.fromMe -> stringResource(R.string.whatsapp_you) + " "
        message.sender != null -> message.sender + ": "
        else -> ""
    }

    val text = message.body?.takeIf { it.isNotBlank() }
        ?: (if (message.sticker) stringResource(R.string.whatsapp_sticker) else null)
        ?: message.media?.let { stringResource(mediaLabel(it.kind)) }
        ?: ""

    return who + text
}

/** The green of WhatsApp's "typing…" in the list. */
internal val WhatsappGreen = Color(0xFF00A884)

internal fun mediaLabel(kind: String): Int = when (kind) {
    "image" -> R.string.whatsapp_media_image
    "video" -> R.string.whatsapp_media_video
    "audio" -> R.string.whatsapp_media_audio
    else -> R.string.whatsapp_media_document
}
