package app.jarvis.android.ui.reminders

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material.icons.automirrored.filled.Undo
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.CalendarMonth
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material.icons.filled.FilterList
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.RadioButtonUnchecked
import androidx.compose.material.icons.filled.Repeat
import androidx.compose.material.icons.filled.SearchOff
import androidx.compose.material.icons.filled.UnfoldMore
import androidx.compose.material.icons.filled.WifiOff
import androidx.compose.material.icons.outlined.Circle
import androidx.compose.material.icons.outlined.Person
import androidx.compose.material3.Button
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.IconToggleButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import app.jarvis.android.R
import app.jarvis.android.app.AppModel
import app.jarvis.android.navigation.AppTab
import app.jarvis.android.navigation.Destination
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.CalendarEntry
import app.jarvis.android.network.RecurrenceFrequency
import app.jarvis.android.network.Reminder
import app.jarvis.android.network.ReminderFilter
import app.jarvis.android.network.ReminderKind
import app.jarvis.android.network.ReminderOccurrence
import app.jarvis.android.network.UserSummary
import app.jarvis.android.network.ModuleSlug
import app.jarvis.android.network.defaultWorkspace
import app.jarvis.android.network.showsWorkspace
import app.jarvis.android.network.showsWorkspaces
import app.jarvis.android.network.workspacePickerOptions
import app.jarvis.android.ui.components.ActionFailureDialog
import app.jarvis.android.ui.components.ConfirmDialog
import app.jarvis.android.ui.components.ContentUnavailable
import app.jarvis.android.ui.components.FilterRow
import app.jarvis.android.ui.components.FormError
import app.jarvis.android.ui.components.FormSheet
import app.jarvis.android.ui.components.GroupedList
import app.jarvis.android.ui.components.JarvisScreen
import app.jarvis.android.ui.components.ListRow
import app.jarvis.android.ui.components.Section
import app.jarvis.android.ui.components.SectionTextField
import app.jarvis.android.ui.components.formatDateTime
import app.jarvis.android.ui.components.formatTime
import app.jarvis.android.ui.components.userMessage
import app.jarvis.android.ui.components.WorkspaceBadge
import app.jarvis.android.ui.components.WorkspaceDot
import app.jarvis.android.ui.components.WorkspacePickerSection
import app.jarvis.android.ui.components.observedUser
import app.jarvis.android.ui.schedule.ScheduleDraft
import app.jarvis.android.ui.schedule.ScheduleEditor
import app.jarvis.android.ui.schedule.WallClock
import app.jarvis.android.ui.schedule.recurrenceSummary
import app.jarvis.android.ui.shell.NoAccessScreen
import kotlinx.coroutines.launch
import java.time.LocalDate
import java.time.LocalDateTime
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle

/**
 * The household's agenda as on the web (US-9.1): a day, a week or a month of
 * everything scheduled, by source; the next 30 days of it as a list; or the
 * tasks under the filters pending, overdue and completed, with their counts. "Only mine" narrows them to the reminders and events the
 * user is responsible for. A reminder is completed or reopened from its row,
 * created and edited in a form with its kind, who is responsible, date, time
 * and repetition, and opens from a push (`jarvis://reminders/{id}`).
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun RemindersHomeScreen(model: AppModel) {
    val store = remember { RemindersStore(model.client, WallClock.zone(model.user?.timezone)) }
    val state by store.state.collectAsStateWithLifecycle()
    val scope = rememberCoroutineScope()
    val uriHandler = LocalUriHandler.current
    var isRefreshing by remember { mutableStateOf(false) }
    var isCreating by remember { mutableStateOf(false) }
    // When the new item starts: an hour tapped on the grid, or the calendar's day at midnight.
    var newItemStartsAt by remember { mutableStateOf<LocalDateTime?>(null) }
    var isSourcesMenuOpen by remember { mutableStateOf(false) }
    var reminderToEdit by remember { mutableStateOf<Reminder?>(null) }
    var reminderToDelete by remember { mutableStateOf<Reminder?>(null) }
    var entryToDelete by remember { mutableStateOf<CalendarEntry?>(null) }
    val user = model.observedUser()
    // The workspace's dot on each item, for a user in two or more workspaces with reminders.
    val showsWorkspace = user?.showsWorkspaces(ModuleSlug.REMINDERS) == true

    // A reminder or event opens in its form; the other items on the web.
    fun open(entry: CalendarEntry) {
        val url = entry.url

        if (entry.reminderId != null) {
            scope.launch { store.reminder(entry)?.let { reminderToEdit = it } }
        } else if (url != null) {
            runCatching { uriHandler.openUri(url) }
        }
    }

    // On opening the tab and back from a reminder (it may have been completed or edited).
    LaunchedEffect(Unit) {
        store.load()
    }

    JarvisScreen(
        title = stringResource(R.string.tab_reminders),
        actions = {
            IconToggleButton(
                checked = state.isMine,
                onCheckedChange = { isMine -> scope.launch { store.showOnlyMine(isMine) } },
                modifier = Modifier.testTag("reminders.mine"),
            ) {
                Icon(
                    if (state.isMine) Icons.Filled.Person else Icons.Outlined.Person,
                    contentDescription = stringResource(R.string.reminders_only_mine),
                    tint = if (state.isMine) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            if (state.mode.view != null && state.sources.isNotEmpty()) {
                Box {
                    IconButton(onClick = { isSourcesMenuOpen = true }, modifier = Modifier.testTag("reminders.sources")) {
                        Icon(
                            Icons.Filled.FilterList,
                            contentDescription = stringResource(R.string.agenda_sources),
                            tint = if (state.hiddenSources.isEmpty()) MaterialTheme.colorScheme.onSurfaceVariant else MaterialTheme.colorScheme.primary,
                        )
                    }

                    DropdownMenu(expanded = isSourcesMenuOpen, onDismissRequest = { isSourcesMenuOpen = false }) {
                        state.sources.forEach { source ->
                            DropdownMenuItem(
                                text = { Text(stringResource(source.title)) },
                                leadingIcon = {
                                    Checkbox(checked = source !in state.hiddenSources, onCheckedChange = null)
                                },
                                onClick = { scope.launch { store.toggle(source) } },
                            )
                        }
                    }
                }
            }
            IconButton(
                onClick = {
                    newItemStartsAt = state.selectedDay.atStartOfDay().takeIf { state.mode.view != null }
                    isCreating = true
                },
                modifier = Modifier.testTag("reminders.new"),
            ) {
                Icon(Icons.Filled.Add, contentDescription = stringResource(R.string.reminders_new))
            }
        },
    ) {
        FilterRow(RemindersStore.Mode.entries, state.mode, { mode -> scope.launch { store.show(mode) } }, showsSelectionIcon = false) { mode ->
            stringResource(mode.title)
        }

        if (state.mode == RemindersStore.Mode.List) {
            FilterRow(ReminderFilter.entries, state.filter, { filter -> scope.launch { store.show(filter) } }) { filter ->
                // "Pending (3)", as the web's tabs.
                val title = stringResource(filter.title)
                state.counts[filter]?.let { stringResource(R.string.filter_with_count, title, it) } ?: title
            }
        }

        PullToRefreshBox(
            isRefreshing = isRefreshing,
            onRefresh = {
                scope.launch {
                    isRefreshing = true
                    store.load()
                    isRefreshing = false
                }
            },
            modifier = Modifier.fillMaxSize(),
        ) {
            val occurrences = state.occurrences
            val failure = state.loadFailure
            val view = state.mode.view
            val isList = view == null

            when {
                view != null && (state.entries != null || failure == null) -> AgendaCalendar(
                    state = state,
                    view = view,
                    zone = store.zone,
                    locale = model.language.locale,
                    onStep = { steps -> scope.launch { store.step(steps) } },
                    onSelect = { day -> scope.launch { store.show(day) } },
                    onOpenDay = { day -> scope.launch { store.open(day) } },
                    onToggle = { entry -> store.toggleCompletion(entry) },
                    onOpen = ::open,
                    onDelete = { entry -> entryToDelete = entry },
                    onCreate = { startsAt ->
                        newItemStartsAt = startsAt
                        isCreating = true
                    },
                    showsWorkspace = { entry -> user?.showsWorkspace(entry) == true },
                )

                isList && occurrences != null && occurrences.isEmpty() -> ContentUnavailable(title = stringResource(state.filter.emptyTitle), icon = Icons.Filled.CheckCircle)
                isList && occurrences != null -> GroupedList {
                    item {
                        Section {
                            occurrences.forEachIndexed { index, occurrence ->
                                ReminderRow(
                                    model = model,
                                    occurrence = occurrence,
                                    showsTimeOnly = false,
                                    showsWorkspace = showsWorkspace,
                                    showDivider = index < occurrences.lastIndex,
                                    onClick = { model.router.push(AppTab.Reminders, Destination.Reminder(occurrence.friendlyId)) },
                                    onToggle = { store.toggleCompletion(occurrence) },
                                    onEdit = { reminderToEdit = occurrence.reminder },
                                    onDelete = { reminderToDelete = occurrence.reminder },
                                )
                            }
                        }
                    }
                }

                failure is ApiException.Forbidden -> NoAccessScreen(onRetry = { scope.launch { model.refreshUser() } })
                failure != null -> ContentUnavailable(
                    title = failure.userMessage(),
                    icon = Icons.Filled.WifiOff,
                    actionLabel = stringResource(R.string.try_again),
                    onAction = { scope.launch { store.load() } },
                )

                else -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
            }
        }
    }

    if (isCreating) {
        ReminderForm(
            model,
            reminder = null,
            assignees = state.assignees,
            assigneesByWorkspace = state.assigneesByWorkspace,
            startsAt = newItemStartsAt,
            onDismiss = { isCreating = false },
        ) { scope.launch { store.load() } }
    }

    reminderToEdit?.let { reminder ->
        ReminderForm(model, reminder, state.assignees, state.assigneesByWorkspace, onDismiss = { reminderToEdit = null }) { scope.launch { store.load() } }
    }

    reminderToDelete?.let { reminder ->
        ConfirmDialog(
            title = stringResource(R.string.reminders_delete_confirm),
            confirmLabel = stringResource(R.string.delete),
            onConfirm = { scope.launch { store.delete(reminder) } },
            onDismiss = { reminderToDelete = null },
        )
    }

    entryToDelete?.let { entry ->
        ConfirmDialog(
            title = stringResource(R.string.reminders_delete_confirm),
            confirmLabel = stringResource(R.string.delete),
            onConfirm = { scope.launch { store.deleteReminder(entry) } },
            onDismiss = { entryToDelete = null },
        )
    }

    ActionFailureDialog(state.actionFailure, store::dismissActionFailure)
}

@Composable
private fun ReminderRow(
    model: AppModel,
    occurrence: ReminderOccurrence,
    showsTimeOnly: Boolean,
    showsWorkspace: Boolean,
    showDivider: Boolean,
    onClick: () -> Unit,
    onToggle: suspend () -> Unit,
    onEdit: () -> Unit,
    onDelete: () -> Unit,
) {
    val timezone = model.user?.timezone ?: "UTC"
    val locale = model.language.locale
    val completedAt = formatDateTime(occurrence.completedAt, timezone, locale)?.takeIf { occurrence.isCompleted }
    val time = formatTime(occurrence.dueAt, timezone, locale)?.takeIf { showsTimeOnly }
    val due = formatDateTime(occurrence.dueAt, timezone, locale)

    ReminderListRow(
        reminder = occurrence.reminder,
        subtitle = when {
            completedAt != null -> stringResource(R.string.reminders_completed_on, completedAt)
            time != null -> time
            due != null -> stringResource(R.string.reminders_due, due)
            else -> stringResource(R.string.reminders_no_date)
        },
        isOverdue = occurrence.isOverdue,
        isCompleted = occurrence.isCompleted,
        showsWorkspace = showsWorkspace,
        showDivider = showDivider,
        onClick = onClick,
        onEdit = onEdit,
        onDelete = onDelete,
        modifier = Modifier.testTag("reminders.row.${occurrence.friendlyId}"),
        leading = { CompletionButton(occurrence, onToggle) },
    )
}

@Composable
private fun ReminderListRow(
    reminder: Reminder,
    subtitle: String,
    isOverdue: Boolean,
    isCompleted: Boolean,
    showsWorkspace: Boolean,
    showDivider: Boolean,
    onClick: () -> Unit,
    onEdit: () -> Unit,
    onDelete: () -> Unit,
    modifier: Modifier,
    leading: @Composable () -> Unit,
    isProjected: Boolean = false,
) {
    var isMenuOpen by remember { mutableStateOf(false) }
    val assignee = reminder.assignedTo?.name

    ListRow(
        title = reminder.title,
        subtitle = if (assignee != null) "$subtitle · $assignee" else subtitle,
        subtitleColor = if (isOverdue) MaterialTheme.colorScheme.error else null,
        showDivider = showDivider,
        onClick = onClick,
        modifier = modifier,
        leading = leading,
        titleContent = {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                reminder.workspace?.takeIf { showsWorkspace }?.let { workspace -> WorkspaceDot(workspace) }

                Text(
                    reminder.title,
                    style = MaterialTheme.typography.bodyLarge,
                    color = if (isProjected) MaterialTheme.colorScheme.onSurfaceVariant else Color.Unspecified,
                    textDecoration = if (isCompleted) TextDecoration.LineThrough else null,
                    modifier = Modifier.weight(1f, fill = false),
                )

                if (reminder.isRecurring) {
                    Icon(Icons.Filled.Repeat, contentDescription = stringResource(R.string.reminders_repeats), tint = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.size(16.dp))
                }
            }
        },
        trailing = {
            Box {
                IconButton(onClick = { isMenuOpen = true }) {
                    Icon(Icons.Filled.MoreVert, contentDescription = stringResource(R.string.more), tint = MaterialTheme.colorScheme.onSurfaceVariant)
                }

                DropdownMenu(expanded = isMenuOpen, onDismissRequest = { isMenuOpen = false }) {
                    DropdownMenuItem(
                        text = { Text(stringResource(R.string.edit)) },
                        onClick = {
                            isMenuOpen = false
                            onEdit()
                        },
                    )
                    DropdownMenuItem(
                        text = { Text(stringResource(R.string.delete), color = MaterialTheme.colorScheme.error) },
                        onClick = {
                            isMenuOpen = false
                            onDelete()
                        },
                    )
                }
            }

            Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
        },
    )
}

/** The round check of a row: completes or reopens the occurrence, with a haptic. */
@Composable
private fun CompletionButton(occurrence: ReminderOccurrence, onToggle: suspend () -> Unit) {
    val scope = rememberCoroutineScope()
    val haptics = LocalHapticFeedback.current
    var isWorking by remember { mutableStateOf(false) }

    IconButton(
        enabled = !isWorking,
        onClick = {
            haptics.performHapticFeedback(if (occurrence.isCompleted) HapticFeedbackType.ToggleOff else HapticFeedbackType.Confirm)
            isWorking = true
            scope.launch {
                onToggle()
                isWorking = false
            }
        },
        modifier = Modifier.testTag("reminders.complete.${occurrence.friendlyId}"),
    ) {
        if (occurrence.isCompleted) {
            Icon(Icons.Filled.CheckCircle, contentDescription = stringResource(R.string.reminders_reopen), tint = MaterialTheme.colorScheme.primary)
        } else {
            Icon(Icons.Filled.RadioButtonUnchecked, contentDescription = stringResource(R.string.reminders_complete), tint = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

/**
 * Creates a reminder or event, or edits one (US-9.1): what it is, title,
 * note, who is responsible — the user, by default, on a new one — and
 * schedule — no date (a task, not for an event), a date and time, or a
 * repetition — all day or, for an event, how long it lasts. A new one from
 * the calendar starts at the hour tapped, or on the day picked ([startsAt]
 * at midnight). For a user in two or more workspaces with reminders, also
 * the workspace it is shared in (the default one on a new reminder); changing
 * it on an edit moves it, and who may be responsible follows the workspace
 * picked ([assigneesByWorkspace]). The server checks the schedule; a refusal
 * (a date in the past) comes translated and is shown under the form.
 * [assignees] is empty from a server that does not know them: no picker then.
 */
@Composable
fun ReminderForm(
    model: AppModel,
    reminder: Reminder?,
    assignees: List<UserSummary>,
    assigneesByWorkspace: Map<String, List<UserSummary>> = emptyMap(),
    startsAt: LocalDateTime? = null,
    onDismiss: () -> Unit,
    onSave: () -> Unit,
) {
    val zone = WallClock.zone(model.user?.timezone)
    var kind by rememberSaveable { mutableStateOf(reminder?.kind ?: ReminderKind.Reminder) }
    var isAllDay by rememberSaveable { mutableStateOf(reminder?.isAllDay ?: false) }
    var durationMinutes by rememberSaveable { mutableIntStateOf(reminder?.durationMinutes ?: 60) }
    var title by rememberSaveable { mutableStateOf(reminder?.title.orEmpty()) }
    var note by rememberSaveable { mutableStateOf(reminder?.note.orEmpty()) }
    var assignedTo by rememberSaveable { mutableStateOf(if (reminder != null) reminder.assignedTo?.friendlyId else model.user?.friendlyId) }
    var schedule by remember {
        mutableStateOf(
            when {
                reminder != null -> ScheduleDraft.of(reminder.recurrence, zone)
                startsAt != null -> ScheduleDraft.startingAt(startsAt, ZonedDateTime.now(zone))
                else -> ScheduleDraft.new(ScheduleDraft.Kind.None, ZonedDateTime.now(zone))
            },
        )
    }
    var isSaving by remember { mutableStateOf(false) }
    var failure by remember { mutableStateOf<ApiException?>(null) }
    val scope = rememberCoroutineScope()
    val user = model.observedUser()
    // None (no picker) with reminders in one workspace only.
    val workspaceOptions = user?.workspacePickerOptions(ModuleSlug.REMINDERS).orEmpty()
    var workspaceId by rememberSaveable { mutableStateOf(reminder?.workspace?.id ?: user?.defaultWorkspace(ModuleSlug.REMINDERS)?.id) }
    val assigneeOptions = reminderAssigneeOptions(reminder, assignees, assigneesByWorkspace, workspaceId)

    FormSheet(
        title = stringResource(
            when {
                reminder == null && kind == ReminderKind.Event -> R.string.reminders_new_event
                reminder == null -> R.string.reminders_new
                kind == ReminderKind.Event -> R.string.reminders_edit_event
                else -> R.string.reminders_edit
            },
        ),
        confirmLabel = stringResource(R.string.save),
        isConfirmEnabled = title.isNotBlank() && !isSaving,
        onConfirm = {
            isSaving = true
            scope.launch {
                try {
                    model.client.saveReminder(
                        reminder,
                        reminderInput(title, note, schedule, assignedTo, kind, isAllDay, durationMinutes, workspace = workspaceId.takeIf { workspaceOptions.isNotEmpty() }),
                    )
                    onSave()
                    onDismiss()
                } catch (exception: ApiException) {
                    failure = exception
                } finally {
                    isSaving = false
                }
            }
        },
        onDismiss = onDismiss,
    ) {
        item {
            FilterRow(ReminderKind.entries, kind, { newKind ->
                kind = newKind

                // An event needs a date.
                if (newKind == ReminderKind.Event && schedule.kind == ScheduleDraft.Kind.None) {
                    schedule = schedule.copy(kind = ScheduleDraft.Kind.Once)
                }
            }) { option ->
                stringResource(if (option == ReminderKind.Event) R.string.reminders_kind_event else R.string.reminders_kind_reminder)
            }
        }

        item {
            Section {
                SectionTextField(title, { title = it }, stringResource(R.string.title), Modifier.testTag("reminder.title"))
                SectionTextField(note, { note = it }, stringResource(R.string.reminders_note), singleLine = false, minLines = 2)
            }
        }

        if (workspaceOptions.isNotEmpty()) {
            item {
                WorkspacePickerSection(workspaceOptions, workspaceId) { picked ->
                    workspaceId = picked
                    assignedTo = keptAssignee(
                        assignedTo,
                        reminderAssigneeOptions(reminder, assignees, assigneesByWorkspace, picked),
                        user?.friendlyId,
                    )
                }
            }
        }

        if (assigneeOptions.isNotEmpty()) {
            item {
                Section {
                    AssigneeRow(assignedTo, assigneeOptions) { assignedTo = it }
                }
            }
        }

        if (schedule.kind != ScheduleDraft.Kind.None) {
            item {
                Section {
                    ListRow(
                        title = stringResource(R.string.agenda_all_day),
                        showDivider = kind == ReminderKind.Event && !isAllDay,
                        onClick = { isAllDay = !isAllDay },
                        modifier = Modifier.testTag("reminder.allDay"),
                        trailing = { Switch(checked = isAllDay, onCheckedChange = { isAllDay = it }) },
                    )

                    if (kind == ReminderKind.Event && !isAllDay) {
                        DurationRow(durationMinutes) { durationMinutes = it }
                    }
                }
            }
        }

        item {
            ScheduleEditor(
                schedule,
                if (kind == ReminderKind.Event) ScheduleDraft.Kind.entries - ScheduleDraft.Kind.None else ScheduleDraft.Kind.entries,
                model.language.locale,
            ) { schedule = it }
        }

        failure?.let { error ->
            item { FormError(error.userMessage()) }
        }
    }
}

/** The durations offered for an event, as on the web. */
private val eventDurations = listOf(15, 30, 45, 60, 90, 120, 180, 240, 480)

/** How long an event lasts: one of [eventDurations], or the saved one. */
@Composable
private fun DurationRow(selected: Int, onSelect: (Int) -> Unit) {
    var isMenuOpen by remember { mutableStateOf(false) }
    val options = if (selected in eventDurations) eventDurations else (eventDurations + selected).sorted()

    Box {
        ListRow(
            title = stringResource(R.string.reminders_duration),
            onClick = { isMenuOpen = true },
            modifier = Modifier.testTag("reminder.duration"),
            trailing = {
                Text(durationText(selected), color = MaterialTheme.colorScheme.primary)
                Icon(Icons.Filled.UnfoldMore, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
            },
        )

        DropdownMenu(expanded = isMenuOpen, onDismissRequest = { isMenuOpen = false }, modifier = Modifier.align(Alignment.CenterEnd)) {
            options.forEach { minutes ->
                DropdownMenuItem(
                    text = { Text(durationText(minutes)) },
                    onClick = {
                        isMenuOpen = false
                        onSelect(minutes)
                    },
                )
            }
        }
    }
}

/** "30 min", "1 h", "1 h 30 min". */
private fun durationText(minutes: Int): String {
    val hours = minutes / 60
    val rest = minutes % 60

    return when {
        hours == 0 -> "$rest min"
        rest == 0 -> "$hours h"
        else -> "$hours h $rest min"
    }
}

/** Who is responsible: one of [assignees], or nobody. */
@Composable
private fun AssigneeRow(selected: String?, assignees: List<UserSummary>, onSelect: (String?) -> Unit) {
    var isMenuOpen by remember { mutableStateOf(false) }
    val nobody = stringResource(R.string.reminders_nobody)

    Box {
        ListRow(
            title = stringResource(R.string.reminders_responsible),
            onClick = { isMenuOpen = true },
            modifier = Modifier.testTag("reminder.assignedTo"),
            trailing = {
                Text(assignees.firstOrNull { it.friendlyId == selected }?.name ?: nobody, color = MaterialTheme.colorScheme.primary)
                Icon(Icons.Filled.UnfoldMore, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
            },
        )

        DropdownMenu(expanded = isMenuOpen, onDismissRequest = { isMenuOpen = false }, modifier = Modifier.align(Alignment.CenterEnd)) {
            DropdownMenuItem(
                text = { Text(nobody) },
                onClick = {
                    isMenuOpen = false
                    onSelect(null)
                },
            )
            assignees.forEach { assignee ->
                DropdownMenuItem(
                    text = { Text(assignee.name) },
                    onClick = {
                        isMenuOpen = false
                        onSelect(assignee.friendlyId)
                    },
                )
            }
        }
    }
}

/**
 * One reminder, as a push opens it (`jarvis://reminders/{id}`, US-5.2): its
 * date, repetition and note, and completing, reopening, editing and deleting
 * it. The id is the occurrence's (`remo_…`) or the reminder's (`rem_…`); the
 * API has no single-reminder read, so it is looked up in the filters, open
 * ones first.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ReminderDetailScreen(model: AppModel, id: String, onBack: () -> Unit) {
    val scope = rememberCoroutineScope()
    val haptics = LocalHapticFeedback.current
    var occurrence by remember(id) { mutableStateOf<ReminderOccurrence?>(null) }
    var assignees by remember(id) { mutableStateOf<List<UserSummary>>(emptyList()) }
    var assigneesByWorkspace by remember(id) { mutableStateOf<Map<String, List<UserSummary>>>(emptyMap()) }
    val showsWorkspace = model.observedUser()?.showsWorkspaces(ModuleSlug.REMINDERS) == true
    var hasLoaded by remember(id) { mutableStateOf(false) }
    var loadFailure by remember(id) { mutableStateOf<ApiException?>(null) }
    var actionFailure by remember { mutableStateOf<ApiException?>(null) }
    var isRefreshing by remember { mutableStateOf(false) }
    var isEditing by remember { mutableStateOf(false) }
    var isConfirmingDelete by remember { mutableStateOf(false) }
    val timezone = model.user?.timezone ?: "UTC"
    val locale = model.language.locale

    suspend fun load() {
        try {
            val current = occurrence
            // After completing a repeating reminder the next occurrence is the open one, so it is looked up by the reminder too.
            val found = findOccurrence(model, current?.friendlyId ?: id)
                ?: current?.reminder?.friendlyId?.let { findOccurrence(model, it) }
            occurrence = found?.occurrence
            found?.let {
                assignees = it.assignees
                assigneesByWorkspace = it.assigneesByWorkspace
            }
            loadFailure = null
        } catch (_: ApiException.Unauthenticated) {
            // The shell already went back to the login.
        } catch (exception: ApiException) {
            loadFailure = exception
        }

        hasLoaded = true
    }

    LaunchedEffect(id) {
        load()
    }

    JarvisScreen(
        title = stringResource(R.string.reminder),
        onBack = onBack,
        actions = {
            if (occurrence != null) {
                IconButton(onClick = { isEditing = true }) {
                    Icon(Icons.Filled.Edit, contentDescription = stringResource(R.string.edit))
                }
            }
        },
    ) {
        PullToRefreshBox(
            isRefreshing = isRefreshing,
            onRefresh = {
                scope.launch {
                    isRefreshing = true
                    load()
                    isRefreshing = false
                }
            },
            modifier = Modifier.fillMaxSize(),
        ) {
            val current = occurrence
            val failure = loadFailure

            when {
                current != null -> GroupedList {
                    item {
                        Section {
                            ListRow(
                                title = current.reminder.title,
                                showDivider = true,
                                titleContent = {
                                    Text(
                                        current.reminder.title,
                                        style = MaterialTheme.typography.titleLarge,
                                        textDecoration = if (current.isCompleted) TextDecoration.LineThrough else null,
                                    )
                                },
                            )
                            ListRow(
                                title = stringResource(R.string.reminders_due_label),
                                showDivider = true,
                                trailing = {
                                    Text(
                                        formatDateTime(current.dueAt, timezone, locale) ?: stringResource(R.string.reminders_no_date),
                                        color = if (current.isOverdue) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurfaceVariant,
                                    )
                                },
                            )

                            ListRow(
                                title = stringResource(R.string.reminders_responsible),
                                showDivider = true,
                                trailing = {
                                    Text(current.reminder.assignedTo?.name ?: stringResource(R.string.reminders_nobody), color = MaterialTheme.colorScheme.onSurfaceVariant)
                                },
                            )

                            current.reminder.workspace?.takeIf { showsWorkspace }?.let { workspace ->
                                ListRow(
                                    title = stringResource(R.string.workspace),
                                    showDivider = true,
                                    modifier = Modifier.testTag("reminder.workspace"),
                                    trailing = { WorkspaceBadge(workspace) },
                                )
                            }

                            current.reminder.recurrence?.takeIf { it.frequency != RecurrenceFrequency.Once }?.let { recurrence ->
                                ListRow(
                                    title = recurrenceSummary(recurrence, locale),
                                    showDivider = true,
                                    leading = { Icon(Icons.Filled.Repeat, contentDescription = null, tint = MaterialTheme.colorScheme.primary) },
                                )
                            }

                            formatDateTime(current.completedAt, timezone, locale)?.takeIf { current.isCompleted }?.let { completedAt ->
                                ListRow(
                                    title = stringResource(R.string.reminders_completed_label),
                                    showDivider = true,
                                    trailing = { Text(completedAt, color = MaterialTheme.colorScheme.onSurfaceVariant) },
                                )

                                current.completedBy?.let { completedBy ->
                                    ListRow(
                                        title = stringResource(R.string.reminders_by),
                                        showDivider = true,
                                        trailing = { Text(completedBy.name, color = MaterialTheme.colorScheme.onSurfaceVariant) },
                                    )
                                }
                            }
                        }
                    }

                    current.reminder.note?.takeIf { it.isNotBlank() }?.let { note ->
                        item {
                            Section(header = stringResource(R.string.reminders_note)) {
                                ListRow(title = note)
                            }
                        }
                    }

                    item {
                        Row(horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically) {
                            Button(
                                onClick = {
                                    haptics.performHapticFeedback(if (current.isCompleted) HapticFeedbackType.ToggleOff else HapticFeedbackType.Confirm)
                                    scope.launch {
                                        try {
                                            occurrence = if (current.isCompleted) {
                                                model.client.call { reopenOccurrence(current.friendlyId) }.data
                                            } else {
                                                model.client.call { completeOccurrence(current.friendlyId) }.data
                                            }
                                        } catch (exception: ApiException) {
                                            actionFailure = exception
                                        }
                                    }
                                },
                                modifier = Modifier.weight(1f).testTag("reminder.toggle"),
                            ) {
                                Icon(if (current.isCompleted) Icons.AutoMirrored.Filled.Undo else Icons.Filled.Check, contentDescription = null)
                                Text(
                                    stringResource(if (current.isCompleted) R.string.reminders_reopen else R.string.reminders_complete),
                                    modifier = Modifier.padding(start = 8.dp),
                                )
                            }

                            OutlinedButton(onClick = { isConfirmingDelete = true }) {
                                Text(stringResource(R.string.delete), color = MaterialTheme.colorScheme.error)
                            }
                        }
                    }
                }

                failure is ApiException.Forbidden -> NoAccessScreen(onRetry = { scope.launch { model.refreshUser() } })
                failure != null -> ContentUnavailable(
                    title = failure.userMessage(),
                    icon = Icons.Filled.WifiOff,
                    actionLabel = stringResource(R.string.try_again),
                    onAction = { scope.launch { load() } },
                )

                hasLoaded -> ContentUnavailable(
                    title = stringResource(R.string.reminder_not_found),
                    icon = Icons.Filled.SearchOff,
                    description = stringResource(R.string.reminder_not_found_description),
                )

                else -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
            }
        }
    }

    if (isEditing) {
        ReminderForm(model, occurrence?.reminder, assignees, assigneesByWorkspace, onDismiss = { isEditing = false }) { scope.launch { load() } }
    }

    if (isConfirmingDelete) {
        ConfirmDialog(
            title = stringResource(R.string.reminders_delete_confirm),
            confirmLabel = stringResource(R.string.delete),
            onConfirm = {
                val current = occurrence ?: return@ConfirmDialog

                scope.launch {
                    try {
                        model.client.call { deleteReminder(current.reminder.friendlyId) }
                        onBack()
                    } catch (exception: ApiException) {
                        actionFailure = exception
                    }
                }
            },
            onDismiss = { isConfirmingDelete = false },
        )
    }

    ActionFailureDialog(actionFailure) { actionFailure = null }
}

/** An occurrence found and who may be made responsible, from the page it was found in. */
private class FoundOccurrence(
    val occurrence: ReminderOccurrence,
    val assignees: List<UserSummary>,
    val assigneesByWorkspace: Map<String, List<UserSummary>>,
)

/** The occurrence of [id] and who may be made responsible, from the page it was found in. */
private suspend fun findOccurrence(model: AppModel, id: String): FoundOccurrence? {
    for (filter in listOf(ReminderFilter.Pending, ReminderFilter.Overdue, ReminderFilter.Completed)) {
        val page = model.client.call { reminderOccurrences(filter.apiValue) }
        val match = page.data.firstOrNull { it.friendlyId == id || it.reminder.friendlyId == id }

        if (match != null) {
            return FoundOccurrence(match, page.meta.assignees, page.meta.assigneesByWorkspace)
        }
    }

    return null
}

private val ReminderFilter.title: Int
    get() = when (this) {
        ReminderFilter.Pending -> R.string.reminders_pending
        ReminderFilter.Overdue -> R.string.reminders_overdue
        ReminderFilter.Completed -> R.string.reminders_completed
    }

private val RemindersStore.Mode.title: Int
    get() = when (this) {
        RemindersStore.Mode.Day -> R.string.agenda_day
        RemindersStore.Mode.Week -> R.string.agenda_week
        RemindersStore.Mode.Month -> R.string.agenda_month
        RemindersStore.Mode.Schedule -> R.string.agenda_schedule
        RemindersStore.Mode.List -> R.string.agenda_tasks
    }

private val ReminderFilter.emptyTitle: Int
    get() = when (this) {
        ReminderFilter.Pending -> R.string.reminders_nothing_pending
        ReminderFilter.Overdue -> R.string.reminders_nothing_overdue
        ReminderFilter.Completed -> R.string.reminders_nothing_completed
    }
