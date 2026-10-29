package app.jarvis.android.ui.components

import androidx.compose.material3.AlertDialog
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.res.stringResource
import app.jarvis.android.R
import app.jarvis.android.network.ApiException

/**
 * What a screen shows for a failed call, in the app's language: the server's
 * message (a 422 comes translated, the client sends `Accept-Language`) or a
 * generic one.
 */
@Composable
fun ApiException.userMessage(): String = when (this) {
    is ApiException.Transport -> stringResource(R.string.error_unreachable)
    else -> displayMessage ?: stringResource(R.string.error_generic)
}

/** An alert with the message of a failed change; dismissing it clears the failure. A 401 shows nothing (the login is back). */
@Composable
fun ActionFailureDialog(failure: ApiException?, onDismiss: () -> Unit) {
    if (failure == null || failure is ApiException.Unauthenticated) {
        return
    }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.could_not_save)) },
        text = { Text(failure.userMessage()) },
        confirmButton = {
            TextButton(onClick = onDismiss) { Text(stringResource(R.string.ok)) }
        },
    )
}

/** A yes / no question before a destructive action. */
@Composable
fun ConfirmDialog(
    title: String,
    confirmLabel: String,
    onConfirm: () -> Unit,
    onDismiss: () -> Unit,
    dismissLabel: String = stringResource(R.string.cancel),
    message: String? = null,
) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title) },
        text = message?.let { { Text(it) } },
        confirmButton = {
            TextButton(
                onClick = {
                    onDismiss()
                    onConfirm()
                },
            ) {
                Text(confirmLabel, color = MaterialTheme.colorScheme.error)
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) { Text(dismissLabel) }
        },
    )
}

/** A dialog with one text field (a new list, a rename). */
@Composable
fun TextFieldDialog(
    title: String,
    label: String,
    confirmLabel: String,
    initialValue: String,
    onConfirm: (String) -> Unit,
    onDismiss: () -> Unit,
) {
    var value by rememberSaveable { mutableStateOf(initialValue) }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title) },
        text = {
            OutlinedTextField(
                value = value,
                onValueChange = { value = it },
                label = { Text(label) },
                singleLine = true,
            )
        },
        confirmButton = {
            TextButton(
                enabled = value.isNotBlank(),
                onClick = {
                    onDismiss()
                    onConfirm(value.trim())
                },
            ) {
                Text(confirmLabel)
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) { Text(stringResource(R.string.cancel)) }
        },
    )
}
