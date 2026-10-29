package app.jarvis.android.ui.whatsapp

import android.Manifest
import android.annotation.SuppressLint
import android.content.Context
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationManager
import android.os.CancellationSignal
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyListScope
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.MyLocation
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
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
import androidx.core.content.ContextCompat
import app.jarvis.android.R
import app.jarvis.android.network.ApiClient
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.WhatsappConversation
import app.jarvis.android.network.WhatsappConversationMessage
import app.jarvis.android.network.WhatsappGroupMember
import app.jarvis.android.ui.components.FormError
import app.jarvis.android.ui.components.FormSheet
import app.jarvis.android.ui.components.ListRow
import app.jarvis.android.ui.components.Section
import app.jarvis.android.ui.components.SectionTextField
import app.jarvis.android.ui.components.userMessage
import java.util.concurrent.Executors
import kotlin.coroutines.resume
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.suspendCancellableCoroutine

/** What the clip of the composer sends besides a file. */
internal enum class WhatsappAttachment { File, Poll, Location, Contact, Sticker }

/** The `@` being typed: where it starts in the text and what follows it. */
internal data class MentionQuery(val start: Int, val text: String)

/** How many members the mention list shows at most. */
private const val MENTION_SUGGESTIONS = 6

/** How long a mention being typed gets before it no longer counts as one. */
private const val MENTION_QUERY_LENGTH = 30

internal object WhatsappMentions {
    /**
     * The `@` typed right before [cursor] (at the start or after a space),
     * with what follows it up to the cursor; null when the cursor is not in
     * one.
     */
    fun query(text: String, cursor: Int): MentionQuery? {
        if (cursor < 0 || cursor > text.length) {
            return null
        }

        val before = text.substring(0, cursor)
        val at = before.lastIndexOf('@')

        if (at < 0 || (at > 0 && !before[at - 1].isWhitespace())) {
            return null
        }

        val typed = before.substring(at + 1)

        return if (typed.length > MENTION_QUERY_LENGTH || typed.any(Char::isWhitespace)) null else MentionQuery(at, typed)
    }

    /** The members [query] matches by name or number (never the number itself, never one without a number). */
    fun matches(members: List<WhatsappGroupMember>, query: String): List<WhatsappGroupMember> = members
        .filter { !it.me && it.mentionDigits != null }
        .filter { query.isEmpty() || it.name.contains(query, ignoreCase = true) || it.mentionDigits!!.contains(query.filter(Char::isDigit).ifEmpty { query }) }
        .take(MENTION_SUGGESTIONS)

    /** [text] with the mention typed up to [cursor] replaced by `@digits `. @return the text and where the cursor goes. */
    fun insert(text: String, cursor: Int, query: MentionQuery, digits: String): Pair<String, Int> {
        val mention = "@$digits "

        return text.replaceRange(query.start, cursor, mention) to query.start + mention.length
    }
}

/** The members the `@` being typed matches, over the composer; a tap writes the mention. */
@Composable
internal fun MentionSuggestions(members: List<WhatsappGroupMember>, background: Color, onPick: (WhatsappGroupMember) -> Unit) {
    Surface(color = background, shape = RoundedCornerShape(12.dp), shadowElevation = 2.dp, modifier = Modifier.fillMaxWidth().padding(bottom = 4.dp).testTag("whatsapp.mentions")) {
        Column {
            members.forEach { member ->
                ListRow(
                    title = member.name,
                    subtitle = member.number,
                    leading = { ChatAvatar(member.name, null, isGroup = false, size = 32.dp) },
                    onClick = { onPick(member) },
                    modifier = Modifier.testTag("whatsapp.mention.${member.mentionDigits}"),
                )
            }
        }
    }
}

/** The message being answered over the composer, as WhatsApp's: who sent it, what it said, and the cross that drops it. */
@Composable
internal fun ReplyBar(message: WhatsappConversationMessage, background: Color, quoteBackground: Color, onCancel: () -> Unit) {
    Surface(color = background, shape = RoundedCornerShape(topStart = 16.dp, topEnd = 16.dp), modifier = Modifier.fillMaxWidth().testTag("whatsapp.reply.bar")) {
        Row(Modifier.padding(start = 6.dp, top = 6.dp, bottom = 2.dp), verticalAlignment = Alignment.CenterVertically) {
            QuoteFrame(
                accent = if (message.fromMe) WhatsappGreen else MaterialTheme.colorScheme.primary,
                background = quoteBackground,
                title = if (message.fromMe) stringResource(R.string.whatsapp_you_quoted) else message.sender ?: stringResource(R.string.whatsapp_quoted_message),
                text = message.body?.takeIf { it.isNotBlank() } ?: quotedKind(message.type),
                onClick = null,
                modifier = Modifier.weight(1f),
            )

            IconButton(onClick = onCancel, modifier = Modifier.testTag("whatsapp.reply.cancel")) {
                Icon(Icons.Filled.Close, contentDescription = stringResource(R.string.whatsapp_cancel_reply))
            }
        }
    }
}

/** A poll: the question, 2 to 10 answers (a new field shows once the last is filled) and whether several can be chosen. */
@Composable
internal fun PollSheet(onSend: suspend (String, List<String>, Boolean) -> Unit, onDismiss: () -> Unit) {
    val scope = rememberCoroutineScope()
    var failure by remember { mutableStateOf<ApiException?>(null) }
    var question by remember { mutableStateOf("") }
    var options by remember { mutableStateOf(listOf("", "")) }
    var multiple by remember { mutableStateOf(false) }
    var isSending by remember { mutableStateOf(false) }
    val filled = options.map { it.trim() }.filter { it.isNotEmpty() }
    val isValid = question.isNotBlank() && filled.size in WhatsappChatStore.POLL_OPTIONS && filled.toSet().size == filled.size

    FormSheet(
        title = stringResource(R.string.whatsapp_poll),
        confirmLabel = stringResource(R.string.whatsapp_send),
        isConfirmEnabled = isValid && !isSending,
        onConfirm = {
            scope.launch {
                isSending = true
                failure = null

                try {
                    onSend(question, filled, multiple)
                    onDismiss()
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
            Section(header = stringResource(R.string.whatsapp_poll_question)) {
                SectionTextField(value = question, onValueChange = { question = it.take(255) }, placeholder = stringResource(R.string.whatsapp_poll_question_placeholder), modifier = Modifier.testTag("whatsapp.poll.question"))
            }
        }

        item {
            Section(header = stringResource(R.string.whatsapp_poll_options), footer = stringResource(R.string.whatsapp_poll_options_footer, WhatsappChatStore.POLL_OPTIONS.last)) {
                options.forEachIndexed { index, option ->
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Box(Modifier.weight(1f)) {
                            SectionTextField(
                                value = option,
                                onValueChange = { value ->
                                    val changed = options.toMutableList().also { it[index] = value.take(100) }
                                    // A new empty answer once the last one is filled, up to the most a poll takes.
                                    options = if (changed.last().isNotBlank() && changed.size < WhatsappChatStore.POLL_OPTIONS.last) changed + "" else changed
                                },
                                placeholder = stringResource(R.string.whatsapp_poll_option, index + 1),
                                modifier = Modifier.testTag("whatsapp.poll.option.$index"),
                            )
                        }

                        if (options.size > WhatsappChatStore.POLL_OPTIONS.first) {
                            IconButton(onClick = { options = options.filterIndexed { i, _ -> i != index } }) {
                                Icon(Icons.Filled.Delete, contentDescription = stringResource(R.string.delete), tint = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                        }
                    }
                }
            }
        }

        item {
            Section {
                ListRow(
                    title = stringResource(R.string.whatsapp_poll_allow_multiple),
                    onClick = { multiple = !multiple },
                    trailing = { Switch(checked = multiple, onCheckedChange = { multiple = it }, modifier = Modifier.testTag("whatsapp.poll.multiple")) },
                )
            }
        }

        if (filled.toSet().size != filled.size) {
            item { FormError(stringResource(R.string.whatsapp_poll_duplicate)) }
        }

        failure?.let { item { FormError(it.firstError("question") ?: it.firstError("options") ?: it.userMessage()) } }
    }
}

/**
 * A location: the phone's current one when the user lets the app read it
 * (asked on the tap), otherwise typed coordinates; a name and address of
 * the place are optional.
 */
@Composable
internal fun LocationSheet(onSend: suspend (Double, Double, String?, String?) -> Unit, onDismiss: () -> Unit) {
    val context = LocalContext.current
    var failure by remember { mutableStateOf<ApiException?>(null) }
    val scope = rememberCoroutineScope()
    var latitude by remember { mutableStateOf("") }
    var longitude by remember { mutableStateOf("") }
    var name by remember { mutableStateOf("") }
    var address by remember { mutableStateOf("") }
    var isLocating by remember { mutableStateOf(false) }
    var isSending by remember { mutableStateOf(false) }
    var locateFailure by remember { mutableStateOf<String?>(null) }
    val noLocation = stringResource(R.string.whatsapp_location_unavailable)
    val lat = latitude.replace(',', '.').toDoubleOrNull()?.takeIf { it in -90.0..90.0 }
    val lng = longitude.replace(',', '.').toDoubleOrNull()?.takeIf { it in -180.0..180.0 }

    val locate = {
        scope.launch {
            isLocating = true
            locateFailure = null
            val found = currentLocation(context)

            if (found == null) {
                locateFailure = noLocation
            } else {
                latitude = "%.6f".format(java.util.Locale.ROOT, found.latitude)
                longitude = "%.6f".format(java.util.Locale.ROOT, found.longitude)
            }

            isLocating = false
        }
    }
    val permission = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { granted ->
        if (granted.values.any { it }) {
            locate()
        } else {
            locateFailure = context.getString(R.string.whatsapp_location_denied)
        }
    }

    FormSheet(
        title = stringResource(R.string.whatsapp_location),
        confirmLabel = stringResource(R.string.whatsapp_send),
        isConfirmEnabled = lat != null && lng != null && !isSending,
        onConfirm = {
            val latitudeValue = lat ?: return@FormSheet
            val longitudeValue = lng ?: return@FormSheet

            scope.launch {
                isSending = true
                failure = null

                try {
                    onSend(latitudeValue, longitudeValue, name, address)
                    onDismiss()
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
            Section(footer = locateFailure) {
                ListRow(
                    title = stringResource(R.string.whatsapp_location_current),
                    leading = { Icon(Icons.Filled.MyLocation, contentDescription = null, tint = MaterialTheme.colorScheme.primary) },
                    trailing = { if (isLocating) CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp) },
                    modifier = Modifier.testTag("whatsapp.location.current"),
                    onClick = {
                        if (hasLocationPermission(context)) {
                            locate()
                        } else {
                            permission.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION))
                        }
                    },
                )
            }
        }

        item {
            Section(header = stringResource(R.string.whatsapp_location_coordinates)) {
                val decimal = KeyboardOptions(keyboardType = KeyboardType.Decimal)
                SectionTextField(value = latitude, onValueChange = { latitude = it }, placeholder = stringResource(R.string.whatsapp_location_latitude), keyboardOptions = decimal, modifier = Modifier.testTag("whatsapp.location.latitude"))
                SectionTextField(value = longitude, onValueChange = { longitude = it }, placeholder = stringResource(R.string.whatsapp_location_longitude), keyboardOptions = decimal, modifier = Modifier.testTag("whatsapp.location.longitude"))
            }
        }

        item {
            Section(header = stringResource(R.string.whatsapp_location_place)) {
                SectionTextField(value = name, onValueChange = { name = it.take(255) }, placeholder = stringResource(R.string.whatsapp_location_name))
                SectionTextField(value = address, onValueChange = { address = it.take(500) }, placeholder = stringResource(R.string.whatsapp_location_address))
            }
        }

        failure?.let { item { FormError(it.firstError("latitude") ?: it.firstError("longitude") ?: it.userMessage()) } }
    }
}

private fun hasLocationPermission(context: Context): Boolean =
    listOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION).any {
        ContextCompat.checkSelfPermission(context, it) == PackageManager.PERMISSION_GRANTED
    }

/** The phone's location now, from the best provider on; null without one or a fix. */
@SuppressLint("MissingPermission")
private suspend fun currentLocation(context: Context): Location? {
    if (!hasLocationPermission(context)) {
        return null
    }

    val manager = context.getSystemService(LocationManager::class.java) ?: return null
    val provider = listOf(LocationManager.FUSED_PROVIDER, LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER)
        .firstOrNull { runCatching { manager.isProviderEnabled(it) }.getOrDefault(false) }
        ?: return null

    return suspendCancellableCoroutine { continuation ->
        val signal = CancellationSignal()
        val executor = Executors.newSingleThreadExecutor()
        continuation.invokeOnCancellation { signal.cancel() }

        manager.getCurrentLocation(provider, signal, executor) { location ->
            executor.shutdown()
            continuation.resume(location)
        }
    }
}

/** How long the contact search waits for the typing to stop. */
private const val CONTACT_SEARCH_DEBOUNCE_MS = 300L

/**
 * Contact chats of the number [accountId] (with a phone number), searched
 * by name or number, up to [max] picked: the cards to send, the members of a
 * new group or the ones added to a group. [header] goes above the list (the
 * new group's subject); [onConfirm] answers the server's refusal as an
 * [ApiException], shown here.
 */
@Composable
internal fun ContactPickerSheet(
    client: ApiClient,
    accountId: String?,
    title: String,
    confirmLabel: String,
    max: Int,
    footer: String? = null,
    canConfirm: Boolean = true,
    header: LazyListScope.() -> Unit = {},
    onConfirm: suspend (List<WhatsappConversation>) -> Unit,
    onDismiss: () -> Unit,
) {
    val scope = rememberCoroutineScope()
    var search by remember { mutableStateOf("") }
    var chats by remember { mutableStateOf<List<WhatsappConversation>?>(null) }
    var picked by remember(accountId) { mutableStateOf(listOf<WhatsappConversation>()) }
    var failure by remember { mutableStateOf<ApiException?>(null) }
    var isSending by remember { mutableStateOf(false) }

    LaunchedEffect(search, accountId) {
        if (search.isNotEmpty()) {
            delay(CONTACT_SEARCH_DEBOUNCE_MS)
        }

        chats = try {
            client.call { whatsappConversations(search = search.trim().ifEmpty { null }, perPage = 50, account = accountId) }.data.filter { !it.isGroup && it.phoneNumber != null }
        } catch (exception: ApiException) {
            failure = exception
            emptyList()
        }
    }

    FormSheet(
        title = title,
        confirmLabel = confirmLabel,
        isConfirmEnabled = picked.isNotEmpty() && canConfirm && !isSending,
        onConfirm = {
            scope.launch {
                isSending = true
                failure = null

                try {
                    onConfirm(picked)
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
        header()

        item {
            Section(footer = footer) {
                SectionTextField(
                    value = search,
                    onValueChange = { search = it },
                    placeholder = stringResource(R.string.whatsapp_search_contacts),
                    modifier = Modifier.testTag("whatsapp.contacts.search"),
                )
            }
        }

        failure?.let { item { FormError(it.firstError("contacts") ?: it.firstError("members") ?: it.firstError("participants") ?: it.userMessage()) } }

        // The picked ones stay on top while searching for others.
        val listed = picked + chats.orEmpty().filterNot { chat -> picked.any { it.friendlyId == chat.friendlyId } }

        item {
            Section {
                when {
                    chats == null -> Box(Modifier.fillMaxWidth().padding(16.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
                    listed.isEmpty() -> ListRow(title = stringResource(R.string.whatsapp_no_contacts))
                    else -> listed.forEachIndexed { index, chat ->
                        val isPicked = picked.any { it.friendlyId == chat.friendlyId }
                        val canPick = isPicked || picked.size < max
                        val toggle = {
                            picked = if (isPicked) picked.filterNot { it.friendlyId == chat.friendlyId } else picked + chat
                        }

                        ListRow(
                            title = chat.name,
                            subtitle = chat.phoneNumber,
                            leading = { ChatAvatar(chat.name, chat.pictureUrl, chat.isGroup, size = 40.dp) },
                            trailing = { Checkbox(checked = isPicked, onCheckedChange = { toggle() }, enabled = canPick) },
                            showDivider = index < listed.lastIndex,
                            onClick = if (canPick) toggle else null,
                            modifier = Modifier.testTag("whatsapp.contacts.chat.${chat.friendlyId}"),
                        )
                    }
                }
            }
        }
    }
}

/** At most this many contact cards go in one message (as the server takes). */
internal const val MAX_CONTACT_CARDS = 10
