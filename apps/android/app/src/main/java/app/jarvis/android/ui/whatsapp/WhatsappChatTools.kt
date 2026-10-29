package app.jarvis.android.ui.whatsapp

import android.widget.Toast
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material.icons.outlined.Alarm
import androidx.compose.material.icons.outlined.AttachFile
import androidx.compose.material.icons.outlined.Image
import androidx.compose.material.icons.outlined.Mic
import androidx.compose.material.icons.outlined.Schedule
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Checkbox
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import app.jarvis.android.R
import app.jarvis.android.app.AppModel
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.WhatsappAccount
import app.jarvis.android.network.WhatsappConversation
import app.jarvis.android.network.WhatsappConversationDetail
import app.jarvis.android.network.WhatsappConversationTag
import app.jarvis.android.network.WhatsappPriorityColors
import app.jarvis.android.network.WhatsappStartChat
import app.jarvis.android.ui.components.ConfirmDialog
import app.jarvis.android.ui.components.FormError
import app.jarvis.android.ui.components.FormSheet
import app.jarvis.android.ui.components.ListRow
import app.jarvis.android.ui.components.Section
import app.jarvis.android.ui.components.SectionTextField
import app.jarvis.android.ui.components.formatDateTime
import app.jarvis.android.ui.components.userMessage
import app.jarvis.android.ui.schedule.DateTimeRows
import app.jarvis.android.ui.schedule.WallClock
import java.time.ZonedDateTime
import java.time.temporal.ChronoUnit
import kotlinx.coroutines.launch

/** The sheets and dialogs of the chat tools, one at a time. */
sealed interface WhatsappChatTool {
    data object Details : WhatsappChatTool

    data object Priority : WhatsappChatTool

    data object Nickname : WhatsappChatTool

    data object Tags : WhatsappChatTool

    data object ManageTags : WhatsappChatTool

    data object Settings : WhatsappChatTool

    /** The defaults of a number's chats; [accountId] null to pick the number. */
    data class NumberDefaults(val accountId: String?) : WhatsappChatTool

    data object Schedule : WhatsappChatTool

    data object Reminder : WhatsappChatTool

    data object NewChat : WhatsappChatTool

    /** A new WhatsApp group on one of the numbers. */
    data object NewGroup : WhatsappChatTool

    /** The chat's info: the contact or the group, the search, the starred messages and the gallery. */
    data object Info : WhatsappChatTool

    /** What the AI's answer must say, before drafting it. */
    data object DraftPrompt : WhatsappChatTool
}

/** Runs a change on the server; a refusal shows in a toast in the user's language. */
@Composable
internal fun rememberToolRunner(): (suspend () -> Unit) -> Unit {
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val failure = remember { mutableStateOf<ApiException?>(null) }

    failure.value?.let { exception ->
        val message = exception.userMessage()

        LaunchedEffect(exception) {
            Toast.makeText(context, message, Toast.LENGTH_LONG).show()
            failure.value = null
        }
    }

    return { action ->
        scope.launch {
            try {
                action()
            } catch (_: ApiException.Unauthenticated) {
                // The shell already went back to the login.
            } catch (exception: ApiException) {
                failure.value = exception
            }
        }
    }
}

/** The dot of a palette color, ringed when picked. */
@Composable
internal fun ColorSwatch(hex: String?, isPicked: Boolean, onClick: () -> Unit, modifier: Modifier = Modifier) {
    val color = hexColor(hex, MaterialTheme.colorScheme.outline)

    Box(
        modifier
            .size(36.dp)
            .border(2.dp, if (isPicked) MaterialTheme.colorScheme.onSurface else Color.Transparent, CircleShape)
            .padding(4.dp)
            .background(color, CircleShape)
            .clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        if (isPicked) {
            Icon(Icons.Filled.Check, contentDescription = null, tint = Color.White, modifier = Modifier.size(16.dp))
        }
    }
}

/** No priority, or 1 (the highest) to 5, each in the user's color (set in the app's settings). */
@Composable
internal fun PriorityDialog(current: Int?, colors: WhatsappPriorityColors, onPick: (Int?) -> Unit, onDismiss: () -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.whatsapp_sort_priority)) },
        text = {
            Column {
                ListRow(
                    title = stringResource(R.string.whatsapp_no_priority),
                    onClick = { onPick(null) },
                    trailing = { if (current == null) Icon(Icons.Filled.Check, contentDescription = null, tint = MaterialTheme.colorScheme.primary) },
                )

                WhatsappChatToolsStore.PRIORITIES.forEach { priority ->
                    ListRow(
                        title = stringResource(R.string.whatsapp_priority, priority),
                        onClick = { onPick(priority) },
                        modifier = Modifier.testTag("whatsapp.priority.$priority"),
                        leading = { Box(Modifier.size(16.dp).background(hexColor(colors.hex(priority), MaterialTheme.colorScheme.primary), CircleShape)) },
                        trailing = { if (current == priority) Icon(Icons.Filled.Check, contentDescription = null, tint = MaterialTheme.colorScheme.primary) },
                    )
                }
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.cancel)) } },
    )
}

/** What the AI's answer must say (up to [DRAFT_PROMPT_LENGTH] characters), then the draft. */
@Composable
internal fun DraftPromptDialog(hasSelection: Boolean, onGenerate: (String, Boolean) -> Unit, onDismiss: () -> Unit) {
    var prompt by remember { mutableStateOf("") }
    var withoutContext by remember { mutableStateOf(false) }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.whatsapp_draft_with_prompt)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(
                    value = prompt,
                    onValueChange = { prompt = it.take(DRAFT_PROMPT_LENGTH) },
                    label = { Text(stringResource(R.string.whatsapp_draft_prompt)) },
                    placeholder = { Text(stringResource(R.string.whatsapp_draft_prompt_placeholder)) },
                    minLines = 2,
                    modifier = Modifier.fillMaxWidth().testTag("whatsapp.draft.prompt"),
                )

                Row(
                    Modifier.fillMaxWidth().clickable { withoutContext = !withoutContext },
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(12.dp),
                ) {
                    Text(stringResource(R.string.whatsapp_draft_without_context), Modifier.weight(1f), style = MaterialTheme.typography.bodyMedium)
                    Switch(checked = withoutContext, onCheckedChange = { withoutContext = it }, modifier = Modifier.testTag("whatsapp.draft.without-context"))
                }

                Text(
                    stringResource(
                        when {
                            withoutContext -> R.string.whatsapp_draft_about_prompt_only
                            hasSelection -> R.string.whatsapp_draft_about_selected
                            else -> R.string.whatsapp_draft_about_latest
                        },
                    ),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        },
        confirmButton = {
            TextButton(onClick = { onGenerate(prompt.trim(), withoutContext) }, enabled = prompt.isNotBlank()) { Text(stringResource(R.string.whatsapp_generate)) }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.cancel)) } },
    )
}

/** The longest instruction the server takes for a draft. */
internal const val DRAFT_PROMPT_LENGTH = 500

/** The user's own name for the chat; removing it shows the WhatsApp name again. */
@Composable
internal fun NicknameDialog(chat: WhatsappConversation, onSave: (String?) -> Unit, onDismiss: () -> Unit) {
    var value by remember { mutableStateOf(chat.nickname.orEmpty()) }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.whatsapp_nickname)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(value = value, onValueChange = { value = it }, label = { Text(stringResource(R.string.whatsapp_nickname)) }, singleLine = true)

                (chat.whatsappName ?: chat.phoneNumber)?.let {
                    Text(stringResource(R.string.whatsapp_nickname_hint, it), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            }
        },
        confirmButton = {
            TextButton(onClick = { onSave(value.trim().ifEmpty { null }) }) { Text(stringResource(R.string.save)) }
        },
        dismissButton = {
            Row {
                if (chat.nickname != null) {
                    TextButton(onClick = { onSave(null) }) { Text(stringResource(R.string.whatsapp_remove)) }
                }

                TextButton(onClick = onDismiss) { Text(stringResource(R.string.cancel)) }
            }
        },
    )
}

/** The tags of the chat, checked on and off; managing them opens [onManage]. */
@Composable
internal fun ChatTagsDialog(tags: List<WhatsappConversationTag>, chat: WhatsappConversation, onSave: (List<String>) -> Unit, onManage: () -> Unit, onDismiss: () -> Unit) {
    var picked by remember { mutableStateOf(chat.tags.map { it.friendlyId }.toSet()) }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.whatsapp_tags)) },
        text = {
            Column {
                if (tags.isEmpty()) {
                    Text(stringResource(R.string.whatsapp_no_tags), color = MaterialTheme.colorScheme.onSurfaceVariant)
                }

                tags.forEach { tag ->
                    val isPicked = tag.friendlyId in picked
                    val toggle = { picked = if (isPicked) picked - tag.friendlyId else picked + tag.friendlyId }

                    ListRow(
                        title = tag.name,
                        onClick = toggle,
                        modifier = Modifier.testTag("whatsapp.tag.${tag.friendlyId}"),
                        leading = { Box(Modifier.size(14.dp).background(hexColor(tag.color, MaterialTheme.colorScheme.outline), CircleShape)) },
                        trailing = { Checkbox(checked = isPicked, onCheckedChange = { toggle() }) },
                    )
                }

                TextButton(onClick = onManage) { Text(stringResource(R.string.whatsapp_manage_tags)) }
            }
        },
        confirmButton = { TextButton(onClick = { onSave(tags.map { it.friendlyId }.filter { it in picked }) }) { Text(stringResource(R.string.save)) } },
        dismissButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.cancel)) } },
    )
}

/** The user's tags: a new one, renaming or recoloring one, deleting one (off every chat). */
@Composable
internal fun ManageTagsSheet(store: WhatsappChatToolsStore, onDismiss: () -> Unit) {
    val state by store.state.collectAsState()
    val run = rememberToolRunner()
    val palette = state.priorityColors.palette
    var editing by remember { mutableStateOf<WhatsappConversationTag?>(null) }
    var name by remember { mutableStateOf("") }
    var color by remember { mutableStateOf(palette.keys.firstOrNull() ?: "blue") }
    var toDelete by remember { mutableStateOf<WhatsappConversationTag?>(null) }

    fun startNew() {
        editing = null
        name = ""
        color = palette.keys.firstOrNull() ?: "blue"
    }

    FormSheet(
        title = stringResource(R.string.whatsapp_manage_tags),
        confirmLabel = stringResource(if (editing == null) R.string.whatsapp_add else R.string.save),
        isConfirmEnabled = name.isNotBlank(),
        onConfirm = {
            val tag = editing
            run {
                store.saveTag(tag, name, color)
                startNew()
            }
        },
        onDismiss = onDismiss,
    ) {
        item {
            Section(header = stringResource(if (editing == null) R.string.whatsapp_new_tag else R.string.edit)) {
                SectionTextField(value = name, onValueChange = { name = it }, placeholder = stringResource(R.string.whatsapp_tag_name), modifier = Modifier.testTag("whatsapp.tag.name"))
                PaletteRow(palette, color) { color = it }
            }
        }

        item {
            Section(header = stringResource(R.string.whatsapp_tags)) {
                if (state.tags.isEmpty()) {
                    ListRow(title = stringResource(R.string.whatsapp_no_tags))
                }

                state.tags.forEachIndexed { index, tag ->
                    ListRow(
                        title = tag.name,
                        showDivider = index < state.tags.lastIndex,
                        leading = { Box(Modifier.size(14.dp).background(hexColor(tag.color, MaterialTheme.colorScheme.outline), CircleShape)) },
                        trailing = {
                            IconButton(onClick = {
                                editing = tag
                                name = tag.name
                                color = tag.colorName ?: color
                            }) {
                                Icon(Icons.Filled.Edit, contentDescription = stringResource(R.string.edit))
                            }
                            IconButton(onClick = { toDelete = tag }) {
                                Icon(Icons.Filled.Delete, contentDescription = stringResource(R.string.delete), tint = MaterialTheme.colorScheme.error)
                            }
                        },
                    )
                }
            }
        }
    }

    toDelete?.let { tag ->
        ConfirmDialog(
            title = stringResource(R.string.whatsapp_delete_tag_confirm, tag.name),
            confirmLabel = stringResource(R.string.delete),
            onConfirm = {
                run {
                    store.deleteTag(tag)

                    if (editing?.friendlyId == tag.friendlyId) {
                        startNew()
                    }
                }
            },
            onDismiss = { toDelete = null },
        )
    }
}

@Composable
private fun PaletteRow(palette: Map<String, String>, picked: String, onPick: (String) -> Unit) {
    FlowRow(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 8.dp), horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        palette.forEach { (name, hex) ->
            ColorSwatch(hex, isPicked = name == picked, onClick = { onPick(name) }, modifier = Modifier.testTag("whatsapp.color.$name"))
        }
    }
}

/** The color of each priority, out of the palette. */
@Composable
internal fun PriorityColorsSheet(store: WhatsappChatToolsStore, onSaved: () -> Unit, onDismiss: () -> Unit) {
    val state by store.state.collectAsState()
    val run = rememberToolRunner()
    var colors by remember { mutableStateOf(WhatsappChatToolsStore.PRIORITIES.associateWith { state.priorityColors.colors[it.toString()] ?: "gray" }) }

    FormSheet(
        title = stringResource(R.string.whatsapp_priority_colors),
        confirmLabel = stringResource(R.string.save),
        isConfirmEnabled = true,
        onConfirm = {
            run {
                store.savePriorityColors(colors)
                onSaved()
                onDismiss()
            }
        },
        onDismiss = onDismiss,
    ) {
        items(WhatsappChatToolsStore.PRIORITIES.toList()) { priority ->
            Section(header = stringResource(R.string.whatsapp_priority, priority)) {
                PaletteRow(state.priorityColors.palette, colors.getValue(priority)) { colors = colors + (priority to it) }
            }
        }
    }
}

/** A setting of one chat: following its number (with what that is now), on, or off. */
@Composable
private fun TriStateRow(title: String, value: Boolean?, accountValue: Boolean, onChange: (Boolean?) -> Unit) {
    val on = stringResource(R.string.whatsapp_on)
    val off = stringResource(R.string.whatsapp_off)

    Column(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(title, style = MaterialTheme.typography.bodyLarge)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            FilterChip(selected = value == null, onClick = { onChange(null) }, label = { Text(stringResource(R.string.whatsapp_follow_number, if (accountValue) on else off)) })
            FilterChip(selected = value == true, onClick = { onChange(true) }, label = { Text(on) })
            FilterChip(selected = value == false, onClick = { onChange(false) }, label = { Text(off) })
        }
    }
}

/** What the chat sends to WhatsApp and whether its messages are pushed, each following its number by default. */
@Composable
internal fun ChatSettingsSheet(
    chat: WhatsappConversation,
    account: WhatsappAccount?,
    onSave: (WhatsappChatChange.Settings) -> Unit,
    onNumberDefaults: () -> Unit,
    onDismiss: () -> Unit,
) {
    val settings = chat.settings
    val defaults = account?.chatSettings
    var readReceipts by remember { mutableStateOf(settings?.sendsReadReceipts) }
    var typing by remember { mutableStateOf(settings?.sendsTyping) }
    var notifications by remember { mutableStateOf(settings?.notifiesMessages) }

    FormSheet(
        title = stringResource(R.string.whatsapp_chat_settings),
        confirmLabel = stringResource(R.string.save),
        isConfirmEnabled = true,
        onConfirm = { onSave(WhatsappChatChange.Settings(readReceipts, typing, notifications)) },
        onDismiss = onDismiss,
    ) {
        item {
            Section(footer = stringResource(R.string.whatsapp_chat_settings_footer)) {
                TriStateRow(stringResource(R.string.whatsapp_read_receipts), readReceipts, defaults?.sendsReadReceipts ?: false) { readReceipts = it }
                TriStateRow(stringResource(R.string.whatsapp_typing_setting), typing, defaults?.sendsTyping ?: false) { typing = it }
                TriStateRow(stringResource(R.string.whatsapp_notifications), notifications, defaults?.notifiesMessages ?: true) { notifications = it }
            }
        }

        item {
            Section {
                ListRow(title = stringResource(R.string.whatsapp_number_defaults), onClick = onNumberDefaults, titleContent = {
                    Text(stringResource(R.string.whatsapp_number_defaults), color = MaterialTheme.colorScheme.primary)
                })
            }
        }
    }
}

/** The defaults of the chats of one of the user's numbers. */
@Composable
internal fun NumberDefaultsSheet(store: WhatsappChatToolsStore, accountId: String?, onSaved: () -> Unit, onDismiss: () -> Unit) {
    val state by store.state.collectAsState()
    val run = rememberToolRunner()
    var pickedId by remember { mutableStateOf(accountId ?: state.accounts.firstOrNull()?.friendlyId) }
    val account = state.accounts.firstOrNull { it.friendlyId == (pickedId ?: state.accounts.firstOrNull()?.friendlyId) }
    var readReceipts by remember(account?.friendlyId) { mutableStateOf(account?.chatSettings?.sendsReadReceipts ?: false) }
    var typing by remember(account?.friendlyId) { mutableStateOf(account?.chatSettings?.sendsTyping ?: false) }
    var notifications by remember(account?.friendlyId) { mutableStateOf(account?.chatSettings?.notifiesMessages ?: true) }
    var phoneReads by remember(account?.friendlyId) { mutableStateOf(account?.chatSettings?.syncsPhoneReads ?: true) }

    FormSheet(
        title = stringResource(R.string.whatsapp_number_defaults),
        confirmLabel = stringResource(R.string.save),
        isConfirmEnabled = account != null,
        onConfirm = {
            account?.let {
                run {
                    store.saveAccountDefaults(it, readReceipts, typing, notifications, phoneReads)
                    onSaved()
                    onDismiss()
                }
            }
        },
        onDismiss = onDismiss,
    ) {
        if (accountId == null && state.accounts.size > 1) {
            item { AccountPicker(state.accounts, account) { pickedId = it.friendlyId } }
        }

        item {
            Section(header = account?.nickname, footer = stringResource(R.string.whatsapp_number_defaults_footer)) {
                SwitchRow(stringResource(R.string.whatsapp_read_receipts), readReceipts, showDivider = true) { readReceipts = it }
                SwitchRow(stringResource(R.string.whatsapp_typing_setting), typing, showDivider = true) { typing = it }
                SwitchRow(stringResource(R.string.whatsapp_notifications), notifications, showDivider = true) { notifications = it }
                SwitchRow(stringResource(R.string.whatsapp_phone_reads), phoneReads, showDivider = false) { phoneReads = it }
            }
        }
    }
}

@Composable
private fun SwitchRow(title: String, checked: Boolean, showDivider: Boolean, onChange: (Boolean) -> Unit) {
    ListRow(title = title, showDivider = showDivider, onClick = { onChange(!checked) }, trailing = { Switch(checked = checked, onCheckedChange = onChange) })
}

@Composable
internal fun AccountPicker(accounts: List<WhatsappAccount>, picked: WhatsappAccount?, onPick: (WhatsappAccount) -> Unit) {
    var isOpen by remember { mutableStateOf(false) }

    Section {
        Box {
            ListRow(
                title = stringResource(R.string.whatsapp_from_number),
                onClick = { isOpen = true },
                trailing = { Text(picked?.nickname.orEmpty(), color = MaterialTheme.colorScheme.primary) },
            )

            DropdownMenu(expanded = isOpen, onDismissRequest = { isOpen = false }, modifier = Modifier.align(Alignment.CenterEnd)) {
                accounts.forEach { account ->
                    DropdownMenuItem(
                        text = { Text(account.nickname) },
                        onClick = {
                            isOpen = false
                            onPick(account)
                        },
                    )
                }
            }
        }
    }
}

/** The chat with a number on one of the user's numbers; it opens once started. */
@Composable
internal fun NewChatSheet(model: AppModel, store: WhatsappChatToolsStore, onStarted: (WhatsappConversation) -> Unit, onDismiss: () -> Unit) {
    val state by store.state.collectAsState()
    val scope = rememberCoroutineScope()
    var pickedId by remember { mutableStateOf<String?>(null) }
    val account = state.accounts.firstOrNull { it.friendlyId == pickedId } ?: state.accounts.firstOrNull()
    var number by remember { mutableStateOf("") }
    var failure by remember { mutableStateOf<ApiException?>(null) }
    var isSaving by remember { mutableStateOf(false) }

    FormSheet(
        title = stringResource(R.string.whatsapp_new_chat),
        confirmLabel = stringResource(R.string.whatsapp_start),
        isConfirmEnabled = account != null && number.isNotBlank() && !isSaving,
        onConfirm = {
            val from = account ?: return@FormSheet
            scope.launch {
                isSaving = true
                try {
                    onStarted(model.client.call { startWhatsappConversation(WhatsappStartChat(from.friendlyId, number.trim())) }.data)
                } catch (exception: ApiException) {
                    failure = exception
                } finally {
                    isSaving = false
                }
            }
        },
        onDismiss = onDismiss,
    ) {
        if (state.accounts.size > 1) {
            item { AccountPicker(state.accounts, account) { pickedId = it.friendlyId } }
        }

        item {
            Section(footer = stringResource(R.string.whatsapp_new_chat_footer)) {
                SectionTextField(
                    value = number,
                    onValueChange = { number = it },
                    placeholder = stringResource(R.string.whatsapp_new_chat_number),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone),
                    modifier = Modifier.testTag("whatsapp.new-chat.number"),
                )
            }
        }

        failure?.let { item { FormError(it.userMessage()) } }
    }
}

/** A text and when (on the user's wall clock): the scheduled message, or the reminder. */
@Composable
internal fun TextAtSheet(
    model: AppModel,
    title: String,
    confirmLabel: String,
    placeholder: String,
    initialText: String,
    caption: String?,
    isMultiline: Boolean,
    onSave: suspend (String, String) -> Unit,
    onDismiss: () -> Unit,
) {
    val zone = WallClock.zone(model.user?.timezone)
    val scope = rememberCoroutineScope()
    var text by remember { mutableStateOf(initialText) }
    var at by remember { mutableStateOf(ZonedDateTime.now(zone).plusHours(1).truncatedTo(ChronoUnit.HOURS).toLocalDateTime()) }
    var failure by remember { mutableStateOf<ApiException?>(null) }
    var isSaving by remember { mutableStateOf(false) }

    FormSheet(
        title = title,
        confirmLabel = confirmLabel,
        isConfirmEnabled = text.isNotBlank() && !isSaving,
        onConfirm = {
            scope.launch {
                isSaving = true
                try {
                    onSave(text, WallClock.dateTime(at))
                    onDismiss()
                } catch (exception: ApiException) {
                    failure = exception
                } finally {
                    isSaving = false
                }
            }
        },
        onDismiss = onDismiss,
    ) {
        item {
            Section(footer = caption) {
                SectionTextField(
                    value = text,
                    onValueChange = { text = it },
                    placeholder = placeholder,
                    singleLine = !isMultiline,
                    minLines = if (isMultiline) 3 else 1,
                    modifier = Modifier.testTag("whatsapp.text-at.text"),
                )
            }
        }

        item {
            Section {
                DateTimeRows(at, model.language.locale, showDivider = false) { at = it }
            }
        }

        failure?.let { item { FormError(it.userMessage()) } }
    }
}

/** The chat's details: its name, number and tags, what is scheduled to it (cancel each) and its open reminders. */
@Composable
internal fun ChatDetailsSheet(model: AppModel, detail: WhatsappConversationDetail?, onCancelScheduled: (String) -> Unit, onDismiss: () -> Unit) {
    val timezone = model.user?.timezone ?: "UTC"
    val locale = model.language.locale
    var toCancel by remember { mutableStateOf<String?>(null) }

    FormSheet(
        title = stringResource(R.string.whatsapp_chat_details),
        confirmLabel = stringResource(R.string.ok),
        isConfirmEnabled = true,
        onConfirm = onDismiss,
        onDismiss = onDismiss,
    ) {
        val chat = detail?.data

        if (chat != null) {
            item {
                Section {
                    ListRow(title = chat.name, subtitle = listOfNotNull(chat.whatsappName?.takeIf { it != chat.name }, chat.phoneNumber, chat.account.nickname).joinToString(" · "))

                    if (chat.tags.isNotEmpty()) {
                        FlowRow(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 8.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            chat.tags.forEach { TagLabel(it) }
                        }
                    }
                }
            }
        }

        item {
            Section(header = stringResource(R.string.whatsapp_scheduled)) {
                val scheduled = detail?.scheduled.orEmpty()

                if (scheduled.isEmpty()) {
                    ListRow(title = stringResource(R.string.whatsapp_nothing_scheduled))
                }

                scheduled.forEachIndexed { index, message ->
                    val media = message.media

                    ListRow(
                        title = message.body.ifBlank { media?.name ?: stringResource(if (media?.isAudio == true) R.string.whatsapp_voice_note else R.string.whatsapp_file) },
                        subtitle = formatDateTime(message.nextRunAt, timezone, locale),
                        showDivider = index < scheduled.lastIndex,
                        leading = {
                            Icon(
                                when {
                                    media == null -> Icons.Outlined.Schedule
                                    media.isAudio -> Icons.Outlined.Mic
                                    media.mime?.startsWith("image/") == true -> Icons.Outlined.Image
                                    else -> Icons.Outlined.AttachFile
                                },
                                contentDescription = null,
                                tint = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        },
                        trailing = {
                            TextButton(onClick = { toCancel = message.friendlyId }) { Text(stringResource(R.string.whatsapp_cancel_message), color = MaterialTheme.colorScheme.error) }
                        },
                    )
                }
            }
        }

        item {
            Section(header = stringResource(R.string.whatsapp_reminders)) {
                val reminders = detail?.reminders.orEmpty()

                if (reminders.isEmpty()) {
                    ListRow(title = stringResource(R.string.whatsapp_no_reminders))
                }

                reminders.forEachIndexed { index, reminder ->
                    ListRow(
                        title = reminder.title,
                        subtitle = formatDateTime(reminder.dueAt, timezone, locale),
                        showDivider = index < reminders.lastIndex,
                        leading = { Icon(Icons.Outlined.Alarm, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant) },
                    )
                }
            }
        }
    }

    toCancel?.let { id ->
        ConfirmDialog(
            title = stringResource(R.string.whatsapp_cancel_confirm),
            confirmLabel = stringResource(R.string.whatsapp_cancel_message),
            dismissLabel = stringResource(R.string.keep),
            onConfirm = { onCancelScheduled(id) },
            onDismiss = { toCancel = null },
        )
    }
}

/** A tag as a small colored label. */
@Composable
internal fun TagLabel(tag: WhatsappConversationTag) {
    val color = hexColor(tag.color, MaterialTheme.colorScheme.outline)

    Text(
        tag.name,
        style = MaterialTheme.typography.labelSmall,
        color = color,
        modifier = Modifier.background(color.copy(alpha = 0.15f), CircleShape).padding(horizontal = 8.dp, vertical = 2.dp),
    )
}

