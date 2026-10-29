package app.jarvis.android.ui.reminders

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.produceState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import app.jarvis.android.R
import app.jarvis.android.network.CalendarEntry
import app.jarvis.android.ui.components.WorkspaceDot
import kotlinx.coroutines.delay
import java.time.LocalDate
import java.time.LocalDateTime
import java.time.LocalTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import java.time.format.TextStyle
import java.util.Locale

private val HourHeight = 48.dp
private val LabelWidth = 48.dp
private val GridHeight = HourHeight * 24

private fun minutesToDp(minutes: Int): Dp = HourHeight * (minutes / 60f)

/** The hour shown first: an hour before now on today, 08:00 otherwise. */
private fun firstHour(showsToday: Boolean, zone: ZoneId): Int =
    if (showsToday) maxOf(0, LocalTime.now(zone).hour - 1) else 8

/**
 * The day on an hour grid, as the web's day view: the all-day items above,
 * the timed ones at their hours — side by side when they overlap — and a
 * line at the current time. A tap on an empty hour creates an item there.
 */
@Composable
fun AgendaDayGrid(
    state: RemindersStore.State,
    zone: ZoneId,
    locale: Locale,
    onOpen: (CalendarEntry) -> Unit,
    onCreate: (LocalDateTime) -> Unit,
) {
    val day = state.selectedDay
    val entries = state.entries.orEmpty().filter { it.day(zone) == day }
    val allDay = entries.filter { it.isAllDay }

    Column(Modifier.fillMaxSize().testTag("agenda.day")) {
        if (allDay.isNotEmpty()) {
            Column(
                verticalArrangement = Arrangement.spacedBy(2.dp),
                modifier = Modifier.padding(start = LabelWidth + 8.dp, end = 8.dp, bottom = 4.dp),
            ) {
                allDay.forEach { entry -> AllDayChip(entry, isCompact = false, onOpen = onOpen) }
            }
        }

        HorizontalDivider()

        TimelineScroll(
            showsToday = day == LocalDate.now(zone),
            zone = zone,
            locale = locale,
            isLoading = state.entries == null,
            scrollKey = day,
        ) {
            DayColumn(DayTimeline.layout(entries, day, zone), day, zone, locale, isCompact = false, onOpen, onCreate, Modifier.weight(1f))
        }
    }
}

/**
 * The week from Sunday on an hour grid, as the web's week view: a column per
 * day, its all-day items on top. A tap on a day's heading opens it on the day
 * view; a tap on an empty hour creates an item there.
 */
@Composable
fun AgendaWeekGrid(
    state: RemindersStore.State,
    zone: ZoneId,
    locale: Locale,
    onOpenDay: (LocalDate) -> Unit,
    onOpen: (CalendarEntry) -> Unit,
    onCreate: (LocalDateTime) -> Unit,
) {
    val days = AgendaPeriod.of(AgendaView.Week, state.selectedDay).days
    val today = LocalDate.now(zone)
    val byDay = state.entries.orEmpty().groupBy { it.day(zone) }
    val dayFormat = DateTimeFormatter.ofLocalizedDate(FormatStyle.FULL).withLocale(locale)

    Column(Modifier.fillMaxSize().testTag("agenda.week")) {
        Row(Modifier.padding(start = LabelWidth, end = 4.dp)) {
            days.forEach { day ->
                val allDay = byDay[day].orEmpty().filter { it.isAllDay }
                val isToday = day == today
                val label = day.format(dayFormat)

                Column(
                    horizontalAlignment = Alignment.CenterHorizontally,
                    verticalArrangement = Arrangement.spacedBy(2.dp),
                    modifier = Modifier.weight(1f).padding(horizontal = 1.dp),
                ) {
                    Column(
                        horizontalAlignment = Alignment.CenterHorizontally,
                        modifier = Modifier
                            .fillMaxWidth()
                            .heightIn(min = 48.dp)
                            .clip(RoundedCornerShape(8.dp))
                            .clickable(role = Role.Button) { onOpenDay(day) }
                            .semantics { contentDescription = label },
                    ) {
                        Text(
                            day.dayOfWeek.getDisplayName(TextStyle.NARROW_STANDALONE, locale),
                            style = MaterialTheme.typography.labelSmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                        Box(
                            Modifier.size(28.dp).clip(CircleShape).background(if (isToday) MaterialTheme.colorScheme.primary else Color.Transparent),
                            contentAlignment = Alignment.Center,
                        ) {
                            Text(
                                day.dayOfMonth.toString(),
                                style = MaterialTheme.typography.bodyMedium,
                                fontWeight = if (isToday) FontWeight.Bold else FontWeight.Normal,
                                color = if (isToday) MaterialTheme.colorScheme.onPrimary else MaterialTheme.colorScheme.onSurface,
                            )
                        }
                    }

                    allDay.take(2).forEach { entry -> AllDayChip(entry, isCompact = true, onOpen = onOpen) }

                    if (allDay.size > 2) {
                        Text("+${allDay.size - 2}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
            }
        }

        HorizontalDivider()

        TimelineScroll(
            showsToday = today in days,
            zone = zone,
            locale = locale,
            isLoading = state.entries == null,
            scrollKey = days.first(),
        ) {
            days.forEach { day ->
                DayColumn(
                    slots = DayTimeline.layout(byDay[day].orEmpty(), day, zone),
                    day = day,
                    zone = zone,
                    locale = locale,
                    isCompact = true,
                    onOpen = onOpen,
                    onCreate = onCreate,
                    modifier = Modifier.weight(1f),
                )
            }
        }
    }
}

/** The 24 hours with their lines and labels, scrolled to the hour that matters, with the columns right of the labels. */
@Composable
private fun TimelineScroll(
    showsToday: Boolean,
    zone: ZoneId,
    locale: Locale,
    isLoading: Boolean,
    scrollKey: Any,
    columns: @Composable androidx.compose.foundation.layout.RowScope.() -> Unit,
) {
    val scroll = rememberScrollState()
    val density = LocalDensity.current
    val hourFormat = DateTimeFormatter.ofLocalizedTime(FormatStyle.SHORT).withLocale(locale)

    LaunchedEffect(scrollKey) {
        scroll.scrollTo(with(density) { (HourHeight * firstHour(showsToday, zone)).roundToPx() })
    }

    Box(Modifier.fillMaxSize().verticalScroll(scroll)) {
        Column(Modifier.fillMaxWidth()) {
            (0 until 24).forEach { hour ->
                Row(Modifier.height(HourHeight)) {
                    Text(
                        if (hour == 0) "" else LocalTime.of(hour, 0).format(hourFormat),
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        textAlign = TextAlign.End,
                        maxLines = 1,
                        modifier = Modifier.width(LabelWidth - 6.dp).offset(y = (-7).dp),
                    )
                    Box(Modifier.padding(start = 6.dp).fillMaxWidth()) {
                        HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant.copy(alpha = 0.5f))
                    }
                }
            }
        }

        Row(Modifier.fillMaxWidth().height(GridHeight).padding(start = LabelWidth, end = 4.dp), content = columns)

        if (showsToday) {
            NowLine(zone)
        }

        if (isLoading) {
            CircularProgressIndicator(
                Modifier.align(Alignment.TopCenter).padding(top = HourHeight * firstHour(showsToday, zone) + 24.dp),
            )
        }
    }
}

/** The red line at the current time, moved every minute. */
@Composable
private fun NowLine(zone: ZoneId) {
    val now by produceState(LocalTime.now(zone)) {
        while (true) {
            delay(60_000)
            value = LocalTime.now(zone)
        }
    }

    Row(
        verticalAlignment = Alignment.CenterVertically,
        modifier = Modifier.padding(start = LabelWidth - 4.dp).offset(y = minutesToDp(now.hour * 60 + now.minute) - 4.dp),
    ) {
        Box(Modifier.size(8.dp).clip(CircleShape).background(Color.Red))
        Box(Modifier.fillMaxWidth().height(1.5.dp).background(Color.Red))
    }
}

/** One day's column: its empty hours, which create an item when tapped, and its timed items at their place. */
@Composable
private fun DayColumn(
    slots: List<TimelineSlot>,
    day: LocalDate,
    zone: ZoneId,
    locale: Locale,
    isCompact: Boolean,
    onOpen: (CalendarEntry) -> Unit,
    onCreate: (LocalDateTime) -> Unit,
    modifier: Modifier,
) {
    val newAt = stringResource(R.string.agenda_new_at)
    val timeFormat = DateTimeFormatter.ofLocalizedTime(FormatStyle.SHORT).withLocale(locale)

    BoxWithConstraints(modifier.fillMaxHeight()) {
        val width = maxWidth

        Column {
            (0 until 24).forEach { hour ->
                val startsAt = day.atTime(hour, 0)

                Box(
                    Modifier
                        .fillMaxWidth()
                        .height(HourHeight)
                        .clickable(role = Role.Button) { onCreate(startsAt) }
                        .semantics { contentDescription = String.format(locale, newAt, startsAt.format(timeFormat)) },
                )
            }
        }

        if (isCompact) {
            Box(Modifier.width(0.5.dp).fillMaxHeight().background(MaterialTheme.colorScheme.outlineVariant.copy(alpha = 0.5f)))
        }

        slots.forEach { slot ->
            val laneWidth = width / slot.lanes

            TimelineBlock(
                entry = slot.entry,
                zone = zone,
                locale = locale,
                isCompact = isCompact,
                showsTime = minutesToDp(slot.height) >= 40.dp,
                onOpen = onOpen,
                modifier = Modifier
                    .offset(x = laneWidth * slot.lane + 1.dp, y = minutesToDp(slot.top) + 1.dp)
                    .width((laneWidth - 2.dp).coerceAtLeast(4.dp))
                    .height((minutesToDp(slot.height) - 2.dp).coerceAtLeast(12.dp)),
            )
        }
    }
}

/** A timed item on the grid: its source's color, title and — when there is room — its time and its workspace's dot. */
@Composable
private fun TimelineBlock(
    entry: CalendarEntry,
    zone: ZoneId,
    locale: Locale,
    isCompact: Boolean,
    showsTime: Boolean,
    onOpen: (CalendarEntry) -> Unit,
    modifier: Modifier,
) {
    val color = entry.calendarSource?.color ?: MaterialTheme.colorScheme.onSurfaceVariant
    val workspace = entry.workspace?.takeIf { !isCompact && LocalShowsWorkspace.current(entry) }
    val time = runCatching {
        java.time.OffsetDateTime.parse(entry.startsAt).atZoneSameInstant(zone).format(DateTimeFormatter.ofLocalizedTime(FormatStyle.SHORT).withLocale(locale))
    }.getOrNull()

    Row(
        modifier
            .clip(RoundedCornerShape(4.dp))
            .background(color.copy(alpha = if (entry.isProjected) 0.1f else 0.2f))
            .clickable(role = Role.Button) { onOpen(entry) }
            .testTag("agenda.block.${entry.key}"),
    ) {
        Box(Modifier.width(3.dp).fillMaxHeight().background(color))

        Column(Modifier.padding(horizontal = if (isCompact) 2.dp else 6.dp, vertical = 2.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                if (workspace != null) {
                    WorkspaceDot(workspace, size = 7.dp)
                }

                Text(
                    entry.title,
                    style = if (isCompact) MaterialTheme.typography.labelSmall else MaterialTheme.typography.labelMedium,
                    fontWeight = FontWeight.SemiBold,
                    textDecoration = if (entry.isCompleted) TextDecoration.LineThrough else null,
                    maxLines = if (showsTime) 2 else 1,
                    overflow = TextOverflow.Ellipsis,
                )
            }

            if (showsTime && !isCompact && time != null) {
                Text(time, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 1)
            }
        }
    }
}

/** An all-day item above the grid. */
@Composable
private fun AllDayChip(entry: CalendarEntry, isCompact: Boolean, onOpen: (CalendarEntry) -> Unit) {
    Text(
        entry.title,
        style = MaterialTheme.typography.labelSmall.let { if (isCompact) it.copy(fontSize = 9.sp) else it },
        fontWeight = FontWeight.SemiBold,
        color = Color.White,
        textDecoration = if (entry.isCompleted) TextDecoration.LineThrough else null,
        maxLines = 1,
        overflow = TextOverflow.Ellipsis,
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(4.dp))
            .background(entry.calendarSource?.color ?: MaterialTheme.colorScheme.onSurfaceVariant)
            .clickable(role = Role.Button) { onOpen(entry) }
            .padding(horizontal = if (isCompact) 2.dp else 6.dp, vertical = if (isCompact) 1.dp else 3.dp),
    )
}
