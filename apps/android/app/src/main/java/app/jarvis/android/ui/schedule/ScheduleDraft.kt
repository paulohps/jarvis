package app.jarvis.android.ui.schedule

import app.jarvis.android.network.Recurrence
import app.jarvis.android.network.RecurrenceFrequency
import app.jarvis.android.network.RecurrenceInput
import app.jarvis.android.network.Weekday
import java.time.LocalDateTime
import java.time.LocalTime
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter
import java.time.temporal.ChronoUnit

/**
 * A schedule being edited (US-12.1), shared by the reminder and the WhatsApp
 * message forms: no date (a reminder only), once at a date and time, or a
 * repeating preset. Everything is on the user's wall clock (their timezone of
 * `/me`); it turns into the API's [RecurrenceInput] without an offset, as the
 * web form sends it.
 */
data class ScheduleDraft(
    val kind: Kind,
    /** The date and time of a one-time schedule. */
    val onceAt: LocalDateTime,
    /** The time of day of a repeating preset. */
    val time: LocalTime,
    val weekdays: Set<Weekday> = emptySet(),
    val dayOfMonth: Int = 1,
    val isLastDayOfMonth: Boolean = false,
    val cronExpression: String = "",
    val hasEnd: Boolean = false,
    val endsAt: LocalDateTime,
) {
    enum class Kind(val frequency: RecurrenceFrequency?) {
        None(null),
        Once(RecurrenceFrequency.Once),
        Daily(RecurrenceFrequency.Daily),
        Weekly(RecurrenceFrequency.Weekly),
        Monthly(RecurrenceFrequency.Monthly),
        CustomCron(RecurrenceFrequency.CustomCron),
        ;

        val isRepeating: Boolean
            get() = this != None && this != Once

        companion object {
            fun of(frequency: RecurrenceFrequency?): Kind = entries.first { it.frequency == frequency }
        }
    }

    /**
     * The API's schedule; `null` for no date. Fields that do not apply to the
     * preset are left out; the end only for a repeating one.
     */
    fun recurrenceInput(): RecurrenceInput? {
        val frequency = kind.frequency ?: return null
        val end = if (hasEnd && kind.isRepeating) WallClock.dateTime(endsAt) else null

        return when (kind) {
            Kind.None -> null
            Kind.Once -> RecurrenceInput(frequency = frequency, startsAt = WallClock.dateTime(onceAt))
            Kind.Daily -> RecurrenceInput(frequency = frequency, timeOfDay = WallClock.time(time), endsAt = end)
            Kind.Weekly -> RecurrenceInput(
                frequency = frequency,
                timeOfDay = WallClock.time(time),
                weekdays = Weekday.entries.filter { it in weekdays },
                endsAt = end,
            )

            Kind.Monthly -> RecurrenceInput(
                frequency = frequency,
                timeOfDay = WallClock.time(time),
                dayOfMonth = if (isLastDayOfMonth) null else dayOfMonth,
                isLastDayOfMonth = isLastDayOfMonth,
                endsAt = end,
            )

            Kind.CustomCron -> RecurrenceInput(frequency = frequency, cronExpression = cronExpression.trim(), endsAt = end)
        }
    }

    companion object {
        /** A new schedule: the next round hour, once (or none, when allowed). */
        fun new(kind: Kind, now: ZonedDateTime): ScheduleDraft {
            val nextHour = now.toLocalDateTime().truncatedTo(ChronoUnit.HOURS).plusHours(1)

            return ScheduleDraft(
                kind = kind,
                onceAt = nextHour,
                time = nextHour.toLocalTime(),
                dayOfMonth = now.dayOfMonth,
                endsAt = nextHour.plusMonths(1),
            )
        }

        /**
         * A new schedule once from the calendar: at the hour tapped; on a day
         * alone (at midnight), the next round hour today and 09:00 on another day.
         */
        fun startingAt(startsAt: LocalDateTime, now: ZonedDateTime): ScheduleDraft {
            val draft = new(Kind.Once, now)
            val day = startsAt.toLocalDate()

            return when {
                startsAt.toLocalTime() != LocalTime.MIDNIGHT -> draft.copy(onceAt = startsAt, time = startsAt.toLocalTime())
                day == now.toLocalDate() -> draft
                else -> draft.copy(onceAt = day.atTime(9, 0), time = LocalTime.of(9, 0))
            }
        }

        /** The schedule of a saved reminder or message, read in the user's timezone. */
        fun of(recurrence: Recurrence?, zone: ZoneId, now: ZonedDateTime = ZonedDateTime.now(zone)): ScheduleDraft {
            val draft = new(Kind.of(recurrence?.frequency), now)

            if (recurrence == null) {
                return draft
            }

            return draft.copy(
                onceAt = if (recurrence.frequency == RecurrenceFrequency.Once) {
                    WallClock.local(recurrence.nextRunAt ?: recurrence.startsAt, zone) ?: draft.onceAt
                } else {
                    draft.onceAt
                },
                time = recurrence.timeOfDay?.let(WallClock::parseTime) ?: draft.time,
                weekdays = recurrence.weekdays.toSet(),
                dayOfMonth = recurrence.dayOfMonth ?: draft.dayOfMonth,
                isLastDayOfMonth = recurrence.isLastDayOfMonth,
                cronExpression = recurrence.cronExpression.orEmpty(),
                hasEnd = recurrence.endsAt != null,
                endsAt = WallClock.local(recurrence.endsAt, zone) ?: draft.endsAt,
            )
        }
    }
}

/** A date and time as the user reads it on a wall clock in their timezone. */
object WallClock {
    private val dateTimeFormat = DateTimeFormatter.ofPattern("yyyy-MM-dd'T'HH:mm")
    private val timeFormat = DateTimeFormatter.ofPattern("HH:mm")

    /** `2026-10-06T09:00`. */
    fun dateTime(value: LocalDateTime): String = value.format(dateTimeFormat)

    /** `09:00`. */
    fun time(value: LocalTime): String = value.format(timeFormat)

    /** A saved `time_of_day` (`09:30`). */
    fun parseTime(value: String): LocalTime? = runCatching { LocalTime.parse(value, timeFormat) }.getOrNull()

    /** An ISO-8601 instant of the API on the wall clock of [zone]. */
    fun local(iso: String?, zone: ZoneId): LocalDateTime? {
        if (iso.isNullOrBlank()) {
            return null
        }

        return runCatching { OffsetDateTime.parse(iso).atZoneSameInstant(zone).toLocalDateTime() }.getOrNull()
    }

    /** The user's timezone of `/me`; the phone's when it is not a valid id. */
    fun zone(timezone: String?): ZoneId = runCatching { ZoneId.of(timezone) }.getOrDefault(ZoneId.systemDefault())
}
