package app.jarvis.android.network

import kotlinx.serialization.EncodeDefault
import kotlinx.serialization.ExperimentalSerializationApi
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/** `components/schemas/WhatsappConversation`: a chat of the WhatsApp screen, a contact or a group. */
@Serializable
data class WhatsappConversation(
    @SerialName("friendly_id") val friendlyId: String,
    /** The user's nickname for it, else the WhatsApp name, else the number. */
    val name: String,
    /** The user's own name for it; null when none. */
    val nickname: String? = null,
    /** The name WhatsApp gives. */
    @SerialName("whatsapp_name") val whatsappName: String? = null,
    @SerialName("is_group") val isGroup: Boolean = false,
    @SerialName("phone_number") val phoneNumber: String? = null,
    /** The WhatsApp picture; it expires, so the initial shows when it fails. */
    @SerialName("picture_url") val pictureUrl: String? = null,
    val account: WhatsappChatAccount,
    @SerialName("unread_count") val unreadCount: Int = 0,
    /** Marked unread by hand (WhatsApp's "Mark as unread"). */
    @SerialName("marked_unread") val markedUnread: Boolean = false,
    val pinned: Boolean = false,
    /** Out of the list; it shows under "Archived". */
    val archived: Boolean = false,
    /** No pushes until then; null when not muted. */
    @SerialName("muted_until") val mutedUntil: String? = null,
    /** A contact the number blocked. */
    val blocked: Boolean = false,
    /** The timer of disappearing messages, in seconds; 0 when off. */
    @SerialName("ephemeral_seconds") val ephemeralSeconds: Int = 0,
    /** A group's description. */
    val description: String? = null,
    /** 1 (the highest) to 5. */
    val priority: Int? = null,
    /** The user's color for the priority, `#rrggbb`. */
    @SerialName("priority_color") val priorityColor: String? = null,
    val tags: List<WhatsappConversationTag> = emptyList(),
    @SerialName("last_message") val lastMessage: WhatsappConversationMessage? = null,
    @SerialName("last_message_at") val lastMessageAt: String? = null,
    val settings: WhatsappChatSettings? = null,
) {
    val isMuted: Boolean get() = mutedUntil != null

    /** Unread messages, or marked unread by hand. */
    val looksUnread: Boolean get() = unreadCount > 0 || markedUnread
}

/** `components/schemas/WhatsappTag`: one of the user's tags. */
@Serializable
data class WhatsappConversationTag(
    @SerialName("friendly_id") val friendlyId: String,
    val name: String,
    /** `#rrggbb`. */
    val color: String,
    /** The palette name of the color, what saving it takes. */
    @SerialName("color_name") val colorName: String? = null,
)

/**
 * What a chat sends to WhatsApp (read receipts, "typing…") and whether its
 * messages are pushed: each null to follow its number, with what is in effect.
 */
@Serializable
data class WhatsappChatSettings(
    @SerialName("sends_read_receipts") val sendsReadReceipts: Boolean? = null,
    @SerialName("sends_typing") val sendsTyping: Boolean? = null,
    @SerialName("notifies_messages") val notifiesMessages: Boolean? = null,
    val effective: WhatsappAccountChatSettings = WhatsappAccountChatSettings(),
)

/**
 * The defaults of a number's chats, or what is in effect for one chat;
 * [syncsPhoneReads], a chat read on the phone is read in Jarvis too (the
 * number's only).
 */
@Serializable
data class WhatsappAccountChatSettings(
    @SerialName("sends_read_receipts") val sendsReadReceipts: Boolean = false,
    @SerialName("sends_typing") val sendsTyping: Boolean = false,
    @SerialName("notifies_messages") val notifiesMessages: Boolean = true,
    @SerialName("syncs_phone_reads") val syncsPhoneReads: Boolean = true,
)

/** The answer of `GET /whatsapp/conversations/{chat}`: the chat, what is scheduled to it and its open reminders. */
@Serializable
data class WhatsappConversationDetail(
    val data: WhatsappConversation,
    val scheduled: List<WhatsappScheduledText> = emptyList(),
    val reminders: List<WhatsappChatReminder> = emptyList(),
)

/** A message scheduled to the chat and not sent yet, with the file it sends; cancelled with `POST /whatsapp/messages/{id}/cancel`. */
@Serializable
data class WhatsappScheduledText(
    @SerialName("friendly_id") val friendlyId: String,
    val body: String,
    @SerialName("next_run_at") val nextRunAt: String? = null,
    val media: WhatsappScheduledMedia? = null,
)

/** The file a scheduled message sends (an audio goes as a voice note). */
@Serializable
data class WhatsappScheduledMedia(
    val mime: String? = null,
    val name: String? = null,
    val size: Long? = null,
) {
    val isAudio: Boolean get() = mime?.startsWith("audio/") == true
}

/** An open reminder about the chat. */
@Serializable
data class WhatsappChatReminder(
    @SerialName("friendly_id") val friendlyId: String,
    val title: String,
    @SerialName("due_at") val dueAt: String? = null,
)

/** The body of `POST /whatsapp/conversations`: the chat with [number] on the account. */
@Serializable
data class WhatsappStartChat(val account: String, val number: String)

/**
 * The body of `POST /whatsapp/conversations/{chat}/draft`: the picked
 * messages, or none for what the contact sent since the user's last
 * message; [instruction], what the answer must say, only when given. With
 * [withoutContext], a new message from the instruction alone, without
 * reading the chat (sent only when true).
 */
@OptIn(ExperimentalSerializationApi::class)
@Serializable
data class WhatsappDraftRequest(
    val messages: List<String>,
    @EncodeDefault(EncodeDefault.Mode.NEVER) val instruction: String? = null,
    @SerialName("without_context") @EncodeDefault(EncodeDefault.Mode.NEVER) val withoutContext: Boolean? = null,
)

/** The body of `POST /whatsapp/conversations/{chat}/reminders`: the picked [messages] (all quoted), none for the chat; [dueAt] on the user's wall clock (`2026-10-06T09:00`). */
@Serializable
data class WhatsappChatReminderInput(
    val title: String,
    @SerialName("due_at") val dueAt: String,
    val messages: List<String>,
)

/** The body of `POST /whatsapp/conversations/{chat}/schedule`; [at] on the user's wall clock. */
@Serializable
data class WhatsappScheduleText(val text: String, val at: String)

/** The answer of `POST /whatsapp/conversations/{chat}/sync`: how many messages were new. */
@Serializable
data class WhatsappSyncResult(val added: Int = 0)

/** The body of `POST`/`PATCH /whatsapp/tags`: the color by its palette name. */
@Serializable
data class WhatsappTagInput(val name: String, val color: String)

/**
 * `GET /whatsapp/priority-colors`: the palette (`{name: hex}`) and the color
 * name of each priority (`{"1": name, …}`).
 */
@Serializable
data class WhatsappPriorityColors(
    val palette: Map<String, String> = emptyMap(),
    val colors: Map<String, String> = emptyMap(),
) {
    /** The hex of [priority], else null. */
    fun hex(priority: Int): String? = colors[priority.toString()]?.let(palette::get)
}

/** The body of `PUT /whatsapp/priority-colors`. */
@Serializable
data class WhatsappPriorityColorsInput(val colors: Map<String, String>)

/** How far a message the number sent got: WhatsApp's ticks. */
@Serializable
enum class WhatsappReceipt {
    @SerialName("failed") Failed,
    @SerialName("pending") Pending,
    @SerialName("sent") Sent,
    @SerialName("delivered") Delivered,
    @SerialName("read") Read,
    @SerialName("played") Played,
}

/** `components/schemas/WhatsappConversationMessage`. */
@Serializable
data class WhatsappConversationMessage(
    @SerialName("friendly_id") val friendlyId: String,
    @SerialName("from_me") val fromMe: Boolean,
    /** The WhatsApp message type (`conversation`, `audioMessage`, `imageMessage`…). */
    val type: String,
    /** Who sent a received group message. */
    val sender: String? = null,
    val body: String? = null,
    val receipt: WhatsappReceipt? = null,
    /** Deleted for everyone on WhatsApp; the text is kept. */
    val revoked: Boolean = false,
    val media: WhatsappConversationMedia? = null,
    @SerialName("sent_at") val sentAt: String,
    /** One per emoji, the most given first. */
    val reactions: List<WhatsappReaction> = emptyList(),
    /** The message it answers. */
    val quoted: WhatsappQuotedMessage? = null,
    /** The members it mentions ([body] already shows them as `@name`). */
    val mentions: List<WhatsappMention> = emptyList(),
    val forwarded: Boolean = false,
    val edited: Boolean = false,
    /** The number sent it, within WhatsApp's 15 minutes. */
    @SerialName("can_edit") val canEdit: Boolean = false,
    /** The number sent it, within WhatsApp's two and a half days. */
    @SerialName("can_delete_for_everyone") val canDeleteForEveryone: Boolean = false,
    /** Pinned for everyone in the chat (set on the phone; Jarvis only shows it). */
    val pinned: Boolean = false,
    /** Starred by the user, kept in Jarvis only. */
    val starred: Boolean = false,
    /** A sticker: its image is [media], shown without the bubble. */
    val sticker: Boolean = false,
    val location: WhatsappLocation? = null,
    val contacts: List<WhatsappContactCard>? = null,
    /** A poll with its votes; voting is done on the phone. */
    val poll: WhatsappPoll? = null,
) {
    /** What can be sent again: not deleted, with a text or a file. */
    val isForwardable: Boolean get() = !revoked && (!body.isNullOrBlank() || media != null)

    /** The user's own reaction, if any. */
    val myReaction: String? get() = reactions.firstOrNull { it.mine }?.emoji
}

/** The message another one answers; [friendlyId] null when Jarvis does not keep it. */
@Serializable
data class WhatsappQuotedMessage(
    @SerialName("friendly_id") val friendlyId: String? = null,
    val sender: String? = null,
    @SerialName("from_me") val fromMe: Boolean = false,
    val type: String? = null,
    val body: String? = null,
)

/** A member mentioned in a message. */
@Serializable
data class WhatsappMention(val jid: String, val name: String? = null)

/** A location sent to a chat; [live] while it is shared live. */
@Serializable
data class WhatsappLocation(
    val latitude: Double,
    val longitude: Double,
    val name: String? = null,
    val address: String? = null,
    val url: String? = null,
    val live: Boolean = false,
)

/** A contact card: the name and its numbers (E.164). */
@Serializable
data class WhatsappContactCard(val name: String? = null, val numbers: List<String> = emptyList())

/** A poll: [selectableCount] 1 for one answer, 0 for any number. */
@Serializable
data class WhatsappPoll(
    val name: String,
    @SerialName("selectable_count") val selectableCount: Int = 1,
    val options: List<WhatsappPollOption> = emptyList(),
) {
    val totalVotes: Int get() = options.sumOf { it.votes }
}

/** An answer of a poll, how many chose it and whether the user did. */
@Serializable
data class WhatsappPollOption(val name: String, val votes: Int = 0, val mine: Boolean = false)

/** An emoji given to a message: how many gave it, and whether the user did (one per person). */
@Serializable
data class WhatsappReaction(val emoji: String, val count: Int = 1, val mine: Boolean = false)

/** The body of `POST /whatsapp/conversations/{chat}/messages/{message}/reaction`: the same emoji again, or an empty one, removes it. */
@Serializable
data class WhatsappReactionInput(val emoji: String)

/** The user's reaction once sent; null when it was removed. */
@Serializable
data class WhatsappReacted(val emoji: String? = null)

/** The body of `POST /whatsapp/conversations/{chat}/forward`: messages of the chat sent again to [to], chats of the same number. */
@Serializable
data class WhatsappForwardInput(val messages: List<String>, val to: List<String>)

/** The answer of the forward: how many messages are on their way. */
@Serializable
data class WhatsappForwarded(val deliveries: Int = 0)

/** The file of a message: what WhatsApp said about it, and the signed URL that hands it over. */
@Serializable
data class WhatsappConversationMedia(
    /** `image`, `video`, `audio` or `document`. */
    val kind: String,
    val mime: String? = null,
    val name: String? = null,
    val size: Long? = null,
    val seconds: Int? = null,
    /** 40 bar heights, 0 to 100, for an audio. */
    val waveform: List<Int>? = null,
    /** A tiny JPEG preview, base64. */
    val thumbnail: String? = null,
    /** Signed, valid 6 hours, no token; null when the file is too big to fetch. */
    val url: String? = null,
)

/** The answer of `GET /whatsapp/conversations/{chat}/messages`: newest first. */
@Serializable
data class WhatsappConversationMessagesPage(
    val data: List<WhatsappConversationMessage>,
    /** What Jarvis is still sending (pending or failed in the last day); only on the first page. */
    val outgoing: List<WhatsappDelivery> = emptyList(),
    val meta: WhatsappConversationMessagesMeta = WhatsappConversationMessagesMeta(),
)

@Serializable
data class WhatsappConversationMessagesMeta(
    @SerialName("has_more") val hasMore: Boolean = false,
)

/** The body of `POST /whatsapp/conversations/{chat}/messages` without a file; [replyTo] the message it answers, only when given. */
@OptIn(ExperimentalSerializationApi::class)
@Serializable
data class WhatsappConversationText(
    val text: String,
    @SerialName("reply_to") @EncodeDefault(EncodeDefault.Mode.NEVER) val replyTo: String? = null,
)

/** The body of `PATCH /whatsapp/conversations/{chat}/messages/{message}`: the new text. */
@Serializable
data class WhatsappMessageEdit(val text: String)

/** The body of `POST /whatsapp/conversations/{chat}/messages/{message}/star`. */
@Serializable
data class WhatsappStarInput(val starred: Boolean)

/**
 * `GET /whatsapp/conversations/{chat}/messages/{message}/info`: how far a
 * message the number sent got; the times only in a one-to-one chat
 * ([perMember] false in a group: WhatsApp does not tell them).
 */
@Serializable
data class WhatsappMessageInfo(
    @SerialName("friendly_id") val friendlyId: String,
    @SerialName("sent_at") val sentAt: String? = null,
    val receipt: WhatsappReceipt? = null,
    @SerialName("delivered_at") val deliveredAt: String? = null,
    @SerialName("read_at") val readAt: String? = null,
    @SerialName("played_at") val playedAt: String? = null,
    @SerialName("per_member") val perMember: Boolean = false,
)

/** The answer of the search, the starred messages and the gallery of a chat. */
@Serializable
data class WhatsappMessageList(
    val data: List<WhatsappConversationMessage>,
    val meta: WhatsappConversationMessagesMeta = WhatsappConversationMessagesMeta(),
)

/** The body of `POST /whatsapp/conversations/{chat}/poll`; [multiple], more than one answer per person. */
@OptIn(ExperimentalSerializationApi::class)
@Serializable
data class WhatsappPollInput(
    val question: String,
    val options: List<String>,
    val multiple: Boolean = false,
    @SerialName("reply_to") @EncodeDefault(EncodeDefault.Mode.NEVER) val replyTo: String? = null,
)

/** The body of `POST /whatsapp/conversations/{chat}/location`. */
@OptIn(ExperimentalSerializationApi::class)
@Serializable
data class WhatsappLocationInput(
    val latitude: Double,
    val longitude: Double,
    @EncodeDefault(EncodeDefault.Mode.NEVER) val name: String? = null,
    @EncodeDefault(EncodeDefault.Mode.NEVER) val address: String? = null,
    @SerialName("reply_to") @EncodeDefault(EncodeDefault.Mode.NEVER) val replyTo: String? = null,
)

/** The body of `POST /whatsapp/conversations/{chat}/contacts`: contact chats of the same number. */
@Serializable
data class WhatsappContactsInput(val contacts: List<String>)

/** The answer of `POST /whatsapp/conversations/{chat}/typing`. */
@Serializable
data class WhatsappTypingShown(val shown: Boolean = false)

/** `whatsapp.chat.updated` on the user's private channel: a chat got a message, sent or received, or new ticks. */
@Serializable
data class WhatsappChatUpdatedEvent(
    @SerialName("account_id") val accountId: String,
    @SerialName("chat_id") val chatId: String,
) {
    companion object {
        const val NAME = "whatsapp.chat.updated"
    }
}

/**
 * `whatsapp.chat.presence` on the user's private channel: someone of a chat
 * is typing, recording, online or gone (`composing`, `recording`, `paused`,
 * `available`, `unavailable`). [who] names the member in a group.
 */
@Serializable
data class WhatsappChatPresenceEvent(
    @SerialName("account_id") val accountId: String,
    @SerialName("chat_id") val chatId: String,
    val presence: String,
    val who: String? = null,
    @SerialName("last_seen") val lastSeen: String? = null,
) {
    companion object {
        const val NAME = "whatsapp.chat.presence"
    }
}
