package app.jarvis.android.ui.components

import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import java.util.Locale

/**
 * An ISO-8601 instant of the API in the user's timezone (`/me`), in the
 * language of the app: `6 Oct 2026, 09:00`.
 */
fun formatDateTime(iso: String?, timezone: String, locale: Locale): String? {
    if (iso.isNullOrBlank()) {
        return null
    }

    return runCatching {
        val zone = runCatching { ZoneId.of(timezone) }.getOrDefault(ZoneId.systemDefault())

        OffsetDateTime.parse(iso)
            .atZoneSameInstant(zone)
            .format(DateTimeFormatter.ofLocalizedDateTime(FormatStyle.MEDIUM, FormatStyle.SHORT).withLocale(locale))
    }.getOrNull()
}

/** An ISO-8601 instant of the API as a date in the user's timezone: `6 Oct 2026`. */
fun formatDate(iso: String?, timezone: String, locale: Locale): String? {
    if (iso.isNullOrBlank()) {
        return null
    }

    return runCatching {
        val zone = runCatching { ZoneId.of(timezone) }.getOrDefault(ZoneId.systemDefault())

        OffsetDateTime.parse(iso)
            .atZoneSameInstant(zone)
            .format(DateTimeFormatter.ofLocalizedDate(FormatStyle.MEDIUM).withLocale(locale))
    }.getOrNull()
}

/** An ISO-8601 instant of the API as a time in the user's timezone: `09:00`. */
fun formatTime(iso: String?, timezone: String, locale: Locale): String? {
    if (iso.isNullOrBlank()) {
        return null
    }

    return runCatching {
        val zone = runCatching { ZoneId.of(timezone) }.getOrDefault(ZoneId.systemDefault())

        OffsetDateTime.parse(iso)
            .atZoneSameInstant(zone)
            .format(DateTimeFormatter.ofLocalizedTime(FormatStyle.SHORT).withLocale(locale))
    }.getOrNull()
}
