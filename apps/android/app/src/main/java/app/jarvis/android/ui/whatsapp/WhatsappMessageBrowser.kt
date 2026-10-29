package app.jarvis.android.ui.whatsapp

import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.PlayCircle
import androidx.compose.material.icons.outlined.Description
import androidx.compose.material.icons.outlined.Link
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
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
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import app.jarvis.android.R
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.WhatsappConversationMessage
import app.jarvis.android.ui.components.FormError
import app.jarvis.android.ui.components.FormSheet
import app.jarvis.android.ui.components.ListRow
import app.jarvis.android.ui.components.Section
import app.jarvis.android.ui.components.SectionTextField
import app.jarvis.android.ui.components.formatDateTime
import app.jarvis.android.ui.components.userMessage
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** What the message browser of a chat lists. */
internal enum class MessageBrowserMode { Search, Starred, Gallery }

/** How long the search waits for the typing to stop. */
private const val SEARCH_DEBOUNCE_MS = 350L

/**
 * The messages of a chat found by a search, the starred ones, or its
 * gallery (photos and videos in a grid, documents, links; older pages on
 * demand); picking one opens it in the chat ([onOpen]).
 */
@Composable
internal fun MessageBrowserSheet(
    store: WhatsappChatInfoStore,
    mode: MessageBrowserMode,
    timezone: String,
    locale: java.util.Locale,
    onOpen: (String) -> Unit,
    onDismiss: () -> Unit,
) {
    val scope = rememberCoroutineScope()
    var query by remember { mutableStateOf("") }
    var kind by remember { mutableStateOf(WhatsappChatInfoStore.GalleryKind.Media) }
    var messages by remember { mutableStateOf<List<WhatsappConversationMessage>?>(null) }
    var hasMore by remember { mutableStateOf(false) }
    var failure by remember { mutableStateOf<ApiException?>(null) }

    LaunchedEffect(mode, query, kind) {
        failure = null

        try {
            when (mode) {
                MessageBrowserMode.Search -> if (query.trim().length < WhatsappChatInfoStore.MIN_SEARCH_LENGTH) {
                    messages = emptyList()
                } else {
                    delay(SEARCH_DEBOUNCE_MS)
                    messages = null
                    messages = store.search(query)
                }

                MessageBrowserMode.Starred -> messages = store.starred()
                MessageBrowserMode.Gallery -> {
                    messages = null
                    val page = store.gallery(kind)
                    messages = page.data
                    hasMore = page.meta.hasMore
                }
            }
        } catch (exception: ApiException) {
            failure = exception
            messages = emptyList()
        }
    }

    val open: (WhatsappConversationMessage) -> Unit = { onOpen(it.friendlyId) }

    FormSheet(
        title = stringResource(
            when (mode) {
                MessageBrowserMode.Search -> R.string.whatsapp_search_in_chat
                MessageBrowserMode.Starred -> R.string.whatsapp_starred_messages
                MessageBrowserMode.Gallery -> R.string.whatsapp_gallery
            },
        ),
        confirmLabel = stringResource(R.string.ok),
        isConfirmEnabled = true,
        onConfirm = onDismiss,
        onDismiss = onDismiss,
    ) {
        if (mode == MessageBrowserMode.Search) {
            item {
                Section(footer = stringResource(R.string.whatsapp_search_in_chat_footer)) {
                    SectionTextField(value = query, onValueChange = { query = it.take(100) }, placeholder = stringResource(R.string.whatsapp_search), modifier = Modifier.testTag("whatsapp.browser.search"))
                }
            }
        }

        if (mode == MessageBrowserMode.Gallery) {
            item {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    WhatsappChatInfoStore.GalleryKind.entries.forEach { entry ->
                        FilterChip(
                            selected = kind == entry,
                            onClick = { kind = entry },
                            label = {
                                Text(
                                    stringResource(
                                        when (entry) {
                                            WhatsappChatInfoStore.GalleryKind.Media -> R.string.whatsapp_gallery_media
                                            WhatsappChatInfoStore.GalleryKind.Documents -> R.string.whatsapp_gallery_documents
                                            WhatsappChatInfoStore.GalleryKind.Links -> R.string.whatsapp_gallery_links
                                        },
                                    ),
                                )
                            },
                            modifier = Modifier.testTag("whatsapp.browser.kind.${entry.query}"),
                        )
                    }
                }
            }
        }

        failure?.let { item { FormError(it.firstError("q") ?: it.userMessage()) } }

        val current = messages

        item {
            when {
                current == null -> Box(Modifier.fillMaxWidth().padding(16.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }

                current.isEmpty() -> Section {
                    ListRow(
                        title = stringResource(
                            when (mode) {
                                MessageBrowserMode.Search -> if (query.trim().length < WhatsappChatInfoStore.MIN_SEARCH_LENGTH) R.string.whatsapp_search_type_more else R.string.whatsapp_search_nothing
                                MessageBrowserMode.Starred -> R.string.whatsapp_no_starred
                                MessageBrowserMode.Gallery -> R.string.whatsapp_gallery_empty
                            },
                        ),
                    )
                }

                mode == MessageBrowserMode.Gallery && kind == WhatsappChatInfoStore.GalleryKind.Media -> MediaGrid(current, open)

                else -> Section {
                    current.forEachIndexed { index, message ->
                        ListRow(
                            title = message.body?.takeIf { it.isNotBlank() } ?: message.media?.name ?: message.media?.let { stringResource(mediaLabel(it.kind)) }.orEmpty(),
                            subtitle = listOfNotNull(
                                if (message.fromMe) stringResource(R.string.whatsapp_you_quoted) else message.sender,
                                formatDateTime(message.sentAt, timezone, locale),
                            ).joinToString(" · "),
                            leading = when {
                                mode == MessageBrowserMode.Gallery && kind == WhatsappChatInfoStore.GalleryKind.Links -> ({ Icon(Icons.Outlined.Link, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant) })
                                message.media?.kind == "document" -> ({ Icon(Icons.Outlined.Description, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant) })
                                else -> null
                            },
                            showDivider = index < current.lastIndex,
                            onClick = { open(message) },
                            modifier = Modifier.testTag("whatsapp.browser.message.${message.friendlyId}"),
                        )
                    }
                }
            }
        }

        if (mode == MessageBrowserMode.Gallery && hasMore && !current.isNullOrEmpty()) {
            item {
                TextButton(
                    onClick = {
                        scope.launch {
                            try {
                                val page = store.gallery(kind, before = current.last().friendlyId)
                                messages = current + page.data
                                hasMore = page.meta.hasMore
                            } catch (exception: ApiException) {
                                failure = exception
                            }
                        }
                    },
                    modifier = Modifier.fillMaxWidth().testTag("whatsapp.browser.more"),
                ) {
                    Text(stringResource(R.string.whatsapp_load_more))
                }
            }
        }
    }
}

/** How big a tile of the photos and videos is. */
private val GALLERY_TILE = 104.dp

/** The photos and videos as tiles of their previews (the video marked); a tap opens the message in the chat. */
@Composable
private fun MediaGrid(messages: List<WhatsappConversationMessage>, onOpen: (WhatsappConversationMessage) -> Unit) {
    FlowRow(horizontalArrangement = Arrangement.spacedBy(4.dp), verticalArrangement = Arrangement.spacedBy(4.dp), modifier = Modifier.fillMaxWidth()) {
        messages.forEach { message ->
            val media = message.media
            val thumbnail = remember(media?.thumbnail) { media?.thumbnail?.let(WhatsappImages::decodeBase64) }

            Box(
                Modifier
                    .size(GALLERY_TILE)
                    .clip(RoundedCornerShape(6.dp))
                    .background(MaterialTheme.colorScheme.surfaceVariant)
                    .clickable { onOpen(message) }
                    .testTag("whatsapp.browser.message.${message.friendlyId}"),
                contentAlignment = Alignment.Center,
            ) {
                val url = media?.url

                when {
                    media?.kind == "image" && url != null -> RemoteImage(url, contentDescription = null, modifier = Modifier.size(GALLERY_TILE), maxSide = 320, placeholder = {
                        thumbnail?.let { Image(it.asImageBitmap(), contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.size(GALLERY_TILE)) }
                    })

                    thumbnail != null -> Image(thumbnail.asImageBitmap(), contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.size(GALLERY_TILE))
                }

                if (media?.kind == "video") {
                    Icon(Icons.Filled.PlayCircle, contentDescription = stringResource(R.string.whatsapp_media_video), tint = Color.White, modifier = Modifier.size(32.dp))
                }
            }
        }
    }
}
