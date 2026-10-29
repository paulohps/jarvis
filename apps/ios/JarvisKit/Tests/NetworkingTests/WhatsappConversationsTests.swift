import Foundation
import XCTest
@testable import Networking

final class WhatsappConversationsTests: XCTestCase {
    func testAConversationsPageCarriesTheChatState() throws {
        let page = try JSONCoding.decoder.decode(Page<WhatsappConversation>.self, from: Data("""
        {"data":[{"friendly_id":"wtgt_1","name":"Ana","is_group":false,"phone_number":"+5511911110000",
          "picture_url":"https://pps.whatsapp.net/v/ana.jpg","account":{"friendly_id":"wacc_1","nickname":"Personal"},
          "unread_count":2,"pinned":true,"priority":1,"priority_color":"#ff3b30",
          "tags":[{"friendly_id":"wtag_1","name":"Clientes","color":"#ff9500"}],
          "last_message":{"friendly_id":"wchm_1","from_me":true,"type":"conversation","sender":null,"body":"Oi Ana",
            "receipt":"read","revoked":false,"media":null,"sent_at":"2026-10-05T11:00:00+00:00"},
          "last_message_at":"2026-10-05T11:00:00+00:00"},
         {"friendly_id":"wtgt_2","name":"Family","is_group":true,"phone_number":null,"picture_url":null,
          "account":{"friendly_id":"wacc_1","nickname":"Personal"},"unread_count":0,"pinned":false,
          "priority":null,"priority_color":null,"tags":[],"last_message":null,"last_message_at":null}],
        "meta":{"current_page":1,"last_page":2}}
        """.utf8))

        let ana = try XCTUnwrap(page.data.first)

        XCTAssertEqual(page.data.map(\.name), ["Ana", "Family"])
        XCTAssertTrue(page.meta.hasMorePages)
        XCTAssertEqual(ana.unreadCount, 2)
        XCTAssertTrue(ana.pinned)
        XCTAssertEqual(ana.priorityColor, "#ff3b30")
        XCTAssertEqual(ana.tags.map(\.name), ["Clientes"])
        XCTAssertEqual(ana.lastMessage?.receipt, .read)
        XCTAssertEqual(ana.initial, "A")
        XCTAssertTrue(page.data[1].isGroup)
        XCTAssertNil(page.data[1].lastMessage)
    }

    func testAMessagesPageCarriesTheMediaAndWhatIsStillGoingOut() throws {
        let page = try JSONCoding.decoder.decode(WhatsappConversationMessagesPage.self, from: Data("""
        {"data":[{"friendly_id":"wchm_2","from_me":false,"type":"audioMessage","sender":"Carla","body":null,
            "receipt":null,"revoked":false,"media":{"kind":"audio","mime":"audio/ogg","name":null,"size":1000,
            "seconds":7,"waveform":[10,50,100],"thumbnail":null,
            "url":"https://jarvis.test/api/v1/whatsapp/media/wchm_2?expires=1&signature=abc"},
            "sent_at":"2026-10-05T11:00:00+00:00"},
          {"friendly_id":"wchm_1","from_me":true,"type":"conversation","sender":null,"body":"Oi",
            "receipt":"something_new","revoked":true,"media":null,"sent_at":"2026-10-05T10:00:00+00:00"}],
         "outgoing":[{"account":{"friendly_id":"wacc_1","nickname":"Personal"},
           "target":{"friendly_id":"wtgt_1","name":"Ana","type":"contact","phone_number":null,"is_synced":true},
           "message_id":null,"body":"Tudo certo!","source":"chat","status":"pending","error_message":null,
           "scheduled_for":null,"sent_at":null,"created_at":"2026-10-05T11:01:00+00:00"}],
         "meta":{"has_more":true}}
        """.utf8))

        let audio = try XCTUnwrap(page.data.first?.media)

        XCTAssertEqual(audio.kind, .audio)
        XCTAssertEqual(audio.seconds, 7)
        XCTAssertEqual(audio.waveform, [10, 50, 100])
        XCTAssertEqual(audio.url?.host(), "jarvis.test")
        XCTAssertEqual(page.data.first?.sender, "Carla")
        // An unknown receipt shows no ticks instead of failing the page.
        XCTAssertNil(page.data.last?.receipt)
        XCTAssertTrue(try XCTUnwrap(page.data.last).revoked)
        XCTAssertEqual(page.outgoing.map(\.body), ["Tudo certo!"])
        XCTAssertTrue(page.meta.hasMore)
    }

    func testTheEndpointsGoToTheConversationPaths() throws {
        XCTAssertEqual(API.whatsappConversations(search: "  ana ").query.map(\.name), ["page", "per_page", "search"])
        XCTAssertEqual(API.whatsappConversations(search: "  ").query.map(\.name), ["page", "per_page"])
        XCTAssertEqual(API.whatsappConversationMessages(chatId: "wtgt_1", before: "wchm_9").query, [URLQueryItem(name: "before", value: "wchm_9")])
        XCTAssertEqual(API.markWhatsappConversationRead(chatId: "wtgt_1").path, "/whatsapp/conversations/wtgt_1/read")
        XCTAssertEqual(API.whatsappConversationTyping(chatId: "wtgt_1").method, .post)

        let send = API.sendWhatsappConversationText(chatId: "wtgt_1", text: " Oi! \n")
        let json = try XCTUnwrap(JSONSerialization.jsonObject(with: XCTUnwrap(send.body)) as? [String: Any])

        XCTAssertEqual(send.path, "/whatsapp/conversations/wtgt_1/messages")
        XCTAssertEqual(json["text"] as? String, "Oi!")
    }

    func testTheTimelineKeepsTheMessagesOldestFirstAcrossPolls() {
        var timeline = WhatsappConversationTimeline()

        timeline.mergeLatest(WhatsappConversationMessagesPage(data: [message("m3", minute: 3), message("m2", minute: 2)], hasMore: true))
        XCTAssertEqual(timeline.messages.map(\.id), ["m2", "m3"])
        XCTAssertTrue(timeline.hasOlder)
        XCTAssertEqual(timeline.oldestId, "m2")

        // A poll brings a new message and new ticks on one already shown.
        timeline.mergeLatest(WhatsappConversationMessagesPage(data: [message("m4", minute: 4, fromMe: false), message("m3", minute: 3, receipt: .read)], hasMore: true))
        XCTAssertEqual(timeline.messages.map(\.id), ["m2", "m3", "m4"])
        XCTAssertEqual(timeline.messages[1].receipt, .read)
        XCTAssertEqual(timeline.newestReceivedId, "m4")
        // Polling never forgets that older messages exist.
        XCTAssertTrue(timeline.hasOlder)

        timeline.mergeOlder(WhatsappConversationMessagesPage(data: [message("m1", minute: 1)], hasMore: false))
        XCTAssertEqual(timeline.messages.map(\.id), ["m1", "m2", "m3", "m4"])
        XCTAssertFalse(timeline.hasOlder)
    }

    func testTypingIsToldAtMostOncePerInterval() {
        var throttle = WhatsappTypingThrottle(interval: 4)
        let start = Date(timeIntervalSince1970: 1_000)

        XCTAssertTrue(throttle.shouldSend(at: start))
        XCTAssertFalse(throttle.shouldSend(at: start.addingTimeInterval(1)))
        XCTAssertFalse(throttle.shouldSend(at: start.addingTimeInterval(3.9)))
        XCTAssertTrue(throttle.shouldSend(at: start.addingTimeInterval(4)))

        throttle.reset()
        XCTAssertTrue(throttle.shouldSend(at: start.addingTimeInterval(5)))
    }

    func testHexColorsOfTheApi() throws {
        let orange = try XCTUnwrap(HexColor("#ff9500"))

        XCTAssertEqual(orange.red, 1)
        XCTAssertEqual(orange.green, 149.0 / 255, accuracy: 0.0001)
        XCTAssertEqual(orange.blue, 0)
        XCTAssertNil(HexColor("orange"))
        XCTAssertNil(HexColor("#fff"))
    }

    private func message(_ id: String, minute: Int, fromMe: Bool = true, receipt: WhatsappConversationMessage.Receipt? = .sent) -> WhatsappConversationMessage {
        WhatsappConversationMessage(
            friendlyId: id,
            fromMe: fromMe,
            body: "line \(minute)",
            receipt: fromMe ? receipt : nil,
            sentAt: Date(timeIntervalSince1970: TimeInterval(minute * 60))
        )
    }
}
