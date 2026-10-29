package app.jarvis.android.ui.whatsapp

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.produceState
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import app.jarvis.android.R
import app.jarvis.android.app.AppModel
import app.jarvis.android.navigation.AppTab
import app.jarvis.android.navigation.Destination
import app.jarvis.android.network.WhatsappConversation
import app.jarvis.android.realtime.WhatsappLive
import app.jarvis.android.ui.components.formatTime
import kotlinx.coroutines.delay

/** How long the in-app banner of a new message stays. */
private const val BANNER_MS = 4_000L

/** How often what the presence says is looked at again (it fades by itself). */
private const val PRESENCE_TICK_MS = 2_000L

/** What [live] says about who is in the chat now, followed as it changes and as it fades. */
@Composable
internal fun rememberPresence(live: WhatsappLive, chatId: String): WhatsappPresenceStatus? {
    val entries by live.presence.entries.collectAsState()
    val now by produceState(System.currentTimeMillis()) {
        while (true) {
            delay(PRESENCE_TICK_MS)
            value = System.currentTimeMillis()
        }
    }

    return remember(entries, now, chatId) { WhatsappPresenceStore.status(entries[chatId], now) }
}

/** "typing…", "Ana is typing…", "online", "last seen today at 10:30"…, in the user's language. */
@Composable
internal fun presenceLabel(status: WhatsappPresenceStatus?, timezone: String, model: AppModel): String? = when (status) {
    is WhatsappPresenceStatus.Typing -> status.who?.let { stringResource(R.string.whatsapp_presence_typing_who, it) }
        ?: stringResource(R.string.whatsapp_presence_typing)
    is WhatsappPresenceStatus.Recording -> status.who?.let { stringResource(R.string.whatsapp_presence_recording_who, it) }
        ?: stringResource(R.string.whatsapp_presence_recording)
    WhatsappPresenceStatus.Online -> stringResource(R.string.whatsapp_presence_online)
    is WhatsappPresenceStatus.LastSeen -> {
        val locale = model.language.locale
        val time = formatTime(status.iso, timezone, locale)

        when {
            time == null -> null
            isToday(status.iso, timezone) -> stringResource(R.string.whatsapp_presence_last_seen_today, time)
            else -> formatShortDate(status.iso, timezone, locale)?.let { stringResource(R.string.whatsapp_presence_last_seen, it, time) }
        }
    }

    null -> null
}

/** Typing or recording: shown in green in place of the latest message, as WhatsApp's list does. */
internal fun WhatsappPresenceStatus?.isActivity(): Boolean =
    this is WhatsappPresenceStatus.Typing || this is WhatsappPresenceStatus.Recording

/**
 * The banner of a message just sent to a chat not on screen, over the app as
 * WhatsApp shows it: the chat's picture, its name and the message; tapping it
 * opens the chat. It goes away by itself after [BANNER_MS].
 */
@Composable
fun WhatsappBannerHost(model: AppModel, live: WhatsappLive, modifier: Modifier = Modifier) {
    val banner by live.banner.collectAsState()

    LaunchedEffect(banner) {
        val shown = banner ?: return@LaunchedEffect

        delay(BANNER_MS)
        live.dismissBanner(shown)
    }

    AnimatedVisibility(
        visible = banner != null,
        enter = slideInVertically { -it },
        exit = slideOutVertically { -it } + fadeOut(),
        modifier = modifier,
    ) {
        // The last banner stays while it slides out.
        val chat = remember { LastBanner() }
        banner?.let { chat.value = it }
        chat.value?.let { shown -> Banner(shown) { open(model, live, shown) } }
    }
}

/** The banner shown last, kept while it slides out (not state: only read while composing). */
private class LastBanner {
    var value: WhatsappConversation? = null
}

private fun open(model: AppModel, live: WhatsappLive, chat: WhatsappConversation) {
    live.dismissBanner(chat)
    model.router.select(AppTab.WhatsApp)
    model.router.push(AppTab.WhatsApp, Destination.WhatsappChat(chat.friendlyId, chat.name, chat))
}

@Composable
private fun Banner(chat: WhatsappConversation, onClick: () -> Unit) {
    Surface(
        shape = RoundedCornerShape(16.dp),
        tonalElevation = 6.dp,
        shadowElevation = 8.dp,
        modifier = Modifier
            .fillMaxWidth()
            .statusBarsPadding()
            .padding(horizontal = 12.dp, vertical = 8.dp)
            .clickable(onClick = onClick)
            .testTag("whatsapp.banner.${chat.friendlyId}"),
    ) {
        Row(Modifier.padding(12.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            ChatAvatar(chat.name, chat.pictureUrl, chat.isGroup, size = 40.dp)

            Column(Modifier.weight(1f)) {
                Text(chat.name, style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(
                    chat.lastMessage?.let { previewText(it) }.orEmpty(),
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                )
            }
        }
    }
}
