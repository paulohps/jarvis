package app.jarvis.android.ui.whatsapp

import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Chat
import androidx.compose.material.icons.automirrored.filled.OpenInNew
import androidx.compose.material.icons.automirrored.filled.Send
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.CalendarMonth
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material.icons.filled.Repeat
import androidx.compose.material.icons.filled.WifiOff
import androidx.compose.material.icons.outlined.Schedule
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.core.net.toUri
import app.jarvis.android.R
import app.jarvis.android.app.AppModel
import app.jarvis.android.app.Features
import app.jarvis.android.navigation.AppTab
import app.jarvis.android.navigation.Destination
import app.jarvis.android.realtime.WhatsappLive
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.RecurrenceFrequency
import app.jarvis.android.network.WhatsappAccount
import app.jarvis.android.network.WhatsappMessage
import app.jarvis.android.network.WhatsappMessageState
import app.jarvis.android.ui.components.ActionFailureDialog
import app.jarvis.android.ui.components.ConfirmDialog
import app.jarvis.android.ui.components.ContentUnavailable
import app.jarvis.android.ui.components.FilterRow
import app.jarvis.android.ui.components.GroupedList
import app.jarvis.android.ui.components.JarvisScreen
import app.jarvis.android.ui.components.ListRow
import app.jarvis.android.ui.components.Section
import app.jarvis.android.ui.components.formatDateTime
import app.jarvis.android.ui.components.userMessage
import app.jarvis.android.ui.schedule.recurrenceSummary
import app.jarvis.android.ui.shell.NoAccessScreen
import kotlinx.coroutines.launch

/** What the compose sheet does. */
sealed interface WhatsappComposer {
    /** Schedules a new message, or edits a saved one. */
    data class Schedule(val message: WhatsappMessage?) : WhatsappComposer

    data object SendNow : WhatsappComposer
}

private enum class Pane { Chats, Messages, History }

/**
 * The user's WhatsApp (US-7.3, US-7.5): the chats of their numbers (opening
 * one pushes it, [WhatsappChatScreen]), their scheduled messages — cancel,
 * pause, resume, edit, delete —, scheduling a new one, sending one now and the
 * history of what went out. Connecting a number by QR code is web only: the
 * app links to the web page instead. With [Features.WHATSAPP_SCHEDULED_PANES]
 * off, only the chats show, with a new chat by number and the shared tools
 * (tags, priority colors, the defaults of each number's chats). A new
 * WhatsApp group is created from the tools menu.
 */
@Composable
fun WhatsAppHomeScreen(model: AppModel, live: WhatsappLive) {
    var pane by rememberSaveable { mutableStateOf(Pane.Chats) }
    var isNewMenuOpen by remember { mutableStateOf(false) }
    var isToolsMenuOpen by remember { mutableStateOf(false) }
    var composer by remember { mutableStateOf<WhatsappComposer?>(null) }
    var accounts by remember { mutableStateOf<List<WhatsappAccount>>(emptyList()) }
    var reloadKey by remember { mutableStateOf(0) }
    val tools = remember { WhatsappChatToolsStore(model.client) }
    val chats = remember { WhatsappConversationsStore(model.client) }
    var tool by remember { mutableStateOf<WhatsappChatTool?>(null) }

    LaunchedEffect(tools) {
        tools.load()
    }

    JarvisScreen(
        title = stringResource(R.string.tab_whatsapp),
        actions = {
            if (Features.WHATSAPP_SCHEDULED_PANES) {
                Box {
                    IconButton(onClick = { isNewMenuOpen = true }, modifier = Modifier.testTag("whatsapp.new")) {
                        Icon(Icons.Filled.Add, contentDescription = stringResource(R.string.whatsapp_new))
                    }

                    DropdownMenu(expanded = isNewMenuOpen, onDismissRequest = { isNewMenuOpen = false }) {
                        DropdownMenuItem(
                            text = { Text(stringResource(R.string.whatsapp_schedule_message)) },
                            leadingIcon = { Icon(Icons.Outlined.Schedule, contentDescription = null) },
                            onClick = {
                                isNewMenuOpen = false
                                composer = WhatsappComposer.Schedule(null)
                            },
                        )
                        DropdownMenuItem(
                            text = { Text(stringResource(R.string.whatsapp_send_now)) },
                            leadingIcon = { Icon(Icons.AutoMirrored.Filled.Send, contentDescription = null) },
                            onClick = {
                                isNewMenuOpen = false
                                composer = WhatsappComposer.SendNow
                            },
                        )
                    }
                }
            } else {
                IconButton(onClick = { tool = WhatsappChatTool.NewChat }, modifier = Modifier.testTag("whatsapp.new-chat")) {
                    Icon(Icons.AutoMirrored.Filled.Chat, contentDescription = stringResource(R.string.whatsapp_new_chat))
                }
            }

            Box {
                IconButton(onClick = { isToolsMenuOpen = true }, modifier = Modifier.testTag("whatsapp.tools")) {
                    Icon(Icons.Filled.MoreVert, contentDescription = stringResource(R.string.more))
                }

                DropdownMenu(expanded = isToolsMenuOpen, onDismissRequest = { isToolsMenuOpen = false }) {
                    listOf(
                        R.string.whatsapp_new_group to WhatsappChatTool.NewGroup,
                        R.string.whatsapp_manage_tags to WhatsappChatTool.ManageTags,
                        R.string.whatsapp_number_defaults to WhatsappChatTool.NumberDefaults(null),
                    ).forEach { (label, picked) ->
                        DropdownMenuItem(
                            text = { Text(stringResource(label)) },
                            onClick = {
                                isToolsMenuOpen = false
                                tool = picked
                            },
                        )
                    }
                }
            }
        },
    ) {
        if (Features.WHATSAPP_SCHEDULED_PANES) {
            FilterRow(Pane.entries, pane, { pane = it }) {
                stringResource(
                    when (it) {
                        Pane.Chats -> R.string.whatsapp_chats
                        Pane.Messages -> R.string.whatsapp_scheduled
                        Pane.History -> R.string.whatsapp_history
                    },
                )
            }
        }

        when (if (Features.WHATSAPP_SCHEDULED_PANES) pane else Pane.Chats) {
            Pane.Chats -> WhatsappChatList(model, live, tools, chats, reloadKey)
            Pane.Messages -> MessagesPane(model, reloadKey, onAccounts = { accounts = it }, onEdit = { composer = WhatsappComposer.Schedule(it) })
            Pane.History -> WhatsappHistory(model)
        }
    }

    composer?.let { current ->
        WhatsappMessageForm(model, current, accounts, onDismiss = { composer = null }) { reloadKey++ }
    }

    when (val current = tool) {
        WhatsappChatTool.NewChat -> NewChatSheet(model, tools, onStarted = { chat ->
            tool = null
            model.router.push(AppTab.WhatsApp, Destination.WhatsappChat(chat.friendlyId, chat.name, chat))
        }, onDismiss = { tool = null })

        WhatsappChatTool.NewGroup -> NewGroupSheet(
            model = model,
            tools = tools,
            onCreate = chats::createGroup,
            onCreated = { chat ->
                tool = null
                model.router.push(AppTab.WhatsApp, Destination.WhatsappChat(chat.friendlyId, chat.name, chat))
            },
            onDismiss = { tool = null },
        )

        WhatsappChatTool.ManageTags -> ManageTagsSheet(tools) {
            tool = null
            reloadKey++
        }

        is WhatsappChatTool.NumberDefaults -> NumberDefaultsSheet(tools, current.accountId, onSaved = {}, onDismiss = { tool = null })
        else -> Unit
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun MessagesPane(model: AppModel, reloadKey: Int, onAccounts: (List<WhatsappAccount>) -> Unit, onEdit: (WhatsappMessage) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var accounts by remember { mutableStateOf<List<WhatsappAccount>>(emptyList()) }
    var messages by remember { mutableStateOf<List<WhatsappMessage>?>(null) }
    var loadFailure by remember { mutableStateOf<ApiException?>(null) }
    var actionFailure by remember { mutableStateOf<ApiException?>(null) }
    var isRefreshing by remember { mutableStateOf(false) }
    var messageToCancel by remember { mutableStateOf<WhatsappMessage?>(null) }
    var messageToDelete by remember { mutableStateOf<WhatsappMessage?>(null) }

    suspend fun load() {
        try {
            accounts = model.client.call { whatsappAccounts() }.data
            onAccounts(accounts)
            messages = model.client.call { whatsappMessages() }.data
            loadFailure = null
        } catch (_: ApiException.Unauthenticated) {
            // The shell already went back to the login.
        } catch (exception: ApiException) {
            if (messages == null) {
                loadFailure = exception
            }
        }
    }

    /** Runs an action on a message, then reads the messages again. */
    val run: (suspend () -> Unit) -> Unit = { action ->
        scope.launch {
            try {
                action()
            } catch (exception: ApiException) {
                actionFailure = exception
            }

            load()
        }
    }

    LaunchedEffect(reloadKey) {
        load()
    }

    PullToRefreshBox(
        isRefreshing = isRefreshing,
        onRefresh = {
            scope.launch {
                isRefreshing = true
                load()
                isRefreshing = false
            }
        },
        modifier = Modifier.fillMaxSize(),
    ) {
        val current = messages
        val failure = loadFailure

        when {
            current != null -> GroupedList {
                item {
                    if (current.isEmpty()) {
                        Section {
                            ListRow(
                                title = stringResource(R.string.whatsapp_no_messages),
                                subtitle = stringResource(R.string.whatsapp_no_messages_description),
                                leading = { Icon(Icons.Outlined.Schedule, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant) },
                            )
                        }
                    } else {
                        Section {
                            current.forEachIndexed { index, message ->
                                MessageRow(
                                    model = model,
                                    message = message,
                                    showDivider = index < current.lastIndex,
                                    onEdit = { onEdit(message) },
                                    onPause = { run { model.client.call { pauseWhatsappMessage(message.friendlyId) } } },
                                    onResume = { run { model.client.call { resumeWhatsappMessage(message.friendlyId) } } },
                                    onCancel = { messageToCancel = message },
                                    onDelete = { messageToDelete = message },
                                )
                            }
                        }
                    }
                }

                item {
                    AccountsSection(accounts) { openConnectOnWeb(context, model) }
                }
            }

            failure is ApiException.Forbidden -> NoAccessScreen(onRetry = { scope.launch { model.refreshUser() } })
            failure != null -> ContentUnavailable(
                title = failure.userMessage(),
                icon = Icons.Filled.WifiOff,
                actionLabel = stringResource(R.string.try_again),
                onAction = { scope.launch { load() } },
            )

            else -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
        }
    }

    messageToCancel?.let { message ->
        ConfirmDialog(
            title = stringResource(R.string.whatsapp_cancel_confirm),
            confirmLabel = stringResource(R.string.whatsapp_cancel_message),
            dismissLabel = stringResource(R.string.keep),
            onConfirm = { run { model.client.call { cancelWhatsappMessage(message.friendlyId) } } },
            onDismiss = { messageToCancel = null },
        )
    }

    messageToDelete?.let { message ->
        ConfirmDialog(
            title = stringResource(R.string.whatsapp_delete_confirm),
            confirmLabel = stringResource(R.string.delete),
            onConfirm = { run { model.client.call { deleteWhatsappMessage(message.friendlyId) } } },
            onDismiss = { messageToDelete = null },
        )
    }

    ActionFailureDialog(actionFailure) { actionFailure = null }
}

@Composable
private fun MessageRow(
    model: AppModel,
    message: WhatsappMessage,
    showDivider: Boolean,
    onEdit: () -> Unit,
    onPause: () -> Unit,
    onResume: () -> Unit,
    onCancel: () -> Unit,
    onDelete: () -> Unit,
) {
    var isMenuOpen by remember { mutableStateOf(false) }
    val timezone = model.user?.timezone ?: "UTC"
    val locale = model.language.locale

    ListRow(
        title = message.targets.joinToString(", ") { it.name },
        showDivider = showDivider,
        onClick = if (message.canBeEdited) onEdit else null,
        modifier = Modifier.testTag("whatsapp.message.${message.friendlyId}"),
        titleContent = {
            Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(
                        message.targets.joinToString(", ") { it.name },
                        style = MaterialTheme.typography.titleSmall,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                        modifier = Modifier.weight(1f),
                    )
                    StateBadge(message.state)
                }

                Text(message.body, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 2, overflow = TextOverflow.Ellipsis)

                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                    val recurrence = message.recurrence
                    val caption = buildList {
                        if (recurrence != null) {
                            if (recurrence.frequency == RecurrenceFrequency.Once) {
                                formatDateTime(recurrence.nextRunAt ?: recurrence.startsAt, timezone, locale)?.let(::add)
                            } else {
                                add(recurrenceSummary(recurrence, locale))

                                if (message.state == WhatsappMessageState.Active) {
                                    formatDateTime(recurrence.nextRunAt, timezone, locale)?.let { add(stringResource(R.string.whatsapp_next_run, it)) }
                                }
                            }
                        }

                        add(message.account.nickname)
                    }

                    if (recurrence != null) {
                        Icon(
                            if (recurrence.frequency == RecurrenceFrequency.Once) Icons.Filled.CalendarMonth else Icons.Filled.Repeat,
                            contentDescription = null,
                            tint = MaterialTheme.colorScheme.onSurfaceVariant,
                            modifier = Modifier.size(14.dp),
                        )
                    }

                    Text(caption.joinToString(" · "), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
            }
        },
        trailing = {
            Box {
                IconButton(onClick = { isMenuOpen = true }) {
                    Icon(Icons.Filled.MoreVert, contentDescription = stringResource(R.string.more), tint = MaterialTheme.colorScheme.onSurfaceVariant)
                }

                DropdownMenu(expanded = isMenuOpen, onDismissRequest = { isMenuOpen = false }) {
                    val item: @Composable (Int, Color?, () -> Unit) -> Unit = { label, color, action ->
                        DropdownMenuItem(
                            text = { Text(stringResource(label), color = color ?: MaterialTheme.colorScheme.onSurface) },
                            onClick = {
                                isMenuOpen = false
                                action()
                            },
                        )
                    }

                    if (message.canBeEdited) {
                        item(R.string.edit, null, onEdit)
                    }

                    if (message.canBePaused) {
                        item(R.string.whatsapp_pause, null, onPause)
                    }

                    if (message.canBeResumed) {
                        item(R.string.whatsapp_resume, null, onResume)
                    }

                    if (message.canBeCancelled) {
                        item(R.string.whatsapp_cancel_message, null, onCancel)
                    }

                    item(R.string.delete, MaterialTheme.colorScheme.error, onDelete)
                }
            }
        },
    )
}

@Composable
private fun StateBadge(state: WhatsappMessageState) {
    val (label, color) = when (state) {
        WhatsappMessageState.Active -> R.string.whatsapp_state_active to MaterialTheme.colorScheme.primary
        WhatsappMessageState.Paused -> R.string.whatsapp_state_paused to MaterialTheme.colorScheme.tertiary
        WhatsappMessageState.Cancelled -> R.string.whatsapp_state_cancelled to MaterialTheme.colorScheme.onSurfaceVariant
        WhatsappMessageState.Finished -> R.string.whatsapp_state_finished to MaterialTheme.colorScheme.primary
    }

    Text(
        stringResource(label),
        style = MaterialTheme.typography.labelMedium,
        color = color,
        modifier = Modifier.background(color.copy(alpha = 0.15f), CircleShape).padding(horizontal = 8.dp, vertical = 3.dp),
    )
}

/** The user's numbers, read only; connecting one is done on the web. */
@Composable
private fun AccountsSection(accounts: List<WhatsappAccount>, onConnectOnWeb: () -> Unit) {
    Section(header = stringResource(R.string.whatsapp_numbers), footer = stringResource(R.string.whatsapp_connect_on_web)) {
        accounts.forEach { account ->
            ListRow(title = account.nickname, subtitle = account.phoneNumber, showDivider = true, trailing = { AccountStatus(account) })
        }

        ListRow(
            title = stringResource(R.string.whatsapp_connect_on_web_action),
            onClick = onConnectOnWeb,
            modifier = Modifier.testTag("whatsapp.connect-on-web"),
            titleContent = { Text(stringResource(R.string.whatsapp_connect_on_web_action), color = MaterialTheme.colorScheme.primary) },
            trailing = { Icon(Icons.AutoMirrored.Filled.OpenInNew, contentDescription = null, tint = MaterialTheme.colorScheme.primary) },
        )
    }
}

@Composable
fun AccountStatus(account: WhatsappAccount) {
    val (label, color) = when (account.status) {
        "open" -> R.string.whatsapp_connected to MaterialTheme.colorScheme.primary
        "connecting" -> R.string.whatsapp_connecting to MaterialTheme.colorScheme.tertiary
        else -> R.string.whatsapp_disconnected to MaterialTheme.colorScheme.onSurfaceVariant
    }

    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        Box(Modifier.size(8.dp).background(color, CircleShape))
        Text(stringResource(label), style = MaterialTheme.typography.bodyMedium, color = color)
    }
}

/** The web page where a number is connected by QR code (US-11.1: not in the app). */
fun openConnectOnWeb(context: Context, model: AppModel) {
    try {
        context.startActivity(Intent(Intent.ACTION_VIEW, model.client.serverUrl.resolve("whatsapp/accounts").toString().toUri()))
    } catch (_: ActivityNotFoundException) {
        // No browser on the phone.
    }
}
