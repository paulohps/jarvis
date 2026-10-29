package app.jarvis.android.ui.whatsapp

import app.jarvis.android.network.ApiClient
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.WhatsappConversation
import app.jarvis.android.network.WhatsappConversationMessage
import app.jarvis.android.network.WhatsappGroup
import app.jarvis.android.network.WhatsappGroupInviteInput
import app.jarvis.android.network.WhatsappGroupMembersInput
import app.jarvis.android.network.WhatsappMessageList
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.toRequestBody

/**
 * The chat's info screen, as WhatsApp's: a group as WhatsApp has it now
 * ([loadGroup]) with its subject, description, permissions and disappearing
 * messages ([updateGroup]), its picture ([changePicture]), its members
 * ([members]: add, remove, promote, demote), the invite link ([invite]) and
 * leaving it ([leave]); and for any chat the search in it ([search]), the
 * starred messages ([starred]) and the gallery ([gallery]). Each change
 * answers the server's refusal as an [ApiException] (a 502 when WhatsApp
 * does not take it).
 */
class WhatsappChatInfoStore(val chatId: String, private val client: ApiClient) {
    data class State(
        val group: WhatsappGroup? = null,
        /** Why the group could not be read (the number disconnected…). */
        val groupFailure: ApiException? = null,
        /** The invite link, once asked for. */
        val inviteUrl: String? = null,
    )

    private val mutableState = MutableStateFlow(State())
    val state: StateFlow<State> = mutableState.asStateFlow()

    suspend fun loadGroup() {
        try {
            val group = client.call { whatsappGroup(chatId) }.data
            mutableState.update { it.copy(group = group, groupFailure = null) }
        } catch (_: ApiException.Unauthenticated) {
            // The shell already went back to the login.
        } catch (exception: ApiException) {
            mutableState.update { it.copy(groupFailure = exception) }
        }
    }

    /** @return the group as WhatsApp has it after the change. */
    suspend fun updateGroup(change: WhatsappGroupChange): WhatsappGroup {
        val group = client.call { updateWhatsappGroup(chatId, change.body()) }.data
        mutableState.update { it.copy(group = group) }

        return group
    }

    /** A JPEG or PNG; the new picture comes with the chat's next read. */
    suspend fun changePicture(name: String, mime: String?, bytes: ByteArray) {
        val part = MultipartBody.Part.createFormData("file", name, bytes.toRequestBody(mime?.toMediaTypeOrNull()))
        client.call { updateWhatsappGroupPicture(chatId, part) }
    }

    /**
     * [action] (`add`, `remove`, `promote`, `demote`) on [participants],
     * numbers with the country code or JIDs. @return how many WhatsApp did.
     */
    suspend fun members(action: String, participants: List<String>): Int {
        val result = client.call { updateWhatsappGroupMembers(chatId, WhatsappGroupMembersInput(action, participants)) }
        mutableState.update { current -> current.copy(group = current.group?.copy(members = result.members)) }

        return result.results.count { it.value }
    }

    /** The link to join; a new one (the old stops working) with [revoke]. */
    suspend fun invite(revoke: Boolean = false): String {
        val url = client.call { whatsappGroupInvite(chatId, WhatsappGroupInviteInput(revoke)) }.url
        mutableState.update { it.copy(inviteUrl = url) }

        return url
    }

    /** The number leaves the group; the chat stays, archived. */
    suspend fun leave(): WhatsappConversation = client.call { leaveWhatsappGroup(chatId) }.data

    /** The messages with every word of [query]; nothing under [MIN_SEARCH_LENGTH] characters. */
    suspend fun search(query: String): List<WhatsappConversationMessage> {
        val trimmed = query.trim()

        return if (trimmed.length < MIN_SEARCH_LENGTH) emptyList() else client.call { searchWhatsappConversation(chatId, trimmed) }.data
    }

    suspend fun starred(): List<WhatsappConversationMessage> = client.call { starredWhatsappMessages(chatId) }.data

    /** A page of [kind] older than [before]. */
    suspend fun gallery(kind: GalleryKind, before: String? = null): WhatsappMessageList = client.call { whatsappGallery(chatId, kind.query, before) }

    /** The tabs of the gallery, as the server names them. */
    enum class GalleryKind(val query: String) {
        Media("media"),
        Documents("documents"),
        Links("links"),
    }

    companion object {
        const val MIN_SEARCH_LENGTH = 2

        /** The timers of disappearing messages WhatsApp offers: off, 24 hours, 7 days, 90 days. */
        val EPHEMERAL_TIMERS = listOf(0, 86_400, 604_800, 7_776_000)

        /** What a member can be done to in a group. */
        const val ADD = "add"
        const val REMOVE = "remove"
        const val PROMOTE = "promote"
        const val DEMOTE = "demote"
    }
}

/** A change to a group (`PATCH /whatsapp/conversations/{chat}/group`): only the field set goes to the server. */
sealed interface WhatsappGroupChange {
    data class Subject(val subject: String) : WhatsappGroupChange

    /** Null or blank removes it. */
    data class Description(val description: String?) : WhatsappGroupChange

    /** Only admins send messages. */
    data class AdminsOnly(val adminsOnly: Boolean) : WhatsappGroupChange

    /** Only admins edit the group's info. */
    data class InfoAdminsOnly(val infoAdminsOnly: Boolean) : WhatsappGroupChange

    /** One of [WhatsappChatInfoStore.EPHEMERAL_TIMERS]. */
    data class Ephemeral(val seconds: Int) : WhatsappGroupChange

    fun body(): JsonObject = buildJsonObject {
        when (val change = this@WhatsappGroupChange) {
            is Subject -> put("subject", JsonPrimitive(change.subject.trim()))
            is Description -> put("description", change.description?.trim()?.takeIf { it.isNotEmpty() }?.let(::JsonPrimitive) ?: JsonNull)
            is AdminsOnly -> put("admins_only", JsonPrimitive(change.adminsOnly))
            is InfoAdminsOnly -> put("info_admins_only", JsonPrimitive(change.infoAdminsOnly))
            is Ephemeral -> put("ephemeral_seconds", JsonPrimitive(change.seconds))
        }
    }
}
