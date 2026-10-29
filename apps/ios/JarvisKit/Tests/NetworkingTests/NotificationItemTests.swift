import Foundation
import XCTest
@testable import Networking

final class NotificationItemTests: XCTestCase {
    func testTheSanitizedHtmlBodyIsShownAsPlainText() {
        let item = NotificationItem(id: "1", title: "Invoice sent", body: "<p>Invoice <strong>202610-001</strong> was sent.</p><p>Tom &amp; Ana</p>")

        XCTAssertEqual(item.plainBody, "Invoice 202610-001 was sent.\n\nTom & Ana")
        XCTAssertNil(NotificationItem(id: "2", title: "x", body: "<p> </p>").plainBody)
        XCTAssertNil(NotificationItem(id: "3", title: "x", body: nil).plainBody)
    }

    func testThePageAndTheRealtimeEventAreRead() throws {
        let page = try JSONCoding.decoder.decode(NotificationPage.self, from: Data("""
        {"data":[{"id":"9b1c","title":"Reminder: milk","body":"<p>Due today.</p>","url":"https://jarvis.test/reminders",
        "actions":[{"label":"Open","url":"https://jarvis.test/reminders"}],"is_read":false,"created_at":"2026-10-06T12:00:00+00:00"}],
        "links":{"first":null,"last":null,"prev":null,"next":null},
        "meta":{"current_page":1,"last_page":2,"per_page":20,"total":21,"from":1,"to":20,"unread_count":5}}
        """.utf8))

        XCTAssertEqual(page.data.first?.title, "Reminder: milk")
        XCTAssertEqual(page.meta.unreadCount, 5)
        XCTAssertTrue(page.hasMorePages)

        let event = try JSONCoding.decoder.decode(NotificationCreatedEvent.self, from: Data(#"{"id":"9b1c","title":"Reminder: milk","unread_count":6}"#.utf8))
        XCTAssertEqual(event.unreadCount, 6)
    }
}
