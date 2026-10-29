package app.jarvis.android.ui.whatsapp

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Group
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.PersonAdd
import androidx.compose.material.icons.filled.PhoneAndroid
import androidx.compose.material.icons.filled.UnfoldMore
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.KeyboardType
import app.jarvis.android.R
import app.jarvis.android.app.AppModel
import app.jarvis.android.network.ApiClient
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.WhatsappAccount
import app.jarvis.android.network.WhatsappMessage
import app.jarvis.android.network.WhatsappMessageInput
import app.jarvis.android.network.WhatsappTarget
import app.jarvis.android.network.WhatsappTargetType
import app.jarvis.android.ui.components.ContentUnavailable
import app.jarvis.android.ui.components.FormError
import app.jarvis.android.ui.components.FormSheet
import app.jarvis.android.ui.components.ListRow
import app.jarvis.android.ui.components.Section
import app.jarvis.android.ui.components.SectionTextField
import app.jarvis.android.ui.components.userMessage
import app.jarvis.android.ui.schedule.ScheduleDraft
import app.jarvis.android.ui.schedule.ScheduleEditor
import app.jarvis.android.ui.schedule.WallClock
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.time.ZonedDateTime

/**
 * Schedules a WhatsApp message, edits a saved one (US-7.3) or sends one now
 * (US-7.5): one of the user's connected numbers, the contacts and groups of
 * that number and/or typed numbers, the text and, unless it is sent now, the
 * schedule. The server checks everything; a refusal (a time in the past, a
 * number not connected) comes translated and is shown in the form.
 */
@Composable
fun WhatsappMessageForm(
    model: AppModel,
    composer: WhatsappComposer,
    accounts: List<WhatsappAccount>,
    onDismiss: () -> Unit,
    onSave: () -> Unit,
) {
    val context = LocalContext.current
    val zone = WallClock.zone(model.user?.timezone)
    val editedMessage = (composer as? WhatsappComposer.Schedule)?.message
    val isSendingNow = composer == WhatsappComposer.SendNow
    val openAccounts = accounts.filter { it.isOpen }
    val selectable = selectableAccounts(accounts, editedMessage)

    var accountId by rememberSaveable { mutableStateOf(editedMessage?.account?.friendlyId ?: openAccounts.firstOrNull()?.friendlyId) }
    var targets by remember { mutableStateOf(editedMessage?.targets.orEmpty()) }
    var numbers by rememberSaveable { mutableStateOf("") }
    var body by rememberSaveable { mutableStateOf(editedMessage?.body.orEmpty()) }
    var schedule by remember {
        mutableStateOf(if (editedMessage != null) ScheduleDraft.of(editedMessage.recurrence, zone) else ScheduleDraft.new(ScheduleDraft.Kind.Once, ZonedDateTime.now(zone)))
    }
    var isSaving by remember { mutableStateOf(false) }
    var failure by remember { mutableStateOf<ApiException?>(null) }
    var isPickingTargets by remember { mutableStateOf(false) }
    var queuedDeliveries by remember { mutableStateOf<Int?>(null) }
    val scope = rememberCoroutineScope()

    val canSave = accountId != null && body.isNotBlank() && (targets.isNotEmpty() || parseNumbers(numbers).isNotEmpty())

    FormSheet(
        title = stringResource(
            when {
                isSendingNow -> R.string.whatsapp_send_now
                editedMessage != null -> R.string.whatsapp_edit_message
                else -> R.string.whatsapp_schedule_message
            },
        ),
        confirmLabel = stringResource(if (isSendingNow) R.string.send else R.string.save),
        isConfirmEnabled = canSave && !isSaving,
        onConfirm = {
            val account = accountId ?: return@FormSheet
            isSaving = true
            scope.launch {
                val input = whatsappMessageInput(account, targets, numbers, body, if (isSendingNow) null else schedule)

                try {
                    val sent = model.client.saveWhatsappMessage(composer, input)
                    onSave()

                    if (sent != null) {
                        queuedDeliveries = sent
                    } else {
                        onDismiss()
                    }
                } catch (exception: ApiException) {
                    failure = exception
                } finally {
                    isSaving = false
                }
            }
        },
        onDismiss = onDismiss,
    ) {
        if (openAccounts.isEmpty() && editedMessage == null) {
            item {
                Box(Modifier.testTag("whatsapp.no-number")) {
                    ContentUnavailable(
                        title = stringResource(R.string.whatsapp_no_numbers),
                        icon = Icons.Filled.PhoneAndroid,
                        description = stringResource(R.string.whatsapp_connect_on_web),
                        actionLabel = stringResource(R.string.whatsapp_connect_on_web_action),
                        onAction = { openConnectOnWeb(context, model) },
                    )
                }
            }

            return@FormSheet
        }

        item {
            Section {
                AccountRow(selectable, accountId) { selected ->
                    // The contacts and groups belong to the number.
                    if (selected != accountId) {
                        targets = emptyList()
                    }

                    accountId = selected
                }
            }
        }

        item {
            Section(header = stringResource(R.string.whatsapp_recipients), footer = stringResource(R.string.whatsapp_numbers_footer)) {
                targets.forEach { target ->
                    ListRow(
                        title = target.name,
                        showDivider = true,
                        leading = { TargetIcon(target) },
                        trailing = {
                            IconButton(onClick = { targets = targets - target }) {
                                Icon(Icons.Filled.Close, contentDescription = stringResource(R.string.delete), tint = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                        },
                    )
                }

                if (accountId != null) {
                    ListRow(
                        title = stringResource(R.string.whatsapp_choose_targets),
                        showDivider = true,
                        onClick = { isPickingTargets = true },
                        modifier = Modifier.testTag("whatsapp.choose-targets"),
                        leading = { Icon(Icons.Filled.PersonAdd, contentDescription = null, tint = MaterialTheme.colorScheme.primary) },
                        titleContent = { Text(stringResource(R.string.whatsapp_choose_targets), color = MaterialTheme.colorScheme.primary) },
                        trailing = { Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant) },
                    )
                }

                SectionTextField(
                    value = numbers,
                    onValueChange = { numbers = it },
                    placeholder = stringResource(R.string.whatsapp_phone_numbers) + " (+5511999990000)",
                    singleLine = false,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone),
                )
            }
        }

        item {
            Section(header = stringResource(R.string.whatsapp_message), footer = stringResource(R.string.whatsapp_template_hint)) {
                SectionTextField(body, { body = it }, stringResource(R.string.whatsapp_message), Modifier.testTag("whatsapp.body"), singleLine = false, minLines = 4)
            }
        }

        if (!isSendingNow) {
            item {
                ScheduleEditor(schedule, ScheduleDraft.Kind.entries - ScheduleDraft.Kind.None, model.language.locale) { schedule = it }
            }
        }

        failure?.let { error ->
            item { FormError(error.userMessage()) }
        }
    }

    val pickerAccount = accountId

    if (isPickingTargets && pickerAccount != null) {
        WhatsappTargetPicker(model, pickerAccount, targets, onDismiss = { isPickingTargets = false }) { targets = it }
    }

    queuedDeliveries?.let { count ->
        AlertDialog(
            onDismissRequest = onDismiss,
            title = { Text(stringResource(R.string.whatsapp_sending)) },
            text = { Text(pluralStringResource(R.plurals.whatsapp_going_out, count, count)) },
            confirmButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.ok)) } },
        )
    }
}

@Composable
private fun AccountRow(accounts: List<WhatsappAccount>, selectedId: String?, onSelect: (String) -> Unit) {
    var isMenuOpen by remember { mutableStateOf(false) }

    Box {
        ListRow(
            title = stringResource(R.string.whatsapp_from),
            onClick = { isMenuOpen = true },
            trailing = {
                Text(accounts.firstOrNull { it.friendlyId == selectedId }?.nickname.orEmpty(), color = MaterialTheme.colorScheme.primary)
                Icon(Icons.Filled.UnfoldMore, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
            },
        )

        DropdownMenu(expanded = isMenuOpen, onDismissRequest = { isMenuOpen = false }, modifier = Modifier.align(Alignment.CenterEnd)) {
            accounts.forEach { account ->
                DropdownMenuItem(
                    text = { Text(account.nickname) },
                    onClick = {
                        isMenuOpen = false
                        onSelect(account.friendlyId)
                    },
                )
            }
        }
    }
}

@Composable
private fun TargetIcon(target: WhatsappTarget) {
    Icon(
        if (target.type == WhatsappTargetType.Group) Icons.Filled.Group else Icons.Filled.Person,
        contentDescription = null,
        tint = MaterialTheme.colorScheme.onSurfaceVariant,
    )
}

/**
 * The contacts and groups of a number, searched by name or number, to pick
 * the recipients from. A short pause while typing, then the first page of the
 * search; the next pages load at the end of the list.
 */
@Composable
private fun WhatsappTargetPicker(
    model: AppModel,
    accountId: String,
    initialSelection: List<WhatsappTarget>,
    onDismiss: () -> Unit,
    onDone: (List<WhatsappTarget>) -> Unit,
) {
    var search by rememberSaveable { mutableStateOf("") }
    var selection by remember { mutableStateOf(initialSelection) }
    var found by remember { mutableStateOf<List<WhatsappTarget>>(emptyList()) }
    var page by remember { mutableStateOf(1) }
    var hasMorePages by remember { mutableStateOf(false) }
    var isLoading by remember { mutableStateOf(true) }
    var failure by remember { mutableStateOf<ApiException?>(null) }

    suspend fun load(pageToLoad: Int) {
        isLoading = true

        try {
            val result = model.client.call { whatsappTargets(accountId, search.trim().ifEmpty { null }, pageToLoad) }
            found = if (pageToLoad == 1) result.data else found + result.data.filter { new -> found.none { it.friendlyId == new.friendlyId } }
            page = result.meta.currentPage
            hasMorePages = result.hasMorePages
            failure = null
        } catch (exception: ApiException) {
            failure = exception
            hasMorePages = false
        } finally {
            isLoading = false
        }
    }

    LaunchedEffect(search) {
        delay(300)
        load(1)
    }

    FormSheet(
        title = stringResource(R.string.whatsapp_recipients),
        confirmLabel = stringResource(R.string.whatsapp_done),
        isConfirmEnabled = true,
        onConfirm = {
            onDone(selection)
            onDismiss()
        },
        onDismiss = onDismiss,
    ) {
        item {
            Section {
                SectionTextField(search, { search = it }, stringResource(R.string.whatsapp_name_or_number), Modifier.testTag("whatsapp.target-search"))
            }
        }

        item {
            Section {
                found.forEachIndexed { index, target ->
                    val isSelected = selection.any { it.friendlyId == target.friendlyId }

                    ListRow(
                        title = target.name,
                        subtitle = target.phoneNumber?.takeIf { it != target.name },
                        showDivider = index < found.lastIndex || hasMorePages,
                        onClick = {
                            selection = if (isSelected) selection.filterNot { it.friendlyId == target.friendlyId } else selection + target
                        },
                        leading = { TargetIcon(target) },
                        trailing = {
                            if (isSelected) {
                                Icon(Icons.Filled.Check, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
                            }
                        },
                    )
                }

                val error = failure

                when {
                    isLoading -> ListRow(title = "", titleContent = { CircularProgressIndicator() })
                    error != null -> ListRow(title = error.userMessage(), titleContent = { Text(error.userMessage(), color = MaterialTheme.colorScheme.error) })
                    hasMorePages -> {
                        // The end of the list was reached: the next page.
                        LaunchedEffect(page) { load(page + 1) }
                    }

                    found.isEmpty() -> ListRow(title = stringResource(R.string.whatsapp_no_targets))
                }
            }
        }
    }
}

/** The connected numbers, and the one of an edited message even when it is not connected now. */
fun selectableAccounts(accounts: List<WhatsappAccount>, editedMessage: WhatsappMessage?): List<WhatsappAccount> {
    val open = accounts.filter { it.isOpen }

    if (editedMessage == null || open.any { it.friendlyId == editedMessage.account.friendlyId }) {
        return open
    }

    return open + accounts.filter { it.friendlyId == editedMessage.account.friendlyId }
}

/** The numbers typed in the field, separated by commas, semicolons or lines. */
fun parseNumbers(text: String): List<String> =
    text.split(',', ';', '\n').map { it.trim() }.filter { it.isNotEmpty() }

/** The message to save or send; without a schedule when it is sent now. */
fun whatsappMessageInput(
    accountId: String,
    targets: List<WhatsappTarget>,
    numbers: String,
    body: String,
    schedule: ScheduleDraft?,
): WhatsappMessageInput = WhatsappMessageInput(
    account = accountId,
    targets = targets.map { it.friendlyId },
    numbers = parseNumbers(numbers),
    body = body.trim(),
    recurrence = schedule?.recurrenceInput(),
)

/**
 * Sends now, schedules or replaces the message of [composer].
 *
 * @return how many deliveries went out when sent now; `null` when it was scheduled.
 * @throws ApiException a 422 comes with the server's message in the user's language.
 */
suspend fun ApiClient.saveWhatsappMessage(composer: WhatsappComposer, input: WhatsappMessageInput): Int? = when (composer) {
    WhatsappComposer.SendNow -> call { sendWhatsappNow(input.copy(recurrence = null)) }.data.size
    is WhatsappComposer.Schedule -> {
        val messageId = composer.message?.friendlyId

        if (messageId != null) {
            call { updateWhatsappMessage(messageId, input) }
        } else {
            call { scheduleWhatsappMessage(input) }
        }

        null
    }
}
