import Foundation

/// The views of the agenda, as on the web.
public enum AgendaView: String, CaseIterable, Sendable {
    case day
    case week
    case month
    case schedule
}

/// The period an agenda view shows around a day, in the user's timezone:
/// the day; its week from Sunday; the six weeks of its month's grid; or
/// AgendaPeriod.scheduleDays from the day on, as the web's schedule.
public struct AgendaPeriod: Equatable, Sendable {
    public static let scheduleDays = 30

    /// Midnight of the first day, and midnight after the last one.
    public let start: Date
    public let end: Date

    public init(start: Date, end: Date) {
        self.start = start
        self.end = end
    }

    public init(_ view: AgendaView, around day: Date, calendar: Calendar) {
        let midnight = calendar.startOfDay(for: day)

        switch view {
        case .day:
            self.init(start: midnight, end: calendar.date(byAdding: .day, value: 1, to: midnight) ?? midnight)
        case .week:
            let weekday = calendar.component(.weekday, from: midnight)
            let sunday = calendar.date(byAdding: .day, value: -(weekday - 1), to: midnight) ?? midnight
            self.init(start: sunday, end: calendar.date(byAdding: .day, value: 7, to: sunday) ?? sunday)
        case .month:
            let month = AgendaMonth(containing: day, calendar: calendar)
            self.init(start: month.start, end: month.end)
        case .schedule:
            self.init(start: midnight, end: calendar.date(byAdding: .day, value: Self.scheduleDays, to: midnight) ?? midnight)
        }
    }

    /// Each day of the period, at its midnight.
    public func days(in calendar: Calendar) -> [Date] {
        var days: [Date] = []
        var day = start

        while day < end {
            days.append(day)
            day = calendar.date(byAdding: .day, value: 1, to: day) ?? end
        }

        return days
    }

    /// The day `steps` periods before or after `day`: a day, a week, a month
    /// or AgendaPeriod.scheduleDays.
    public static func step(_ view: AgendaView, from day: Date, by steps: Int, calendar: Calendar) -> Date {
        switch view {
        case .day: calendar.date(byAdding: .day, value: steps, to: day) ?? day
        case .week: calendar.date(byAdding: .day, value: 7 * steps, to: day) ?? day
        case .month: calendar.date(byAdding: .month, value: steps, to: day) ?? day
        case .schedule: calendar.date(byAdding: .day, value: scheduleDays * steps, to: day) ?? day
        }
    }
}

/// The month on the agenda's calendar: six weeks from the Sunday on or
/// before its first day, as the web shows it.
public struct AgendaMonth: Equatable, Sendable {
    /// Midnight of the first day of the month.
    public let firstDay: Date
    public let calendar: Calendar

    public init(containing date: Date, calendar: Calendar) {
        self.calendar = calendar
        firstDay = calendar.date(from: calendar.dateComponents([.year, .month], from: date)) ?? calendar.startOfDay(for: date)
    }

    /// The first day on the grid: the Sunday on or before the first day.
    public var start: Date {
        let weekday = calendar.component(.weekday, from: firstDay)

        return calendar.date(byAdding: .day, value: -(weekday - 1), to: firstDay) ?? firstDay
    }

    /// The 42 days on the grid.
    public var days: [Date] {
        (0..<42).compactMap { calendar.date(byAdding: .day, value: $0, to: start) }
    }

    /// Midnight after the last day on the grid.
    public var end: Date {
        calendar.date(byAdding: .day, value: 42, to: start) ?? start
    }

    public func contains(_ day: Date) -> Bool {
        calendar.isDate(day, equalTo: firstDay, toGranularity: .month)
    }
}

/// A timed item on the day and week grids: where it starts and how long it
/// shows, in minutes of the day, and its lane among the items it overlaps.
public struct TimelineSlot: Equatable, Sendable, Identifiable {
    public let entry: CalendarEntry
    public let top: Int
    public let height: Int
    public let lane: Int
    public let lanes: Int

    public var id: String { entry.key }
}

/// The timed items of one day on the day and week grids, as the web's
/// DayTimeline draws them (Google Calendar's way): each at its start, as tall
/// as it lasts (a moment as `minMinutes`), and the ones that overlap side by
/// side — every group of overlapping items split into as many lanes as it
/// needs, each item in the first lane free at its start. An item that runs
/// past midnight is cut at the end of the day.
public enum DayTimeline {
    public static let minMinutes = 30
    public static let dayMinutes = 1440

    public static func layout(_ entries: [CalendarEntry], on day: Date, calendar: Calendar) -> [TimelineSlot] {
        let midnight = calendar.startOfDay(for: day)

        let placed = entries.filter { !$0.isAllDay }.map { entry -> (entry: CalendarEntry, top: Int, height: Int) in
            let top = min(max(0, Int(entry.startsAt.timeIntervalSince(midnight) / 60)), dayMinutes - minMinutes)
            let lasts = entry.endsAt.map { Int($0.timeIntervalSince(entry.startsAt) / 60) } ?? 0

            return (entry, top, min(max(lasts, minMinutes), dayMinutes - top))
        }
        .sorted { ($0.top, -$0.height) < ($1.top, -$1.height) }

        var laid: [TimelineSlot] = []
        var group: [(entry: CalendarEntry, top: Int, height: Int, lane: Int)] = []
        var groupEnd = 0
        var laneEnds: [Int] = []

        func closeGroup() {
            laid += group.map { TimelineSlot(entry: $0.entry, top: $0.top, height: $0.height, lane: $0.lane, lanes: max(1, laneEnds.count)) }
            group = []
            laneEnds = []
        }

        for item in placed {
            if !group.isEmpty, item.top >= groupEnd {
                closeGroup()
            }

            let lane = laneEnds.firstIndex { $0 <= item.top } ?? laneEnds.count

            if lane == laneEnds.count {
                laneEnds.append(item.top + item.height)
            } else {
                laneEnds[lane] = item.top + item.height
            }

            groupEnd = group.isEmpty ? item.top + item.height : max(groupEnd, item.top + item.height)
            group.append((item.entry, item.top, item.height, lane))
        }

        closeGroup()

        return laid
    }
}
