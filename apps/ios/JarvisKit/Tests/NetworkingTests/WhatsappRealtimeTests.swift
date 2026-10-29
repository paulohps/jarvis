import Foundation
import XCTest
@testable import Networking

final class WhatsappRealtimeTests: XCTestCase {
    func testTheBroadcastsDecode() throws {
        let updated = try JSONCoding.decoder.decode(WhatsappChatUpdatedEvent.self, from: Data(#"{"account_id":"wacc_1","chat_id":"wtgt_1"}"#.utf8))
        let presence = try JSONCoding.decoder.decode(WhatsappChatPresenceEvent.self, from: Data("""
        {"account_id":"wacc_1","chat_id":"wtgt_1","presence":"unavailable","who":null,"last_seen":"2026-10-05T11:00:00+00:00"}
        """.utf8))

        XCTAssertEqual(updated, WhatsappChatUpdatedEvent(accountId: "wacc_1", chatId: "wtgt_1"))
        XCTAssertEqual(presence.presence, "unavailable")
        XCTAssertNil(presence.who)
        XCTAssertEqual(presence.lastSeen, Date(timeIntervalSince1970: 1_791_198_000))
    }

    func testTypingFadesWithoutNewsAndOnlineLastsLonger() {
        var book = WhatsappPresenceBook()
        let start = Date(timeIntervalSince1970: 1_000)

        XCTAssertNil(book.status(of: "wtgt_1", at: start))

        book.receive(WhatsappChatPresenceEvent(accountId: "a", chatId: "wtgt_1", presence: "composing"), at: start)

        XCTAssertEqual(book.status(of: "wtgt_1", at: start.addingTimeInterval(5)), .typing(who: nil))
        XCTAssertTrue(book.status(of: "wtgt_1", at: start.addingTimeInterval(5))?.isActivity == true)
        // Typing stopped being repeated: still online for a while.
        XCTAssertEqual(book.status(of: "wtgt_1", at: start.addingTimeInterval(11)), .online)
        XCTAssertNil(book.status(of: "wtgt_1", at: start.addingTimeInterval(301)))

        book.receive(WhatsappChatPresenceEvent(accountId: "a", chatId: "wtgt_1", presence: "recording"), at: start)
        XCTAssertEqual(book.status(of: "wtgt_1", at: start), .recording(who: nil))
    }

    func testGoneKeepsTheLastSeenAndAGroupMemberSaysNothingOfTheGroup() {
        var book = WhatsappPresenceBook()
        let seen = Date(timeIntervalSince1970: 500)
        let now = Date(timeIntervalSince1970: 1_000)

        book.receive(WhatsappChatPresenceEvent(accountId: "a", chatId: "wtgt_1", presence: "unavailable", lastSeen: seen), at: now)
        XCTAssertEqual(book.status(of: "wtgt_1", at: now), .lastSeen(seen))

        // A later presence without a last seen keeps the one known.
        book.receive(WhatsappChatPresenceEvent(accountId: "a", chatId: "wtgt_1", presence: "unavailable"), at: now)
        XCTAssertEqual(book.status(of: "wtgt_1", at: now), .lastSeen(seen))

        book.receive(WhatsappChatPresenceEvent(accountId: "a", chatId: "wtgt_g", presence: "composing", who: "Carla"), at: now)
        XCTAssertEqual(book.status(of: "wtgt_g", at: now), .typing(who: "Carla"))
        XCTAssertNil(book.status(of: "wtgt_g", at: now.addingTimeInterval(11)))
    }

    func testOnlyMessagesReceivedAfterTheFirstLookAreNews() throws {
        var detector = WhatsappIncomingDetector()

        XCTAssertEqual(detector.newlyReceived(in: try chats([("wtgt_1", "m1", false), ("wtgt_2", "m2", false)])), [])
        XCTAssertTrue(detector.isPrimed)

        // Ana wrote again, Bruno is unchanged, the number answered Carla, Dani is new to the list.
        let news = detector.newlyReceived(in: try chats([
            ("wtgt_1", "m3", false), ("wtgt_2", "m2", false), ("wtgt_3", "m4", true), ("wtgt_4", "m5", false),
        ]))

        XCTAssertEqual(news.map(\.friendlyId), ["wtgt_4", "wtgt_1"])

        // Nothing new: no news.
        XCTAssertEqual(detector.newlyReceived(in: try chats([("wtgt_1", "m3", false)])), [])

        detector.reset()
        XCTAssertEqual(detector.newlyReceived(in: try chats([("wtgt_1", "m9", false)])), [])
    }

    /// Chats whose latest message is `(chat, message, fromMe)`, the later ones sent later.
    private func chats(_ rows: [(String, String, Bool)]) throws -> [WhatsappConversation] {
        let items = rows.enumerated().map { index, row in
            """
            {"friendly_id":"\(row.0)","name":"\(row.0)","is_group":false,"phone_number":null,"picture_url":null,
             "account":{"friendly_id":"wacc_1","nickname":"Personal"},"unread_count":0,"pinned":false,
             "priority":null,"priority_color":null,"tags":[],
             "last_message":{"friendly_id":"\(row.1)","from_me":\(row.2),"type":"conversation","sender":null,"body":"Oi",
               "receipt":null,"revoked":false,"media":null,"sent_at":"2026-10-05T11:0\(index):00+00:00"},
             "last_message_at":"2026-10-05T11:0\(index):00+00:00"}
            """
        }

        return try JSONCoding.decoder.decode([WhatsappConversation].self, from: Data("[\(items.joined(separator: ","))]".utf8))
    }
}
