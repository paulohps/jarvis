import Foundation
import XCTest
@testable import Networking

final class AgendaLayoutTests: XCTestCase {
    private let calendar: Calendar = {
        var calendar = Calendar(identifier: .gregorian)
        calendar.timeZone = TimeZone(identifier: "America/Sao_Paulo")!
        return calendar
    }()

    private func date(_ text: String) -> Date {
        JSONCoding.parseDate(text)!
    }

    private func entry(_ key: String, _ startsAt: String, _ endsAt: String? = nil, isAllDay: Bool = false) -> CalendarEntry {
        CalendarEntry(key: key, source: "events", title: key, startsAt: date(startsAt), endsAt: endsAt.map(date), isAllDay: isAllDay)
    }

    func testEachViewCoversItsPeriodInTheUsersTimezone() {
        // Tuesday, October 6, 2026, 15:00 in São Paulo.
        let day = date("2026-10-06T18:00:00+00:00")

        let today = AgendaPeriod(.day, around: day, calendar: calendar)
        XCTAssertEqual(today.start, date("2026-10-06T03:00:00+00:00"))
        XCTAssertEqual(today.end, date("2026-10-07T03:00:00+00:00"))

        // From Sunday.
        let week = AgendaPeriod(.week, around: day, calendar: calendar)
        XCTAssertEqual(week.start, date("2026-10-04T03:00:00+00:00"))
        XCTAssertEqual(week.days(in: calendar).count, 7)

        // The six weeks of the month's grid.
        let month = AgendaPeriod(.month, around: day, calendar: calendar)
        XCTAssertEqual(month.start, date("2026-09-27T03:00:00+00:00"))
        XCTAssertEqual(month.days(in: calendar).count, 42)

        let schedule = AgendaPeriod(.schedule, around: day, calendar: calendar)
        XCTAssertEqual(schedule.days(in: calendar).count, AgendaPeriod.scheduleDays)

        XCTAssertEqual(AgendaPeriod.step(.week, from: day, by: -1, calendar: calendar), date("2026-09-29T18:00:00+00:00"))
        XCTAssertEqual(AgendaPeriod.step(.month, from: day, by: 1, calendar: calendar), date("2026-11-06T18:00:00+00:00"))
    }

    func testOverlappingItemsShareTheHourSideBySide() {
        let day = date("2026-10-06T03:00:00+00:00")
        let slots = DayTimeline.layout([
            // 09:00–10:30 and 09:30–10:00 overlap; 11:00 is alone; a moment takes the minimum.
            entry("long", "2026-10-06T12:00:00+00:00", "2026-10-06T13:30:00+00:00"),
            entry("short", "2026-10-06T12:30:00+00:00", "2026-10-06T13:00:00+00:00"),
            entry("alone", "2026-10-06T14:00:00+00:00"),
            entry("holiday", "2026-10-06T03:00:00+00:00", isAllDay: true),
        ], on: day, calendar: calendar)

        let byKey = Dictionary(uniqueKeysWithValues: slots.map { ($0.entry.key, $0) })

        // All-day items stay above the grid.
        XCTAssertNil(byKey["holiday"])
        XCTAssertEqual(byKey["long"]?.top, 9 * 60)
        XCTAssertEqual(byKey["long"]?.height, 90)
        XCTAssertEqual(byKey["long"].map { [$0.lane, $0.lanes] }, [0, 2])
        XCTAssertEqual(byKey["short"].map { [$0.lane, $0.lanes] }, [1, 2])
        XCTAssertEqual(byKey["alone"].map { [$0.top, $0.height, $0.lane, $0.lanes] }, [11 * 60, DayTimeline.minMinutes, 0, 1])
    }

    func testAnItemPastMidnightIsCutAtTheEndOfTheDay() {
        let day = date("2026-10-06T03:00:00+00:00")
        let slot = DayTimeline.layout([entry("party", "2026-10-07T01:00:00+00:00", "2026-10-07T05:00:00+00:00")], on: day, calendar: calendar).first

        // 22:00 until midnight.
        XCTAssertEqual(slot?.top, 22 * 60)
        XCTAssertEqual(slot?.height, 120)
    }
}
