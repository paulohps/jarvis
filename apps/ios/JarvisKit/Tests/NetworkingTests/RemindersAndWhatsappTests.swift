import Foundation
import XCTest
@testable import Networking

final class RemindersAndWhatsappTests: XCTestCase {
    private let saoPaulo = TimeZone(identifier: "America/Sao_Paulo")!

    func testTheRemindersPageCarriesTheCountsOfEveryFilter() throws {
        let page = try JSONCoding.decoder.decode(ReminderOccurrencePage.self, from: Data("""
        {"data":[{"friendly_id":"remo_1","due_at":"2026-10-06T12:00:00+00:00","is_completed":false,"is_overdue":true,
          "completed_at":null,"completed_by":null,
          "reminder":{"friendly_id":"rem_1","title":"Pay rent","note":null,"is_recurring":true,
            "recurrence":{"frequency":"weekly","time_of_day":"09:00","weekdays":["monday","friday"],"day_of_month":null,
              "is_last_day_of_month":false,"cron_expression":null,"timezone":"America/Sao_Paulo",
              "starts_at":"2026-10-01T12:00:00+00:00","ends_at":null,"next_run_at":"2026-10-09T12:00:00+00:00","is_paused":false},
            "created_by":{"friendly_id":"usr_1","name":"Ana"},"created_at":null,"updated_at":null}}],
        "meta":{"filter":"overdue","counts":{"pending":4,"overdue":1,"completed":12},"timezone":"America/Sao_Paulo"}}
        """.utf8))

        XCTAssertEqual(page.meta.counts.count(of: .pending), 4)
        XCTAssertEqual(page.meta.counts.count(of: .overdue), 1)
        XCTAssertEqual(page.meta.counts.count(of: .completed), 12)
        let recurrence = try XCTUnwrap(page.data.first?.reminder.recurrence)
        XCTAssertEqual(recurrence.frequency, .weekly)
        XCTAssertEqual(recurrence.weekdays, [.monday, .friday])
        XCTAssertEqual(recurrence.timeOfDay, "09:00")
    }

    func testAReminderIsSentWholeWithTheDateOnTheUsersWallClock() throws {
        let dueAt = try XCTUnwrap(JSONCoding.parseDate("2026-10-06T12:00:00+00:00"))
        let endpoint = API.updateReminder(
            id: "rem_1",
            ReminderInput(title: "Dentist", note: nil, dueAt: WallClock.dateTime(dueAt, in: saoPaulo), recurrence: nil, assignedTo: "usr_2")
        )
        let json = try XCTUnwrap(JSONSerialization.jsonObject(with: XCTUnwrap(endpoint.body)) as? [String: Any])

        XCTAssertEqual(endpoint.method, .patch)
        XCTAssertEqual(json["title"] as? String, "Dentist")
        // Without an offset: the server reads it in the user's timezone, as the web form sends it.
        XCTAssertEqual(json["due_at"] as? String, "2026-10-06T09:00")
        // An edit replaces the whole reminder, so the cleared fields go as null.
        XCTAssertTrue(json["note"] is NSNull)
        XCTAssertTrue(json["recurrence"] is NSNull)
        XCTAssertEqual(json["assigned_to"] as? String, "usr_2")
    }

    func testNobodyResponsibleIsSentAsNull() throws {
        let input = ReminderInput(title: "Shared chore", note: nil, dueAt: nil, recurrence: nil, assignedTo: nil)
        let json = try XCTUnwrap(JSONSerialization.jsonObject(with: XCTUnwrap(API.createReminder(input).body)) as? [String: Any])

        // Left out, the server would make it the creator's: null means nobody.
        XCTAssertTrue(json["assigned_to"] is NSNull)
    }

    func testOnlyMineAsksTheServerForTheUsersOwn() {
        XCTAssertEqual(API.reminderOccurrences(filter: .overdue, mine: true).query, [
            URLQueryItem(name: "filter", value: "overdue"),
            URLQueryItem(name: "mine", value: "1"),
        ])
        XCTAssertEqual(API.reminderOccurrences(filter: .pending).query, [URLQueryItem(name: "filter", value: "pending")])
        XCTAssertEqual(API.reminderAgenda(mine: true).path, "/reminders/agenda")
        XCTAssertEqual(API.reminderAgenda(mine: true).query, [URLQueryItem(name: "mine", value: "1")])
        XCTAssertEqual(API.reminderAgenda().query, [])
    }

    func testTheAgendaCarriesItsDaysAndTheProjectedRuns() throws {
        let page = try JSONCoding.decoder.decode(ReminderAgendaPage.self, from: Data("""
        {"data":[
          {"key":"overdue","date":null,"entries":[{"due_at":"2026-10-04T12:00:00+00:00","is_projected":false,"is_overdue":true,
            "occurrence":{"friendly_id":"remo_1","due_at":"2026-10-04T12:00:00+00:00","is_completed":false,"is_overdue":true,
              "completed_at":null,"completed_by":null,"reminder":{"friendly_id":"rem_1","title":"Pay rent","note":null,"is_recurring":false,
              "recurrence":null,"created_by":{"friendly_id":"usr_1","name":"Ana"},"assigned_to":{"friendly_id":"usr_2","name":"Paulo"}}},
            "reminder":{"friendly_id":"rem_1","title":"Pay rent","note":null,"is_recurring":false,"recurrence":null,
              "created_by":{"friendly_id":"usr_1","name":"Ana"},"assigned_to":{"friendly_id":"usr_2","name":"Paulo"}}}]},
          {"key":"2026-10-06","date":"2026-10-06","entries":[{"due_at":"2026-10-06T21:00:00+00:00","is_projected":true,"is_overdue":false,
            "occurrence":null,"reminder":{"friendly_id":"rem_2","title":"Feed the cat","note":null,"is_recurring":true,"recurrence":null,
              "created_by":{"friendly_id":"usr_1","name":"Ana"},"assigned_to":null}}]}],
        "meta":{"mine":false,"projection_days":14,"assignees":[{"friendly_id":"usr_1","name":"Ana"},{"friendly_id":"usr_2","name":"Paulo"}],
          "timezone":"America/Sao_Paulo"}}
        """.utf8))

        XCTAssertEqual(page.data.map(\.key), ["overdue", "2026-10-06"])
        XCTAssertNil(page.data[0].day(in: saoPaulo))
        XCTAssertEqual(page.data[0].entries[0].occurrence?.friendlyId, "remo_1")
        XCTAssertEqual(page.data[0].entries[0].reminder.assignedTo?.name, "Paulo")

        let projected = page.data[1].entries[0]
        XCTAssertTrue(projected.isProjected)
        XCTAssertNil(projected.occurrence)
        XCTAssertNil(projected.reminder.assignedTo)
        XCTAssertNotEqual(projected.id, page.data[0].entries[0].id)
        // The day at its midnight in the user's timezone (03:00 UTC in São Paulo).
        XCTAssertEqual(page.data[1].day(in: saoPaulo), JSONCoding.parseDate("2026-10-06T03:00:00+00:00"))
        XCTAssertEqual(page.meta.assignees.map(\.name), ["Ana", "Paulo"])
        XCTAssertEqual(page.meta.projectionDays, 14)
    }

    func testTheCalendarAsksForAPeriodAndCarriesEverySource() throws {
        let from = try XCTUnwrap(JSONCoding.parseDate("2026-09-27T03:00:00+00:00"))
        let to = try XCTUnwrap(JSONCoding.parseDate("2026-11-08T03:00:00+00:00"))
        let endpoint = API.agenda(from: from, to: to, mine: true, hidden: [.invoices, .google])

        XCTAssertEqual(endpoint.path, "/agenda")
        XCTAssertEqual(endpoint.query, [
            URLQueryItem(name: "from", value: "2026-09-27T03:00:00Z"),
            URLQueryItem(name: "to", value: "2026-11-08T03:00:00Z"),
            URLQueryItem(name: "mine", value: "1"),
            URLQueryItem(name: "hide[]", value: "invoices"),
            URLQueryItem(name: "hide[]", value: "google"),
        ])

        let page = try JSONCoding.decoder.decode(CalendarPage.self, from: Data("""
        {"data":[
          {"key":"remo_1","source":"reminders","title":"Pay the school","subtitle":null,"starts_at":"2026-10-06T13:00:00+00:00",
           "ends_at":null,"is_all_day":false,"is_projected":false,"is_completable":true,"is_completed":false,"is_overdue":false,
           "reminder_id":"rem_1","occurrence_id":"remo_1","url":null},
          {"key":"gev_1","source":"birthdays","title":"Someone new","subtitle":"Other","starts_at":"2026-10-07T03:00:00+00:00",
           "ends_at":null,"is_all_day":true,"is_projected":false,"is_completable":false,"is_completed":false,"is_overdue":false,
           "reminder_id":null,"occurrence_id":null,"url":"https://calendar.google.com/event?eid=1"}],
        "meta":{"from":"2026-09-27T03:00:00+00:00","to":"2026-11-08T03:00:00+00:00","mine":true,
          "sources":["reminders","events","google"],"timezone":"America/Sao_Paulo",
          "assignees":[{"friendly_id":"usr_1","name":"Ana"}]}}
        """.utf8))

        XCTAssertEqual(page.data[0].calendarSource, .reminders)
        XCTAssertEqual(page.data[0].occurrenceId, "remo_1")
        XCTAssertTrue(page.data[0].isCompletable)
        // A source this app does not know yet still decodes, without a color of its own.
        XCTAssertNil(page.data[1].calendarSource)
        XCTAssertTrue(page.data[1].isAllDay)
        XCTAssertEqual(page.meta.sources, ["reminders", "events", "google"])
        XCTAssertEqual(page.meta.assignees?.map(\.name), ["Ana"])
    }

    func testAnEventIsSentWithItsKindAndDuration() throws {
        let input = ReminderInput(
            title: "Concert", note: nil, dueAt: "2026-10-09T20:00", recurrence: nil, assignedTo: nil,
            kind: .event, isAllDay: false, durationMinutes: 120
        )
        let json = try XCTUnwrap(JSONSerialization.jsonObject(with: XCTUnwrap(API.createReminder(input).body)) as? [String: Any])

        XCTAssertEqual(json["kind"] as? String, "event")
        XCTAssertEqual(json["is_all_day"] as? Bool, false)
        XCTAssertEqual(json["duration_minutes"] as? Int, 120)

        let reminder = try JSONCoding.decoder.decode(ReminderPage.self, from: Data("""
        {"data":{"friendly_id":"rem_1","title":"Concert","note":null,"kind":"event","is_all_day":false,"duration_minutes":120,
          "is_recurring":false,"recurrence":null,"created_by":{"friendly_id":"usr_1","name":"Ana"},"assigned_to":null},
        "meta":{"timezone":"America/Sao_Paulo","assignees":[]}}
        """.utf8)).data

        XCTAssertTrue(reminder.isEvent)
        XCTAssertEqual(reminder.durationMinutes, 120)
        XCTAssertEqual(API.reminder(id: "rem_1").path, "/reminders/rem_1")
    }

    func testARepeatingScheduleIsSentWithItsPresetFields() throws {
        let input = ReminderInput(
            title: "Gym",
            note: nil,
            dueAt: nil,
            recurrence: RecurrenceInput(frequency: .customCron, cronExpression: "0 7 * * 1-5", endsAt: "2026-12-31T23:00"),
            assignedTo: nil
        )
        let json = try XCTUnwrap(JSONSerialization.jsonObject(with: XCTUnwrap(API.createReminder(input).body)) as? [String: Any])
        let recurrence = try XCTUnwrap(json["recurrence"] as? [String: Any])

        XCTAssertEqual(recurrence["frequency"] as? String, "custom_cron")
        XCTAssertEqual(recurrence["cron_expression"] as? String, "0 7 * * 1-5")
        XCTAssertEqual(recurrence["ends_at"] as? String, "2026-12-31T23:00")
        XCTAssertNil(recurrence["time_of_day"])
    }

    func testAPastScheduleShowsTheServersTranslatedMessage() async throws {
        let transport = StubTransport([(422, #"{"message":"A data e a hora precisam estar no futuro.","errors":{"recurrence.starts_at":["A data e a hora precisam estar no futuro."]}}"#)])
        let client = APIClient(baseURL: URL(string: "https://jarvis.test")!, tokenStore: InMemoryTokenStore(token: "1|t"), transport: transport)
        let input = WhatsappMessageInput(
            account: "wacc_1",
            targets: ["wtg_1"],
            numbers: [],
            body: "Hi {% recipient.name %}!",
            recurrence: RecurrenceInput(frequency: .once, startsAt: "2020-01-01T09:00")
        )

        do {
            try await client.send(API.scheduleWhatsappMessage(input))
            XCTFail("A 422 must throw.")
        } catch {
            XCTAssertEqual(error.displayMessage, "A data e a hora precisam estar no futuro.")
            XCTAssertEqual(error.firstError(for: "recurrence.starts_at"), "A data e a hora precisam estar no futuro.")
        }
    }

    func testSendingNowLeavesTheScheduleOut() throws {
        let input = WhatsappMessageInput(account: "wacc_1", targets: [], numbers: ["+5511999990000"], body: "Hi", recurrence: RecurrenceInput(frequency: .daily, timeOfDay: "09:00"))
        let endpoint = API.sendWhatsappNow(input)
        let json = try XCTUnwrap(JSONSerialization.jsonObject(with: XCTUnwrap(endpoint.body)) as? [String: Any])

        XCTAssertEqual(endpoint.path, "/whatsapp/send-now")
        XCTAssertEqual(json["numbers"] as? [String], ["+5511999990000"])
        XCTAssertNil(json["recurrence"])
    }

    func testTheMessageActionsFollowItsState() throws {
        func message(state: String, frequency: String) throws -> WhatsappMessage {
            try JSONCoding.decoder.decode(WhatsappMessage.self, from: Data("""
            {"friendly_id":"wmsg_1","account":{"friendly_id":"wacc_1","nickname":"Personal"},
            "targets":[{"friendly_id":"wtg_1","name":"Ana","type":"contact","phone_number":"+5511999990000","is_synced":true}],
            "body":"Hi","state":"\(state)",
            "recurrence":{"frequency":"\(frequency)","time_of_day":null,"weekdays":[],"day_of_month":null,"is_last_day_of_month":false,
              "cron_expression":null,"timezone":"UTC","starts_at":"2026-10-06T12:00:00+00:00","ends_at":null,"next_run_at":null,"is_paused":false},
            "cancelled_at":null,"created_at":null,"updated_at":null}
            """.utf8))
        }

        let once = try message(state: "active", frequency: "once")
        XCTAssertTrue(once.canBeCancelled)
        XCTAssertTrue(once.canBePaused)

        let daily = try message(state: "active", frequency: "daily")
        XCTAssertFalse(daily.canBeCancelled)

        let paused = try message(state: "paused", frequency: "daily")
        XCTAssertTrue(paused.canBeResumed)
        XCTAssertFalse(paused.canBePaused)

        let cancelled = try message(state: "cancelled", frequency: "once")
        XCTAssertFalse(cancelled.canBeEdited)
        XCTAssertFalse(cancelled.canBeCancelled)
    }

    func testASavedTimeOfDayIsReadOnTheUsersWallClock() throws {
        let day = try XCTUnwrap(JSONCoding.parseDate("2026-10-06T15:00:00+00:00"))
        let date = try XCTUnwrap(WallClock.date(atTime: "09:30", in: saoPaulo, on: day))

        XCTAssertEqual(WallClock.time(date, in: saoPaulo), "09:30")
        XCTAssertEqual(WallClock.dateTime(date, in: saoPaulo), "2026-10-06T09:30")
    }
}
