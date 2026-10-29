package app.jarvis.android.ui.whatsapp

import android.content.Context
import android.net.Uri
import android.provider.OpenableColumns
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.ui.unit.min
import androidx.compose.runtime.produceState
import androidx.compose.foundation.layout.BoxScope
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.isImeVisible
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.ime
import androidx.compose.foundation.layout.navigationBars
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.union
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.sizeIn
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.Forward
import androidx.compose.material.icons.automirrored.filled.Reply
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.outlined.LocationOn
import androidx.compose.material.icons.outlined.Person
import androidx.compose.material.icons.outlined.Poll
import androidx.compose.material.icons.outlined.StarOutline
import androidx.compose.material.icons.automirrored.filled.Send
import androidx.compose.material.icons.filled.AttachFile
import androidx.compose.material.icons.filled.AutoAwesome
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material.icons.outlined.Alarm
import androidx.compose.material.icons.filled.Block
import androidx.compose.material.icons.filled.Done
import androidx.compose.material.icons.filled.DoneAll
import androidx.compose.material.icons.filled.Download
import androidx.compose.material.icons.filled.Keyboard
import androidx.compose.material.icons.outlined.EmojiEmotions
import androidx.compose.material.icons.filled.ErrorOutline
import androidx.compose.material.icons.filled.PlayCircle
import androidx.compose.material.icons.filled.WifiOff
import androidx.compose.material.icons.outlined.Description
import androidx.compose.material.icons.outlined.Schedule
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.TextField
import androidx.compose.material3.TextFieldDefaults
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
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
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.graphics.luminance
import androidx.compose.ui.layout.Layout
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.platform.LocalSoftwareKeyboardController
import androidx.compose.ui.text.TextRange
import androidx.compose.ui.text.input.TextFieldValue
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import kotlin.math.max
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.repeatOnLifecycle
import app.jarvis.android.R
import app.jarvis.android.app.AppModel
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.WhatsappConversation
import app.jarvis.android.realtime.WhatsappLive
import app.jarvis.android.network.WhatsappConversationMedia
import app.jarvis.android.network.WhatsappConversationMessage
import app.jarvis.android.network.WhatsappDelivery
import app.jarvis.android.network.WhatsappDeliveryStatus
import app.jarvis.android.network.WhatsappGroupMember
import app.jarvis.android.network.WhatsappReceipt
import app.jarvis.android.network.WhatsappStartChat
import app.jarvis.android.navigation.AppTab
import app.jarvis.android.navigation.Destination
import app.jarvis.android.ui.components.ConfirmDialog
import app.jarvis.android.push.NotificationPresenter
import app.jarvis.android.push.OpenWhatsappChat
import android.widget.Toast
import app.jarvis.android.ui.components.ContentUnavailable
import app.jarvis.android.ui.components.formatDate
import app.jarvis.android.ui.components.formatTime
import app.jarvis.android.ui.components.userMessage
import app.jarvis.android.ui.shell.NoAccessScreen
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.filter
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.withTimeoutOrNull
import androidx.compose.runtime.snapshotFlow
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/** How often the open chat is read again, without Reverb. */
private const val CHAT_POLL_MS = 4_000L

/** How often the open chat is read again while Reverb tells each change. */
private const val CHAT_POLL_CONNECTED_MS = 15_000L

/** Files above it are not sent from the phone. */
private const val MAX_UPLOAD_BYTES = 32L * 1024 * 1024

/** The blue of WhatsApp's read ticks. */
private val ReadTicks = Color(0xFF53BDEB)

/** WhatsApp's chat colors, light and dark: the wallpaper, the two bubbles and the send button. */
private class ChatColors(val wallpaper: Color, val mine: Color, val theirs: Color, val bar: Color, val send: Color, val meta: Color)

private val LightChat = ChatColors(Color(0xFFEFEAE2), Color(0xFFD9FDD3), Color(0xFFFFFFFF), Color(0xFFFFFFFF), Color(0xFF00A884), Color(0xFF667781))

private val DarkChat = ChatColors(Color(0xFF0B141A), Color(0xFF005C4B), Color(0xFF202C33), Color(0xFF1F2C34), Color(0xFF00A884), Color(0xFF8696A0))

@Composable
private fun chatColors(): ChatColors = if (MaterialTheme.colorScheme.surface.luminance() < 0.5f) DarkChat else LightChat

/**
 * One chat, laid out as WhatsApp on the phone (full screen, without the tab
 * bar: the header with the picture, the wallpaper, the bubbles with the time
 * and ticks inside, the composer pill), as on the web: the messages (group senders, ticks, deleted ones
 * marked, the media), the day between them, older pages on scrolling up,
 * what Jarvis is still sending, and the composer (text or a file from the
 * phone). Read again as soon as Reverb tells it changed ([WhatsappLive.updates])
 * and every few seconds as a fallback (slower while Reverb is connected);
 * who is typing, recording or online shows under the name. The chat is marked read
 * whenever a newer message is on screen, and "typing…" is told while typing
 * (both reach WhatsApp only as the chat's settings say). The menu holds the
 * chat tools of the web (pin, priority, nickname, tags, the AI draft,
 * scheduling, a reminder, syncing, the chat's settings); a long press picks
 * messages for the AI draft, a reminder, forwarding, and with one picked
 * answering it, starring it, its info, editing it or deleting it for
 * everyone. The header opens the chat's info (the contact or the group, the
 * search, the starred messages, the gallery); a quoted message, or a result
 * found there, scrolls the chat to it and flashes it.
 */
@Composable
fun WhatsappChatScreen(model: AppModel, live: WhatsappLive, chatId: String, title: String, chat: WhatsappConversation?, onBack: () -> Unit) {
    val store = remember(chatId) { WhatsappChatStore(chatId, model.client) }
    var header by remember(chatId) { mutableStateOf(chat) }
    val colors = chatColors()
    val state by store.state.collectAsState()
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val lifecycle = LocalLifecycleOwner.current.lifecycle
    val listState = rememberLazyListState()
    val timezone = model.user?.timezone ?: "UTC"
    val locale = model.language.locale
    var text by rememberSaveable(chatId) { mutableStateOf("") }
    var localFailure by remember { mutableStateOf<String?>(null) }
    var draftFailure by remember { mutableStateOf<ApiException?>(null) }
    val tooBig = stringResource(R.string.whatsapp_file_too_big)
    val tools = remember { WhatsappChatToolsStore(model.client) }
    val toolsState by tools.state.collectAsState()
    var tool by remember { mutableStateOf<WhatsappChatTool?>(null) }
    var isDrafting by remember { mutableStateOf(false) }
    val run = rememberToolRunner()
    /** The message whose reaction is picked from every emoji. */
    var reactingTo by remember { mutableStateOf<String?>(null) }
    var isForwarding by remember { mutableStateOf(false) }
    var editing by remember { mutableStateOf<WhatsappConversationMessage?>(null) }
    var infoFor by remember { mutableStateOf<String?>(null) }
    var deleting by remember { mutableStateOf<WhatsappConversationMessage?>(null) }
    var attachment by remember { mutableStateOf<WhatsappAttachment?>(null) }
    /** The message the chat scrolled to, flashed for a moment. */
    var highlighted by remember { mutableStateOf<String?>(null) }
    val notLoaded = stringResource(R.string.whatsapp_message_not_loaded)

    /** The chat scrolled to a message, reading older pages to reach it; it flashes once there. */
    val scrollTo: (String) -> Unit = { messageId ->
        scope.launch {
            if (!store.reveal(messageId)) {
                Toast.makeText(context, notLoaded, Toast.LENGTH_SHORT).show()

                return@launch
            }

            val current = store.state.value
            val days = current.messages.map { chatDay(it.sentAt, timezone) }
            messageListIndex(current.outgoing.size, current.messages.map { it.friendlyId }, dayStarts(days), messageId)?.let { index ->
                // The older pages just read reach the list on the next frames.
                withTimeoutOrNull(REVEAL_LAYOUT_MS) { snapshotFlow { listState.layoutInfo.totalItemsCount }.first { it > index } }
                listState.animateScrollToItem(index)
            }
            highlighted = messageId
        }
    }

    LaunchedEffect(highlighted) {
        if (highlighted != null) {
            delay(HIGHLIGHT_MS)
            highlighted = null
        }
    }

    /** The chat with a number of a contact card, on the same number; it opens once started. */
    val messageContact: (String) -> Unit = { number ->
        header?.account?.friendlyId?.let { accountId ->
            run {
                val started = model.client.call { startWhatsappConversation(WhatsappStartChat(accountId, number)) }.data
                model.router.push(AppTab.WhatsApp, Destination.WhatsappChat(started.friendlyId, started.name, started))
            }
        }
    }

    /** The user's reaction to a message (the same one again takes it back); the picking ends. */
    val react: (String, String) -> Unit = { messageId, emoji ->
        store.clearSelection()
        reactingTo = null
        run { store.react(messageId, emoji) }
    }

    // The chat with its settings and tags (opened from a push, the header comes from here).
    LaunchedEffect(chatId) {
        header = store.loadDetail() ?: header ?: store.findConversation()
        tools.load()
    }

    // The members of a group, for the mentions.
    LaunchedEffect(header?.isGroup) {
        if (header?.isGroup == true) {
            store.loadMembers()
        }
    }

    /**
     * The AI's answer to the picked messages, or to what the contact sent
     * since the user's last message, into the composer; with a prompt, it
     * says what the prompt asks.
     */
    val draft: (String?, Boolean) -> Unit = { instruction, withoutContext ->
        scope.launch {
            isDrafting = true
            draftFailure = null

            try {
                text = store.draft(instruction, withoutContext)
            } catch (exception: ApiException) {
                draftFailure = exception
            } finally {
                isDrafting = false
            }
        }
    }

    /** A change to the chat; the header follows what the server keeps. */
    val change: (WhatsappChatChange) -> Unit = { change ->
        run {
            header = store.apply(change)
            tool = null
        }
    }

    LaunchedEffect(store) {
        lifecycle.repeatOnLifecycle(Lifecycle.State.RESUMED) {
            // No push for the chat on screen, and its notifications go away.
            OpenWhatsappChat.chatId = chatId
            NotificationPresenter(context).dismiss(OpenWhatsappChat.thread(chatId))

            val changes = launch {
                live.updates.filter { it == chatId }.collect {
                    store.refresh()
                    store.markRead()
                }
            }

            try {
                while (true) {
                    store.refresh()
                    store.markRead()
                    delay(if (live.isConnected.value) CHAT_POLL_CONNECTED_MS else CHAT_POLL_MS)
                }
            } finally {
                changes.cancel()
                if (OpenWhatsappChat.chatId == chatId) {
                    OpenWhatsappChat.chatId = null
                }
            }
        }
    }

    // A new message at the bottom follows into view when the user was at the bottom.
    val newest = state.messages.firstOrNull()?.friendlyId
    LaunchedEffect(newest, state.outgoing.size) {
        if (listState.firstVisibleItemIndex <= 2) {
            listState.animateScrollToItem(0)
        }
    }

    val isNearTop by remember {
        derivedStateOf {
            val last = listState.layoutInfo.visibleItemsInfo.lastOrNull()?.index ?: 0
            last >= listState.layoutInfo.totalItemsCount - 5
        }
    }

    LaunchedEffect(isNearTop, state.hasMore) {
        if (isNearTop && state.hasMore) {
            store.loadOlder()
        }
    }

    val picker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri ->
        if (uri == null) {
            return@rememberLauncherForActivityResult
        }

        scope.launch {
            val file = withContext(Dispatchers.IO) { readPicked(context, uri) }

            if (file == null) {
                localFailure = tooBig

                return@launch
            }

            if (store.sendFile(file.name, file.mime, file.bytes, text)) {
                text = ""
            }
        }
    }

    val stickerTooBig = stringResource(R.string.whatsapp_sticker_too_big)
    val stickerPicker = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri ->
        uri ?: return@rememberLauncherForActivityResult

        scope.launch {
            val file = withContext(Dispatchers.IO) { readPicked(context, uri, WhatsappChatStore.MAX_STICKER_BYTES) }

            if (file == null || file.mime !in STICKER_MIMES) {
                localFailure = stickerTooBig

                return@launch
            }

            store.sendSticker(file.name, file.mime, file.bytes)
        }
    }

    val presence = presenceLabel(rememberPresence(live, chatId), timezone, model)

    Column(Modifier.fillMaxSize().background(colors.wallpaper)) {
        if (state.isSelecting) {
            SelectionHeader(
                count = state.selected.size,
                message = state.selectedMessage?.takeIf { !it.revoked },
                isDrafting = isDrafting,
                background = colors.bar,
                onClear = store::clearSelection,
                onDraft = { draft(null, false) },
                onDraftWithPrompt = { tool = WhatsappChatTool.DraftPrompt },
                onRemind = { tool = WhatsappChatTool.Reminder },
                canForward = state.canForward,
                onForward = { isForwarding = true },
                onReply = store::startReply,
                onStar = { message -> run { store.star(message.friendlyId, !message.starred) } },
                onEdit = { editing = it },
                onInfo = {
                    infoFor = it.friendlyId
                    store.clearSelection()
                },
                onDelete = { deleting = it },
            )
        } else {
            ChatHeader(
                name = header?.name ?: title,
                subtitle = presence ?: header?.let { if (it.isGroup) stringResource(R.string.whatsapp_group) else it.phoneNumber?.takeIf { phone -> phone != it.name } },
                pictureUrl = header?.pictureUrl,
                isGroup = header?.isGroup ?: false,
                background = colors.bar,
                onBack = onBack,
                onTitleClick = { tool = WhatsappChatTool.Info },
            ) {
                if (isDrafting) {
                    CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp)
                }

                ChatMenu(
                    chat = header,
                    onPick = { picked ->
                        if (picked == WhatsappChatTool.Details) {
                            scope.launch { store.loadDetail() }
                        }

                        tool = picked
                    },
                    onPin = { header?.let { change(WhatsappChatChange.Pinned(!it.pinned)) } },
                    onDraft = { draft(null, false) },
                    onSync = {
                        run {
                            val added = store.sync()
                            Toast.makeText(context, context.getString(R.string.whatsapp_synced, added), Toast.LENGTH_SHORT).show()
                        }
                    },
                )
            }
        }

        val failure = state.loadFailure

        when {
            state.isLoaded -> Box(Modifier.weight(1f).fillMaxWidth()) {
                LazyColumn(
                    state = listState,
                    reverseLayout = true,
                    contentPadding = PaddingValues(horizontal = 8.dp, vertical = 8.dp),
                    verticalArrangement = Arrangement.spacedBy(2.dp),
                    modifier = Modifier.fillMaxSize(),
                ) {
                    state.outgoing.asReversed().forEach { delivery ->
                        item(key = "outgoing-${delivery.createdAt}-${delivery.body.hashCode()}") { OutgoingBubble(delivery, timezone, locale, colors) }
                    }

                    val days = state.messages.map { chatDay(it.sentAt, timezone) }
                    val starts = dayStarts(days)

                    state.messages.forEachIndexed { index, message ->
                        item(key = message.friendlyId) {
                            val isSelected = message.friendlyId in state.selected

                            Box(
                                Modifier
                                    .fillMaxWidth()
                                    .background(
                                        when {
                                            isSelected -> colors.send.copy(alpha = 0.18f)
                                            message.friendlyId == highlighted -> colors.send.copy(alpha = 0.3f)
                                            else -> Color.Transparent
                                        },
                                        RoundedCornerShape(6.dp),
                                    )
                                    .combinedClickable(
                                        interactionSource = null,
                                        indication = null,
                                        onLongClick = { store.toggleSelection(message.friendlyId) },
                                        onClick = { if (state.isSelecting) store.toggleSelection(message.friendlyId) },
                                    )
                                    .testTag("whatsapp.message.${message.friendlyId}"),
                            ) {
                                Column {
                                    MessageBubble(
                                        message,
                                        timezone,
                                        locale,
                                        colors,
                                        onMediaFailure = { scope.launch { store.refresh() } },
                                        onQuote = scrollTo,
                                        onContactMessage = messageContact,
                                    )

                                    if (message.reactions.isNotEmpty()) {
                                        ReactionChips(message.reactions, message.fromMe, if (message.fromMe) colors.mine else colors.theirs) {
                                            react(message.friendlyId, message.myReaction.orEmpty())
                                        }
                                    }
                                }
                            }
                        }

                        if (index in starts) {
                            item(key = "day-${message.friendlyId}") { DaySeparator(message.sentAt, timezone, locale) }
                        }
                    }

                    if (state.isLoadingOlder) {
                        item(key = "older") {
                            Box(Modifier.fillMaxWidth().padding(8.dp), contentAlignment = Alignment.Center) {
                                CircularProgressIndicator(Modifier.size(24.dp), strokeWidth = 2.dp)
                            }
                        }
                    }
                }

                // One message picked (and not deleted): WhatsApp's reaction bar over the chat.
                state.selected.singleOrNull()?.let { id -> state.messages.firstOrNull { it.friendlyId == id } }?.takeIf { !it.revoked }?.let { message ->
                    ReactionBar(
                        mine = message.myReaction,
                        onReact = { emoji -> react(message.friendlyId, emoji) },
                        onMore = { reactingTo = message.friendlyId },
                        modifier = Modifier.align(Alignment.TopCenter).padding(top = 8.dp),
                    )
                }
            }

            failure is ApiException.Forbidden -> NoAccessScreen(onRetry = { scope.launch { model.refreshUser() } })
            failure is ApiException.NotFound -> ContentUnavailable(title = stringResource(R.string.whatsapp_chat_not_found), icon = Icons.Filled.Block)
            failure != null -> ContentUnavailable(
                title = failure.userMessage(),
                icon = Icons.Filled.WifiOff,
                actionLabel = stringResource(R.string.try_again),
                onAction = { scope.launch { store.refresh() } },
            )

            else -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
        }

        if (state.isLoaded) {
            Composer(
                colors = colors,
                text = text,
                isSending = state.isSending,
                failure = localFailure ?: draftFailure?.userMessage() ?: state.sendFailure?.userMessage(),
                replyingTo = state.replyingTo,
                members = state.members,
                onCancelReply = store::cancelReply,
                onTextChange = {
                    text = it
                    localFailure = null
                    draftFailure = null
                    store.clearSendFailure()

                    if (it.isNotBlank()) {
                        scope.launch { store.typing() }
                    }
                },
                onAttach = { picked ->
                    when (picked) {
                        WhatsappAttachment.File -> picker.launch("*/*")
                        WhatsappAttachment.Sticker -> stickerPicker.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly))
                        else -> attachment = picked
                    }
                },
                onSend = {
                    scope.launch {
                        if (store.send(text)) {
                            text = ""
                        }
                    }
                },
            )
        }
    }

    reactingTo?.let { id -> EmojiPickerSheet(onPick = { emoji -> react(id, emoji) }, onDismiss = { reactingTo = null }) }

    editing?.let { message ->
        EditMessageDialog(message, onSave = { edited -> store.edit(message.friendlyId, edited) }, onDismiss = { editing = null })
    }

    infoFor?.let { id ->
        MessageInfoDialog(load = { store.messageInfo(id) }, timezone = timezone, locale = locale, onDismiss = { infoFor = null })
    }

    deleting?.let { message ->
        ConfirmDialog(
            title = stringResource(R.string.whatsapp_delete_for_everyone_confirm),
            message = stringResource(R.string.whatsapp_delete_for_everyone_message),
            confirmLabel = stringResource(R.string.whatsapp_delete_for_everyone),
            onConfirm = { run { store.deleteForEveryone(message.friendlyId) } },
            onDismiss = { deleting = null },
        )
    }

    val closeAttachment = { attachment = null }

    /** A send from a sheet: its refusal is thrown for the sheet to show, not left under the composer. */
    val sendOrThrow: suspend (suspend () -> Boolean) -> Unit = { send ->
        if (!send()) {
            store.state.value.sendFailure?.let { failure ->
                store.clearSendFailure()
                throw failure
            }
        }
    }

    when (attachment) {
        WhatsappAttachment.Poll -> PollSheet(
            onSend = { question, options, multiple -> sendOrThrow { store.sendPoll(question, options, multiple) } },
            onDismiss = closeAttachment,
        )

        WhatsappAttachment.Location -> LocationSheet(
            onSend = { latitude, longitude, name, address -> sendOrThrow { store.sendLocation(latitude, longitude, name, address) } },
            onDismiss = closeAttachment,
        )

        WhatsappAttachment.Contact -> ContactPickerSheet(
            client = model.client,
            accountId = header?.account?.friendlyId,
            title = stringResource(R.string.whatsapp_send_contacts),
            confirmLabel = stringResource(R.string.whatsapp_send),
            max = MAX_CONTACT_CARDS,
            onConfirm = { picked ->
                sendOrThrow { store.sendContacts(picked.map { it.friendlyId }) }
                closeAttachment()
            },
            onDismiss = closeAttachment,
        )

        WhatsappAttachment.File, WhatsappAttachment.Sticker, null -> Unit
    }

    if (isForwarding) {
        ForwardSheet(
            client = model.client,
            accountId = header?.account?.friendlyId,
            count = state.selected.size,
            onForward = { to ->
                store.forward(to)
                isForwarding = false
                Toast.makeText(context, context.getString(R.string.whatsapp_forwarded, to.size), Toast.LENGTH_SHORT).show()
            },
            onDismiss = { isForwarding = false },
        )
    }

    val current = header
    val dismiss = { tool = null }

    when (val picked = tool) {
        null, WhatsappChatTool.NewChat, WhatsappChatTool.NewGroup -> Unit
        WhatsappChatTool.Info -> current?.let {
            ChatInfoSheet(
                model = model,
                chat = it,
                onChange = change,
                onOpenMessage = { id ->
                    tool = null
                    scrollTo(id)
                },
                onLeft = { left ->
                    header = left
                    tool = null
                    Toast.makeText(context, context.getString(R.string.whatsapp_left_group), Toast.LENGTH_SHORT).show()
                },
                onDetails = {
                    scope.launch { store.loadDetail() }
                    tool = WhatsappChatTool.Details
                },
                onDismiss = {
                    tool = null
                    // The subject, description or picture may have changed there.
                    scope.launch { store.loadDetail()?.let { fresh -> header = fresh } }
                },
            )
        }

        WhatsappChatTool.Details -> ChatDetailsSheet(model, state.detail, onCancelScheduled = { id -> run { store.cancelScheduled(id) } }, onDismiss = dismiss)
        WhatsappChatTool.Priority -> PriorityDialog(
            current = current?.priority,
            colors = toolsState.priorityColors,
            onPick = { change(WhatsappChatChange.Priority(it)) },
            onDismiss = dismiss,
        )

        WhatsappChatTool.Nickname -> current?.let { NicknameDialog(it, onSave = { nickname -> change(WhatsappChatChange.Nickname(nickname)) }, onDismiss = dismiss) }
        WhatsappChatTool.Tags -> current?.let {
            ChatTagsDialog(toolsState.tags, it, onSave = { ids -> change(WhatsappChatChange.Tags(ids)) }, onManage = { tool = WhatsappChatTool.ManageTags }, onDismiss = dismiss)
        }

        WhatsappChatTool.ManageTags -> ManageTagsSheet(tools) {
            tool = WhatsappChatTool.Tags
            scope.launch { store.loadDetail()?.let { header = it } }
        }

        WhatsappChatTool.Settings -> current?.let {
            ChatSettingsSheet(
                chat = it,
                account = toolsState.accounts.firstOrNull { account -> account.friendlyId == it.account.friendlyId },
                onSave = change,
                onNumberDefaults = { tool = WhatsappChatTool.NumberDefaults(it.account.friendlyId) },
                onDismiss = dismiss,
            )
        }

        is WhatsappChatTool.NumberDefaults -> NumberDefaultsSheet(
            tools,
            picked.accountId,
            onSaved = { scope.launch { store.loadDetail()?.let { header = it } } },
            onDismiss = dismiss,
        )

        WhatsappChatTool.DraftPrompt -> DraftPromptDialog(
            hasSelection = state.selected.isNotEmpty(),
            onGenerate = { prompt, withoutContext ->
                tool = null
                draft(prompt, withoutContext)
            },
            onDismiss = dismiss,
        )

        WhatsappChatTool.Schedule -> ScheduleMessageSheet(
            model = model,
            initialText = text,
            onSave = { body, at, file ->
                if (file == null) {
                    store.schedule(body, at)
                } else {
                    store.scheduleFile(file.name, file.mime, file.bytes, body, at)
                }
                text = ""
                Toast.makeText(context, context.getString(R.string.whatsapp_scheduled_saved), Toast.LENGTH_SHORT).show()
            },
            onDismiss = dismiss,
        )

        WhatsappChatTool.Reminder -> {
            val about = state.selected.singleOrNull()?.let { id -> state.messages.firstOrNull { it.friendlyId == id } }

            TextAtSheet(
                model = model,
                title = stringResource(R.string.whatsapp_add_reminder),
                confirmLabel = stringResource(R.string.save),
                placeholder = stringResource(R.string.whatsapp_reminder_title),
                initialText = about?.body?.take(REMINDER_TITLE_LENGTH).orEmpty(),
                caption = when {
                    state.selected.size > 1 -> stringResource(R.string.whatsapp_reminder_about_messages, state.selected.size)
                    about != null -> stringResource(R.string.whatsapp_reminder_about_message)
                    else -> null
                },
                isMultiline = false,
                onSave = { reminderTitle, at ->
                    store.remind(reminderTitle, at)
                    Toast.makeText(context, context.getString(R.string.whatsapp_reminder_saved), Toast.LENGTH_SHORT).show()
                },
                onDismiss = dismiss,
            )
        }
    }
}

/** How much of a picked message a reminder's title starts with. */
private const val REMINDER_TITLE_LENGTH = 80

/** How long the scroll to a message waits for the pages just read to be laid out. */
private const val REVEAL_LAYOUT_MS = 1_000L

/** How long a message the chat scrolled to stays flashed. */
private const val HIGHLIGHT_MS = 1_600L

/** The images the server takes as a sticker. */
private val STICKER_MIMES = setOf("image/webp", "image/png", "image/jpeg")

/**
 * Where [messageId] is in the chat's list, laid out bottom up: what Jarvis
 * is still sending first, then each message followed by its day when a
 * day starts there ([dayStarts]); null when it is not loaded.
 */
internal fun messageListIndex(outgoingCount: Int, messageIds: List<String>, dayStarts: Set<Int>, messageId: String): Int? {
    val position = messageIds.indexOf(messageId).takeIf { it >= 0 } ?: return null

    return outgoingCount + position + dayStarts.count { it < position }
}

/**
 * The top while messages are picked, as WhatsApp's: how many; with one
 * picked, answering it and starring it; the AI draft (with a prompt or not),
 * forwarding them (only when every one can be sent again), and in "more" a
 * reminder about them and, for one picked, its info, editing it and
 * deleting it for everyone (when WhatsApp still lets the number).
 */
@Composable
private fun SelectionHeader(
    count: Int,
    message: WhatsappConversationMessage?,
    isDrafting: Boolean,
    background: Color,
    onClear: () -> Unit,
    onDraft: () -> Unit,
    onDraftWithPrompt: () -> Unit,
    onRemind: () -> Unit,
    canForward: Boolean,
    onForward: () -> Unit,
    onReply: (WhatsappConversationMessage) -> Unit,
    onStar: (WhatsappConversationMessage) -> Unit,
    onEdit: (WhatsappConversationMessage) -> Unit,
    onInfo: (WhatsappConversationMessage) -> Unit,
    onDelete: (WhatsappConversationMessage) -> Unit,
) {
    var isDraftMenuOpen by remember { mutableStateOf(false) }
    var isMoreOpen by remember { mutableStateOf(false) }

    Surface(color = background, shadowElevation = 2.dp) {
        Row(
            Modifier.fillMaxWidth().statusBarsPadding().height(60.dp).padding(end = 4.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            IconButton(onClick = onClear, modifier = Modifier.testTag("whatsapp.selection.clear")) {
                Icon(Icons.Filled.Close, contentDescription = stringResource(R.string.whatsapp_clear_selection))
            }

            Text(stringResource(R.string.whatsapp_selected_count, count), style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f))

            if (message != null) {
                IconButton(onClick = { onReply(message) }, modifier = Modifier.testTag("whatsapp.selection.reply")) {
                    Icon(Icons.AutoMirrored.Filled.Reply, contentDescription = stringResource(R.string.whatsapp_reply))
                }

                IconButton(onClick = { onStar(message) }, modifier = Modifier.testTag("whatsapp.selection.star")) {
                    Icon(
                        if (message.starred) Icons.Outlined.StarOutline else Icons.Filled.Star,
                        contentDescription = stringResource(if (message.starred) R.string.whatsapp_unstar else R.string.whatsapp_star),
                    )
                }
            }

            if (isDrafting) {
                CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp)
            } else {
                Box {
                    IconButton(onClick = { isDraftMenuOpen = true }, modifier = Modifier.testTag("whatsapp.selection.draft")) {
                        Icon(Icons.Filled.AutoAwesome, contentDescription = stringResource(R.string.whatsapp_ai_draft))
                    }

                    DropdownMenu(expanded = isDraftMenuOpen, onDismissRequest = { isDraftMenuOpen = false }) {
                        DraftMenuItems(
                            onDraft = {
                                isDraftMenuOpen = false
                                onDraft()
                            },
                            onDraftWithPrompt = {
                                isDraftMenuOpen = false
                                onDraftWithPrompt()
                            },
                        )
                    }
                }
            }

            IconButton(onClick = onForward, enabled = canForward, modifier = Modifier.testTag("whatsapp.selection.forward")) {
                Icon(Icons.AutoMirrored.Filled.Forward, contentDescription = stringResource(R.string.whatsapp_forward))
            }

            Box {
                IconButton(onClick = { isMoreOpen = true }, modifier = Modifier.testTag("whatsapp.selection.more")) {
                    Icon(Icons.Filled.MoreVert, contentDescription = stringResource(R.string.more))
                }

                DropdownMenu(expanded = isMoreOpen, onDismissRequest = { isMoreOpen = false }) {
                    val item: @Composable (Int, String, Boolean, () -> Unit) -> Unit = { label, tag, isDestructive, action ->
                        DropdownMenuItem(
                            text = { Text(stringResource(label), color = if (isDestructive) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurface) },
                            onClick = {
                                isMoreOpen = false
                                action()
                            },
                            modifier = Modifier.testTag("whatsapp.selection.$tag"),
                        )
                    }

                    item(R.string.whatsapp_add_reminder, "remind", false, onRemind)

                    if (message != null) {
                        if (message.fromMe) {
                            item(R.string.whatsapp_message_info, "info", false) { onInfo(message) }
                        }

                        if (message.canEdit) {
                            item(R.string.whatsapp_edit_message, "edit", false) { onEdit(message) }
                        }

                        if (message.canDeleteForEveryone) {
                            item(R.string.whatsapp_delete_for_everyone, "delete", true) { onDelete(message) }
                        }
                    }
                }
            }
        }
    }
}

/** The chat's menu, as the web's chat actions. */
@Composable
private fun ChatMenu(chat: WhatsappConversation?, onPick: (WhatsappChatTool) -> Unit, onPin: () -> Unit, onDraft: () -> Unit, onSync: () -> Unit) {
    var isOpen by remember { mutableStateOf(false) }

    Box {
        IconButton(onClick = { isOpen = true }, modifier = Modifier.testTag("whatsapp.chat.menu")) {
            Icon(Icons.Filled.MoreVert, contentDescription = stringResource(R.string.whatsapp_chat_options))
        }

        DropdownMenu(expanded = isOpen, onDismissRequest = { isOpen = false }) {
            val item: @Composable (Int, () -> Unit) -> Unit = { label, action ->
                DropdownMenuItem(
                    text = { Text(stringResource(label)) },
                    onClick = {
                        isOpen = false
                        action()
                    },
                )
            }

            item(R.string.whatsapp_chat_details) { onPick(WhatsappChatTool.Details) }

            if (chat != null) {
                item(if (chat.pinned) R.string.whatsapp_unpin else R.string.whatsapp_pin, onPin)
                item(R.string.whatsapp_sort_priority) { onPick(WhatsappChatTool.Priority) }
                item(R.string.whatsapp_nickname) { onPick(WhatsappChatTool.Nickname) }
                item(R.string.whatsapp_tags) { onPick(WhatsappChatTool.Tags) }
            }

            HorizontalDivider()
            item(R.string.whatsapp_draft_reply, onDraft)
            item(R.string.whatsapp_draft_with_prompt) { onPick(WhatsappChatTool.DraftPrompt) }
            item(R.string.whatsapp_schedule_message) { onPick(WhatsappChatTool.Schedule) }
            item(R.string.whatsapp_add_reminder) { onPick(WhatsappChatTool.Reminder) }
            item(R.string.whatsapp_sync, onSync)
            HorizontalDivider()

            if (chat != null) {
                item(R.string.whatsapp_chat_settings) { onPick(WhatsappChatTool.Settings) }
            }

            item(R.string.whatsapp_manage_tags) { onPick(WhatsappChatTool.ManageTags) }
        }
    }
}

/** The two ways of the AI draft: straight away, or saying first what it must say. */
@Composable
private fun DraftMenuItems(onDraft: () -> Unit, onDraftWithPrompt: () -> Unit) {
    DropdownMenuItem(text = { Text(stringResource(R.string.whatsapp_draft_reply)) }, onClick = onDraft, modifier = Modifier.testTag("whatsapp.draft.plain"))
    DropdownMenuItem(text = { Text(stringResource(R.string.whatsapp_draft_with_prompt)) }, onClick = onDraftWithPrompt, modifier = Modifier.testTag("whatsapp.draft.with-prompt"))
}

@Composable
private fun DaySeparator(sentAt: String, timezone: String, locale: java.util.Locale) {
    val day = chatDay(sentAt, timezone)
    val today = today(timezone)
    val label = when (day) {
        today -> stringResource(R.string.whatsapp_today)
        today.minusDays(1) -> stringResource(R.string.whatsapp_yesterday)
        else -> formatDate(sentAt, timezone, locale).orEmpty()
    }

    Box(Modifier.fillMaxWidth().padding(vertical = 8.dp), contentAlignment = Alignment.Center) {
        Text(
            label,
            style = MaterialTheme.typography.labelMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.background(MaterialTheme.colorScheme.surfaceContainerHigh, CircleShape).padding(horizontal = 12.dp, vertical = 4.dp),
        )
    }
}

/** The top of the chat, as WhatsApp's: back, the picture, the name and what it is. */
@Composable
private fun ChatHeader(
    name: String,
    subtitle: String?,
    pictureUrl: String?,
    isGroup: Boolean,
    background: Color,
    onBack: () -> Unit,
    onTitleClick: () -> Unit,
    actions: @Composable RowScope.() -> Unit,
) {
    Surface(color = background, shadowElevation = 2.dp) {
        Row(
            Modifier.fillMaxWidth().statusBarsPadding().height(60.dp).padding(end = 4.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            IconButton(onClick = onBack, modifier = Modifier.testTag("whatsapp.chat.back")) {
                Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = stringResource(R.string.back))
            }

            Row(Modifier.weight(1f).clickable(onClick = onTitleClick).testTag("whatsapp.chat.title"), verticalAlignment = Alignment.CenterVertically) {
                ChatAvatar(name, pictureUrl, isGroup, size = 40.dp)

                Column(Modifier.weight(1f).padding(start = 12.dp)) {
                    Text(name, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    subtitle?.let {
                        Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                }
            }

            actions()
        }
    }
}

/**
 * A bubble with WhatsApp's proportions: square corner on the sender's side
 * at the top, up to 80% of the width. A [framed] one (an image) is as wide
 * as WhatsApp makes it — 75% of the width, up to [IMAGE_BUBBLE_MAX] — with
 * a thin frame around the image.
 */
@Composable
private fun Bubble(fromMe: Boolean, colors: ChatColors, framed: Boolean = false, content: @Composable ColumnScope.() -> Unit) {
    BoxWithConstraints(Modifier.fillMaxWidth()) {
        val maxBubble = maxWidth * 0.8f
        val imageBubble = min(maxWidth * 0.75f, IMAGE_BUBBLE_MAX)

        Row(Modifier.fillMaxWidth(), horizontalArrangement = if (fromMe) Arrangement.End else Arrangement.Start) {
            Surface(
                color = if (fromMe) colors.mine else colors.theirs,
                contentColor = MaterialTheme.colorScheme.onSurface,
                shape = RoundedCornerShape(
                    topStart = if (fromMe) 10.dp else 0.dp,
                    topEnd = if (fromMe) 0.dp else 10.dp,
                    bottomStart = 10.dp,
                    bottomEnd = 10.dp,
                ),
                shadowElevation = 0.5.dp,
                modifier = if (framed) Modifier.width(imageBubble) else Modifier.widthIn(max = maxBubble),
            ) {
                Column(
                    if (framed) Modifier.padding(3.dp) else Modifier.padding(start = 8.dp, end = 8.dp, top = 5.dp, bottom = 4.dp),
                    verticalArrangement = Arrangement.spacedBy(3.dp),
                    content = content,
                )
            }
        }
    }
}

/**
 * The text with the time (and ticks) at its bottom right, as WhatsApp sets
 * it: on the last line when it fits there, otherwise on a line of its own.
 */
@Composable
private fun BodyWithMeta(body: String?, meta: @Composable () -> Unit) {
    val lastLine = remember { IntArray(1) }

    Layout(content = {
        body?.let { Text(it, style = MaterialTheme.typography.bodyLarge, onTextLayout = { layout -> lastLine[0] = layout.getLineRight(layout.lineCount - 1).toInt() }) }
        meta()
    }) { measurables, constraints ->
        val loose = constraints.copy(minWidth = 0)
        val text = if (body != null) measurables.first().measure(loose) else null
        val info = measurables.last().measure(loose)
        val gap = 6.dp.roundToPx()

        if (text == null) {
            return@Layout layout(info.width, info.height) { info.place(0, 0) }
        }

        val fitsOnLastLine = lastLine[0] + gap + info.width <= constraints.maxWidth
        val width = if (fitsOnLastLine) max(text.width, lastLine[0] + gap + info.width) else max(text.width, info.width)
        val height = if (fitsOnLastLine) max(text.height, info.height) else text.height + info.height

        layout(width, height) {
            text.place(0, 0)
            info.place(width - info.width, height - info.height)
        }
    }
}

/** The time and ticks of a message, after its marks (pinned, starred, edited) when [message] is given. */
@Composable
private fun MessageMeta(time: String, colors: ChatColors, tint: Color = colors.meta, message: WhatsappConversationMessage? = null, ticks: (@Composable () -> Unit)?) {
    Row(Modifier.padding(top = 2.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(3.dp)) {
        message?.let { MessageMarks(it.edited, it.starred, it.pinned, tint) }
        Text(time, style = MaterialTheme.typography.labelSmall, color = tint)
        ticks?.invoke()
    }
}

/**
 * A message as WhatsApp draws it: the sender in a group, "Forwarded", the
 * message it answers (a tap goes to it, [onQuote]), deleted, a location,
 * contact cards ("Message" opens the chat, [onContactMessage]) or a poll in
 * place of the text they stand for, the media, the text with its time, ticks
 * and marks; a sticker without the bubble.
 */
@Composable
private fun MessageBubble(
    message: WhatsappConversationMessage,
    timezone: String,
    locale: java.util.Locale,
    colors: ChatColors,
    onMediaFailure: () -> Unit,
    onQuote: (String) -> Unit = {},
    onContactMessage: ((String) -> Unit)? = null,
) {
    val media = message.media
    val time = formatTime(message.sentAt, timezone, locale).orEmpty()

    if (message.sticker && media != null && !message.revoked) {
        StickerBubble(message, media, time, colors)

        return
    }

    val isImage = media?.kind == "image"
    val inset = if (isImage) Modifier.padding(horizontal = 5.dp) else Modifier
    val hasCard = !message.revoked && (message.location != null || message.contacts != null || message.poll != null)
    // A location's name, a contact's or a poll's question stand for the text: the card shows them.
    val caption = message.body?.takeIf { it.isNotBlank() && !hasCard }
    val quoteBackground = colors.meta.copy(alpha = 0.14f)

    Bubble(message.fromMe, colors, framed = isImage) {
        message.sender?.let {
            Text(it, style = MaterialTheme.typography.labelLarge, color = MaterialTheme.colorScheme.primary, fontWeight = FontWeight.SemiBold, modifier = inset)
        }

        if (message.forwarded) {
            ForwardedLabel(colors.meta, inset)
        }

        message.quoted?.let { quoted ->
            QuotedBlock(quoted, quoteBackground, onClick = quoted.friendlyId?.let { id -> { onQuote(id) } }, modifier = inset)
        }

        if (message.revoked) {
            Row(inset, verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                Icon(Icons.Filled.Block, contentDescription = null, modifier = Modifier.size(14.dp), tint = colors.meta)
                Text(stringResource(R.string.whatsapp_deleted), style = MaterialTheme.typography.labelMedium, fontStyle = FontStyle.Italic, color = colors.meta)
            }
        }

        if (!message.revoked) {
            message.location?.let { LocationCard(it, quoteBackground) }
            message.contacts?.let { ContactCards(it, quoteBackground, onContactMessage) }
            message.poll?.let { PollCard(it, colors.meta) }
        }

        if (media != null && isImage) {
            // Without a caption, the time and ticks sit over the image, on a dark capsule.
            ImageMedia(media, onMediaFailure) {
                if (caption == null) {
                    Box(
                        Modifier.align(Alignment.BottomEnd).padding(6.dp).background(Color.Black.copy(alpha = 0.4f), RoundedCornerShape(50)).padding(horizontal = 6.dp),
                    ) {
                        MessageMeta(time, colors, tint = Color.White, message = message, ticks = if (message.fromMe) ({ ReceiptTicks(message.receipt, colors, Color.White) }) else null)
                    }
                }
            }

            if (media.url == null) {
                Text(stringResource(R.string.whatsapp_media_too_big), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = inset)
            }

            if (caption != null) {
                Box(Modifier.padding(start = 5.dp, end = 5.dp, bottom = 1.dp)) {
                    BodyWithMeta(caption) {
                        MessageMeta(time, colors, message = message, ticks = if (message.fromMe) ({ ReceiptTicks(message.receipt, colors) }) else null)
                    }
                }
            }

            return@Bubble
        }

        media?.let { MediaContent(it, message.fromMe, onMediaFailure) }

        BodyWithMeta(caption) {
            MessageMeta(time, colors, message = message, ticks = if (message.fromMe) ({ ReceiptTicks(message.receipt, colors) }) else null)
        }
    }
}

/** A sticker as WhatsApp shows it: no bubble, the time and ticks on a capsule under it. */
@Composable
private fun StickerBubble(message: WhatsappConversationMessage, media: WhatsappConversationMedia, time: String, colors: ChatColors) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = if (message.fromMe) Arrangement.End else Arrangement.Start) {
        Column(horizontalAlignment = if (message.fromMe) Alignment.End else Alignment.Start, verticalArrangement = Arrangement.spacedBy(2.dp)) {
            message.sender?.let {
                Text(
                    it,
                    style = MaterialTheme.typography.labelLarge,
                    color = MaterialTheme.colorScheme.primary,
                    fontWeight = FontWeight.SemiBold,
                    modifier = Modifier.background(colors.theirs, RoundedCornerShape(8.dp)).padding(horizontal = 6.dp, vertical = 2.dp),
                )
            }

            StickerImage(media)

            Box(Modifier.background(colors.theirs.copy(alpha = 0.85f), RoundedCornerShape(50)).padding(horizontal = 6.dp)) {
                MessageMeta(time, colors, message = message, ticks = if (message.fromMe) ({ ReceiptTicks(message.receipt, colors) }) else null)
            }
        }
    }
}

/** How wide an image bubble gets at most, as on WhatsApp. */
private val IMAGE_BUBBLE_MAX = 300.dp

/** How tall an image gets in its bubble; a taller one is cropped. */
private val IMAGE_MAX_HEIGHT = 340.dp

/** How short an image gets in its bubble; a wider one is cropped. */
private val IMAGE_MIN_HEIGHT = 120.dp

/**
 * An image filling its bubble's width, cropped between [IMAGE_MIN_HEIGHT]
 * and [IMAGE_MAX_HEIGHT], in the proportions of the picture (its
 * thumbnail's until it loads, 4:3 without one). A tap loads it, the next one
 * opens it; [overlay] draws over it (the time without a caption).
 */
@Composable
private fun ImageMedia(media: WhatsappConversationMedia, onFailure: () -> Unit, overlay: @Composable BoxScope.() -> Unit = {}) {
    val context = LocalContext.current
    val url = media.url
    var isLoading by remember(url) { mutableStateOf(false) }
    val thumbnail = remember(media.thumbnail) { media.thumbnail?.let(WhatsappImages::decodeBase64) }
    val loaded by produceState<android.graphics.Bitmap?>(null, url, isLoading) {
        if (isLoading && url != null) {
            value = WhatsappImages.load(url, 1280)

            if (value == null) {
                isLoading = false
                onFailure()
            }
        }
    }
    val picture = loaded ?: thumbnail
    val aspect = picture?.let { it.width.toFloat() / it.height.coerceAtLeast(1) } ?: (4f / 3f)

    BoxWithConstraints(Modifier.fillMaxWidth()) {
        val height = (maxWidth / aspect).coerceIn(IMAGE_MIN_HEIGHT, IMAGE_MAX_HEIGHT)

        Box(
            Modifier
                .fillMaxWidth()
                .height(height)
                .clip(RoundedCornerShape(7.dp))
                .background(MaterialTheme.colorScheme.surfaceVariant)
                .clickable(enabled = url != null) { if (loaded != null) url?.let { openMedia(context, it, media.mime) } else isLoading = true },
            contentAlignment = Alignment.Center,
        ) {
            picture?.let {
                Image(it.asImageBitmap(), contentDescription = stringResource(R.string.whatsapp_media_image), contentScale = ContentScale.Crop, modifier = Modifier.matchParentSize())
            }

            if (loaded == null) {
                if (isLoading) {
                    CircularProgressIndicator(Modifier.size(32.dp))
                } else if (url != null) {
                    Icon(
                        Icons.Filled.Download,
                        contentDescription = stringResource(R.string.whatsapp_tap_to_load),
                        tint = Color.White,
                        modifier = Modifier.size(40.dp).background(Color.Black.copy(alpha = 0.45f), CircleShape).padding(8.dp),
                    )
                }
            }

            overlay()
        }
    }
}

@Composable
private fun ReceiptTicks(receipt: WhatsappReceipt?, colors: ChatColors, metaTint: Color = colors.meta) {
    val tint = metaTint
    val (icon, color, label) = when (receipt) {
        WhatsappReceipt.Failed -> Triple(Icons.Filled.ErrorOutline, MaterialTheme.colorScheme.error, R.string.whatsapp_status_failed)
        WhatsappReceipt.Pending, null -> Triple(Icons.Outlined.Schedule, tint, R.string.whatsapp_status_pending)
        WhatsappReceipt.Sent -> Triple(Icons.Filled.Done, tint, R.string.whatsapp_status_sent)
        WhatsappReceipt.Delivered -> Triple(Icons.Filled.DoneAll, tint, R.string.whatsapp_receipt_delivered)
        WhatsappReceipt.Read -> Triple(Icons.Filled.DoneAll, ReadTicks, R.string.whatsapp_receipt_read)
        WhatsappReceipt.Played -> Triple(Icons.Filled.DoneAll, ReadTicks, R.string.whatsapp_receipt_played)
    }

    Icon(icon, contentDescription = stringResource(label), tint = color, modifier = Modifier.size(16.dp))
}

@Composable
private fun MediaContent(media: WhatsappConversationMedia, fromMe: Boolean, onFailure: () -> Unit) {
    val context = LocalContext.current
    val url = media.url

    when (media.kind) {
        "image" -> ImageMedia(media, onFailure)

        "video" -> {
            val thumbnail = remember(media.thumbnail) { media.thumbnail?.let(WhatsappImages::decodeBase64) }

            Box(
                Modifier.size(220.dp, 160.dp).clip(RoundedCornerShape(12.dp)).background(Color.Black).clickable(enabled = url != null) { url?.let { openMedia(context, it, media.mime ?: "video/*") } },
                contentAlignment = Alignment.Center,
            ) {
                thumbnail?.let { Image(it.asImageBitmap(), contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize()) }
                Icon(Icons.Filled.PlayCircle, contentDescription = stringResource(R.string.whatsapp_media_video), tint = Color.White, modifier = Modifier.size(48.dp))
                media.seconds?.let {
                    Text(clock(it * 1000), color = Color.White, style = MaterialTheme.typography.labelSmall, modifier = Modifier.align(Alignment.BottomStart).padding(6.dp))
                }
            }
        }

        "audio" -> AudioPlayer(url, media.seconds, media.waveform, if (fromMe) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.secondary, onFailure)

        else -> Row(
            Modifier
                .clip(RoundedCornerShape(8.dp))
                .background(MaterialTheme.colorScheme.surface.copy(alpha = 0.5f))
                .clickable(enabled = url != null) { url?.let { openMedia(context, it, media.mime) } }
                .padding(8.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Icon(Icons.Outlined.Description, contentDescription = null)
            Column(Modifier.widthIn(max = 200.dp)) {
                Text(media.name ?: stringResource(R.string.whatsapp_media_document), style = MaterialTheme.typography.bodyMedium, maxLines = 2)
                media.size?.let { Text(android.text.format.Formatter.formatShortFileSize(context, it), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
            }
        }
    }

    if (url == null) {
        Text(stringResource(R.string.whatsapp_media_too_big), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

/** A text Jarvis is still sending, or failed to send. */
@Composable
private fun OutgoingBubble(delivery: WhatsappDelivery, timezone: String, locale: java.util.Locale, colors: ChatColors) {
    val failed = delivery.status == WhatsappDeliveryStatus.Failed

    Bubble(fromMe = true, colors = colors) {
        BodyWithMeta(delivery.body) {
            MessageMeta(formatTime(delivery.createdAt, timezone, locale).orEmpty(), colors) {
                ReceiptTicks(if (failed) WhatsappReceipt.Failed else WhatsappReceipt.Pending, colors)
            }
        }

        if (failed) {
            Text(delivery.errorMessage ?: stringResource(R.string.whatsapp_not_sent), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.error)
        }
    }
}

/**
 * WhatsApp's composer: the message in a rounded field (the smiley opens the
 * emojis in place of the keyboard, put where the cursor is; the clip
 * attaches a file, a poll, a location, contacts or a sticker) and the round
 * send button beside it, above the keyboard or the system bar, whichever is
 * taller. The message being answered shows on top of the field; in a group,
 * typing `@` lists the members ([members]) and picking one writes
 * `@<number>`, which WhatsApp turns into the mention.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun Composer(
    colors: ChatColors,
    text: String,
    isSending: Boolean,
    failure: String?,
    replyingTo: WhatsappConversationMessage?,
    members: List<WhatsappGroupMember>,
    onTextChange: (String) -> Unit,
    onCancelReply: () -> Unit,
    onAttach: (WhatsappAttachment) -> Unit,
    onSend: () -> Unit,
) {
    val keyboard = LocalSoftwareKeyboardController.current
    var isEmojiOpen by rememberSaveable { mutableStateOf(false) }
    var isAttachOpen by remember { mutableStateOf(false) }
    var editing by remember { mutableStateOf(TextFieldValue(text, TextRange(text.length))) }
    // A text set from outside (sent, drafted by the AI) moves the cursor to its end.
    val field = if (editing.text == text) editing else TextFieldValue(text, TextRange(text.length))
    val isImeVisible = WindowInsets.isImeVisible

    // The keyboard coming back (a tap on the field) closes the emojis.
    LaunchedEffect(isImeVisible) {
        if (isImeVisible) {
            isEmojiOpen = false
        }
    }

    BackHandler(enabled = isEmojiOpen) { isEmojiOpen = false }

    Column(
        Modifier
            .fillMaxWidth()
            .windowInsetsPadding(WindowInsets.ime.union(WindowInsets.navigationBars))
            .padding(start = 6.dp, end = 6.dp, top = 4.dp, bottom = 6.dp),
    ) {
        failure?.let {
            Text(
                it,
                color = MaterialTheme.colorScheme.error,
                style = MaterialTheme.typography.bodySmall,
                modifier = Modifier.padding(horizontal = 8.dp, vertical = 4.dp).background(colors.theirs, RoundedCornerShape(8.dp)).padding(horizontal = 8.dp, vertical = 4.dp),
            )
        }

        val mention = if (members.isEmpty() || !field.selection.collapsed) null else WhatsappMentions.query(field.text, field.selection.min)
        val suggestions = mention?.let { WhatsappMentions.matches(members, it.text) }.orEmpty()

        if (mention != null && suggestions.isNotEmpty()) {
            MentionSuggestions(suggestions, colors.bar) { member ->
                val (inserted, cursor) = WhatsappMentions.insert(field.text, field.selection.min, mention, member.mentionDigits ?: return@MentionSuggestions)
                editing = TextFieldValue(inserted, TextRange(cursor))
                onTextChange(inserted)
            }
        }

        Row(verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            Surface(color = colors.theirs, shape = RoundedCornerShape(24.dp), shadowElevation = 0.5.dp, modifier = Modifier.weight(1f)) {
                Column {
                    replyingTo?.let { ReplyBar(it, colors.theirs, colors.meta.copy(alpha = 0.14f), onCancelReply) }

                    TextField(
                        value = field,
                        onValueChange = {
                            editing = it

                            if (it.text != text) {
                                onTextChange(it.text)
                            }
                        },
                        placeholder = { Text(stringResource(R.string.whatsapp_message)) },
                        maxLines = 6,
                        leadingIcon = {
                            IconButton(
                                onClick = {
                                    if (isEmojiOpen) {
                                        isEmojiOpen = false
                                        keyboard?.show()
                                    } else {
                                        keyboard?.hide()
                                        isEmojiOpen = true
                                    }
                                },
                                modifier = Modifier.testTag("whatsapp.chat.emoji"),
                            ) {
                                Icon(
                                    if (isEmojiOpen) Icons.Filled.Keyboard else Icons.Outlined.EmojiEmotions,
                                    contentDescription = stringResource(if (isEmojiOpen) R.string.whatsapp_keyboard else R.string.whatsapp_emoji),
                                    tint = colors.meta,
                                )
                            }
                        },
                        trailingIcon = {
                            Box {
                                IconButton(onClick = { isAttachOpen = true }, enabled = !isSending, modifier = Modifier.testTag("whatsapp.chat.attach")) {
                                    Icon(Icons.Filled.AttachFile, contentDescription = stringResource(R.string.whatsapp_attach), tint = colors.meta)
                                }

                                DropdownMenu(expanded = isAttachOpen, onDismissRequest = { isAttachOpen = false }) {
                                    listOf(
                                        Triple(WhatsappAttachment.File, R.string.whatsapp_attach_file, Icons.Outlined.Description),
                                        Triple(WhatsappAttachment.Poll, R.string.whatsapp_poll, Icons.Outlined.Poll),
                                        Triple(WhatsappAttachment.Location, R.string.whatsapp_location, Icons.Outlined.LocationOn),
                                        Triple(WhatsappAttachment.Contact, R.string.whatsapp_contact, Icons.Outlined.Person),
                                        Triple(WhatsappAttachment.Sticker, R.string.whatsapp_sticker, Icons.Outlined.EmojiEmotions),
                                    ).forEach { (attachment, label, icon) ->
                                        DropdownMenuItem(
                                            text = { Text(stringResource(label)) },
                                            leadingIcon = { Icon(icon, contentDescription = null) },
                                            onClick = {
                                                isAttachOpen = false
                                                onAttach(attachment)
                                            },
                                            modifier = Modifier.testTag("whatsapp.attach.${attachment.name.lowercase()}"),
                                        )
                                    }
                                }
                            }
                        },
                        colors = TextFieldDefaults.colors(
                            focusedContainerColor = Color.Transparent,
                            unfocusedContainerColor = Color.Transparent,
                            disabledContainerColor = Color.Transparent,
                            focusedIndicatorColor = Color.Transparent,
                            unfocusedIndicatorColor = Color.Transparent,
                            disabledIndicatorColor = Color.Transparent,
                        ),
                        modifier = Modifier.fillMaxWidth().testTag("whatsapp.chat.text"),
                    )
                }
            }

            val canSend = !isSending && text.isNotBlank()

            Box(
                Modifier
                    .size(48.dp)
                    .clip(CircleShape)
                    .background(colors.send)
                    .clickable(enabled = canSend, onClick = onSend)
                    .testTag("whatsapp.chat.send"),
                contentAlignment = Alignment.Center,
            ) {
                if (isSending) {
                    CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp, color = Color.White)
                } else {
                    Icon(Icons.AutoMirrored.Filled.Send, contentDescription = stringResource(R.string.whatsapp_send), tint = Color.White.copy(alpha = if (canSend) 1f else 0.6f))
                }
            }
        }

        if (isEmojiOpen) {
            EmojiGrid(
                onPick = { emoji ->
                    val inserted = field.text.replaceRange(field.selection.min, field.selection.max, emoji)
                    editing = TextFieldValue(inserted, TextRange(field.selection.min + emoji.length))
                    onTextChange(inserted)
                },
                modifier = Modifier.fillMaxWidth().height(EMOJI_PANEL_HEIGHT).padding(top = 6.dp).background(colors.bar, RoundedCornerShape(12.dp)),
            )
        }
    }
}

/** How tall the emojis are in place of the keyboard. */
private val EMOJI_PANEL_HEIGHT = 280.dp

internal class PickedFile(val name: String, val mime: String?, val bytes: ByteArray)

/** The file picked on the phone; null when it is above [maxBytes] or cannot be read. */
internal fun readPicked(context: Context, uri: Uri, maxBytes: Long = MAX_UPLOAD_BYTES): PickedFile? {
    val resolver = context.contentResolver
    var name = "file"
    var size: Long? = null

    resolver.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME, OpenableColumns.SIZE), null, null, null)?.use { cursor ->
        if (cursor.moveToFirst()) {
            name = cursor.getString(0) ?: name
            size = if (cursor.isNull(1)) null else cursor.getLong(1)
        }
    }

    if ((size ?: 0) > maxBytes) {
        return null
    }

    val bytes = runCatching { resolver.openInputStream(uri)?.use { it.readBytes() } }.getOrNull() ?: return null

    return if (bytes.size > maxBytes) null else PickedFile(name, resolver.getType(uri), bytes)
}
