package app.jarvis.android.ui.whatsapp

import java.time.LocalDate
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import java.util.Locale

/** The day of an ISO-8601 instant of the API in the user's timezone; null when it cannot be read. */
internal fun chatDay(iso: String?, timezone: String): LocalDate? {
    if (iso.isNullOrBlank()) {
        return null
    }

    return runCatching {
        val zone = runCatching { ZoneId.of(timezone) }.getOrDefault(ZoneId.systemDefault())

        OffsetDateTime.parse(iso).atZoneSameInstant(zone).toLocalDate()
    }.getOrNull()
}

internal fun isToday(iso: String?, timezone: String, today: LocalDate = today(timezone)): Boolean = chatDay(iso, timezone) == today

internal fun today(timezone: String): LocalDate =
    LocalDate.now(runCatching { ZoneId.of(timezone) }.getOrDefault(ZoneId.systemDefault()))

/** `06/10/26`: a chat's last message on another day. */
internal fun formatShortDate(iso: String?, timezone: String, locale: Locale): String? =
    chatDay(iso, timezone)?.format(DateTimeFormatter.ofLocalizedDate(FormatStyle.SHORT).withLocale(locale))

/**
 * Where a day separator goes in a chat listed newest first: above (in the
 * list, after) each message whose day differs from the next older one, and
 * above the oldest. @return the indexes of [days] that start a day.
 */
internal fun dayStarts(days: List<LocalDate?>): Set<Int> = days.indices.filter { index ->
    index == days.lastIndex || days[index] != days[index + 1]
}.toSet()
