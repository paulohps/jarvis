import Foundation
import Networking
import Observation

/// The household's agenda as on the web (US-9.1): a day, a week or a month
/// of everything scheduled — reminders, events, invoices, WhatsApp messages,
/// shopping lists and Google calendars — the next 30 days of it as a
/// schedule, or the tasks of one filter and how many each filter has; "Only
/// mine" narrows them to the reminders and events the user is responsible
/// for. Plus completing, reopening and deleting.
@MainActor
@Observable
final class RemindersStore {
    enum Mode: Hashable, Sendable {
        /// A view of the calendar.
        case calendar(AgendaView)
        /// The tasks under a filter.
        case list

        static let all: [Mode] = AgendaView.allCases.map(Mode.calendar) + [.list]
    }

    var mode: Mode = .calendar(.month)
    var filter: ReminderFilter = .pending
    private(set) var isMine = false

    /// The day the calendar is on, at its midnight.
    private(set) var selectedDay: Date

    /// The period whose items are loaded (or loading).
    private(set) var period: AgendaPeriod?

    /// The calendar's items of `period`, and the sources the user may see.
    private(set) var entries: [CalendarEntry]?
    private(set) var sources: [CalendarSource] = []

    /// The sources left off the calendar.
    private(set) var hiddenSources: Set<CalendarSource> = []

    private(set) var occurrences: [ReminderOccurrence]?
    private(set) var counts: ReminderOccurrencePage.Counts?
    private(set) var loadFailure: APIError?

    /// Who may be made responsible, for the form.
    private(set) var assignees: [UserSummary] = []

    /// Who may be made responsible in each workspace, for the form's picker.
    private(set) var assigneesByWorkspace: [String: [UserSummary]] = [:]

    /// The failure of the last change, for an alert.
    var actionFailure: APIError?

    @ObservationIgnored private let client: APIClient
    @ObservationIgnored let timeZone: TimeZone
    @ObservationIgnored let calendar: Calendar

    init(client: APIClient, timeZone: TimeZone, now: Date = .now) {
        var calendar = Calendar(identifier: .gregorian)
        calendar.timeZone = timeZone
        calendar.firstWeekday = 1

        self.client = client
        self.timeZone = timeZone
        self.calendar = calendar
        selectedDay = calendar.startOfDay(for: now)
    }

    /// The calendar view on screen; nil on the tasks.
    var view: AgendaView? {
        if case .calendar(let view) = mode {
            return view
        }

        return nil
    }

    /// The month of the day the calendar is on.
    var month: AgendaMonth {
        AgendaMonth(containing: selectedDay, calendar: calendar)
    }

    /// Whether the current view has something to show.
    var hasLoaded: Bool {
        switch mode {
        case .calendar: entries != nil
        case .list: occurrences != nil
        }
    }

    /// The calendar's items of a day, as the server orders them.
    func entries(on day: Date) -> [CalendarEntry] {
        (entries ?? []).filter { calendar.isDate($0.startsAt, inSameDayAs: day) }
    }

    func load() async {
        let requested = (mode, filter, isMine)
        let requestedHidden = hiddenSources

        do throws(APIError) {
            switch requested.0 {
            case .calendar(let view):
                let requestedPeriod = AgendaPeriod(view, around: selectedDay, calendar: calendar)
                period = requestedPeriod

                let page = try await client.send(API.agenda(
                    from: requestedPeriod.start,
                    to: requestedPeriod.end,
                    mine: requested.2,
                    hidden: CalendarSource.allCases.filter(requestedHidden.contains)
                ))

                // Another period, view or source meanwhile wins.
                guard requested == (mode, filter, isMine), requestedPeriod == period, requestedHidden == hiddenSources else {
                    return
                }

                entries = page.data
                sources = page.meta.sources.compactMap(CalendarSource.init(rawValue:))
                assignees = page.meta.assignees ?? assignees
                assigneesByWorkspace = page.meta.assigneesByWorkspace?.values ?? assigneesByWorkspace
            case .list:
                let page = try await client.send(API.reminderOccurrences(filter: requested.1, mine: requested.2))

                // A tap on another filter meanwhile wins.
                guard requested == (mode, filter, isMine) else {
                    return
                }

                occurrences = page.data
                counts = page.meta.counts
                assignees = page.meta.assignees ?? []
                assigneesByWorkspace = page.meta.assigneesByWorkspace?.values ?? [:]
            }

            loadFailure = nil
        } catch .unauthenticated {
            // The shell already went back to the login.
        } catch {
            if !hasLoaded {
                loadFailure = error
            }
        }
    }

    /// The period before or after: a day, a week, a month or 30 days.
    func step(by steps: Int) async {
        guard let view else {
            return
        }

        await show(day: AgendaPeriod.step(view, from: selectedDay, by: steps, calendar: calendar))
    }

    /// Today, in the current view.
    func showToday() async {
        await show(day: .now)
    }

    /// A day in the current view; another period is loaded from scratch.
    func show(day: Date) async {
        selectedDay = calendar.startOfDay(for: day)

        guard let view, AgendaPeriod(view, around: selectedDay, calendar: calendar) != period else {
            return
        }

        entries = nil
        loadFailure = nil
        await load()
    }

    /// A day on the day view, as a tap on it in the week or month.
    func open(day: Date) async {
        selectedDay = calendar.startOfDay(for: day)
        await show(Mode.calendar(.day))
    }

    /// A source on or off the calendar.
    func toggle(_ source: CalendarSource) async {
        if hiddenSources.contains(source) {
            hiddenSources.remove(source)
        } else {
            hiddenSources.insert(source)
        }

        entries = nil
        loadFailure = nil
        await load()
    }

    /// Completes or reopens the occurrence of a calendar item.
    func toggleCompletion(of entry: CalendarEntry) async {
        guard let occurrenceId = entry.occurrenceId else {
            return
        }

        do throws(APIError) {
            if entry.isCompleted {
                try await client.send(API.reopenOccurrence(id: occurrenceId))
            } else {
                try await client.send(API.completeOccurrence(id: occurrenceId))
            }
        } catch {
            actionFailure = error
        }

        await load()
    }

    /// The reminder or event of a calendar item, to edit it.
    func reminder(of entry: CalendarEntry) async -> Reminder? {
        guard let reminderId = entry.reminderId else {
            return nil
        }

        do throws(APIError) {
            let page = try await client.send(API.reminder(id: reminderId))

            if let assignees = page.meta.assignees {
                self.assignees = assignees
            }

            if let byWorkspace = page.meta.assigneesByWorkspace {
                assigneesByWorkspace = byWorkspace.values
            }

            return page.data
        } catch {
            actionFailure = error

            return nil
        }
    }

    /// Deletes the reminder or event of a calendar item.
    func deleteReminder(of entry: CalendarEntry) async {
        guard let reminderId = entry.reminderId else {
            return
        }

        do throws(APIError) {
            try await client.send(API.deleteReminder(id: reminderId))
            entries?.removeAll { $0.reminderId == reminderId }
        } catch {
            actionFailure = error
        }

        await load()
    }

    /// Another filter: its list from scratch.
    func show(_ newFilter: ReminderFilter) async {
        filter = newFilter
        occurrences = nil
        loadFailure = nil
        await load()
    }

    /// A view of the calendar or the tasks, from scratch.
    func show(_ newMode: Mode) async {
        mode = newMode
        period = nil
        entries = nil
        occurrences = nil
        loadFailure = nil
        await load()
    }

    /// "Only mine" on or off: every view from scratch.
    func showOnlyMine(_ isOn: Bool) async {
        isMine = isOn
        entries = nil
        occurrences = nil
        counts = nil
        loadFailure = nil
        await load()
    }

    /// Completes an open occurrence or reopens a completed one; a repeating
    /// reminder gets its next occurrence from the server.
    func toggleCompletion(of occurrence: ReminderOccurrence) async {
        do throws(APIError) {
            if occurrence.isCompleted {
                try await client.send(API.reopenOccurrence(id: occurrence.friendlyId))
            } else {
                try await client.send(API.completeOccurrence(id: occurrence.friendlyId))
            }
        } catch {
            actionFailure = error
        }

        await load()
    }

    func delete(_ reminder: Reminder) async {
        do throws(APIError) {
            try await client.send(API.deleteReminder(id: reminder.friendlyId))
            occurrences?.removeAll { $0.reminder.friendlyId == reminder.friendlyId }
        } catch {
            actionFailure = error
        }

        await load()
    }
}
