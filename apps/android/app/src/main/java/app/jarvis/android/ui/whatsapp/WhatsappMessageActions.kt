package app.jarvis.android.ui.whatsapp

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Done
import androidx.compose.material.icons.filled.DoneAll
import androidx.compose.material.icons.filled.PlayCircle
import androidx.compose.material.icons.outlined.Schedule
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import app.jarvis.android.R
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.WhatsappConversationMessage
import app.jarvis.android.network.WhatsappMessageInfo
import app.jarvis.android.network.WhatsappReceipt
import app.jarvis.android.ui.components.ListRow
import app.jarvis.android.ui.components.formatDateTime
import app.jarvis.android.ui.components.userMessage
import kotlinx.coroutines.launch

/** How long a text WhatsApp takes. */
private const val MAX_TEXT_LENGTH = 4096

/**
 * The new text of a message the number sent (WhatsApp lets it change for 15
 * minutes); a refusal of the server shows in the dialog.
 */
@Composable
internal fun EditMessageDialog(message: WhatsappConversationMessage, onSave: suspend (String) -> Unit, onDismiss: () -> Unit) {
    val scope = rememberCoroutineScope()
    var text by remember { mutableStateOf(message.body.orEmpty()) }
    var failure by remember { mutableStateOf<ApiException?>(null) }
    var isSaving by remember { mutableStateOf(false) }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.whatsapp_edit_message)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(
                    value = text,
                    onValueChange = { text = it.take(MAX_TEXT_LENGTH) },
                    minLines = 2,
                    modifier = Modifier.fillMaxWidth().testTag("whatsapp.edit.text"),
                )

                failure?.let { Text(it.firstError("text") ?: it.userMessage(), color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall) }
            }
        },
        confirmButton = {
            TextButton(
                enabled = text.isNotBlank() && text.trim() != message.body?.trim() && !isSaving,
                onClick = {
                    scope.launch {
                        isSaving = true
                        failure = null

                        try {
                            onSave(text)
                            onDismiss()
                        } catch (_: ApiException.Unauthenticated) {
                            // The shell already went back to the login.
                        } catch (exception: ApiException) {
                            failure = exception
                        } finally {
                            isSaving = false
                        }
                    }
                },
                modifier = Modifier.testTag("whatsapp.edit.save"),
            ) {
                Text(stringResource(R.string.save))
            }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.cancel)) } },
    )
}

/**
 * How far a message the number sent got, and when (WhatsApp's "Info"): in a
 * one-to-one chat the times it was delivered, read and played; in a group
 * the ticks only, WhatsApp telling Jarvis nothing per member.
 */
@Composable
internal fun MessageInfoDialog(load: suspend () -> WhatsappMessageInfo, timezone: String, locale: java.util.Locale, onDismiss: () -> Unit) {
    var info by remember { mutableStateOf<WhatsappMessageInfo?>(null) }
    var failure by remember { mutableStateOf<ApiException?>(null) }

    LaunchedEffect(Unit) {
        try {
            info = load()
        } catch (exception: ApiException) {
            failure = exception
        }
    }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.whatsapp_message_info)) },
        text = {
            val current = info
            val failed = failure

            when {
                failed != null -> Text(failed.userMessage(), color = MaterialTheme.colorScheme.error)
                current == null -> Box(Modifier.fillMaxWidth().padding(16.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
                else -> Column(Modifier.testTag("whatsapp.message.info")) {
                    val row: @Composable (ImageVector, Int, String?) -> Unit = { icon, label, at ->
                        ListRow(
                            title = stringResource(label),
                            subtitle = formatDateTime(at, timezone, locale) ?: "—",
                            leading = { Icon(icon, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant) },
                        )
                    }

                    row(Icons.Outlined.Schedule, R.string.whatsapp_info_sent, current.sentAt)

                    if (current.perMember) {
                        row(Icons.Filled.DoneAll, R.string.whatsapp_receipt_delivered, current.deliveredAt)
                        row(Icons.Filled.DoneAll, R.string.whatsapp_receipt_read, current.readAt)

                        if (current.playedAt != null) {
                            row(Icons.Filled.PlayCircle, R.string.whatsapp_receipt_played, current.playedAt)
                        }
                    } else {
                        ListRow(
                            title = stringResource(receiptLabel(current)),
                            leading = { Icon(Icons.Filled.Done, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant) },
                        )
                        Text(stringResource(R.string.whatsapp_info_group_note), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.padding(horizontal = 16.dp))
                    }
                }
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.ok)) } },
    )
}

private fun receiptLabel(info: WhatsappMessageInfo): Int = when (info.receipt) {
    WhatsappReceipt.Failed -> R.string.whatsapp_status_failed
    WhatsappReceipt.Pending, null -> R.string.whatsapp_status_pending
    WhatsappReceipt.Sent -> R.string.whatsapp_status_sent
    WhatsappReceipt.Delivered -> R.string.whatsapp_receipt_delivered
    WhatsappReceipt.Read -> R.string.whatsapp_receipt_read
    WhatsappReceipt.Played -> R.string.whatsapp_receipt_played
}
