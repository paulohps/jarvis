package app.jarvis.android.network

import kotlinx.serialization.EncodeDefault
import kotlinx.serialization.ExperimentalSerializationApi
import kotlinx.serialization.KSerializer
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.builtins.ListSerializer
import kotlinx.serialization.builtins.MapSerializer
import kotlinx.serialization.builtins.nullable
import kotlinx.serialization.builtins.serializer
import kotlinx.serialization.descriptors.SerialDescriptor
import kotlinx.serialization.encoding.Decoder
import kotlinx.serialization.encoding.Encoder
import kotlinx.serialization.json.JsonDecoder
import kotlinx.serialization.json.JsonEncoder
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put

/** The module slugs of `/me` whose records live in a workspace. */
object ModuleSlug {
    const val SHOPPING = "shopping"
    const val REMINDERS = "reminders"
    const val INVOICES = "invoices"
}

/**
 * `components/schemas/WorkspaceSummary`: the workspace a shared record (a
 * shopping list, a recurring list, a reminder, an agenda entry) belongs to, in
 * the color the signed-in user sees it (their own, when they chose one).
 * Absent from a server older than the workspaces.
 */
@Serializable
data class WorkspaceSummary(
    val id: String,
    val name: String,
    val color: String,
    @SerialName("color_hex") val colorHex: String,
)

/**
 * The `workspace` of a shared record, read leniently: absent, `null` or
 * unreadable is no workspace rather than a failed screen.
 */
object LenientWorkspaceSerializer : KSerializer<WorkspaceSummary?> {
    override val descriptor: SerialDescriptor = WorkspaceSummary.serializer().nullable.descriptor

    override fun deserialize(decoder: Decoder): WorkspaceSummary? {
        val element = (decoder as? JsonDecoder)?.decodeJsonElement() ?: return null

        return runCatching { decoder.json.decodeFromJsonElement(WorkspaceSummary.serializer(), element) }.getOrNull()
    }

    override fun serialize(encoder: Encoder, value: WorkspaceSummary?) {
        if (value == null) {
            (encoder as JsonEncoder).encodeJsonElement(JsonNull)
        } else {
            encoder.encodeSerializableValue(WorkspaceSummary.serializer(), value)
        }
    }
}

/**
 * `components/schemas/MyWorkspace`: a workspace of the signed-in user, as
 * `GET /me` lists it: the color they see ([color], their own [ownColor] when
 * set, otherwise the workspace's [workspaceColor]), whether it is their
 * default, and the modules they have in it. The family's shared data (lists,
 * reminders) lives in a workspace; the user sees every one of theirs together.
 */
@Serializable
data class UserWorkspace(
    val id: String,
    val name: String,
    val color: String,
    @SerialName("color_hex") val colorHex: String,
    @SerialName("workspace_color") val workspaceColor: String = color,
    @SerialName("own_color") val ownColor: String? = null,
    @SerialName("is_default") val isDefault: Boolean = false,
    val modules: List<String> = emptyList(),
) {
    fun hasModule(slug: String): Boolean = slug in modules

    /** The workspace as a shared record names it. */
    val summary: WorkspaceSummary
        get() = WorkspaceSummary(id, name, color, colorHex)
}

/** The user's workspaces where they have the module, in the order of `/me`. */
fun User.workspacesWithModule(slug: String): List<UserWorkspace> = workspaces.filter { it.hasModule(slug) }

/**
 * Whether the module's screens show which workspace a record is in and ask
 * for one: only with two or more workspaces where the user has it. With one,
 * everything is in it and the screens stay quiet.
 */
fun User.showsWorkspaces(slug: String): Boolean = workspacesWithModule(slug).size > 1

/** The workspaces a form offers for the module: none (no picker) when the user has the module in one workspace only. */
fun User.workspacePickerOptions(slug: String): List<UserWorkspace> =
    if (showsWorkspaces(slug)) workspacesWithModule(slug) else emptyList()

/**
 * Where a new record of the module goes when the user picks none: their
 * default workspace when they have the module there, otherwise the first one
 * with the module (as the server picks it).
 */
fun User.defaultWorkspace(slug: String): UserWorkspace? {
    val candidates = workspacesWithModule(slug)

    return candidates.firstOrNull { it.isDefault } ?: candidates.firstOrNull()
}

/**
 * Whether an agenda item shows its workspace's color: a shared item (a
 * reminder, an event, a list, an invoice) whose module the user has in two or
 * more workspaces. Personal sources (WhatsApp, Google) have none.
 */
fun User.showsWorkspace(entry: CalendarEntry): Boolean {
    if (entry.workspace == null) {
        return false
    }

    val slug = when (entry.calendarSource) {
        CalendarSource.Reminders, CalendarSource.Events -> ModuleSlug.REMINDERS
        CalendarSource.Shopping -> ModuleSlug.SHOPPING
        CalendarSource.Invoices -> ModuleSlug.INVOICES
        CalendarSource.Whatsapp, CalendarSource.Google, null -> null
    }

    return slug != null && showsWorkspaces(slug)
}

/**
 * `meta.assignees_by_workspace` of the reminder answers: who may be made
 * responsible in each of the user's workspaces, by its friendly id. Read
 * leniently: an empty one comes as `[]` from PHP, and anything unreadable is
 * no list rather than a failed screen.
 */
object AssigneesByWorkspaceSerializer : KSerializer<Map<String, List<UserSummary>>> {
    private val delegate = MapSerializer(String.serializer(), ListSerializer(UserSummary.serializer()))

    override val descriptor: SerialDescriptor = delegate.descriptor

    override fun deserialize(decoder: Decoder): Map<String, List<UserSummary>> {
        val element = (decoder as? JsonDecoder)?.decodeJsonElement() as? JsonObject ?: return emptyMap()

        return runCatching { decoder.json.decodeFromJsonElement(delegate, element) }.getOrDefault(emptyMap())
    }

    override fun serialize(encoder: Encoder, value: Map<String, List<UserSummary>>) {
        encoder.encodeSerializableValue(delegate, value)
    }
}

/** `GET /workspace-colors`: a color a workspace, or a member's view of it, may have. */
@Serializable
data class WorkspaceColorOption(val name: String, val hex: String)

/**
 * The body of `PATCH /me/workspaces/{workspace}`: the user's own color for a
 * workspace and/or making it their default. Only what is set is sent;
 * [ColorChoice.WorkspaceColor] sends `color: null`, back to the workspace's.
 */
data class WorkspacePreferenceInput(
    val color: ColorChoice = ColorChoice.Keep,
    val makeDefault: Boolean = false,
) {
    sealed interface ColorChoice {
        data object Keep : ColorChoice

        data object WorkspaceColor : ColorChoice

        data class Own(val name: String) : ColorChoice
    }

    val body: JsonObject
        get() = buildJsonObject {
            when (color) {
                ColorChoice.Keep -> Unit
                ColorChoice.WorkspaceColor -> put("color", JsonNull)
                is ColorChoice.Own -> put("color", JsonPrimitive(color.name))
            }

            if (makeDefault) {
                put("is_default", true)
            }
        }
}

// Admin (super admin only)

/** A module as the admin area lists it (`GET /admin/modules`). */
@Serializable
data class AdminModule(
    val slug: String,
    val name: String = slug,
    @SerialName("is_active") val isActive: Boolean = true,
    @SerialName("is_available_on_mobile") val isAvailableOnMobile: Boolean = false,
)

/** A workspace of `GET /admin/workspaces`. */
@Serializable
data class AdminWorkspace(
    val id: String,
    val name: String,
    val color: String,
    @SerialName("color_hex") val colorHex: String,
    @SerialName("members_count") val membersCount: Int = 0,
    @SerialName("created_at") val createdAt: String? = null,
)

/**
 * `GET /admin/workspaces/{workspace}`: the workspace and its members.
 * [hasData] tells it cannot be deleted (the server refuses with a 422).
 */
@Serializable
data class AdminWorkspaceDetail(
    val id: String,
    val name: String,
    val color: String,
    @SerialName("color_hex") val colorHex: String,
    val members: List<AdminWorkspaceMember> = emptyList(),
    @SerialName("members_count") val membersCount: Int = members.size,
    @SerialName("has_data") val hasData: Boolean = false,
)

/** The user of a membership. */
@Serializable
data class AdminMemberUser(
    val id: String,
    val name: String,
    val email: String,
    @SerialName("avatar_url") val avatarUrl: String? = null,
    @SerialName("is_super_admin") val isSuperAdmin: Boolean = false,
    @SerialName("is_deactivated") val isDeactivated: Boolean = false,
)

/**
 * A member of a workspace: their own color for it ([color], `null` for the
 * workspace's), the color they see, whether it is their default and their
 * modules there.
 */
@Serializable
data class AdminWorkspaceMember(
    val user: AdminMemberUser,
    val color: String? = null,
    @SerialName("effective_color") val effectiveColor: String,
    @SerialName("effective_color_hex") val effectiveColorHex: String,
    @SerialName("is_default") val isDefault: Boolean = false,
    val modules: List<String> = emptyList(),
) {
    val id: String
        get() = user.id
}

/** A user of `GET /admin/users`, with the workspaces they are in. */
@Serializable
data class AdminUser(
    val id: String,
    val name: String,
    val email: String,
    @SerialName("avatar_url") val avatarUrl: String? = null,
    @SerialName("is_super_admin") val isSuperAdmin: Boolean = false,
    @SerialName("is_deactivated") val isDeactivated: Boolean = false,
    @SerialName("last_seen_at") val lastSeenAt: String? = null,
    val workspaces: List<WorkspaceSummary> = emptyList(),
)

/** The state of an invitation. */
enum class InvitationStatus(val apiValue: String) {
    Pending("pending"),
    Accepted("accepted"),
    Expired("expired"),
    Revoked("revoked"),
    ;

    companion object {
        fun of(apiValue: String): InvitationStatus? = entries.firstOrNull { it.apiValue == apiValue }
    }
}

/**
 * An invitation of `GET /admin/invitations`: who is invited, to which
 * workspace and with which modules there. [status] is kept raw;
 * [knownStatus] is `null` for one this app does not know.
 */
@Serializable
data class AdminInvitation(
    val id: String,
    val email: String,
    val status: String,
    @Serializable(with = LenientWorkspaceSerializer::class) val workspace: WorkspaceSummary? = null,
    val modules: List<String> = emptyList(),
    val locale: String? = null,
    @SerialName("expires_at") val expiresAt: String? = null,
    @SerialName("created_at") val createdAt: String? = null,
) {
    val knownStatus: InvitationStatus?
        get() = InvitationStatus.of(status)

    /** Only an open invitation can be sent again or revoked. */
    val isPending: Boolean
        get() = knownStatus == InvitationStatus.Pending
}

/** The body of `POST /admin/workspaces` and `PATCH /admin/workspaces/{workspace}`. */
@Serializable
data class AdminWorkspaceInput(val name: String, val color: String)

/**
 * The body of `POST /admin/workspaces/{workspace}/members` (with [user]) and
 * of `PATCH …/members/{user}` (without). The color is sent always, `null` for
 * the workspace's own; `is_default` only to make it the default.
 */
@OptIn(ExperimentalSerializationApi::class)
@Serializable
data class AdminMemberInput(
    @EncodeDefault(EncodeDefault.Mode.NEVER) val user: String? = null,
    val modules: List<String>,
    val color: String?,
    @EncodeDefault(EncodeDefault.Mode.NEVER) @SerialName("is_default") val isDefault: Boolean = false,
)

/** The body of `POST /admin/invitations`. */
@Serializable
data class AdminInvitationInput(
    val email: String,
    val workspace: String,
    val modules: List<String>,
    val locale: String,
)
