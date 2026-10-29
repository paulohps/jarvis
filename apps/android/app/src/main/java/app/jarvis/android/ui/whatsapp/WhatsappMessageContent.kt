package app.jarvis.android.ui.whatsapp

import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.widget.Toast
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.IntrinsicSize
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Forward
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.LocationOn
import androidx.compose.material.icons.filled.PushPin
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.outlined.Person
import androidx.compose.material.icons.outlined.Poll
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.core.net.toUri
import app.jarvis.android.R
import app.jarvis.android.network.WhatsappContactCard
import app.jarvis.android.network.WhatsappConversationMedia
import app.jarvis.android.network.WhatsappLocation
import app.jarvis.android.network.WhatsappPoll
import app.jarvis.android.network.WhatsappQuotedMessage

/** WhatsApp's "Forwarded" over a message, in italics with the arrow. */
@Composable
internal fun ForwardedLabel(tint: Color, modifier: Modifier = Modifier) {
    Row(modifier.testTag("whatsapp.message.forwarded"), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(3.dp)) {
        Icon(Icons.AutoMirrored.Filled.Forward, contentDescription = null, tint = tint, modifier = Modifier.size(14.dp))
        Text(stringResource(R.string.whatsapp_forwarded_label), style = MaterialTheme.typography.labelMedium, fontStyle = FontStyle.Italic, color = tint)
    }
}

/**
 * The message a message answers, as WhatsApp sets it at the top of the
 * bubble: a colored bar, who sent it ("You" for the number) and what it
 * said; a tap goes to it when Jarvis keeps it ([onClick]).
 */
@Composable
internal fun QuotedBlock(quoted: WhatsappQuotedMessage, background: Color, onClick: (() -> Unit)?, modifier: Modifier = Modifier) {
    val accent = if (quoted.fromMe) WhatsappGreen else MaterialTheme.colorScheme.primary

    QuoteFrame(
        accent = accent,
        background = background,
        title = if (quoted.fromMe) stringResource(R.string.whatsapp_you_quoted) else quoted.sender ?: stringResource(R.string.whatsapp_quoted_message),
        text = quoted.body?.takeIf { it.isNotBlank() } ?: quotedKind(quoted.type),
        onClick = onClick,
        modifier = modifier.testTag("whatsapp.message.quoted"),
    )
}

/** The quote's frame, shared with the bar of the message being answered over the composer. */
@Composable
internal fun QuoteFrame(accent: Color, background: Color, title: String, text: String, onClick: (() -> Unit)?, modifier: Modifier = Modifier) {
    Row(
        modifier
            .fillMaxWidth()
            .height(IntrinsicSize.Min)
            .clip(RoundedCornerShape(6.dp))
            .background(background)
            .then(if (onClick != null) Modifier.clickable(onClick = onClick) else Modifier),
    ) {
        Box(Modifier.width(4.dp).fillMaxHeight().background(accent))

        Column(Modifier.padding(horizontal = 8.dp, vertical = 4.dp)) {
            Text(title, style = MaterialTheme.typography.labelLarge, color = accent, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(text, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 3, overflow = TextOverflow.Ellipsis)
        }
    }
}

/** What a quoted message without text was (a photo, a voice note…), by its WhatsApp type. */
@Composable
internal fun quotedKind(type: String?): String = stringResource(
    when (type) {
        "imageMessage" -> R.string.whatsapp_media_image
        "videoMessage", "ptvMessage" -> R.string.whatsapp_media_video
        "audioMessage" -> R.string.whatsapp_media_audio
        "stickerMessage" -> R.string.whatsapp_sticker
        "locationMessage", "liveLocationMessage" -> R.string.whatsapp_location
        "contactMessage", "contactsArrayMessage" -> R.string.whatsapp_contact
        "pollCreationMessage", "pollCreationMessageV2", "pollCreationMessageV3" -> R.string.whatsapp_poll
        "documentMessage", "documentWithCaptionMessage" -> R.string.whatsapp_media_document
        else -> R.string.whatsapp_message_label
    },
)

/** The marks beside the time: edited, starred, pinned (as WhatsApp shows them). */
@Composable
internal fun MessageMarks(edited: Boolean, starred: Boolean, pinned: Boolean, tint: Color) {
    if (pinned) {
        Icon(Icons.Filled.PushPin, contentDescription = stringResource(R.string.whatsapp_pinned_message), tint = tint, modifier = Modifier.size(12.dp).testTag("whatsapp.message.pinned"))
    }

    if (starred) {
        Icon(Icons.Filled.Star, contentDescription = stringResource(R.string.whatsapp_starred), tint = tint, modifier = Modifier.size(12.dp).testTag("whatsapp.message.starred"))
    }

    if (edited) {
        Text(stringResource(R.string.whatsapp_edited), style = MaterialTheme.typography.labelSmall, color = tint, modifier = Modifier.testTag("whatsapp.message.edited"))
    }
}

/** A location: the pin, its name and address (or the coordinates); a tap opens it in the maps app. */
@Composable
internal fun LocationCard(location: WhatsappLocation, background: Color) {
    val context = LocalContext.current

    Row(
        Modifier
            .widthIn(min = 200.dp)
            .clip(RoundedCornerShape(8.dp))
            .background(background)
            .clickable { openLocation(context, location) }
            .padding(10.dp)
            .testTag("whatsapp.message.location"),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Icon(Icons.Filled.LocationOn, contentDescription = null, tint = MaterialTheme.colorScheme.error, modifier = Modifier.size(32.dp))

        Column(Modifier.widthIn(max = 220.dp)) {
            Text(
                location.name ?: stringResource(if (location.live) R.string.whatsapp_live_location else R.string.whatsapp_location),
                style = MaterialTheme.typography.bodyMedium,
                fontWeight = FontWeight.SemiBold,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )
            Text(
                location.address ?: "%.5f, %.5f".format(java.util.Locale.ROOT, location.latitude, location.longitude),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )
        }
    }
}

/** The location in the maps app of the phone (`geo:`), else in the browser. */
internal fun openLocation(context: Context, location: WhatsappLocation) {
    val label = location.name?.let { "(${android.net.Uri.encode(it)})" }.orEmpty()
    val geo = "geo:${location.latitude},${location.longitude}?q=${location.latitude},${location.longitude}$label".toUri()

    try {
        context.startActivity(Intent(Intent.ACTION_VIEW, geo).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    } catch (_: ActivityNotFoundException) {
        val web = location.url ?: "https://maps.google.com/?q=${location.latitude},${location.longitude}"

        try {
            context.startActivity(Intent(Intent.ACTION_VIEW, web.toUri()).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
        } catch (_: ActivityNotFoundException) {
            Toast.makeText(context, R.string.whatsapp_open_failed, Toast.LENGTH_SHORT).show()
        }
    }
}

/** Contact cards: the name and number of each, and "Message" to open the chat with it on the same number. */
@Composable
internal fun ContactCards(contacts: List<WhatsappContactCard>, background: Color, onMessage: ((String) -> Unit)?) {
    Column(Modifier.widthIn(min = 200.dp).testTag("whatsapp.message.contacts"), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        contacts.forEach { contact ->
            val number = contact.numbers.firstOrNull()

            Column(Modifier.clip(RoundedCornerShape(8.dp)).background(background)) {
                Row(Modifier.padding(10.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    Box(Modifier.size(36.dp).clip(RoundedCornerShape(18.dp)).background(MaterialTheme.colorScheme.secondaryContainer), contentAlignment = Alignment.Center) {
                        Icon(Icons.Outlined.Person, contentDescription = null, tint = MaterialTheme.colorScheme.onSecondaryContainer)
                    }

                    Column(Modifier.widthIn(max = 200.dp)) {
                        Text(contact.name ?: number ?: stringResource(R.string.whatsapp_contact), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        contact.numbers.forEach { Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                    }
                }

                if (number != null && onMessage != null) {
                    HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
                    TextButton(onClick = { onMessage(number) }, modifier = Modifier.fillMaxWidth().testTag("whatsapp.message.contact.message")) {
                        Text(stringResource(R.string.whatsapp_contact_message))
                    }
                }
            }
        }
    }
}

/**
 * A poll, read only (the Evolution API cannot vote): the question, each
 * answer with its bar and votes (the user's marked), and the hint to vote on
 * the phone.
 */
@Composable
internal fun PollCard(poll: WhatsappPoll, tint: Color) {
    val total = poll.totalVotes

    Column(Modifier.widthIn(min = 220.dp).testTag("whatsapp.message.poll"), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            Icon(Icons.Outlined.Poll, contentDescription = null, tint = tint, modifier = Modifier.size(18.dp))
            Text(poll.name, style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.SemiBold)
        }

        Text(
            stringResource(if (poll.selectableCount == 1) R.string.whatsapp_poll_single else R.string.whatsapp_poll_multiple),
            style = MaterialTheme.typography.labelSmall,
            color = tint,
        )

        poll.options.forEach { option ->
            Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    if (option.mine) {
                        Icon(Icons.Filled.CheckCircle, contentDescription = stringResource(R.string.whatsapp_poll_your_vote), tint = WhatsappGreen, modifier = Modifier.size(16.dp))
                    }

                    Text(option.name, style = MaterialTheme.typography.bodyMedium, modifier = Modifier.weight(1f))
                    Text(option.votes.toString(), style = MaterialTheme.typography.labelMedium, color = tint)
                }

                LinearProgressIndicator(
                    progress = { if (total == 0) 0f else option.votes.toFloat() / total },
                    color = WhatsappGreen,
                    trackColor = tint.copy(alpha = 0.25f),
                    strokeCap = StrokeCap.Round,
                    drawStopIndicator = {},
                    modifier = Modifier.fillMaxWidth(),
                )
            }
        }

        Text(
            pluralStringResource(R.plurals.whatsapp_poll_votes, total, total) + " · " + stringResource(R.string.whatsapp_poll_vote_on_phone),
            style = MaterialTheme.typography.labelSmall,
            color = tint,
        )
    }
}

/** How big a sticker shows, as on WhatsApp. */
internal val STICKER_SIZE = 150.dp

/** A sticker without a bubble: the image as it is (its preview until it loads). */
@Composable
internal fun StickerImage(media: WhatsappConversationMedia) {
    val thumbnail = remember(media.thumbnail) { media.thumbnail?.let(WhatsappImages::decodeBase64) }
    val description = stringResource(R.string.whatsapp_sticker)
    val preview: @Composable () -> Unit = {
        thumbnail?.let { Image(it.asImageBitmap(), contentDescription = description, contentScale = ContentScale.Fit, modifier = Modifier.size(STICKER_SIZE)) }
    }

    Box(Modifier.size(STICKER_SIZE).testTag("whatsapp.message.sticker"), contentAlignment = Alignment.Center) {
        val url = media.url

        if (url != null) {
            RemoteImage(url, contentDescription = description, modifier = Modifier.size(STICKER_SIZE), maxSide = 512, contentScale = ContentScale.Fit, placeholder = preview)
        } else {
            preview()
        }
    }
}
