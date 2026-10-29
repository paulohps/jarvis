package app.jarvis.android.network

import kotlinx.serialization.EncodeDefault
import kotlinx.serialization.ExperimentalSerializationApi
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/** `components/schemas/UserSummary`. */
@Serializable
data class UserSummary(
    @SerialName("friendly_id") val friendlyId: String,
    val name: String,
)

/** `components/schemas/ShoppingList` (US-8.1). The counts come on the index; the items only on a single list. */
@Serializable
data class ShoppingList(
    @SerialName("friendly_id") val friendlyId: String,
    val name: String,
    @SerialName("is_archived") val isArchived: Boolean = false,
    @SerialName("archived_at") val archivedAt: String? = null,
    /** The household's default: a voice command that names no list adds to it. */
    @SerialName("is_default") val isDefault: Boolean = false,
    @SerialName("items_count") val itemsCount: Int? = null,
    @SerialName("unchecked_items_count") val uncheckedItemsCount: Int? = null,
    /**
     * Made by a recurring list ([scheduleId]): it has a deadline, and is
     * completed — by hand or at the deadline — instead of archived.
     */
    @SerialName("schedule_id") val scheduleId: String? = null,
    @SerialName("due_at") val dueAt: String? = null,
    @SerialName("is_overdue") val isOverdue: Boolean = false,
    @SerialName("completed_at") val completedAt: String? = null,
    /** Only on a single list: unchecked first by position, checked at the end. */
    val items: List<ShoppingListItem> = emptyList(),
    /** The workspace it is shared in; `null` from a server older than them. */
    @Serializable(with = LenientWorkspaceSerializer::class) val workspace: WorkspaceSummary? = null,
) {
    companion object {
        /** The private Reverb channel of a list (`ShoppingListChanged`, US-8.2). */
        fun broadcastChannel(listId: String): String = "private-shopping-lists.$listId"
    }
}

/** What a recurring list's next list starts with. */
@Serializable
enum class ShoppingListNextContent {
    @SerialName("pending") Pending,
    @SerialName("all") All,
    @SerialName("template_and_pending") TemplateAndPending,
    @SerialName("empty") Empty,
}

/**
 * `components/schemas/ShoppingListSchedule`: a shopping list made again on a
 * schedule (the market every Friday), each run the deadline of a list.
 * [openList] is the list it made that is still open, if any.
 */
@Serializable
data class ShoppingListSchedule(
    @SerialName("friendly_id") val friendlyId: String,
    val name: String,
    @SerialName("next_list_content") val nextListContent: ShoppingListNextContent = ShoppingListNextContent.Pending,
    @SerialName("closes_automatically") val closesAutomatically: Boolean = true,
    @SerialName("waits_for_close") val waitsForClose: Boolean = false,
    @SerialName("template_items") val templateItems: List<String> = emptyList(),
    val recurrence: Recurrence? = null,
    @SerialName("open_list") val openList: OpenList? = null,
    /** The workspace its lists are made in; `null` from a server older than them. */
    @Serializable(with = LenientWorkspaceSerializer::class) val workspace: WorkspaceSummary? = null,
) {
    @Serializable
    data class OpenList(
        @SerialName("friendly_id") val friendlyId: String,
        val name: String,
        @SerialName("due_at") val dueAt: String? = null,
        @SerialName("is_overdue") val isOverdue: Boolean = false,
    )
}

/**
 * `components/schemas/ShoppingListScheduleInput`: the whole recurring list, as
 * the web form sends it. Each run of [recurrence] is a list's deadline;
 * [templateItems] only count for `template_and_pending`, and [waitsForClose]
 * only for a list closed by hand (the server ignores it otherwise).
 * [workspace] (a friendly id) is sent only when set: on a new one, `null`
 * means the user's default workspace; on an edit, it moves the schedule.
 */
@OptIn(ExperimentalSerializationApi::class)
@Serializable
data class ShoppingListScheduleInput(
    val name: String,
    val recurrence: RecurrenceInput,
    @SerialName("next_list_content") val nextListContent: ShoppingListNextContent,
    @SerialName("closes_automatically") val closesAutomatically: Boolean,
    @SerialName("waits_for_close") val waitsForClose: Boolean,
    @SerialName("template_items") val templateItems: List<String>,
    @EncodeDefault(EncodeDefault.Mode.NEVER) val workspace: String? = null,
) {
    companion object {
        /** The template typed one item per line, without the blank lines. */
        fun templateItems(lines: String): List<String> = lines.lines().map { it.trim() }.filter { it.isNotEmpty() }
    }
}

/** The `filter` of `GET /shopping/lists`. */
enum class ShoppingListFilter(val apiValue: String) {
    Active("active"),
    Archived("archived"),
}

/** `components/schemas/ShoppingListItem`. */
@Serializable
data class ShoppingListItem(
    @SerialName("friendly_id") val friendlyId: String,
    val name: String,
    @SerialName("quantity_note") val quantityNote: String? = null,
    val position: Int = 0,
    @SerialName("is_checked") val isChecked: Boolean = false,
    @SerialName("checked_at") val checkedAt: String? = null,
    @SerialName("checked_by") val checkedBy: UserSummary? = null,
    @SerialName("client_updated_at") val clientUpdatedAt: String? = null,
)

/** As the API orders them: unchecked by position, then checked in the order they were checked. */
fun List<ShoppingListItem>.sortedForDisplay(): List<ShoppingListItem> {
    val unchecked = filterNot { it.isChecked }.sortedBy { it.position }
    val checked = filter { it.isChecked }.sortedWith(
        compareBy<ShoppingListItem> { it.checkedAt?.let(::parseInstant) ?: java.time.Instant.MAX }.thenBy { it.position },
    )

    return unchecked + checked
}

/**
 * The items with [order] (friendly ids) in the first positions, as
 * `POST /shopping/lists/{list}/reorder` leaves them: the items left out keep
 * their relative order after them, unknown ids are ignored.
 */
fun List<ShoppingListItem>.reordered(order: List<String>): List<ShoppingListItem> {
    val rank = order.withIndex().reversed().associate { (index, id) -> id to index }
    val current = sortedBy { it.position }
    val given = current.filter { it.friendlyId in rank }.sortedBy { rank.getValue(it.friendlyId) }
    val others = current.filterNot { it.friendlyId in rank }

    return (given + others).mapIndexed { position, item -> item.copy(position = position) }
}

/** `POST /shopping/lists/{list}/reorder`: the item ids in their new order. */
@Serializable
data class ShoppingItemsOrder(val items: List<String>)

private fun parseInstant(iso: String): java.time.Instant? =
    runCatching { java.time.OffsetDateTime.parse(iso).toInstant() }.getOrNull()

/**
 * `components/schemas/ShoppingListInput`. [workspace] (a friendly id) is sent
 * only when set: on a new list, `null` means the user's default workspace; on
 * an update, it moves the list there.
 */
@OptIn(ExperimentalSerializationApi::class)
@Serializable
data class ShoppingListInput(
    val name: String,
    @EncodeDefault(EncodeDefault.Mode.NEVER) val workspace: String? = null,
)

/** `components/schemas/ShoppingListItemInput`. A `null` note is sent, so an edit can clear it. */
@Serializable
data class ShoppingItemInput(
    val name: String,
    @SerialName("quantity_note") val quantityNote: String?,
)

/**
 * The body of `POST /shopping/items/{item}/toggle` (US-8.2): `checked` is the
 * state to end in and `client_updated_at` the moment of the tap on the phone.
 */
@Serializable
data class ShoppingItemToggle(
    val checked: Boolean,
    @SerialName("client_updated_at") val clientUpdatedAt: String?,
)

/** The answer of `POST /shopping/lists/{list}/clear-checked`. */
@Serializable
data class ClearedCheckedItems(
    val data: ShoppingList,
    val meta: Meta = Meta(),
) {
    @Serializable
    data class Meta(val removed: Int = 0)
}

/** The `shopping-list.changed` event of a list's channel: a hint of what changed; the screen reads the list again. */
object ShoppingListChangedEvent {
    const val NAME = "shopping-list.changed"
}

/** `components/schemas/Reminder` (US-9.1). */
@Serializable
data class Reminder(
    @SerialName("friendly_id") val friendlyId: String,
    val title: String,
    val note: String? = null,
    @SerialName("is_recurring") val isRecurring: Boolean = false,
    /** A reminder or an event; absent before the server knew it: a reminder. */
    val kind: ReminderKind = ReminderKind.Reminder,
    @SerialName("is_all_day") val isAllDay: Boolean = false,
    /** How long a timed event lasts; `null` otherwise. */
    @SerialName("duration_minutes") val durationMinutes: Int? = null,
    /** `null` for an undated task; `once` for a dated one. */
    val recurrence: Recurrence? = null,
    @SerialName("created_by") val createdBy: UserSummary? = null,
    /** Who is responsible, and receives `reminder_due`; `null` for nobody (the creator receives it then). */
    @SerialName("assigned_to") val assignedTo: UserSummary? = null,
    /** The workspace it is shared in; `null` from a server older than them. */
    @Serializable(with = LenientWorkspaceSerializer::class) val workspace: WorkspaceSummary? = null,
)

/** What a reminder is: something to do and complete, or an event on the calendar, with a duration and nothing to complete. */
@Serializable
enum class ReminderKind {
    @SerialName("reminder") Reminder,
    @SerialName("event") Event,
}

/** The answer of `GET /reminders/{id}`: the reminder and who may be made responsible, for the form. */
@Serializable
data class ReminderPage(
    val data: Reminder,
    val meta: Meta = Meta(),
) {
    @Serializable
    data class Meta(
        val timezone: String? = null,
        val assignees: List<UserSummary> = emptyList(),
        /** Who may be made responsible in each of the user's workspaces (where it may be moved). */
        @SerialName("assignees_by_workspace")
        @Serializable(with = AssigneesByWorkspaceSerializer::class)
        val assigneesByWorkspace: Map<String, List<UserSummary>> = emptyMap(),
    )
}

/** The sources of the agenda's calendar, as [CalendarEntry.source] names them. */
enum class CalendarSource(val apiValue: String) {
    Reminders("reminders"),
    Events("events"),
    Invoices("invoices"),
    Whatsapp("whatsapp"),
    Shopping("shopping"),
    Google("google"),
    ;

    companion object {
        fun of(apiValue: String): CalendarSource? = entries.firstOrNull { it.apiValue == apiValue }
    }
}

/**
 * `components/schemas/CalendarEntry`: one item of the agenda's calendar, from
 * any source. A reminder or event names its `reminder_id` (to edit it) and,
 * once it has one, its `occurrence_id` (to complete a reminder); the other
 * sources link to their web page (`url`).
 */
@Serializable
data class CalendarEntry(
    val key: String,
    val source: String,
    val title: String,
    val subtitle: String? = null,
    @SerialName("starts_at") val startsAt: String,
    @SerialName("ends_at") val endsAt: String? = null,
    @SerialName("is_all_day") val isAllDay: Boolean = false,
    @SerialName("is_projected") val isProjected: Boolean = false,
    @SerialName("is_completable") val isCompletable: Boolean = false,
    @SerialName("is_completed") val isCompleted: Boolean = false,
    @SerialName("is_overdue") val isOverdue: Boolean = false,
    @SerialName("reminder_id") val reminderId: String? = null,
    @SerialName("occurrence_id") val occurrenceId: String? = null,
    val url: String? = null,
    /**
     * The workspace of a shared item (a reminder, an event, a list); `null`
     * for a personal source (WhatsApp, Google) and from a server older than them.
     */
    @Serializable(with = LenientWorkspaceSerializer::class) val workspace: WorkspaceSummary? = null,
) {
    /** The known source, `null` for one this app does not know yet. */
    val calendarSource: CalendarSource?
        get() = CalendarSource.of(source)
}

/** The answer of `GET /agenda`: the items starting in the period, all-day ones first in each day, and the sources the user may see. */
@Serializable
data class CalendarPage(
    val data: List<CalendarEntry>,
    val meta: Meta = Meta(),
) {
    @Serializable
    data class Meta(
        val mine: Boolean = false,
        val sources: List<String> = emptyList(),
        val timezone: String? = null,
        /** Who may be made responsible; absent before the server knew it. */
        val assignees: List<UserSummary> = emptyList(),
        /** Who may be made responsible in each of the user's workspaces, by its friendly id. */
        @SerialName("assignees_by_workspace")
        @Serializable(with = AssigneesByWorkspaceSerializer::class)
        val assigneesByWorkspace: Map<String, List<UserSummary>> = emptyMap(),
    )
}

/** `components/schemas/ReminderOccurrence`. */
@Serializable
data class ReminderOccurrence(
    @SerialName("friendly_id") val friendlyId: String,
    @SerialName("due_at") val dueAt: String? = null,
    @SerialName("is_completed") val isCompleted: Boolean = false,
    @SerialName("is_overdue") val isOverdue: Boolean = false,
    @SerialName("completed_at") val completedAt: String? = null,
    @SerialName("completed_by") val completedBy: UserSummary? = null,
    val reminder: Reminder,
)

/**
 * The answer of `GET /reminders`: the occurrences under the filter and how
 * many each filter has, as the web's tabs, and who may be made responsible
 * (absent before the server knew it: none).
 */
@Serializable
data class ReminderOccurrencePage(
    val data: List<ReminderOccurrence>,
    val meta: Meta = Meta(),
) {
    @Serializable
    data class Meta(
        val counts: Map<String, Int> = emptyMap(),
        val timezone: String? = null,
        val assignees: List<UserSummary> = emptyList(),
        /** Who may be made responsible in each of the user's workspaces, by its friendly id. */
        @SerialName("assignees_by_workspace")
        @Serializable(with = AssigneesByWorkspaceSerializer::class)
        val assigneesByWorkspace: Map<String, List<UserSummary>> = emptyMap(),
    )

    fun count(filter: ReminderFilter): Int? = meta.counts[filter.apiValue]
}

/**
 * The answer of `GET /reminders/agenda`: the open occurrences by day, as the
 * web's agenda, with the coming runs of the repeating reminders projected for
 * the next `meta.projection_days`.
 */
@Serializable
data class ReminderAgendaPage(
    val data: List<ReminderAgendaGroup>,
    val meta: Meta = Meta(),
) {
    @Serializable
    data class Meta(
        val mine: Boolean = false,
        @SerialName("projection_days") val projectionDays: Int = 0,
        val assignees: List<UserSummary> = emptyList(),
        val timezone: String? = null,
        /** Who may be made responsible in each of the user's workspaces, by its friendly id. */
        @SerialName("assignees_by_workspace")
        @Serializable(with = AssigneesByWorkspaceSerializer::class)
        val assigneesByWorkspace: Map<String, List<UserSummary>> = emptyMap(),
    )
}

/** A group of the agenda: `overdue` first, then each day (`date`, `YYYY-MM-DD` in the user's timezone), then `undated`. */
@Serializable
data class ReminderAgendaGroup(
    val key: String,
    val date: String? = null,
    val entries: List<ReminderAgendaEntry> = emptyList(),
) {
    /** The day of a day group. */
    val day: java.time.LocalDate?
        get() = date?.let { runCatching { java.time.LocalDate.parse(it) }.getOrNull() }

    val isOverdue: Boolean
        get() = key == OVERDUE

    companion object {
        const val OVERDUE = "overdue"
        const val UNDATED = "undated"
    }
}

/**
 * A line of the agenda: an open occurrence, or a coming run of a repeating
 * reminder projected without one (`is_projected`), which cannot be completed
 * until its occurrence exists.
 */
@Serializable
data class ReminderAgendaEntry(
    @SerialName("due_at") val dueAt: String? = null,
    @SerialName("is_projected") val isProjected: Boolean = false,
    @SerialName("is_overdue") val isOverdue: Boolean = false,
    val occurrence: ReminderOccurrence? = null,
    val reminder: Reminder,
) {
    /** A key unique in the agenda. */
    val key: String
        get() = occurrence?.friendlyId ?: "${reminder.friendlyId}@$dueAt"
}

/** The presets of a schedule (US-12.1). */
@Serializable
enum class RecurrenceFrequency {
    @SerialName("once") Once,
    @SerialName("daily") Daily,
    @SerialName("weekly") Weekly,
    @SerialName("monthly") Monthly,
    @SerialName("custom_cron") CustomCron,
}

/** The weekday slugs of a weekly schedule, Monday first (ISO). */
@Serializable
enum class Weekday(val dayOfWeek: java.time.DayOfWeek) {
    @SerialName("monday") Monday(java.time.DayOfWeek.MONDAY),
    @SerialName("tuesday") Tuesday(java.time.DayOfWeek.TUESDAY),
    @SerialName("wednesday") Wednesday(java.time.DayOfWeek.WEDNESDAY),
    @SerialName("thursday") Thursday(java.time.DayOfWeek.THURSDAY),
    @SerialName("friday") Friday(java.time.DayOfWeek.FRIDAY),
    @SerialName("saturday") Saturday(java.time.DayOfWeek.SATURDAY),
    @SerialName("sunday") Sunday(java.time.DayOfWeek.SUNDAY),
}

/**
 * `components/schemas/Recurrence`: a schedule as saved. `time_of_day` and the
 * weekdays are read in `timezone`; the instants are in UTC.
 */
@Serializable
data class Recurrence(
    val frequency: RecurrenceFrequency,
    @SerialName("time_of_day") val timeOfDay: String? = null,
    val weekdays: List<Weekday> = emptyList(),
    @SerialName("day_of_month") val dayOfMonth: Int? = null,
    @SerialName("is_last_day_of_month") val isLastDayOfMonth: Boolean = false,
    @SerialName("cron_expression") val cronExpression: String? = null,
    val timezone: String = "UTC",
    @SerialName("starts_at") val startsAt: String? = null,
    @SerialName("ends_at") val endsAt: String? = null,
    @SerialName("next_run_at") val nextRunAt: String? = null,
    @SerialName("is_paused") val isPaused: Boolean = false,
)

/**
 * `components/schemas/RecurrenceInput`: a schedule to save. The dates go
 * without an offset (`2026-10-06T09:00`), so the server reads them in the
 * user's timezone, as the web form sends them. Fields that do not apply to the
 * frequency are left out.
 */
@Serializable
data class RecurrenceInput(
    val frequency: RecurrenceFrequency,
    @SerialName("time_of_day") val timeOfDay: String? = null,
    val weekdays: List<Weekday>? = null,
    @SerialName("day_of_month") val dayOfMonth: Int? = null,
    @SerialName("is_last_day_of_month") val isLastDayOfMonth: Boolean? = null,
    @SerialName("cron_expression") val cronExpression: String? = null,
    @SerialName("starts_at") val startsAt: String? = null,
    @SerialName("ends_at") val endsAt: String? = null,
)

/**
 * `components/schemas/ReminderInput`: the whole reminder. No `due_at` nor
 * `recurrence` makes an undated task; both are sent as `null` on purpose, as
 * an edit replaces the reminder. So is `assigned_to`: `null` leaves the
 * reminder with nobody responsible. [workspace] (a friendly id) is sent only
 * when set: on a new one, `null` means the user's default workspace; on an
 * edit, it moves the reminder.
 */
@OptIn(ExperimentalSerializationApi::class)
@Serializable
data class ReminderInput(
    val title: String,
    val note: String?,
    @SerialName("due_at") val dueAt: String?,
    val recurrence: RecurrenceInput?,
    @SerialName("assigned_to") val assignedTo: String?,
    val kind: ReminderKind = ReminderKind.Reminder,
    /** An all-day item is due at the server's all-day time, whatever time is sent. */
    @SerialName("is_all_day") val isAllDay: Boolean = false,
    /** Needed for a timed event. */
    @SerialName("duration_minutes") val durationMinutes: Int? = null,
    @EncodeDefault(EncodeDefault.Mode.NEVER) val workspace: String? = null,
)

/** The filters of `GET /reminders`, as the web's tabs. */
enum class ReminderFilter(val apiValue: String) {
    Pending("pending"),
    Overdue("overdue"),
    Completed("completed"),
}

/** `components/schemas/WhatsappAccount` (US-7.2), read only in the app. */
@Serializable
data class WhatsappAccount(
    @SerialName("friendly_id") val friendlyId: String,
    val nickname: String,
    @SerialName("phone_number") val phoneNumber: String? = null,
    /** `connecting`, `open` or `closed`. */
    val status: String = "closed",
    @SerialName("is_open") val isOpen: Boolean = status == "open",
    /** The defaults of its chats. */
    @SerialName("chat_settings") val chatSettings: WhatsappAccountChatSettings = WhatsappAccountChatSettings(),
)

@Serializable
enum class WhatsappTargetType {
    @SerialName("contact") Contact,
    @SerialName("group") Group,
}

/** `components/schemas/WhatsappTarget`: a contact or group of an account. */
@Serializable
data class WhatsappTarget(
    @SerialName("friendly_id") val friendlyId: String,
    val name: String,
    val type: WhatsappTargetType = WhatsappTargetType.Contact,
    @SerialName("phone_number") val phoneNumber: String? = null,
    @SerialName("is_synced") val isSynced: Boolean = true,
)

/** The account a message or delivery is sent from. */
@Serializable
data class WhatsappAccountSummary(
    @SerialName("friendly_id") val friendlyId: String,
    val nickname: String,
)

@Serializable
enum class WhatsappMessageState {
    @SerialName("active") Active,
    @SerialName("paused") Paused,
    @SerialName("cancelled") Cancelled,
    @SerialName("finished") Finished,
}

/** `components/schemas/WhatsappMessage`: a scheduled message of the user (US-7.3). */
@Serializable
data class WhatsappMessage(
    @SerialName("friendly_id") val friendlyId: String,
    val account: WhatsappAccountSummary,
    val targets: List<WhatsappTarget> = emptyList(),
    val body: String,
    val state: WhatsappMessageState,
    val recurrence: Recurrence? = null,
    @SerialName("cancelled_at") val cancelledAt: String? = null,
) {
    /** Only a one-time message not sent yet can be cancelled; a recurring one is paused. */
    val canBeCancelled: Boolean
        get() = state == WhatsappMessageState.Active && recurrence?.frequency == RecurrenceFrequency.Once

    val canBePaused: Boolean
        get() = state == WhatsappMessageState.Active

    val canBeResumed: Boolean
        get() = state == WhatsappMessageState.Paused

    /** A cancelled message cannot be edited. */
    val canBeEdited: Boolean
        get() = state != WhatsappMessageState.Cancelled
}

@Serializable
enum class WhatsappDeliveryStatus {
    @SerialName("pending") Pending,
    @SerialName("sent") Sent,
    @SerialName("failed") Failed,
}

/** `components/schemas/WhatsappDelivery`: one sending to one target (US-7.5). */
@Serializable
data class WhatsappDelivery(
    val account: WhatsappAccountSummary,
    val target: WhatsappTarget,
    @SerialName("message_id") val messageId: String? = null,
    val body: String,
    /** `scheduled`, `manual`, `notification` or `voice`. */
    val source: String,
    val status: WhatsappDeliveryStatus,
    @SerialName("error_message") val errorMessage: String? = null,
    @SerialName("scheduled_for") val scheduledFor: String? = null,
    @SerialName("sent_at") val sentAt: String? = null,
    @SerialName("created_at") val createdAt: String? = null,
)

/**
 * `components/schemas/WhatsappSendingInput`, and with a schedule
 * `WhatsappMessageInput` (US-7.3, US-7.5). `recurrence` is left out when
 * sending now.
 */
@OptIn(ExperimentalSerializationApi::class)
@Serializable
data class WhatsappMessageInput(
    val account: String,
    val targets: List<String>,
    val numbers: List<String>,
    val body: String,
    @EncodeDefault(EncodeDefault.Mode.NEVER) val recurrence: RecurrenceInput? = null,
)

/** A page of a paginated list (`meta.current_page` / `meta.last_page`). */
@Serializable
data class Page<T>(
    val data: List<T>,
    val meta: PageMeta = PageMeta(),
) {
    val hasMorePages: Boolean
        get() = meta.currentPage < meta.lastPage
}

@Serializable
data class PageMeta(
    @SerialName("current_page") val currentPage: Int = 1,
    @SerialName("last_page") val lastPage: Int = 1,
)
