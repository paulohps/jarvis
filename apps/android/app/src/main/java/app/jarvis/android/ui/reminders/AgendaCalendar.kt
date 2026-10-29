package app.jarvis.android.ui.reminders

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowLeft
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material.icons.automirrored.filled.OpenInNew
import androidx.compose.material.icons.filled.CalendarMonth
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material.icons.filled.RadioButtonUnchecked
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import app.jarvis.android.R
import app.jarvis.android.network.CalendarEntry
import app.jarvis.android.network.CalendarSource
import app.jarvis.android.ui.components.ContentUnavailable
import app.jarvis.android.ui.components.GroupedList
import app.jarvis.android.ui.components.ListRow
import app.jarvis.android.ui.components.Section
import app.jarvis.android.ui.components.WorkspaceDot
import kotlinx.coroutines.launch
import java.time.DayOfWeek
import java.time.LocalDate
import java.time.LocalDateTime
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import java.time.format.TextStyle
import java.util.Locale

/**
 * The agenda's calendar, as the web's (US-9.1): a header to step through the
 * periods, then the day or the week on an hour grid, the month as six weeks
 * with a dot per source on each day and the picked day's items below, or the
 * next 30 days as a list — reminders with their check, events, invoices,
 * WhatsApp messages, shopping lists and Google events. An item opens through
 * [onOpen]: a reminder or event to be edited, the others on the web; a tap on
 * an empty hour creates one there ([onCreate]). An item for which
 * [showsWorkspace] holds (a shared one, for a user in two or more workspaces
 * with its module) shows its workspace's dot.
 */
@Composable
fun AgendaCalendar(
    state: RemindersStore.State,
    view: AgendaView,
    zone: ZoneId,
    locale: Locale,
    onStep: (Long) -> Unit,
    onSelect: (LocalDate) -> Unit,
    onOpenDay: (LocalDate) -> Unit,
    onToggle: suspend (CalendarEntry) -> Unit,
    onOpen: (CalendarEntry) -> Unit,
    onDelete: (CalendarEntry) -> Unit,
    onCreate: (LocalDateTime) -> Unit,
    showsWorkspace: (CalendarEntry) -> Boolean = { false },
) {
    CompositionLocalProvider(LocalShowsWorkspace provides showsWorkspace) {
        Column(Modifier.testTag("reminders.calendar")) {
            PeriodHeader(state, view, zone, locale, onStep, onSelect)

            when (view) {
                AgendaView.Day -> AgendaDayGrid(state, zone, locale, onOpen, onCreate)
                AgendaView.Week -> AgendaWeekGrid(state, zone, locale, onOpenDay, onOpen, onCreate)
                AgendaView.Month -> MonthView(state, zone, locale, onSelect, onToggle, onOpen, onDelete)
                AgendaView.Schedule -> ScheduleView(state, zone, locale, onToggle, onOpen, onDelete)
            }
        }
    }
}

/** Whether an item of the calendar shows its workspace's dot, as [AgendaCalendar] was told. */
internal val LocalShowsWorkspace = staticCompositionLocalOf<(CalendarEntry) -> Boolean> { { false } }

/** The arrows to the period before and after, its name and "Today". */
@Composable
private fun PeriodHeader(
    state: RemindersStore.State,
    view: AgendaView,
    zone: ZoneId,
    locale: Locale,
    onStep: (Long) -> Unit,
    onSelect: (LocalDate) -> Unit,
) {
    val period = AgendaPeriod.of(view, state.selectedDay)
    val title = when (view) {
        AgendaView.Day -> state.selectedDay.format(DateTimeFormatter.ofPattern("EEEE, d MMM", locale))
        AgendaView.Month -> state.month.firstDay.format(DateTimeFormatter.ofPattern("LLLL yyyy", locale))
        AgendaView.Week, AgendaView.Schedule -> {
            val format = DateTimeFormatter.ofLocalizedDate(FormatStyle.MEDIUM).withLocale(locale)
            "${period.start.format(format)} – ${period.end.minusDays(1).format(format)}"
        }
    }.replaceFirstChar { it.titlecase(locale) }

    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(horizontal = 8.dp)) {
        IconButton(onClick = { onStep(-1) }, modifier = Modifier.testTag("agenda.previous")) {
            Icon(Icons.AutoMirrored.Filled.KeyboardArrowLeft, contentDescription = stringResource(R.string.agenda_previous))
        }

        Text(
            title,
            style = MaterialTheme.typography.titleMedium,
            textAlign = TextAlign.Center,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
            modifier = Modifier.weight(1f),
        )

        TextButton(onClick = { onSelect(LocalDate.now(zone)) }) {
            Text(stringResource(R.string.reminders_today))
        }

        IconButton(onClick = { onStep(1) }, modifier = Modifier.testTag("agenda.next")) {
            Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = stringResource(R.string.agenda_next))
        }
    }
}

/** The month's grid, and the picked day's items below. */
@Composable
private fun MonthView(
    state: RemindersStore.State,
    zone: ZoneId,
    locale: Locale,
    onSelect: (LocalDate) -> Unit,
    onToggle: suspend (CalendarEntry) -> Unit,
    onOpen: (CalendarEntry) -> Unit,
    onDelete: (CalendarEntry) -> Unit,
) {
    val entries = state.entries
    val dayEntries = entries.orEmpty().filter { it.day(zone) == state.selectedDay }

    GroupedList {
        item(key = "month") {
            Section {
                MonthGrid(state, zone, locale, onSelect)
            }
        }

        item(key = "day") {
            Section(header = state.selectedDay.format(DateTimeFormatter.ofLocalizedDate(FormatStyle.FULL).withLocale(locale))) {
                when {
                    entries == null -> Box(Modifier.fillMaxWidth().padding(16.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
                    dayEntries.isEmpty() -> ListRow(title = stringResource(R.string.agenda_nothing_on_day))
                    else -> EntryRows(dayEntries, zone, locale, onToggle, onOpen, onDelete)
                }
            }
        }
    }
}

/** The days of the next 30 that have something, each with its items. */
@Composable
private fun ScheduleView(
    state: RemindersStore.State,
    zone: ZoneId,
    locale: Locale,
    onToggle: suspend (CalendarEntry) -> Unit,
    onOpen: (CalendarEntry) -> Unit,
    onDelete: (CalendarEntry) -> Unit,
) {
    val entries = state.entries

    if (entries == null) {
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
        return
    }

    val byDay = entries.groupBy { it.day(zone) }.filterKeys { it != null }

    if (byDay.isEmpty()) {
        ContentUnavailable(title = stringResource(R.string.reminders_nothing_on_agenda), icon = Icons.Filled.CalendarMonth)
        return
    }

    val today = LocalDate.now(zone)

    GroupedList(modifier = Modifier.testTag("reminders.schedule")) {
        byDay.toSortedMap(compareBy { it }).forEach { (day, dayEntries) ->
            item(key = day.toString()) {
                val heading = when (day) {
                    today -> stringResource(R.string.reminders_today)
                    today.plusDays(1) -> stringResource(R.string.reminders_tomorrow)
                    else -> day!!.format(DateTimeFormatter.ofLocalizedDate(FormatStyle.FULL).withLocale(locale))
                }

                Section(header = heading) {
                    EntryRows(dayEntries, zone, locale, onToggle, onOpen, onDelete)
                }
            }
        }
    }
}

@Composable
private fun EntryRows(
    entries: List<CalendarEntry>,
    zone: ZoneId,
    locale: Locale,
    onToggle: suspend (CalendarEntry) -> Unit,
    onOpen: (CalendarEntry) -> Unit,
    onDelete: (CalendarEntry) -> Unit,
) {
    entries.forEachIndexed { index, entry ->
        CalendarEntryRow(
            entry = entry,
            zone = zone,
            locale = locale,
            showDivider = index < entries.lastIndex,
            onToggle = { onToggle(entry) },
            onOpen = { onOpen(entry) },
            onDelete = { onDelete(entry) },
        )
    }
}

/** The weekdays and the 42 days. */
@Composable
private fun MonthGrid(
    state: RemindersStore.State,
    zone: ZoneId,
    locale: Locale,
    onSelect: (LocalDate) -> Unit,
) {
    val today = LocalDate.now(zone)
    val sourcesByDay = remember(state.entries) {
        state.entries.orEmpty()
            .mapNotNull { entry -> entry.day(zone)?.let { day -> entry.calendarSource?.let { day to it } } }
            .groupBy({ it.first }, { it.second })
            .mapValues { (_, sources) -> sources.distinct() }
    }
    val dayFormat = DateTimeFormatter.ofLocalizedDate(FormatStyle.FULL).withLocale(locale)

    Column(Modifier.padding(8.dp)) {
        Row(Modifier.fillMaxWidth()) {
            // From Sunday, as the grid.
            listOf(DayOfWeek.SUNDAY, *DayOfWeek.entries.dropLast(1).toTypedArray()).forEach { weekday ->
                Text(
                    weekday.getDisplayName(TextStyle.NARROW_STANDALONE, locale),
                    style = MaterialTheme.typography.labelSmall,
                    fontWeight = FontWeight.SemiBold,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.weight(1f),
                )
            }
        }

        state.month.days.chunked(7).forEach { week ->
            Row(Modifier.fillMaxWidth()) {
                week.forEach { day ->
                    val isSelected = day == state.selectedDay
                    val isToday = day == today
                    val sources = sourcesByDay[day].orEmpty()
                    val label = day.format(dayFormat)

                    Column(
                        horizontalAlignment = Alignment.CenterHorizontally,
                        modifier = Modifier
                            .weight(1f)
                            .heightIn(min = 48.dp)
                            .clip(RoundedCornerShape(8.dp))
                            .clickable { onSelect(day) }
                            .semantics {
                                contentDescription = label
                                selected = isSelected
                            }
                            .padding(vertical = 4.dp),
                    ) {
                        Box(
                            Modifier
                                .size(32.dp)
                                .clip(CircleShape)
                                .background(if (isSelected) MaterialTheme.colorScheme.primary else Color.Transparent),
                            contentAlignment = Alignment.Center,
                        ) {
                            Text(
                                day.dayOfMonth.toString(),
                                style = MaterialTheme.typography.bodyMedium,
                                fontWeight = if (isToday) FontWeight.Bold else FontWeight.Normal,
                                color = when {
                                    isSelected -> MaterialTheme.colorScheme.onPrimary
                                    isToday -> MaterialTheme.colorScheme.primary
                                    day in state.month -> MaterialTheme.colorScheme.onSurface
                                    else -> MaterialTheme.colorScheme.onSurfaceVariant.copy(alpha = 0.6f)
                                },
                            )
                        }

                        Spacer(Modifier.height(3.dp))

                        Row(horizontalArrangement = Arrangement.spacedBy(2.dp), modifier = Modifier.height(5.dp)) {
                            sources.take(4).forEach { source ->
                                Box(Modifier.size(5.dp).clip(CircleShape).background(source.color))
                            }
                        }
                    }
                }
            }
        }
    }
}

/**
 * An item of the picked day: its source's color, title, time and — for a
 * reminder with an occurrence — the check that completes it; for a user in
 * two or more workspaces with its module, its workspace's dot.
 */
@Composable
private fun CalendarEntryRow(
    entry: CalendarEntry,
    zone: ZoneId,
    locale: Locale,
    showDivider: Boolean,
    onToggle: suspend () -> Unit,
    onOpen: () -> Unit,
    onDelete: () -> Unit,
) {
    val scope = rememberCoroutineScope()
    val haptics = LocalHapticFeedback.current
    var isWorking by remember { mutableStateOf(false) }
    var isMenuOpen by remember { mutableStateOf(false) }
    val time = entry.timeText(zone, locale) ?: stringResource(R.string.agenda_all_day)
    val subtitle = listOfNotNull(time, entry.subtitle?.takeIf { it.isNotBlank() }).joinToString(" · ")
    val workspace = entry.workspace?.takeIf { LocalShowsWorkspace.current(entry) }

    ListRow(
        title = entry.title,
        subtitle = subtitle,
        subtitleColor = if (entry.isOverdue) MaterialTheme.colorScheme.error else null,
        showDivider = showDivider,
        onClick = onOpen,
        modifier = Modifier.testTag("agenda.entry.${entry.key}"),
        leading = {
            if (entry.isCompletable) {
                IconButton(
                    enabled = !isWorking,
                    onClick = {
                        haptics.performHapticFeedback(if (entry.isCompleted) HapticFeedbackType.ToggleOff else HapticFeedbackType.Confirm)
                        isWorking = true
                        scope.launch {
                            onToggle()
                            isWorking = false
                        }
                    },
                ) {
                    if (entry.isCompleted) {
                        Icon(Icons.Filled.CheckCircle, contentDescription = stringResource(R.string.reminders_reopen), tint = MaterialTheme.colorScheme.primary)
                    } else {
                        Icon(Icons.Filled.RadioButtonUnchecked, contentDescription = stringResource(R.string.reminders_complete), tint = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
            } else {
                Box(Modifier.size(48.dp), contentAlignment = Alignment.Center) {
                    Box(
                        Modifier
                            .width(4.dp)
                            .height(32.dp)
                            .clip(RoundedCornerShape(2.dp))
                            .background(entry.calendarSource?.color ?: MaterialTheme.colorScheme.onSurfaceVariant),
                    )
                }
            }
        },
        titleContent = {
            Text(
                entry.title,
                style = MaterialTheme.typography.bodyLarge,
                color = if (entry.isProjected) MaterialTheme.colorScheme.onSurfaceVariant else Color.Unspecified,
                textDecoration = if (entry.isCompleted) TextDecoration.LineThrough else null,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )
        },
        trailing = {
            if (workspace != null) {
                WorkspaceDot(workspace)
            }

            if (entry.reminderId != null) {
                Box {
                    IconButton(onClick = { isMenuOpen = true }) {
                        Icon(Icons.Filled.MoreVert, contentDescription = stringResource(R.string.more), tint = MaterialTheme.colorScheme.onSurfaceVariant)
                    }

                    DropdownMenu(expanded = isMenuOpen, onDismissRequest = { isMenuOpen = false }) {
                        DropdownMenuItem(
                            text = { Text(stringResource(R.string.edit)) },
                            onClick = {
                                isMenuOpen = false
                                onOpen()
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
            } else if (entry.url != null) {
                Icon(Icons.AutoMirrored.Filled.OpenInNew, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        },
    )
}

/** "14:00" or "14:00 – 15:30"; `null` for an all-day item. */
private fun CalendarEntry.timeText(zone: ZoneId, locale: Locale): String? {
    if (isAllDay) {
        return null
    }

    val format = DateTimeFormatter.ofLocalizedTime(FormatStyle.SHORT).withLocale(locale)
    val start = runCatching { OffsetDateTime.parse(startsAt).atZoneSameInstant(zone) }.getOrNull() ?: return null
    val end = endsAt?.let { runCatching { OffsetDateTime.parse(it).atZoneSameInstant(zone) }.getOrNull() }?.takeIf { it.isAfter(start) }

    return if (end != null) "${start.format(format)} – ${end.format(format)}" else start.format(format)
}

/** The source's color, as the web's calendar tokens. */
val CalendarSource.color: Color
    get() = when (this) {
        CalendarSource.Reminders -> Color(0xFF0062CC)
        CalendarSource.Events -> Color(0xFF8944AB)
        CalendarSource.Invoices -> Color(0xFFC93400)
        CalendarSource.Whatsapp -> Color(0xFF248A3D)
        CalendarSource.Shopping -> Color(0xFF5856D6)
        CalendarSource.Google -> Color(0xFF039BE5)
    }

val CalendarSource.title: Int
    get() = when (this) {
        CalendarSource.Reminders -> R.string.agenda_source_reminders
        CalendarSource.Events -> R.string.agenda_source_events
        CalendarSource.Invoices -> R.string.agenda_source_invoices
        CalendarSource.Whatsapp -> R.string.agenda_source_whatsapp
        CalendarSource.Shopping -> R.string.agenda_source_shopping
        CalendarSource.Google -> R.string.agenda_source_google
    }
