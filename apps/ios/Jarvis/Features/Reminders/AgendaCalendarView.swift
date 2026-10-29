import Localization
import Networking
import SwiftUI

/// The agenda's calendar, as the web's (US-9.1): a header to step through
/// the periods, then the day or the week on an hour grid, the month as six
/// weeks with a dot per source on each day and the picked day's items below,
/// or the next 30 days as a schedule — reminders with their check, events,
/// invoices, WhatsApp messages, shopping lists and Google events. An item
/// opens through `onOpen`: a reminder or event to be edited, the others on
/// the web; a tap on an empty hour creates one there (`onCreate`).
struct AgendaCalendarView: View {
    @Bindable var store: RemindersStore
    let view: AgendaView
    let onOpen: (CalendarEntry) -> Void
    let onDelete: (CalendarEntry) -> Void
    let onCreate: (Date) -> Void

    @Environment(\.appLanguage) private var language

    var body: some View {
        Group {
            switch view {
            case .day:
                AgendaDayView(store: store, onOpen: onOpen, onCreate: onCreate)
            case .week:
                AgendaWeekView(store: store, onOpen: onOpen, onCreate: onCreate)
            case .month:
                monthView
            case .schedule:
                scheduleView
            }
        }
        .pinnedHeader {
            AgendaPeriodHeader(store: store, view: view)
                .padding(.horizontal)
                .padding(.bottom, 4)
        }
        .accessibilityIdentifier("reminders.calendar")
    }

    private var monthView: some View {
        List {
            Section {
                AgendaMonthGrid(store: store)
                    .listRowInsets(EdgeInsets(top: 8, leading: 8, bottom: 8, trailing: 8))
            }

            Section {
                if store.entries != nil {
                    let dayEntries = store.entries(on: store.selectedDay)

                    if dayEntries.isEmpty {
                        language.text("Nothing on this day")
                            .foregroundStyle(.secondary)
                    } else {
                        ForEach(dayEntries) { entry in
                            entryRow(entry)
                        }
                    }
                } else if store.loadFailure == nil {
                    ProgressView()
                        .frame(maxWidth: .infinity)
                }
            } header: {
                Text(verbatim: store.selectedDay.formatted(
                    Date.FormatStyle(date: .complete, time: .omitted, locale: language.locale, timeZone: store.timeZone)
                ))
            }
        }
    }

    /// The days of the next 30 that have something, each with its items.
    @ViewBuilder
    private var scheduleView: some View {
        if let entries = store.entries {
            let days = store.period?.days(in: store.calendar).filter { !store.entries(on: $0).isEmpty } ?? []

            if entries.isEmpty || days.isEmpty {
                ContentUnavailableView(language.localized("Nothing on the agenda"), systemImage: "calendar")
            } else {
                List {
                    ForEach(days, id: \.self) { day in
                        Section {
                            ForEach(store.entries(on: day)) { entry in
                                entryRow(entry)
                            }
                        } header: {
                            Text(verbatim: dayHeading(day))
                        }
                    }
                }
                .accessibilityIdentifier("reminders.schedule")
            }
        } else {
            ProgressView(language.localized("Loading…"))
                .frame(maxWidth: .infinity, maxHeight: .infinity)
        }
    }

    private func entryRow(_ entry: CalendarEntry) -> some View {
        AgendaEntryRow(entry: entry, timeZone: store.timeZone) {
            await store.toggleCompletion(of: entry)
        }
        .contentShape(.rect)
        .onTapGesture { onOpen(entry) }
        .accessibilityAddTraits(.isButton)
        .swipeActions(edge: .trailing) {
            if entry.reminderId != nil {
                Button(language.localized("Delete"), systemImage: "trash", role: .destructive) {
                    onDelete(entry)
                }
            }
        }
    }

    /// "Today", "Tomorrow" or the day, as the web's schedule.
    private func dayHeading(_ day: Date) -> String {
        if store.calendar.isDateInToday(day) {
            return language.localized("Today")
        }

        if store.calendar.isDateInTomorrow(day) {
            return language.localized("Tomorrow")
        }

        return day.formatted(Date.FormatStyle(date: .complete, time: .omitted, locale: language.locale, timeZone: store.timeZone))
    }
}

/// The arrows to the period before and after, its name and "Today".
private struct AgendaPeriodHeader: View {
    @Bindable var store: RemindersStore
    let view: AgendaView

    @Environment(\.appLanguage) private var language

    var body: some View {
        HStack {
            Button(language.localized("Previous"), systemImage: "chevron.left") {
                Task { await store.step(by: -1) }
            }
            .labelStyle(.iconOnly)
            .accessibilityIdentifier("agenda.previous")

            Spacer()

            Text(verbatim: title)
                .font(.headline)
                .lineLimit(1)
                .minimumScaleFactor(0.8)

            Spacer()

            Button(language.localized("Today")) {
                Task { await store.showToday() }
            }
            .font(.subheadline)

            Button(language.localized("Next"), systemImage: "chevron.right") {
                Task { await store.step(by: 1) }
            }
            .labelStyle(.iconOnly)
            .accessibilityIdentifier("agenda.next")
        }
        .buttonStyle(.borderless)
        .frame(minHeight: 44)
    }

    /// "Tuesday, Oct 6", "Oct 4 – 10, 2026" or "October 2026".
    private var title: String {
        let locale = language.locale
        let timeZone = store.timeZone

        switch view {
        case .day:
            return store.selectedDay.formatted(Date.FormatStyle(locale: locale, timeZone: timeZone).weekday(.wide).day().month(.abbreviated))
                .capitalized(with: locale)
        case .month:
            return store.month.firstDay.formatted(Date.FormatStyle(locale: locale, timeZone: timeZone).month(.wide).year())
                .capitalized(with: locale)
        case .week, .schedule:
            let period = AgendaPeriod(view, around: store.selectedDay, calendar: store.calendar)
            let last = store.calendar.date(byAdding: .day, value: -1, to: period.end) ?? period.end
            var style = Date.IntervalFormatStyle(date: .abbreviated, time: .omitted, locale: locale)
            style.timeZone = timeZone

            return (period.start..<last).formatted(style)
        }
    }
}

/// The weekdays and the 42 days of the month.
private struct AgendaMonthGrid: View {
    @Bindable var store: RemindersStore

    @Environment(\.appLanguage) private var language

    private let columns = Array(repeating: GridItem(.flexible(), spacing: 0), count: 7)

    var body: some View {
        let month = store.month
        let calendar = month.calendar
        let sourcesByDay = sourcesByDay(in: calendar)

        LazyVGrid(columns: columns, spacing: 4) {
            ForEach(Array(weekdaySymbols.enumerated()), id: \.offset) { _, symbol in
                Text(verbatim: symbol)
                    .font(.caption2.weight(.semibold))
                    .foregroundStyle(.secondary)
                    .accessibilityHidden(true)
            }

            ForEach(month.days, id: \.self) { day in
                dayCell(day, sources: sourcesByDay[day] ?? [], calendar: calendar)
            }
        }
    }

    private func dayCell(_ day: Date, sources: [CalendarSource], calendar: Calendar) -> some View {
        let isSelected = calendar.isDate(day, inSameDayAs: store.selectedDay)
        let isToday = calendar.isDateInToday(day)
        let isInMonth = store.month.contains(day)

        return Button {
            Task { await store.show(day: day) }
        } label: {
            VStack(spacing: 3) {
                Text(verbatim: String(calendar.component(.day, from: day)))
                    .font(.callout.weight(isToday ? .bold : .regular))
                    .foregroundStyle(isSelected ? Color.white : isToday ? Color.accentColor : isInMonth ? Color.primary : Color.secondary)
                    .frame(width: 32, height: 32)
                    .background(Circle().fill(isSelected ? Color.accentColor : Color.clear))

                HStack(spacing: 2) {
                    ForEach(sources.prefix(4), id: \.self) { source in
                        Circle()
                            .fill(source.color)
                            .frame(width: 5, height: 5)
                    }
                }
                .frame(height: 5)
            }
            .frame(maxWidth: .infinity, minHeight: 44)
            .contentShape(.rect)
        }
        .buttonStyle(.plain)
        .accessibilityLabel(Text(verbatim: accessibilityLabel(of: day, count: store.entries(on: day).count)))
        .accessibilityAddTraits(isSelected ? .isSelected : [])
    }

    /// The sources with something on each day, in the order they come.
    private func sourcesByDay(in calendar: Calendar) -> [Date: [CalendarSource]] {
        var sources: [Date: [CalendarSource]] = [:]

        for entry in store.entries ?? [] {
            guard let source = entry.calendarSource else {
                continue
            }

            let day = calendar.startOfDay(for: entry.startsAt)

            if !(sources[day] ?? []).contains(source) {
                sources[day, default: []].append(source)
            }
        }

        return sources
    }

    /// "S M T W T F S", from Sunday, in the app's language.
    private var weekdaySymbols: [String] {
        var calendar = Calendar(identifier: .gregorian)
        calendar.locale = language.locale

        return calendar.veryShortStandaloneWeekdaySymbols
    }

    private func accessibilityLabel(of day: Date, count: Int) -> String {
        let date = day.formatted(Date.FormatStyle(date: .complete, time: .omitted, locale: language.locale, timeZone: store.timeZone))

        return count == 0 ? date : "\(date), \(language.localized("%lld items", count))"
    }
}

/// An item of the picked day: its source's color, title, time and — for a
/// reminder with an occurrence — the check that completes it; for a user in
/// two or more workspaces with its module, its workspace's dot.
struct AgendaEntryRow: View {
    let entry: CalendarEntry
    let timeZone: TimeZone
    let toggle: () async -> Void

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language
    @State private var isWorking = false

    var body: some View {
        HStack(spacing: 12) {
            if entry.isCompletable {
                Button {
                    isWorking = true
                    Task {
                        await toggle()
                        isWorking = false
                    }
                } label: {
                    Image(systemName: entry.isCompleted ? "checkmark.circle.fill" : "circle")
                        .font(.title2)
                        .foregroundStyle(entry.isCompleted ? Color.green : Color.secondary)
                        .frame(minWidth: 44, minHeight: 44)
                        .contentShape(.rect)
                }
                .buttonStyle(.plain)
                .disabled(isWorking)
                .sensoryFeedback(.success, trigger: entry.isCompleted)
                .accessibilityLabel(Text(verbatim: language.localized(entry.isCompleted ? "Reopen" : "Complete")))
            } else {
                RoundedRectangle(cornerRadius: 2)
                    .fill(entry.calendarSource?.color ?? Color.secondary)
                    .frame(width: 4, height: 36)
                    .frame(minWidth: 44)
                    .accessibilityHidden(true)
            }

            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: entry.title)
                    .strikethrough(entry.isCompleted)
                    .foregroundStyle(entry.isProjected ? Color.secondary : Color.primary)

                HStack(spacing: 6) {
                    Text(verbatim: timeText)
                        .foregroundStyle(entry.isOverdue ? Color.red : Color.secondary)

                    if let subtitle = entry.subtitle, !subtitle.isEmpty {
                        Text(verbatim: "· \(subtitle)")
                            .foregroundStyle(.secondary)
                            .lineLimit(1)
                    }
                }
                .font(.subheadline)
            }

            Spacer(minLength: 0)

            if let workspace = entry.workspace, model.user?.showsWorkspace(of: entry) == true {
                WorkspaceDot(hex: workspace.colorHex, accessibilityName: language.localized("Workspace: %@", workspace.name))
            }

            if entry.reminderId == nil, entry.url != nil {
                Image(systemName: "arrow.up.right.square")
                    .foregroundStyle(.tertiary)
                    .accessibilityHidden(true)
            }
        }
    }

    /// "All day", "14:00" or "14:00 – 15:30".
    private var timeText: String {
        if entry.isAllDay {
            return language.localized("All day")
        }

        let style = Date.FormatStyle(date: .omitted, time: .shortened, locale: language.locale, timeZone: timeZone)
        let start = entry.startsAt.formatted(style)

        guard let endsAt = entry.endsAt, endsAt > entry.startsAt else {
            return start
        }

        return "\(start) – \(endsAt.formatted(style))"
    }
}

extension CalendarSource {
    /// The source's color, as the web's calendar tokens.
    var color: Color {
        switch self {
        case .reminders: Color(red: 0x00 / 255, green: 0x62 / 255, blue: 0xCC / 255)
        case .events: Color(red: 0x89 / 255, green: 0x44 / 255, blue: 0xAB / 255)
        case .invoices: Color(red: 0xC9 / 255, green: 0x34 / 255, blue: 0x00 / 255)
        case .whatsapp: Color(red: 0x24 / 255, green: 0x8A / 255, blue: 0x3D / 255)
        case .shopping: Color(red: 0x58 / 255, green: 0x56 / 255, blue: 0xD6 / 255)
        case .google: Color(red: 0x03 / 255, green: 0x9B / 255, blue: 0xE5 / 255)
        }
    }

    var titleKey: String {
        switch self {
        case .reminders: "Reminders"
        case .events: "Events"
        case .invoices: "Invoices"
        case .whatsapp: "WhatsApp"
        case .shopping: "Shopping"
        case .google: "Google Calendar"
        }
    }
}
