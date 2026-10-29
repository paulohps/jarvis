import Localization
import Networking
import SwiftUI

/// A schedule being edited (US-12.1), shared by the reminder and the
/// WhatsApp message forms: no date (a reminder only), once at a date and
/// time, or a repeating preset. It turns into the API's `RecurrenceInput`
/// with the dates on the user's wall clock.
struct ScheduleDraft: Equatable {
    enum Kind: Hashable, CaseIterable {
        case none
        case once
        case daily
        case weekly
        case monthly
        case customCron

        var titleKey: String {
            switch self {
            case .none: "No date"
            case .once: "Once"
            case .daily: "Daily"
            case .weekly: "Weekly"
            case .monthly: "Monthly"
            case .customCron: "Custom (cron)"
            }
        }

        var frequency: RecurrenceFrequency? {
            switch self {
            case .none: nil
            case .once: .once
            case .daily: .daily
            case .weekly: .weekly
            case .monthly: .monthly
            case .customCron: .customCron
            }
        }

        init(_ frequency: RecurrenceFrequency?) {
            switch frequency {
            case nil: self = .none
            case .once: self = .once
            case .daily: self = .daily
            case .weekly: self = .weekly
            case .monthly: self = .monthly
            case .customCron: self = .customCron
            }
        }
    }

    var kind: Kind
    var date: Date
    var time: Date
    var weekdays: Set<Weekday>
    var dayOfMonth: Int
    var isLastDayOfMonth: Bool
    var cronExpression: String
    var hasEnd: Bool
    var endsAt: Date

    /// A new schedule: the next round hour, once (or none, when allowed).
    init(kind: Kind, now: Date = .now, timeZone: TimeZone) {
        var calendar = Calendar(identifier: .gregorian)
        calendar.timeZone = timeZone
        let nextHour = calendar.nextDate(after: now, matching: DateComponents(minute: 0), matchingPolicy: .nextTime) ?? now

        self.kind = kind
        date = nextHour
        time = nextHour
        weekdays = []
        dayOfMonth = calendar.component(.day, from: now)
        isLastDayOfMonth = false
        cronExpression = ""
        hasEnd = false
        endsAt = calendar.date(byAdding: .month, value: 1, to: nextHour) ?? nextHour
    }

    /// The schedule of a saved reminder or message.
    init(recurrence: Recurrence?, timeZone: TimeZone) {
        self.init(kind: Kind(recurrence?.frequency), timeZone: timeZone)

        guard let recurrence else {
            return
        }

        if recurrence.frequency == .once {
            date = recurrence.nextRunAt ?? recurrence.startsAt
        }

        if let timeOfDay = recurrence.timeOfDay, let saved = WallClock.date(atTime: timeOfDay, in: timeZone) {
            time = saved
        }

        weekdays = Set(recurrence.weekdays)
        dayOfMonth = recurrence.dayOfMonth ?? dayOfMonth
        isLastDayOfMonth = recurrence.isLastDayOfMonth
        cronExpression = recurrence.cronExpression ?? ""

        if let savedEnd = recurrence.endsAt {
            hasEnd = true
            endsAt = savedEnd
        }
    }

    /// The API's schedule; `nil` for no date.
    func recurrenceInput(in timeZone: TimeZone) -> RecurrenceInput? {
        guard let frequency = kind.frequency else {
            return nil
        }

        let endsAt = hasEnd && frequency != .once ? WallClock.dateTime(endsAt, in: timeZone) : nil

        switch frequency {
        case .once:
            return RecurrenceInput(frequency: .once, startsAt: WallClock.dateTime(date, in: timeZone))
        case .daily:
            return RecurrenceInput(frequency: .daily, timeOfDay: WallClock.time(time, in: timeZone), endsAt: endsAt)
        case .weekly:
            return RecurrenceInput(
                frequency: .weekly,
                timeOfDay: WallClock.time(time, in: timeZone),
                weekdays: Weekday.allCases.filter(weekdays.contains),
                endsAt: endsAt
            )
        case .monthly:
            return RecurrenceInput(
                frequency: .monthly,
                timeOfDay: WallClock.time(time, in: timeZone),
                dayOfMonth: isLastDayOfMonth ? nil : dayOfMonth,
                isLastDayOfMonth: isLastDayOfMonth,
                endsAt: endsAt
            )
        case .customCron:
            return RecurrenceInput(frequency: .customCron, cronExpression: cronExpression.trimmingCharacters(in: .whitespaces), endsAt: endsAt)
        }
    }
}

/// The fields of a schedule, as form sections.
struct ScheduleEditor: View {
    @Binding var draft: ScheduleDraft
    let kinds: [ScheduleDraft.Kind]

    @Environment(\.appLanguage) private var language

    var body: some View {
        Section {
            Picker(language.localized("Repeat"), selection: $draft.kind) {
                ForEach(kinds, id: \.self) { kind in
                    Text(verbatim: language.localized(kind.titleKey)).tag(kind)
                }
            }

            switch draft.kind {
            case .none:
                EmptyView()
            case .once:
                // Not limited to the future: the server refuses a past date in the user's language (422), as on the web.
                DatePicker(language.localized("Date and time"), selection: $draft.date, displayedComponents: [.date, .hourAndMinute])
            case .daily:
                timePicker
            case .weekly:
                timePicker
                weekdayPicker
            case .monthly:
                timePicker
                Toggle(language.localized("Last day of the month"), isOn: $draft.isLastDayOfMonth)

                if !draft.isLastDayOfMonth {
                    Stepper(value: $draft.dayOfMonth, in: 1...31) {
                        LabeledContent(language.localized("Day of the month")) {
                            Text(draft.dayOfMonth, format: .number)
                                .monospacedDigit()
                        }
                    }
                }
            case .customCron:
                TextField(language.localized("Cron expression"), text: $draft.cronExpression, prompt: Text(verbatim: "0 9 * * 1-5"))
                    .font(.body.monospaced())
                    .textInputAutocapitalization(.never)
                    .autocorrectionDisabled()
            }
        } header: {
            language.text("Schedule")
        }

        if draft.kind != .none, draft.kind != .once {
            Section {
                Toggle(language.localized("Ends"), isOn: $draft.hasEnd.animation())

                if draft.hasEnd {
                    DatePicker(language.localized("End"), selection: $draft.endsAt, displayedComponents: [.date, .hourAndMinute])
                }
            }
        }
    }

    private var timePicker: some View {
        DatePicker(language.localized("Time"), selection: $draft.time, displayedComponents: .hourAndMinute)
    }

    private var weekdayPicker: some View {
        HStack(spacing: 6) {
            ForEach(Weekday.allCases, id: \.self) { weekday in
                let isOn = draft.weekdays.contains(weekday)

                Button {
                    if isOn {
                        draft.weekdays.remove(weekday)
                    } else {
                        draft.weekdays.insert(weekday)
                    }
                } label: {
                    Text(verbatim: weekday.shortName(in: language.locale))
                        .font(.subheadline.weight(.semibold))
                        .frame(maxWidth: .infinity, minHeight: 36)
                }
                .buttonStyle(.plain)
                .background(isOn ? Color.accentColor : Color.secondary.opacity(0.15), in: .capsule)
                .foregroundStyle(isOn ? Color.white : Color.primary)
                .accessibilityLabel(Text(verbatim: weekday.fullName(in: language.locale)))
                .accessibilityAddTraits(isOn ? .isSelected : [])
            }
        }
    }
}

extension Weekday {
    /// The index in `Calendar.weekdaySymbols`, which start on Sunday.
    private var calendarIndex: Int {
        switch self {
        case .sunday: 0
        case .monday: 1
        case .tuesday: 2
        case .wednesday: 3
        case .thursday: 4
        case .friday: 5
        case .saturday: 6
        }
    }

    func shortName(in locale: Locale) -> String {
        var calendar = Calendar(identifier: .gregorian)
        calendar.locale = locale

        return calendar.veryShortStandaloneWeekdaySymbols[calendarIndex]
    }

    func abbreviation(in locale: Locale) -> String {
        var calendar = Calendar(identifier: .gregorian)
        calendar.locale = locale

        return calendar.shortStandaloneWeekdaySymbols[calendarIndex]
    }

    func fullName(in locale: Locale) -> String {
        var calendar = Calendar(identifier: .gregorian)
        calendar.locale = locale

        return calendar.standaloneWeekdaySymbols[calendarIndex]
    }
}

extension Recurrence {
    /// "Daily at 09:00", "Weekly on Mon, Wed at 09:00", … in the app's language.
    func summary(in language: AppLanguage) -> String {
        let time = timeOfDay ?? ""

        switch frequency {
        case .once:
            return language.localized("Once")
        case .daily:
            return language.localized("Daily at %@", time)
        case .weekly:
            let days = weekdays.map { $0.abbreviation(in: language.locale) }.joined(separator: ", ")

            return language.localized("Weekly on %@ at %@", days, time)
        case .monthly:
            return isLastDayOfMonth
                ? language.localized("Monthly on the last day at %@", time)
                : language.localized("Monthly on day %lld at %@", dayOfMonth ?? 1, time)
        case .customCron:
            return language.localized("Cron: %@", cronExpression ?? "")
        }
    }
}
