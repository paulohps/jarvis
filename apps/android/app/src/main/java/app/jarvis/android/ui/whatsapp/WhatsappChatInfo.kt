package app.jarvis.android.ui.whatsapp

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.widget.Toast
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyListScope
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ExitToApp
import androidx.compose.material.icons.filled.Block
import androidx.compose.material.icons.filled.Link
import androidx.compose.material.icons.filled.PersonAdd
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.outlined.Edit
import androidx.compose.material.icons.outlined.Image
import androidx.compose.material.icons.outlined.Info
import androidx.compose.material.icons.outlined.NotificationsOff
import androidx.compose.material.icons.outlined.PhotoLibrary
import androidx.compose.material.icons.outlined.Timer
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.RadioButton
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import app.jarvis.android.R
import app.jarvis.android.app.AppModel
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.WhatsappConversation
import app.jarvis.android.network.WhatsappGroup
import app.jarvis.android.network.WhatsappGroupMember
import app.jarvis.android.ui.components.ConfirmDialog
import app.jarvis.android.ui.components.FormSheet
import app.jarvis.android.ui.components.ListRow
import app.jarvis.android.ui.components.Section
import app.jarvis.android.ui.components.TextFieldDialog
import app.jarvis.android.ui.components.formatDateTime
import app.jarvis.android.ui.components.userMessage
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

/** The biggest picture the server takes for a group. */
private const val MAX_GROUP_PICTURE_BYTES = 5L * 1024 * 1024

/** What the info screen opens over itself, one at a time. */
private sealed interface InfoDialog {
    data object Subject : InfoDialog

    data object Description : InfoDialog

    data object Ephemeral : InfoDialog

    data object Mute : InfoDialog

    data object Block : InfoDialog

    data object Leave : InfoDialog

    data object AddMembers : InfoDialog

    data object RevokeInvite : InfoDialog

    data class RemoveMember(val member: WhatsappGroupMember) : InfoDialog

    data class Browse(val mode: MessageBrowserMode) : InfoDialog
}

/**
 * The chat's info, opened from its header as on WhatsApp: a contact's
 * number and blocking it; a group's subject, description and picture
 * (changed when the number is an admin or the group lets anyone), its
 * disappearing messages, its permissions (admins), its members (promoted,
 * demoted or removed by an admin, added from the number's contacts), the
 * invite link (shown, copied, reset) and leaving it. For any chat, muting
 * it, the search in it, the starred messages and the gallery, whose results
 * open in the chat ([onOpenMessage]); [onDetails] shows what is scheduled
 * to it and its reminders.
 */
@Composable
internal fun ChatInfoSheet(
    model: AppModel,
    chat: WhatsappConversation,
    onChange: (WhatsappChatChange) -> Unit,
    onOpenMessage: (String) -> Unit,
    onLeft: (WhatsappConversation) -> Unit,
    onDetails: () -> Unit,
    onDismiss: () -> Unit,
) {
    val store = remember(chat.friendlyId) { WhatsappChatInfoStore(chat.friendlyId, model.client) }
    val state by store.state.collectAsState()
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val run = rememberToolRunner()
    val timezone = model.user?.timezone ?: "UTC"
    val locale = model.language.locale
    var dialog by remember { mutableStateOf<InfoDialog?>(null) }
    val tooBig = stringResource(R.string.whatsapp_file_too_big)
    val group = state.group

    LaunchedEffect(chat.friendlyId) {
        if (chat.isGroup) {
            store.loadGroup()
        }
    }

    val picturePicker = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri ->
        uri ?: return@rememberLauncherForActivityResult

        run {
            val file = withContext(Dispatchers.IO) { readPicked(context, uri, MAX_GROUP_PICTURE_BYTES) }

            if (file == null) {
                Toast.makeText(context, tooBig, Toast.LENGTH_LONG).show()
            } else {
                store.changePicture(file.name, file.mime, file.bytes)
                Toast.makeText(context, context.getString(R.string.whatsapp_group_picture_changed), Toast.LENGTH_SHORT).show()
            }
        }
    }

    FormSheet(
        title = stringResource(if (chat.isGroup) R.string.whatsapp_group_info else R.string.whatsapp_contact_info),
        confirmLabel = stringResource(R.string.ok),
        isConfirmEnabled = true,
        onConfirm = onDismiss,
        onDismiss = onDismiss,
    ) {
        item {
            Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(6.dp)) {
                ChatAvatar(chat.name, chat.pictureUrl, chat.isGroup, size = 96.dp)
                Text(group?.chat?.name ?: chat.name, style = MaterialTheme.typography.headlineSmall, textAlign = TextAlign.Center)
                Text(
                    when {
                        group != null -> pluralStringResource(R.plurals.whatsapp_group_members_count, group.members.size, group.members.size)
                        chat.isGroup -> stringResource(R.string.whatsapp_group)
                        else -> chat.phoneNumber.orEmpty()
                    },
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }

        if (chat.isGroup) {
            groupSections(
                chat = chat,
                group = group,
                failure = state.groupFailure,
                inviteUrl = state.inviteUrl,
                onEdit = { dialog = it },
                onPicture = { picturePicker.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) },
                onPermission = { change -> run { store.updateGroup(change) } },
                onMember = { member, action ->
                    if (action == WhatsappChatInfoStore.REMOVE) {
                        dialog = InfoDialog.RemoveMember(member)
                    } else {
                        run { store.members(action, listOf(member.jid)) }
                    }
                },
                onInvite = { run { store.invite() } },
                onCopyInvite = { url -> copy(context, url) },
                onRetry = { run { store.loadGroup() } },
            )
        } else {
            item {
                Section {
                    ListRow(title = chat.phoneNumber ?: chat.name, subtitle = chat.account.nickname)
                }
            }
        }

        item {
            Section {
                ListRow(
                    title = stringResource(R.string.whatsapp_search_in_chat),
                    leading = { Icon(Icons.Filled.Search, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant) },
                    showDivider = true,
                    onClick = { dialog = InfoDialog.Browse(MessageBrowserMode.Search) },
                    modifier = Modifier.testTag("whatsapp.info.search"),
                )
                ListRow(
                    title = stringResource(R.string.whatsapp_starred_messages),
                    leading = { Icon(Icons.Filled.Star, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant) },
                    showDivider = true,
                    onClick = { dialog = InfoDialog.Browse(MessageBrowserMode.Starred) },
                    modifier = Modifier.testTag("whatsapp.info.starred"),
                )
                ListRow(
                    title = stringResource(R.string.whatsapp_gallery),
                    leading = { Icon(Icons.Outlined.PhotoLibrary, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant) },
                    showDivider = true,
                    onClick = { dialog = InfoDialog.Browse(MessageBrowserMode.Gallery) },
                    modifier = Modifier.testTag("whatsapp.info.gallery"),
                )
                ListRow(
                    title = stringResource(R.string.whatsapp_chat_details),
                    leading = { Icon(Icons.Outlined.Info, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant) },
                    onClick = onDetails,
                )
            }
        }

        item {
            Section {
                ListRow(
                    title = stringResource(if (chat.isMuted) R.string.whatsapp_unmute else R.string.whatsapp_mute),
                    subtitle = chat.mutedUntil?.let { mutedUntilLabel(it, timezone, locale) },
                    leading = { Icon(Icons.Outlined.NotificationsOff, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant) },
                    showDivider = !chat.isGroup || group != null,
                    onClick = { if (chat.isMuted) onChange(WhatsappChatChange.Muted(null)) else dialog = InfoDialog.Mute },
                    modifier = Modifier.testTag("whatsapp.info.mute"),
                )

                if (chat.isGroup) {
                    if (group != null) {
                        ListRow(
                            title = stringResource(R.string.whatsapp_leave_group),
                            leading = { Icon(Icons.AutoMirrored.Filled.ExitToApp, contentDescription = null, tint = MaterialTheme.colorScheme.error) },
                            titleContent = { Text(stringResource(R.string.whatsapp_leave_group), color = MaterialTheme.colorScheme.error) },
                            onClick = { dialog = InfoDialog.Leave },
                            modifier = Modifier.testTag("whatsapp.info.leave"),
                        )
                    }
                } else {
                    val label = stringResource(if (chat.blocked) R.string.whatsapp_unblock else R.string.whatsapp_block, chat.name)

                    ListRow(
                        title = label,
                        leading = { Icon(Icons.Filled.Block, contentDescription = null, tint = MaterialTheme.colorScheme.error) },
                        titleContent = { Text(label, color = MaterialTheme.colorScheme.error) },
                        onClick = { if (chat.blocked) onChange(WhatsappChatChange.Blocked(false)) else dialog = InfoDialog.Block },
                        modifier = Modifier.testTag("whatsapp.info.block"),
                    )
                }
            }
        }
    }

    val dismiss = { dialog = null }

    when (val current = dialog) {
        null -> Unit
        InfoDialog.Subject -> TextFieldDialog(
            title = stringResource(R.string.whatsapp_group_subject),
            label = stringResource(R.string.whatsapp_group_subject),
            confirmLabel = stringResource(R.string.save),
            initialValue = group?.chat?.name ?: chat.name,
            onConfirm = { subject -> run { store.updateGroup(WhatsappGroupChange.Subject(subject)) } },
            onDismiss = dismiss,
        )

        InfoDialog.Description -> TextFieldDialog(
            title = stringResource(R.string.whatsapp_group_description),
            label = stringResource(R.string.whatsapp_group_description),
            confirmLabel = stringResource(R.string.save),
            initialValue = group?.chat?.description ?: chat.description.orEmpty(),
            onConfirm = { description -> run { store.updateGroup(WhatsappGroupChange.Description(description)) } },
            onDismiss = dismiss,
        )

        InfoDialog.Ephemeral -> ChoiceDialog(
            title = stringResource(R.string.whatsapp_disappearing_messages),
            options = WhatsappChatInfoStore.EPHEMERAL_TIMERS,
            current = group?.chat?.ephemeralSeconds ?: chat.ephemeralSeconds,
            label = { ephemeralLabel(it) },
            onPick = { seconds ->
                dismiss()
                run { store.updateGroup(WhatsappGroupChange.Ephemeral(seconds)) }
            },
            onDismiss = dismiss,
        )

        InfoDialog.Mute -> MuteDialog(onPick = { duration ->
            dismiss()
            onChange(WhatsappChatChange.Muted(duration))
        }, onDismiss = dismiss)

        InfoDialog.Block -> ConfirmDialog(
            title = stringResource(R.string.whatsapp_block_confirm, chat.name),
            message = stringResource(R.string.whatsapp_block_message),
            confirmLabel = stringResource(R.string.whatsapp_block_action),
            onConfirm = { onChange(WhatsappChatChange.Blocked(true)) },
            onDismiss = dismiss,
        )

        InfoDialog.Leave -> ConfirmDialog(
            title = stringResource(R.string.whatsapp_leave_group_confirm, chat.name),
            message = stringResource(R.string.whatsapp_leave_group_message),
            confirmLabel = stringResource(R.string.whatsapp_leave),
            onConfirm = { run { onLeft(store.leave()) } },
            onDismiss = dismiss,
        )

        InfoDialog.RevokeInvite -> ConfirmDialog(
            title = stringResource(R.string.whatsapp_reset_invite_confirm),
            message = stringResource(R.string.whatsapp_reset_invite_message),
            confirmLabel = stringResource(R.string.whatsapp_reset_invite),
            onConfirm = { run { store.invite(revoke = true) } },
            onDismiss = dismiss,
        )

        is InfoDialog.RemoveMember -> ConfirmDialog(
            title = stringResource(R.string.whatsapp_remove_member_confirm, current.member.name),
            confirmLabel = stringResource(R.string.whatsapp_remove),
            onConfirm = { run { store.members(WhatsappChatInfoStore.REMOVE, listOf(current.member.jid)) } },
            onDismiss = dismiss,
        )

        InfoDialog.AddMembers -> ContactPickerSheet(
            client = model.client,
            accountId = chat.account.friendlyId,
            title = stringResource(R.string.whatsapp_add_members),
            confirmLabel = stringResource(R.string.whatsapp_add),
            max = MAX_MEMBERS_AT_ONCE,
            footer = stringResource(R.string.whatsapp_add_members_footer),
            onConfirm = { picked ->
                val added = store.members(WhatsappChatInfoStore.ADD, picked.mapNotNull { it.phoneNumber })
                Toast.makeText(context, context.resources.getQuantityString(R.plurals.whatsapp_members_added, added, added), Toast.LENGTH_SHORT).show()
                dismiss()
            },
            onDismiss = dismiss,
        )

        is InfoDialog.Browse -> MessageBrowserSheet(
            store = store,
            mode = current.mode,
            timezone = timezone,
            locale = locale,
            onOpen = { id ->
                dismiss()
                onOpenMessage(id)
            },
            onDismiss = dismiss,
        )
    }
}

/** At most this many members are added at once (as the server takes). */
private const val MAX_MEMBERS_AT_ONCE = 50

/** The group's sections: its info, its disappearing messages, the admins' permissions, the members and the invite link. */
private fun LazyListScope.groupSections(
    chat: WhatsappConversation,
    group: WhatsappGroup?,
    failure: ApiException?,
    inviteUrl: String?,
    onEdit: (InfoDialog) -> Unit,
    onPicture: () -> Unit,
    onPermission: (WhatsappGroupChange) -> Unit,
    onMember: (WhatsappGroupMember, String) -> Unit,
    onInvite: () -> Unit,
    onCopyInvite: (String) -> Unit,
    onRetry: () -> Unit,
) {
    if (group == null) {
        item {
            Section {
                if (failure != null) {
                    ListRow(title = failure.userMessage(), subtitle = stringResource(R.string.try_again), onClick = onRetry)
                } else {
                    Box(Modifier.fillMaxWidth().padding(16.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
                }
            }
        }

        return
    }

    val canEdit = group.canEditInfo

    item {
        Section(header = stringResource(R.string.whatsapp_group_description)) {
            ListRow(
                title = group.chat.description?.takeIf { it.isNotBlank() } ?: stringResource(R.string.whatsapp_no_description),
                trailing = if (canEdit) ({ Icon(Icons.Outlined.Edit, contentDescription = stringResource(R.string.edit), tint = MaterialTheme.colorScheme.onSurfaceVariant) }) else null,
                onClick = if (canEdit) ({ onEdit(InfoDialog.Description) }) else null,
                modifier = Modifier.testTag("whatsapp.info.description"),
            )
        }
    }

    item {
        Section {
            if (canEdit) {
                ListRow(
                    title = stringResource(R.string.whatsapp_group_subject),
                    subtitle = group.chat.name,
                    leading = { Icon(Icons.Outlined.Edit, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant) },
                    showDivider = true,
                    onClick = { onEdit(InfoDialog.Subject) },
                    modifier = Modifier.testTag("whatsapp.info.subject"),
                )
                ListRow(
                    title = stringResource(R.string.whatsapp_group_picture),
                    leading = { Icon(Icons.Outlined.Image, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant) },
                    showDivider = true,
                    onClick = onPicture,
                    modifier = Modifier.testTag("whatsapp.info.picture"),
                )
            }

            ListRow(
                title = stringResource(R.string.whatsapp_disappearing_messages),
                subtitle = ephemeralLabel(group.chat.ephemeralSeconds),
                leading = { Icon(Icons.Outlined.Timer, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant) },
                onClick = if (canEdit) ({ onEdit(InfoDialog.Ephemeral) }) else null,
                modifier = Modifier.testTag("whatsapp.info.ephemeral"),
            )
        }
    }

    if (group.isAdmin) {
        item {
            Section(header = stringResource(R.string.whatsapp_group_permissions)) {
                ListRow(
                    title = stringResource(R.string.whatsapp_admins_only_send),
                    showDivider = true,
                    onClick = { onPermission(WhatsappGroupChange.AdminsOnly(!group.adminsOnly)) },
                    trailing = { Switch(checked = group.adminsOnly, onCheckedChange = { onPermission(WhatsappGroupChange.AdminsOnly(it)) }, modifier = Modifier.testTag("whatsapp.info.admins-only")) },
                )
                ListRow(
                    title = stringResource(R.string.whatsapp_admins_only_info),
                    onClick = { onPermission(WhatsappGroupChange.InfoAdminsOnly(!group.infoAdminsOnly)) },
                    trailing = { Switch(checked = group.infoAdminsOnly, onCheckedChange = { onPermission(WhatsappGroupChange.InfoAdminsOnly(it)) }, modifier = Modifier.testTag("whatsapp.info.info-admins-only")) },
                )
            }
        }
    }

    item {
        Section(header = pluralStringResource(R.plurals.whatsapp_group_members_count, group.members.size, group.members.size)) {
            if (group.isAdmin) {
                ListRow(
                    title = stringResource(R.string.whatsapp_add_members),
                    leading = { Icon(Icons.Filled.PersonAdd, contentDescription = null, tint = MaterialTheme.colorScheme.primary) },
                    titleContent = { Text(stringResource(R.string.whatsapp_add_members), color = MaterialTheme.colorScheme.primary) },
                    showDivider = true,
                    onClick = { onEdit(InfoDialog.AddMembers) },
                    modifier = Modifier.testTag("whatsapp.info.add-members"),
                )
                ListRow(
                    title = stringResource(R.string.whatsapp_invite_link),
                    subtitle = inviteUrl,
                    leading = { Icon(Icons.Filled.Link, contentDescription = null, tint = MaterialTheme.colorScheme.primary) },
                    titleContent = { Text(stringResource(R.string.whatsapp_invite_link), color = MaterialTheme.colorScheme.primary) },
                    trailing = if (inviteUrl != null) ({
                        Column {
                            TextButton(onClick = { onCopyInvite(inviteUrl) }, modifier = Modifier.testTag("whatsapp.info.invite.copy")) { Text(stringResource(R.string.whatsapp_copy)) }
                            TextButton(onClick = { onEdit(InfoDialog.RevokeInvite) }, modifier = Modifier.testTag("whatsapp.info.invite.reset")) {
                                Text(stringResource(R.string.whatsapp_reset_invite), color = MaterialTheme.colorScheme.error)
                            }
                        }
                    }) else null,
                    showDivider = true,
                    onClick = if (inviteUrl == null) onInvite else null,
                    modifier = Modifier.testTag("whatsapp.info.invite"),
                )
            }

            group.members.sortedWith(compareByDescending<WhatsappGroupMember> { it.me }.thenByDescending { it.admin }.thenBy { it.name.lowercase() }).let { members ->
                members.forEachIndexed { index, member -> MemberRow(member, canManage = group.isAdmin && !member.me, showDivider = index < members.lastIndex, onAction = { onMember(member, it) }) }
            }
        }
    }
}

/** A member: their name (or "You"), number and the admin mark; an admin taps it to promote, demote or remove them. */
@Composable
private fun MemberRow(member: WhatsappGroupMember, canManage: Boolean, showDivider: Boolean, onAction: (String) -> Unit) {
    var isMenuOpen by remember { mutableStateOf(false) }

    Box {
        ListRow(
            title = if (member.me) stringResource(R.string.whatsapp_you_quoted) else member.name,
            subtitle = member.number,
            leading = { ChatAvatar(member.name, null, isGroup = false, size = 40.dp) },
            trailing = if (member.admin) ({ Text(stringResource(R.string.whatsapp_group_admin), style = MaterialTheme.typography.labelMedium, color = WhatsappGreen, fontWeight = FontWeight.SemiBold) }) else null,
            showDivider = showDivider,
            onClick = if (canManage) ({ isMenuOpen = true }) else null,
            modifier = Modifier.testTag("whatsapp.info.member.${member.jid}"),
        )

        DropdownMenu(expanded = isMenuOpen, onDismissRequest = { isMenuOpen = false }, modifier = Modifier.align(Alignment.CenterEnd)) {
            val item: @Composable (Int, String) -> Unit = { label, action ->
                DropdownMenuItem(
                    text = { Text(stringResource(label), color = if (action == WhatsappChatInfoStore.REMOVE) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurface) },
                    onClick = {
                        isMenuOpen = false
                        onAction(action)
                    },
                )
            }

            if (member.admin) {
                item(R.string.whatsapp_demote, WhatsappChatInfoStore.DEMOTE)
            } else {
                item(R.string.whatsapp_promote, WhatsappChatInfoStore.PROMOTE)
            }

            item(R.string.whatsapp_remove_member, WhatsappChatInfoStore.REMOVE)
        }
    }
}

/** The timer of disappearing messages in words ("Off", "24 hours"…). */
@Composable
internal fun ephemeralLabel(seconds: Int): String = stringResource(
    when (seconds) {
        0 -> R.string.whatsapp_off
        86_400 -> R.string.whatsapp_ephemeral_day
        604_800 -> R.string.whatsapp_ephemeral_week
        7_776_000 -> R.string.whatsapp_ephemeral_90_days
        else -> R.string.whatsapp_on
    },
)

/** Until when a chat is muted ("Always" when for good). */
@Composable
internal fun mutedUntilLabel(mutedUntil: String, timezone: String, locale: java.util.Locale): String {
    val year = runCatching { java.time.OffsetDateTime.parse(mutedUntil).year }.getOrNull()

    // The server keeps "always" as a date far ahead.
    return if (year != null && year > java.time.Year.now().value + 50) {
        stringResource(R.string.whatsapp_muted_always)
    } else {
        stringResource(R.string.whatsapp_muted_until, formatDateTime(mutedUntil, timezone, locale).orEmpty())
    }
}

/** How long to mute a chat: 8 hours, a week or always. */
@Composable
internal fun MuteDialog(onPick: (String) -> Unit, onDismiss: () -> Unit) {
    ChoiceDialog(
        title = stringResource(R.string.whatsapp_mute_for),
        options = WhatsappChatToolsStore.MUTE_DURATIONS,
        current = null,
        label = {
            stringResource(
                when (it) {
                    "8h" -> R.string.whatsapp_mute_8_hours
                    "1w" -> R.string.whatsapp_mute_week
                    else -> R.string.whatsapp_mute_always
                },
            )
        },
        onPick = onPick,
        onDismiss = onDismiss,
    )
}

/** One of [options], picked with a tap. */
@Composable
private fun <T> ChoiceDialog(title: String, options: List<T>, current: T?, label: @Composable (T) -> String, onPick: (T) -> Unit, onDismiss: () -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title) },
        text = {
            Column {
                options.forEach { option ->
                    ListRow(
                        title = label(option),
                        leading = { RadioButton(selected = option == current, onClick = { onPick(option) }) },
                        onClick = { onPick(option) },
                        modifier = Modifier.testTag("whatsapp.choice.$option"),
                    )
                }
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.cancel)) } },
    )
}

private fun copy(context: Context, text: String) {
    context.getSystemService(ClipboardManager::class.java)?.setPrimaryClip(ClipData.newPlainText(text, text))
    Toast.makeText(context, R.string.whatsapp_copied, Toast.LENGTH_SHORT).show()
}
