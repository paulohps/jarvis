package app.jarvis.android.network

import kotlinx.serialization.EncodeDefault
import kotlinx.serialization.ExperimentalSerializationApi
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/**
 * `components/schemas/WhatsappGroup`: a group as WhatsApp has it now, its
 * permissions (only admins send, only admins edit the info), whether the
 * number is an admin, and its members.
 */
@Serializable
data class WhatsappGroup(
    val chat: WhatsappConversation,
    @SerialName("admins_only") val adminsOnly: Boolean = false,
    @SerialName("info_admins_only") val infoAdminsOnly: Boolean = false,
    @SerialName("is_admin") val isAdmin: Boolean = false,
    val members: List<WhatsappGroupMember> = emptyList(),
) {
    /** The subject, description and picture can be changed by the number. */
    val canEditInfo: Boolean get() = isAdmin || !infoAdminsOnly
}

/** `components/schemas/WhatsappGroupMember`; [me], the number itself. */
@Serializable
data class WhatsappGroupMember(
    val jid: String,
    val lid: String? = null,
    val name: String,
    /** E.164; null when WhatsApp only gives the `@lid`. */
    val number: String? = null,
    val admin: Boolean = false,
    val me: Boolean = false,
) {
    /** What a mention of the member is written with: `@` and these digits (the number with its country code). */
    val mentionDigits: String? get() = number?.filter(Char::isDigit)?.takeIf { it.isNotEmpty() }
}

/** The body of `POST /whatsapp/groups`: [members] are contact chats of the number [account]. */
@OptIn(ExperimentalSerializationApi::class)
@Serializable
data class WhatsappGroupInput(
    val account: String,
    val subject: String,
    val members: List<String>,
    @EncodeDefault(EncodeDefault.Mode.NEVER) val description: String? = null,
)

/** The body of `POST /whatsapp/conversations/{chat}/group/members`: `add`, `remove`, `promote` or `demote` [participants] (numbers or JIDs). */
@Serializable
data class WhatsappGroupMembersInput(val action: String, val participants: List<String>)

/** Whether WhatsApp did it for each participant (by JID), and the members now. */
@Serializable
data class WhatsappGroupMembersResult(
    val results: Map<String, Boolean> = emptyMap(),
    val members: List<WhatsappGroupMember> = emptyList(),
)

/** The body of `POST /whatsapp/conversations/{chat}/group/invite`: a new link with [revoke]. */
@Serializable
data class WhatsappGroupInviteInput(val revoke: Boolean = false)

/** The link to join a group. */
@Serializable
data class WhatsappGroupInvite(val url: String)
