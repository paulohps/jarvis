package app.jarvis.android.network

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/** `components/schemas/WhatsappChatMessage`: `body` is null for an audio or a media without caption. */
@Serializable
data class WhatsappChatMessage(
    @SerialName("friendly_id") val friendlyId: String,
    @SerialName("from_me") val fromMe: Boolean,
    val type: String,
    val body: String? = null,
    @SerialName("sent_at") val sentAt: String,
)

/** `components/schemas/WhatsappChat`: a chat for the reply keyboard. */
@Serializable
data class WhatsappChat(
    @SerialName("friendly_id") val friendlyId: String,
    val name: String,
    @SerialName("phone_number") val phoneNumber: String? = null,
    val account: WhatsappChatAccount,
    @SerialName("last_received_message") val lastReceivedMessage: WhatsappChatMessage? = null,
    @SerialName("awaiting_reply") val awaitingReply: Boolean = false,
)

@Serializable
data class WhatsappChatAccount(
    @SerialName("friendly_id") val friendlyId: String,
    val nickname: String,
)

/** The body of `POST /whatsapp/chat-messages/{message}/reply`: blank instructions are sent as null. */
@Serializable
data class WhatsappReplyRequest(val instruction: String?) {
    companion object {
        fun of(typed: String?): WhatsappReplyRequest = WhatsappReplyRequest(typed?.trim()?.takeIf { it.isNotEmpty() })
    }
}

/** The answer of `POST /whatsapp/chat-messages/{message}/reply`. */
@Serializable
data class WhatsappReplyDraft(val text: String)
