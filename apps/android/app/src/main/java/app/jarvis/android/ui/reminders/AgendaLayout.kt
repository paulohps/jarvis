package app.jarvis.android.ui.reminders

import app.jarvis.android.network.CalendarEntry
import java.time.DayOfWeek
import java.time.Duration
import java.time.LocalDate
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.temporal.TemporalAdjusters

/** The views of the agenda's calendar, as on the web. */
enum class AgendaView { Day, Week, Month, Schedule }

/**
 * The days an agenda view shows around a day, in the user's timezone: the
 * day; its week from Sunday; the six weeks of its month's grid; or
 * [SCHEDULE_DAYS] from the day on, as the web's schedule. [end] is the day
 * after the last one.
 */
data class AgendaPeriod(val start: LocalDate, val end: LocalDate) {
    /** Each day of the period. */
    val days: List<LocalDate>
        get() = generateSequence(start) { it.plusDays(1) }.takeWhile { it < end }.toList()

    companion object {
        const val SCHEDULE_DAYS = 30L

        fun of(view: AgendaView, day: LocalDate): AgendaPeriod = when (view) {
            AgendaView.Day -> AgendaPeriod(day, day.plusDays(1))
            AgendaView.Week -> day.with(TemporalAdjusters.previousOrSame(DayOfWeek.SUNDAY)).let { AgendaPeriod(it, it.plusDays(7)) }
            AgendaView.Month -> AgendaMonth.of(day).let { AgendaPeriod(it.start, it.end) }
            AgendaView.Schedule -> AgendaPeriod(day, day.plusDays(SCHEDULE_DAYS))
        }

        /** The day [steps] periods before or after [day]: a day, a week, a month or [SCHEDULE_DAYS]. */
        fun step(view: AgendaView, day: LocalDate, steps: Long): LocalDate = when (view) {
            AgendaView.Day -> day.plusDays(steps)
            AgendaView.Week -> day.plusWeeks(steps)
            AgendaView.Month -> day.plusMonths(steps)
            AgendaView.Schedule -> day.plusDays(SCHEDULE_DAYS * steps)
        }
    }
}

/**
 * The month on the agenda's calendar: six weeks from the Sunday on or before
 * its first day, as the web shows it.
 */
data class AgendaMonth(val firstDay: LocalDate) {
    /** The first day on the grid: the Sunday on or before the first day. */
    val start: LocalDate
        get() = firstDay.with(TemporalAdjusters.previousOrSame(DayOfWeek.SUNDAY))

    /** The 42 days on the grid. */
    val days: List<LocalDate>
        get() = (0L until 42L).map { start.plusDays(it) }

    /** The day after the last one on the grid. */
    val end: LocalDate
        get() = start.plusDays(42)

    operator fun contains(day: LocalDate): Boolean = day.year == firstDay.year && day.month == firstDay.month

    companion object {
        fun of(day: LocalDate): AgendaMonth = AgendaMonth(day.withDayOfMonth(1))
    }
}

/** A timed item on the day and week grids: where it starts and how long it shows, in minutes of the day, and its lane among the items it overlaps. */
data class TimelineSlot(val entry: CalendarEntry, val top: Int, val height: Int, val lane: Int, val lanes: Int)

/**
 * The timed items of one day on the day and week grids, as the web's
 * DayTimeline draws them (Google Calendar's way): each at its start, as tall
 * as it lasts (a moment as [MIN_MINUTES]), and the ones that overlap side by
 * side — every group of overlapping items split into as many lanes as it
 * needs, each item in the first lane free at its start. An item that runs
 * past midnight is cut at the end of the day.
 */
object DayTimeline {
    const val MIN_MINUTES = 30
    const val DAY_MINUTES = 1440

    fun layout(entries: List<CalendarEntry>, day: LocalDate, zone: ZoneId): List<TimelineSlot> {
        val midnight = day.atStartOfDay(zone)

        val placed = entries.filterNot { it.isAllDay }.mapNotNull { entry ->
            val startsAt = runCatching { OffsetDateTime.parse(entry.startsAt).toZonedDateTime() }.getOrNull() ?: return@mapNotNull null
            val endsAt = entry.endsAt?.let { runCatching { OffsetDateTime.parse(it).toZonedDateTime() }.getOrNull() }
            val top = Duration.between(midnight, startsAt).toMinutes().toInt().coerceIn(0, DAY_MINUTES - MIN_MINUTES)
            val lasts = endsAt?.let { Duration.between(startsAt, it).toMinutes().toInt() } ?: 0

            Triple(entry, top, maxOf(lasts, MIN_MINUTES).coerceAtMost(DAY_MINUTES - top))
        }.sortedWith(compareBy({ it.second }, { -it.third }))

        val laid = mutableListOf<TimelineSlot>()
        val group = mutableListOf<TimelineSlot>()
        val laneEnds = mutableListOf<Int>()
        var groupEnd = 0

        fun closeGroup() {
            laid += group.map { it.copy(lanes = maxOf(1, laneEnds.size)) }
            group.clear()
            laneEnds.clear()
        }

        for ((entry, top, height) in placed) {
            if (group.isNotEmpty() && top >= groupEnd) {
                closeGroup()
            }

            val lane = laneEnds.indexOfFirst { it <= top }.takeIf { it >= 0 } ?: laneEnds.size

            if (lane == laneEnds.size) {
                laneEnds += top + height
            } else {
                laneEnds[lane] = top + height
            }

            groupEnd = if (group.isEmpty()) top + height else maxOf(groupEnd, top + height)
            group += TimelineSlot(entry, top, height, lane, 1)
        }

        closeGroup()

        return laid
    }
}
