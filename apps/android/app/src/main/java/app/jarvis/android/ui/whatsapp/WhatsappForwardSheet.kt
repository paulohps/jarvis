package app.jarvis.android.ui.whatsapp

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import app.jarvis.android.R
import app.jarvis.android.network.ApiClient
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.WhatsappConversation
import app.jarvis.android.ui.components.FormError
import app.jarvis.android.ui.components.FormSheet
import app.jarvis.android.ui.components.ListRow
import app.jarvis.android.ui.components.Section
import app.jarvis.android.ui.components.SectionTextField
import app.jarvis.android.ui.components.userMessage
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** How long the search waits for the typing to stop. */
private const val FORWARD_SEARCH_DEBOUNCE_MS = 300L

/**
 * Where the picked messages go: the chats of the same number ([accountId]),
 * searched by name or number, up to [WhatsappChatStore.MAX_FORWARD_CHATS];
 * [onForward] sends them, and a refusal of the server (a 422) shows here.
 */
@Composable
internal fun ForwardSheet(client: ApiClient, accountId: String?, count: Int, onForward: suspend (List<String>) -> Unit, onDismiss: () -> Unit) {
    val scope = rememberCoroutineScope()
    var search by remember { mutableStateOf("") }
    var chats by remember { mutableStateOf<List<WhatsappConversation>?>(null) }
    var picked by remember { mutableStateOf(listOf<WhatsappConversation>()) }
    var failure by remember { mutableStateOf<ApiException?>(null) }
    var isSending by remember { mutableStateOf(false) }

    LaunchedEffect(search, accountId) {
        if (search.isNotEmpty()) {
            delay(FORWARD_SEARCH_DEBOUNCE_MS)
        }

        chats = try {
            client.call { whatsappConversations(search = search.trim().ifEmpty { null }, perPage = 50, account = accountId) }.data
        } catch (exception: ApiException) {
            failure = exception
            emptyList()
        }
    }

    FormSheet(
        title = stringResource(R.string.whatsapp_forward_title, count),
        confirmLabel = stringResource(R.string.whatsapp_forward),
        isConfirmEnabled = picked.isNotEmpty() && !isSending,
        onConfirm = {
            scope.launch {
                isSending = true
                failure = null

                try {
                    onForward(picked.map { it.friendlyId })
                } catch (_: ApiException.Unauthenticated) {
                    // The shell already went back to the login.
                } catch (exception: ApiException) {
                    failure = exception
                } finally {
                    isSending = false
                }
            }
        },
        onDismiss = onDismiss,
    ) {
        item {
            Section(footer = stringResource(R.string.whatsapp_forward_footer, WhatsappChatStore.MAX_FORWARD_CHATS)) {
                SectionTextField(
                    value = search,
                    onValueChange = { search = it },
                    placeholder = stringResource(R.string.whatsapp_forward_search),
                    modifier = Modifier.testTag("whatsapp.forward.search"),
                )
            }
        }

        failure?.let { item { FormError(it.firstError("to") ?: it.firstError("messages") ?: it.userMessage()) } }

        // The picked ones stay on top while searching for others.
        val listed = picked + chats.orEmpty().filterNot { chat -> picked.any { it.friendlyId == chat.friendlyId } }

        item {
            Section {
                when {
                    chats == null -> Box(Modifier.fillMaxWidth().padding(16.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
                    listed.isEmpty() -> ListRow(title = stringResource(R.string.whatsapp_forward_none))
                    else -> listed.forEachIndexed { index, chat ->
                        val isPicked = picked.any { it.friendlyId == chat.friendlyId }
                        val canPick = isPicked || picked.size < WhatsappChatStore.MAX_FORWARD_CHATS
                        val toggle = {
                            picked = if (isPicked) picked.filterNot { it.friendlyId == chat.friendlyId } else picked + chat
                        }

                        ListRow(
                            title = chat.name,
                            subtitle = if (chat.isGroup) stringResource(R.string.whatsapp_group) else chat.phoneNumber,
                            leading = { ChatAvatar(chat.name, chat.pictureUrl, chat.isGroup, size = 40.dp) },
                            trailing = { Checkbox(checked = isPicked, onCheckedChange = { toggle() }, enabled = canPick) },
                            showDivider = index < listed.lastIndex,
                            onClick = if (canPick) toggle else null,
                            modifier = Modifier.testTag("whatsapp.forward.chat.${chat.friendlyId}"),
                        )
                    }
                }
            }
        }
    }
}
