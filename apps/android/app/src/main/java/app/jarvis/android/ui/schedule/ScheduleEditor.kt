package app.jarvis.android.ui.schedule

import android.text.format.DateFormat
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Remove
import androidx.compose.material.icons.filled.UnfoldMore
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.DatePicker
import androidx.compose.material3.DatePickerDialog
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TimePicker
import androidx.compose.material3.rememberDatePickerState
import androidx.compose.material3.rememberTimePickerState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import app.jarvis.android.R
import app.jarvis.android.network.Recurrence
import app.jarvis.android.network.RecurrenceFrequency
import app.jarvis.android.network.Weekday
import app.jarvis.android.ui.components.ListRow
import app.jarvis.android.ui.components.Section
import java.time.Instant
import java.time.LocalDateTime
import java.time.LocalTime
import java.time.ZoneOffset
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import java.time.format.TextStyle
import java.util.Locale

/**
 * The fields of a schedule, as form sections: the preset, then its date or
 * time, weekdays, day of the month or cron expression, and the end of a
 * repeating one. A date in the past is not blocked here: the server refuses it
 * in the user's language (422), as on the web.
 */
@Composable
fun ScheduleEditor(draft: ScheduleDraft, kinds: List<ScheduleDraft.Kind>, locale: Locale, onChange: (ScheduleDraft) -> Unit) {
    Section(header = stringResource(R.string.schedule_header)) {
        KindRow(draft.kind, kinds) { onChange(draft.copy(kind = it)) }

        when (draft.kind) {
            ScheduleDraft.Kind.None -> Unit
            ScheduleDraft.Kind.Once -> DateTimeRows(draft.onceAt, locale, showDivider = false) { onChange(draft.copy(onceAt = it)) }
            ScheduleDraft.Kind.Daily -> TimeRow(draft.time, locale, showDivider = false) { onChange(draft.copy(time = it)) }
            ScheduleDraft.Kind.Weekly -> {
                TimeRow(draft.time, locale, showDivider = true) { onChange(draft.copy(time = it)) }
                WeekdayPicker(draft.weekdays, locale) { onChange(draft.copy(weekdays = it)) }
            }

            ScheduleDraft.Kind.Monthly -> {
                TimeRow(draft.time, locale, showDivider = true) { onChange(draft.copy(time = it)) }
                SwitchRow(stringResource(R.string.schedule_last_day), draft.isLastDayOfMonth, showDivider = !draft.isLastDayOfMonth) {
                    onChange(draft.copy(isLastDayOfMonth = it))
                }

                if (!draft.isLastDayOfMonth) {
                    DayOfMonthRow(draft.dayOfMonth) { onChange(draft.copy(dayOfMonth = it)) }
                }
            }

            ScheduleDraft.Kind.CustomCron -> OutlinedTextField(
                value = draft.cronExpression,
                onValueChange = { onChange(draft.copy(cronExpression = it)) },
                label = { Text(stringResource(R.string.schedule_cron_expression)) },
                placeholder = { Text("0 9 * * 1-5", fontFamily = FontFamily.Monospace) },
                textStyle = MaterialTheme.typography.bodyLarge.copy(fontFamily = FontFamily.Monospace),
                singleLine = true,
                keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.None, autoCorrectEnabled = false),
                modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 8.dp),
            )
        }
    }

    if (draft.kind.isRepeating) {
        Section {
            SwitchRow(stringResource(R.string.schedule_ends), draft.hasEnd, showDivider = draft.hasEnd) { onChange(draft.copy(hasEnd = it)) }

            if (draft.hasEnd) {
                DateTimeRows(draft.endsAt, locale, showDivider = false, dateLabel = stringResource(R.string.schedule_end)) {
                    onChange(draft.copy(endsAt = it))
                }
            }
        }
    }
}

@Composable
private fun KindRow(kind: ScheduleDraft.Kind, kinds: List<ScheduleDraft.Kind>, onSelect: (ScheduleDraft.Kind) -> Unit) {
    var isMenuOpen by remember { mutableStateOf(false) }

    Box {
        ListRow(
            title = stringResource(R.string.schedule_repeat),
            showDivider = kind != ScheduleDraft.Kind.None,
            onClick = { isMenuOpen = true },
            trailing = {
                Text(stringResource(kind.title), color = MaterialTheme.colorScheme.primary)
                Icon(Icons.Filled.UnfoldMore, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
            },
        )

        DropdownMenu(expanded = isMenuOpen, onDismissRequest = { isMenuOpen = false }, modifier = Modifier.align(Alignment.CenterEnd)) {
            kinds.forEach { option ->
                DropdownMenuItem(
                    text = { Text(stringResource(option.title)) },
                    onClick = {
                        isMenuOpen = false
                        onSelect(option)
                    },
                )
            }
        }
    }
}

/** A date row and a time row, each opening its picker. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun DateTimeRows(
    value: LocalDateTime,
    locale: Locale,
    showDivider: Boolean,
    dateLabel: String = stringResource(R.string.schedule_date),
    onChange: (LocalDateTime) -> Unit,
) {
    var isPickingDate by remember { mutableStateOf(false) }

    ListRow(
        title = dateLabel,
        showDivider = true,
        onClick = { isPickingDate = true },
        trailing = { Text(value.toLocalDate().format(DateTimeFormatter.ofLocalizedDate(FormatStyle.MEDIUM).withLocale(locale)), color = MaterialTheme.colorScheme.primary) },
    )
    TimeRow(value.toLocalTime(), locale, showDivider) { onChange(LocalDateTime.of(value.toLocalDate(), it)) }

    if (isPickingDate) {
        // The picker works on UTC midnights.
        val state = rememberDatePickerState(initialSelectedDateMillis = value.toLocalDate().atStartOfDay().toInstant(ZoneOffset.UTC).toEpochMilli())

        DatePickerDialog(
            onDismissRequest = { isPickingDate = false },
            confirmButton = {
                TextButton(
                    onClick = {
                        state.selectedDateMillis?.let { millis ->
                            val date = Instant.ofEpochMilli(millis).atZone(ZoneOffset.UTC).toLocalDate()
                            onChange(LocalDateTime.of(date, value.toLocalTime()))
                        }
                        isPickingDate = false
                    },
                ) {
                    Text(stringResource(R.string.ok))
                }
            },
            dismissButton = {
                TextButton(onClick = { isPickingDate = false }) { Text(stringResource(R.string.cancel)) }
            },
        ) {
            DatePicker(state)
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun TimeRow(value: LocalTime, locale: Locale, showDivider: Boolean, onChange: (LocalTime) -> Unit) {
    var isPicking by remember { mutableStateOf(false) }
    val is24Hour = DateFormat.is24HourFormat(LocalContext.current)

    ListRow(
        title = stringResource(R.string.schedule_time),
        showDivider = showDivider,
        onClick = { isPicking = true },
        trailing = { Text(value.format(DateTimeFormatter.ofLocalizedTime(FormatStyle.SHORT).withLocale(locale)), color = MaterialTheme.colorScheme.primary) },
    )

    if (isPicking) {
        val state = rememberTimePickerState(initialHour = value.hour, initialMinute = value.minute, is24Hour = is24Hour)

        AlertDialog(
            onDismissRequest = { isPicking = false },
            text = { TimePicker(state) },
            confirmButton = {
                TextButton(
                    onClick = {
                        onChange(LocalTime.of(state.hour, state.minute))
                        isPicking = false
                    },
                ) {
                    Text(stringResource(R.string.ok))
                }
            },
            dismissButton = {
                TextButton(onClick = { isPicking = false }) { Text(stringResource(R.string.cancel)) }
            },
        )
    }
}

@Composable
private fun SwitchRow(title: String, checked: Boolean, showDivider: Boolean, onChange: (Boolean) -> Unit) {
    ListRow(
        title = title,
        showDivider = showDivider,
        onClick = { onChange(!checked) },
        trailing = { Switch(checked = checked, onCheckedChange = onChange) },
    )
}

@Composable
private fun DayOfMonthRow(day: Int, onChange: (Int) -> Unit) {
    ListRow(
        title = stringResource(R.string.schedule_day_of_month),
        trailing = {
            IconButton(onClick = { onChange((day - 1).coerceAtLeast(1)) }, enabled = day > 1) {
                Icon(Icons.Filled.Remove, contentDescription = null)
            }
            Text(day.toString(), style = MaterialTheme.typography.titleMedium, textAlign = TextAlign.Center, modifier = Modifier.padding(horizontal = 4.dp))
            IconButton(onClick = { onChange((day + 1).coerceAtMost(31)) }, enabled = day < 31) {
                Icon(Icons.Filled.Add, contentDescription = null)
            }
        },
    )
}

@Composable
private fun WeekdayPicker(selected: Set<Weekday>, locale: Locale, onChange: (Set<Weekday>) -> Unit) {
    Row(
        modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 10.dp),
        horizontalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Weekday.entries.forEach { weekday ->
            val isOn = weekday in selected

            Box(
                modifier = Modifier
                    .weight(1f)
                    .heightIn(min = 36.dp)
                    .background(if (isOn) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.outlineVariant.copy(alpha = 0.25f), CircleShape)
                    .clickable { onChange(if (isOn) selected - weekday else selected + weekday) },
                contentAlignment = Alignment.Center,
            ) {
                Text(
                    weekday.dayOfWeek.getDisplayName(TextStyle.NARROW_STANDALONE, locale),
                    style = MaterialTheme.typography.labelLarge,
                    color = if (isOn) Color.White else MaterialTheme.colorScheme.onSurface,
                )
            }
        }
    }
}

private val ScheduleDraft.Kind.title: Int
    get() = when (this) {
        ScheduleDraft.Kind.None -> R.string.reminders_no_date
        ScheduleDraft.Kind.Once -> R.string.schedule_once
        ScheduleDraft.Kind.Daily -> R.string.schedule_daily
        ScheduleDraft.Kind.Weekly -> R.string.schedule_weekly
        ScheduleDraft.Kind.Monthly -> R.string.schedule_monthly
        ScheduleDraft.Kind.CustomCron -> R.string.schedule_custom_cron
    }

/** "Daily at 09:00", "Weekly on Mon, Wed at 09:00", … in the app's language. */
@Composable
fun recurrenceSummary(recurrence: Recurrence, locale: Locale): String {
    val time = recurrence.timeOfDay.orEmpty()

    return when (recurrence.frequency) {
        RecurrenceFrequency.Once -> stringResource(R.string.schedule_once)
        RecurrenceFrequency.Daily -> stringResource(R.string.schedule_daily_at, time)
        RecurrenceFrequency.Weekly -> stringResource(
            R.string.schedule_weekly_on,
            recurrence.weekdays.joinToString(", ") { it.dayOfWeek.getDisplayName(TextStyle.SHORT_STANDALONE, locale) },
            time,
        )

        RecurrenceFrequency.Monthly -> if (recurrence.isLastDayOfMonth) {
            stringResource(R.string.schedule_monthly_last_day, time)
        } else {
            stringResource(R.string.schedule_monthly_on_day, recurrence.dayOfMonth ?: 1, time)
        }

        RecurrenceFrequency.CustomCron -> stringResource(R.string.schedule_cron, recurrence.cronExpression.orEmpty())
    }
}
