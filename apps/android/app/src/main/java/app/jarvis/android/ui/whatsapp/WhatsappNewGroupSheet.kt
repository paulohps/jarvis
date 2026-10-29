package app.jarvis.android.ui.whatsapp

import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import app.jarvis.android.R
import app.jarvis.android.app.AppModel
import app.jarvis.android.network.WhatsappConversation
import app.jarvis.android.ui.components.Section
import app.jarvis.android.ui.components.SectionTextField

/** The longest subject WhatsApp takes for a group. */
private const val GROUP_SUBJECT_LENGTH = 100

/** The longest description WhatsApp takes for a group. */
private const val GROUP_DESCRIPTION_LENGTH = 2048

/** At most this many members are picked for a new group here. */
private const val MAX_NEW_GROUP_MEMBERS = 50

/**
 * A new WhatsApp group: the number it is created on (when the user has
 * more than one), its subject and description, and its members out of the
 * number's contacts; it opens once created ([onCreated]).
 */
@Composable
internal fun NewGroupSheet(
    model: AppModel,
    tools: WhatsappChatToolsStore,
    onCreate: suspend (accountId: String, subject: String, memberIds: List<String>, description: String?) -> WhatsappConversation,
    onCreated: (WhatsappConversation) -> Unit,
    onDismiss: () -> Unit,
) {
    val toolsState by tools.state.collectAsState()
    var pickedAccount by remember { mutableStateOf<String?>(null) }
    val account = toolsState.accounts.firstOrNull { it.friendlyId == pickedAccount } ?: toolsState.accounts.firstOrNull()
    var subject by remember { mutableStateOf("") }
    var description by remember { mutableStateOf("") }

    ContactPickerSheet(
        client = model.client,
        accountId = account?.friendlyId,
        title = stringResource(R.string.whatsapp_new_group),
        confirmLabel = stringResource(R.string.whatsapp_create),
        max = MAX_NEW_GROUP_MEMBERS,
        footer = stringResource(R.string.whatsapp_new_group_members_footer),
        canConfirm = account != null && subject.isNotBlank(),
        header = {
            if (toolsState.accounts.size > 1) {
                item { AccountPicker(toolsState.accounts, account) { pickedAccount = it.friendlyId } }
            }

            item {
                Section(header = stringResource(R.string.whatsapp_group_subject)) {
                    SectionTextField(
                        value = subject,
                        onValueChange = { subject = it.take(GROUP_SUBJECT_LENGTH) },
                        placeholder = stringResource(R.string.whatsapp_group_subject_placeholder),
                        modifier = Modifier.testTag("whatsapp.new-group.subject"),
                    )
                    SectionTextField(
                        value = description,
                        onValueChange = { description = it.take(GROUP_DESCRIPTION_LENGTH) },
                        placeholder = stringResource(R.string.whatsapp_group_description_placeholder),
                        singleLine = false,
                        modifier = Modifier.testTag("whatsapp.new-group.description"),
                    )
                }
            }
        },
        onConfirm = { members ->
            val from = account ?: return@ContactPickerSheet
            onCreated(onCreate(from.friendlyId, subject, members.map { it.friendlyId }, description))
        },
        onDismiss = onDismiss,
    )
}
