package app.jarvis.android.ui.reminders

import app.jarvis.android.network.ApiClient
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.CalendarEntry
import app.jarvis.android.network.CalendarSource
import app.jarvis.android.network.Reminder
import app.jarvis.android.network.ReminderFilter
import app.jarvis.android.network.ReminderInput
import app.jarvis.android.network.ReminderKind
import app.jarvis.android.network.ReminderOccurrence
import app.jarvis.android.network.UserSummary
import app.jarvis.android.ui.schedule.ScheduleDraft
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import java.time.LocalDate
import java.time.ZoneId
import java.time.format.DateTimeFormatter

/**
 * The household's agenda as on the web (US-9.1): a day, a week or a month of
 * everything scheduled — reminders, events, invoices, WhatsApp messages,
 * shopping lists and Google calendars — the next 30 days of it as a list, or
 * the occurrences of one filter and how many each filter has; "Only mine"
 * narrows them to the reminders and events the user is responsible for. Plus
 * completing, reopening and deleting.
 */
class RemindersStore(
    private val client: ApiClient,
    val zone: ZoneId = ZoneId.systemDefault(),
    mode: Mode = Mode.Month,
    today: LocalDate = LocalDate.now(zone),
) {
    enum class Mode(val view: AgendaView?) {
        Day(AgendaView.Day),
        Week(AgendaView.Week),
        Month(AgendaView.Month),
        Schedule(AgendaView.Schedule),
        List(null),
    }

    data class State(
        val mode: Mode = Mode.Month,
        val filter: ReminderFilter = ReminderFilter.Pending,
        val isMine: Boolean = false,
        /** The day the calendar is on. */
        val selectedDay: LocalDate,
        /** The period whose items are loaded (or loading). */
        val period: AgendaPeriod? = null,
        /** `null` while the period loads. */
        val entries: List<CalendarEntry>? = null,
        /** The sources the user may see, and those left off the calendar. */
        val sources: List<CalendarSource> = emptyList(),
        val hiddenSources: Set<CalendarSource> = emptySet(),
        /** `null` while the filter loads for the first time. */
        val occurrences: List<ReminderOccurrence>? = null,
        val counts: Map<ReminderFilter, Int> = emptyMap(),
        /** Who may be made responsible, for the form. */
        val assignees: List<UserSummary> = emptyList(),
        /** Who may be made responsible in each of the user's workspaces, for the form's picker. */
        val assigneesByWorkspace: Map<String, List<UserSummary>> = emptyMap(),
        val loadFailure: ApiException? = null,
        /** The failure of the last change, for an alert. */
        val actionFailure: ApiException? = null,
    ) {
        /** The month of the day the calendar is on. */
        val month: AgendaMonth
            get() = AgendaMonth.of(selectedDay)

        /** Whether the current view has something to show. */
        val hasLoaded: Boolean
            get() = if (mode == Mode.List) occurrences != null else entries != null

        internal val request: List<Any?>
            get() = listOf(mode, filter, isMine, period, hiddenSources)
    }

    private val mutableState = MutableStateFlow(State(mode = mode, selectedDay = today))
    val state: StateFlow<State> = mutableState.asStateFlow()

    suspend fun load() {
        val view = state.value.mode.view

        if (view != null) {
            val period = AgendaPeriod.of(view, state.value.selectedDay)
            mutableState.update { it.copy(period = period) }
        }

        val requested = state.value.request
        val (filter, isMine) = state.value.let { it.filter to it.isMine }
        val period = state.value.period
        val hidden = state.value.hiddenSources
        val mine = if (isMine) 1 else null

        try {
            if (view != null && period != null) {
                val page = client.call {
                    agenda(
                        from = period.start.atStartOfDay(zone).toOffsetDateTime().format(DateTimeFormatter.ISO_OFFSET_DATE_TIME),
                        to = period.end.atStartOfDay(zone).toOffsetDateTime().format(DateTimeFormatter.ISO_OFFSET_DATE_TIME),
                        mine = mine,
                        hide = CalendarSource.entries.filter { it in hidden }.map { it.apiValue },
                    )
                }

                // Another period, view or source meanwhile wins.
                if (requested != state.value.request) {
                    return
                }

                mutableState.update { current ->
                    current.copy(
                        entries = page.data,
                        sources = page.meta.sources.mapNotNull(CalendarSource::of),
                        assignees = page.meta.assignees.ifEmpty { current.assignees },
                        assigneesByWorkspace = page.meta.assigneesByWorkspace.ifEmpty { current.assigneesByWorkspace },
                        loadFailure = null,
                    )
                }
            } else {
                val page = client.call { reminderOccurrences(filter.apiValue, mine) }

                // A tap on another filter meanwhile wins.
                if (requested != state.value.request) {
                    return
                }

                mutableState.update { current ->
                    current.copy(
                        occurrences = page.data,
                        counts = ReminderFilter.entries.mapNotNull { option -> page.count(option)?.let { option to it } }.toMap(),
                        assignees = page.meta.assignees,
                        assigneesByWorkspace = page.meta.assigneesByWorkspace,
                        loadFailure = null,
                    )
                }
            }
        } catch (_: ApiException.Unauthenticated) {
            // The shell already went back to the login.
        } catch (exception: ApiException) {
            if (!state.value.hasLoaded) {
                mutableState.update { it.copy(loadFailure = exception) }
            }
        }
    }

    /** The period before or after: a day, a week, a month or 30 days. */
    suspend fun step(steps: Long) {
        val view = state.value.mode.view ?: return
        show(AgendaPeriod.step(view, state.value.selectedDay, steps))
    }

    /** A day in the current view; another period is loaded from scratch. */
    suspend fun show(day: LocalDate) {
        val view = state.value.mode.view
        mutableState.update { it.copy(selectedDay = day) }

        if (view == null || AgendaPeriod.of(view, day) == state.value.period) {
            return
        }

        mutableState.update { it.copy(entries = null, loadFailure = null) }
        load()
    }

    /** A day on the day view, as a tap on it in the week. */
    suspend fun open(day: LocalDate) {
        mutableState.update { it.copy(selectedDay = day) }
        show(Mode.Day)
    }

    /** A source on or off the calendar. */
    suspend fun toggle(source: CalendarSource) {
        mutableState.update { current ->
            val hidden = if (source in current.hiddenSources) current.hiddenSources - source else current.hiddenSources + source
            current.copy(hiddenSources = hidden, entries = null, loadFailure = null)
        }
        load()
    }

    /** Completes or reopens the occurrence of a calendar item. */
    suspend fun toggleCompletion(entry: CalendarEntry) {
        val occurrenceId = entry.occurrenceId ?: return

        try {
            if (entry.isCompleted) {
                client.call { reopenOccurrence(occurrenceId) }
            } else {
                client.call { completeOccurrence(occurrenceId) }
            }
        } catch (exception: ApiException) {
            fail(exception)
        }

        load()
    }

    /** The reminder or event of a calendar item, to edit it. */
    suspend fun reminder(entry: CalendarEntry): Reminder? {
        val reminderId = entry.reminderId ?: return null

        return try {
            val page = client.call { reminder(reminderId) }
            mutableState.update { current ->
                current.copy(
                    assignees = page.meta.assignees.ifEmpty { current.assignees },
                    assigneesByWorkspace = page.meta.assigneesByWorkspace.ifEmpty { current.assigneesByWorkspace },
                )
            }
            page.data
        } catch (exception: ApiException) {
            fail(exception)
            null
        }
    }

    /** Deletes the reminder or event of a calendar item. */
    suspend fun deleteReminder(entry: CalendarEntry) {
        val reminderId = entry.reminderId ?: return

        try {
            client.call { deleteReminder(reminderId) }
            mutableState.update { current -> current.copy(entries = current.entries?.filterNot { it.reminderId == reminderId }) }
        } catch (exception: ApiException) {
            fail(exception)
        }

        load()
    }

    /** Another filter: its list from scratch. */
    suspend fun show(filter: ReminderFilter) {
        mutableState.update { it.copy(filter = filter, occurrences = null, loadFailure = null) }
        load()
    }

    /** A view of the calendar or the tasks, from scratch. */
    suspend fun show(mode: Mode) {
        mutableState.update { it.copy(mode = mode, period = null, entries = null, occurrences = null, loadFailure = null) }
        load()
    }

    /** "Only mine" on or off: every view from scratch. */
    suspend fun showOnlyMine(isMine: Boolean) {
        mutableState.update { it.copy(isMine = isMine, entries = null, occurrences = null, counts = emptyMap(), loadFailure = null) }
        load()
    }

    /** Completes an open occurrence or reopens a completed one; a repeating reminder gets its next occurrence from the server. */
    suspend fun toggleCompletion(occurrence: ReminderOccurrence) {
        try {
            if (occurrence.isCompleted) {
                client.call { reopenOccurrence(occurrence.friendlyId) }
            } else {
                client.call { completeOccurrence(occurrence.friendlyId) }
            }
        } catch (exception: ApiException) {
            fail(exception)
        }

        load()
    }

    suspend fun delete(reminder: Reminder) {
        try {
            client.call { deleteReminder(reminder.friendlyId) }
            mutableState.update { current ->
                current.copy(occurrences = current.occurrences?.filterNot { it.reminder.friendlyId == reminder.friendlyId })
            }
        } catch (exception: ApiException) {
            fail(exception)
        }

        load()
    }

    fun dismissActionFailure() {
        mutableState.update { it.copy(actionFailure = null) }
    }

    private fun fail(exception: ApiException) {
        if (exception !is ApiException.Unauthenticated) {
            mutableState.update { it.copy(actionFailure = exception) }
        }
    }
}

/**
 * The reminder to save, as the web form sends it: a single date goes as
 * `due_at` (its errors under `due_at`), a repetition as `recurrence`, and no
 * date as neither (an undated task). [workspace] is left out when `null`
 * (the user's default on a new one; where it is, on an edit).
 */
fun reminderInput(
    title: String,
    note: String,
    schedule: ScheduleDraft,
    assignedTo: String?,
    kind: ReminderKind = ReminderKind.Reminder,
    isAllDay: Boolean = false,
    durationMinutes: Int? = null,
    workspace: String? = null,
): ReminderInput {
    val recurrence = schedule.recurrenceInput()
    val isOnce = schedule.kind == ScheduleDraft.Kind.Once

    return ReminderInput(
        title = title.trim(),
        note = note.trim().ifEmpty { null },
        dueAt = if (isOnce) recurrence?.startsAt else null,
        recurrence = if (isOnce) null else recurrence,
        assignedTo = assignedTo,
        kind = kind,
        // An undated task is never all day.
        isAllDay = isAllDay && recurrence != null,
        durationMinutes = if (kind == ReminderKind.Event && !isAllDay) durationMinutes else null,
        workspace = workspace,
    )
}

/** The day an item starts on, in [zone]. */
fun CalendarEntry.day(zone: ZoneId): LocalDate? =
    runCatching { java.time.OffsetDateTime.parse(startsAt).atZoneSameInstant(zone).toLocalDate() }.getOrNull()

/**
 * Saves the reminder: creates it, or replaces [reminder].
 *
 * @throws ApiException a 422 comes with the server's message in the user's language.
 */
suspend fun ApiClient.saveReminder(reminder: Reminder?, input: ReminderInput) {
    val reminderId = reminder?.friendlyId

    if (reminderId != null) {
        call { updateReminder(reminderId, input) }
    } else {
        call { createReminder(input) }
    }
}

/**
 * Who a reminder's form offers as responsible: those of the picked
 * workspace ([assignees] — of the default one — from a server without them by
 * workspace), plus the one responsible now when they no longer are one and
 * the reminder stays where it is (the server keeps them through an edit).
 */
fun reminderAssigneeOptions(
    reminder: Reminder?,
    assignees: List<UserSummary>,
    assigneesByWorkspace: Map<String, List<UserSummary>>,
    workspaceId: String?,
): List<UserSummary> {
    val options = workspaceId?.let { assigneesByWorkspace[it] } ?: assignees
    val current = reminder?.assignedTo

    return if (current != null && options.isNotEmpty() && current !in options && workspaceId == reminder.workspace?.id) {
        options + current
    } else {
        options
    }
}

/**
 * Who stays responsible after another workspace was picked: only its members
 * may be (422 otherwise), so one who is not becomes [currentUserId] when they
 * are, or nobody.
 */
fun keptAssignee(assignedTo: String?, options: List<UserSummary>, currentUserId: String?): String? = when {
    assignedTo == null || options.any { it.friendlyId == assignedTo } -> assignedTo
    options.any { it.friendlyId == currentUserId } -> currentUserId
    else -> null
}
