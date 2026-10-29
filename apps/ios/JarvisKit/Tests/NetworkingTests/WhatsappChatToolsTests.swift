import Foundation
import XCTest
@testable import Networking

final class WhatsappChatToolsTests: XCTestCase {
    func testTheChatDetailsCarryItsSettingsScheduledMessagesAndReminders() throws {
        let details = try JSONCoding.decoder.decode(WhatsappChatDetails.self, from: Data("""
        {"data":{"friendly_id":"wtgt_1","name":"Aninha","nickname":"Aninha","whatsapp_name":"Ana","is_group":false,
          "phone_number":"+5511911110000","picture_url":null,"account":{"friendly_id":"wacc_1","nickname":"Personal"},
          "unread_count":0,"pinned":false,"priority":2,"priority_color":"#ff9500",
          "tags":[{"friendly_id":"wtag_1","name":"Clientes","color":"#ff9500","color_name":"orange"}],
          "last_message":null,"last_message_at":null,
          "settings":{"sends_read_receipts":null,"sends_typing":true,"notifies_messages":false,
            "effective":{"sends_read_receipts":false,"sends_typing":true,"notifies_messages":false}}},
         "scheduled":[{"friendly_id":"wmsg_1","body":"Bom dia","next_run_at":"2026-10-08T09:00:00+00:00"}],
         "reminders":[{"friendly_id":"rmd_1","title":"Ligar","due_at":"2026-10-08T10:00:00+00:00"}]}
        """.utf8))

        XCTAssertEqual(details.data.nickname, "Aninha")
        XCTAssertEqual(details.data.whatsappName, "Ana")
        XCTAssertEqual(details.data.tags.first?.colorName, "orange")
        XCTAssertNil(details.data.settings?.sendsReadReceipts)
        XCTAssertEqual(details.data.settings?.sendsTyping, true)
        XCTAssertEqual(details.data.settings?.effective.notifiesMessages, false)
        XCTAssertEqual(details.scheduled.map(\.body), ["Bom dia"])
        XCTAssertEqual(details.reminders.map(\.title), ["Ligar"])
    }

    func testAnUpdateSendsOnlyWhatChangedAndNullToClear() throws {
        var update = WhatsappConversationUpdate()
        update.priority = .set(nil)
        update.tags = .set(["wtag_1"])
        update.sendsTyping = .set(false)

        let body = try XCTUnwrap(API.updateWhatsappConversation(chatId: "wtgt_1", update).body)
        let json = try XCTUnwrap(JSONSerialization.jsonObject(with: body) as? [String: Any])

        XCTAssertEqual(Set(json.keys), ["priority", "tags", "sends_typing"])
        XCTAssertTrue(json["priority"] is NSNull)
        XCTAssertEqual(json["tags"] as? [String], ["wtag_1"])
        XCTAssertEqual(json["sends_typing"] as? Bool, false)
    }

    func testThePriorityColorsKeepThePaletteOrder() throws {
        let colors = try JSONCoding.decoder.decode(WhatsappPriorityColors.self, from: Data("""
        {"palette":{"red":"#ff3b30","orange":"#ff9500","gray":"#8e8e93","teal":"#30b0c7"},
         "colors":{"1":"red","2":"gray","3":"orange","4":"red","5":"red"}}
        """.utf8))

        XCTAssertEqual(colors.names, ["red", "orange", "gray", "teal"])
        XCTAssertEqual(colors.name(of: 2), "gray")
        XCTAssertEqual(colors.hex(of: 3), "#ff9500")
    }

    func testTheChatToolsGoToTheirPaths() {
        XCTAssertEqual(API.whatsappConversations(sort: .priority, tag: "wtag_1").query.map(\.name), ["page", "per_page", "sort", "tag"])
        XCTAssertEqual(API.whatsappConversations().query.map(\.name), ["page", "per_page"])
        XCTAssertEqual(API.startWhatsappConversation(accountId: "wacc_1", number: "11 91111-0000").path, "/whatsapp/conversations")
        XCTAssertEqual(API.syncWhatsappConversation(chatId: "wtgt_1").path, "/whatsapp/conversations/wtgt_1/sync")
        XCTAssertEqual(API.draftWhatsappReply(chatId: "wtgt_1").path, "/whatsapp/conversations/wtgt_1/draft")
        XCTAssertEqual(API.saveWhatsappPriorityColors(["1": "red"]).method, .put)
        XCTAssertEqual(API.updateWhatsappAccountChatSettings(accountId: "wacc_1", .init(sendsReadReceipts: true, sendsTyping: false, notifiesMessages: true)).path, "/whatsapp/chat-settings/wacc_1")
    }

    func testTheDraftSendsThePromptOnlyWhenThereIsOne() throws {
        let prompted = try XCTUnwrap(API.draftWhatsappReply(chatId: "wtgt_1", messageIds: ["m1"], instruction: "  diz que sim  ").body)
        let plain = try XCTUnwrap(API.draftWhatsappReply(chatId: "wtgt_1", instruction: "   ").body)

        XCTAssertEqual(try JSONSerialization.jsonObject(with: prompted) as? NSDictionary, ["messages": ["m1"], "instruction": "diz que sim"])
        XCTAssertEqual(try JSONSerialization.jsonObject(with: plain) as? NSDictionary, ["messages": []])
    }

    func testThePromptAloneLeavesTheChatOut() throws {
        let body = try XCTUnwrap(API.draftWhatsappReply(chatId: "wtgt_1", messageIds: ["m1"], instruction: "parabéns", withoutContext: true).body)

        XCTAssertEqual(try JSONSerialization.jsonObject(with: body) as? NSDictionary, ["messages": [], "instruction": "parabéns", "without_context": true])
    }

    func testAScheduledFileGoesAsAMultipartForm() throws {
        let file = MultipartForm.File(name: "file", fileName: "voice.m4a", mimeType: "audio/mp4", data: Data([0x01, 0x02]))
        let endpoint = API.scheduleWhatsappConversationFile(chatId: "wtgt_1", text: "", at: "2026-10-08T09:00", file: file)
        let body = try XCTUnwrap(endpoint.body)
        let boundary = try XCTUnwrap(endpoint.contentType.components(separatedBy: "boundary=").last)

        XCTAssertTrue(endpoint.contentType.hasPrefix("multipart/form-data; boundary="))
        XCTAssertEqual(endpoint.path, "/whatsapp/conversations/wtgt_1/schedule")

        var expected = Data("--\(boundary)\r\nContent-Disposition: form-data; name=\"at\"\r\n\r\n2026-10-08T09:00\r\n".utf8)
        expected.append(Data("--\(boundary)\r\nContent-Disposition: form-data; name=\"file\"; filename=\"voice.m4a\"\r\nContent-Type: audio/mp4\r\n\r\n".utf8))
        expected.append(Data([0x01, 0x02]))
        expected.append(Data("\r\n--\(boundary)--\r\n".utf8))

        XCTAssertEqual(body, expected)
    }

    func testTheNumberDefaultsReadThePhoneReadsAndKeepThemOnWhenMissing() throws {
        let sent = try JSONCoding.decoder.decode(WhatsappAccountChatSettings.Defaults.self, from: Data(#"{"sends_read_receipts":true,"sends_typing":false,"notifies_messages":true,"syncs_phone_reads":false}"#.utf8))
        let older = try JSONCoding.decoder.decode(WhatsappAccountChatSettings.Defaults.self, from: Data(#"{"sends_read_receipts":true,"sends_typing":false,"notifies_messages":true}"#.utf8))

        XCTAssertFalse(sent.syncsPhoneReads)
        XCTAssertTrue(older.syncsPhoneReads)

        let body = try XCTUnwrap(API.updateWhatsappAccountChatSettings(accountId: "wacc_1", sent).body)
        XCTAssertEqual((try JSONSerialization.jsonObject(with: body) as? NSDictionary)?["syncs_phone_reads"] as? Bool, false)
    }

    func testAScheduledMessageCarriesItsFile() throws {
        let scheduled = try JSONCoding.decoder.decode(WhatsappChatDetails.Scheduled.self, from: Data(#"{"friendly_id":"wmsg_1","body":"","next_run_at":null,"media":{"mime":"audio/mp4","name":"voice.m4a","size":2048}}"#.utf8))

        XCTAssertEqual(scheduled.media?.name, "voice.m4a")
        XCTAssertTrue(scheduled.media?.isAudio == true)
    }

    func testAMessageCarriesItsReactionsAndTheNumbersOwn() throws {
        let page = try JSONCoding.decoder.decode(WhatsappConversationMessagesPage.self, from: Data("""
        {"data":[{"friendly_id":"wcm_1","from_me":false,"type":"conversation","body":"Vamos?","revoked":false,"sent_at":"2026-10-06T12:00:00+00:00",
          "reactions":[{"emoji":"❤️","count":2,"mine":false},{"emoji":"👍","count":1,"mine":true}]},
         {"friendly_id":"wcm_2","from_me":true,"type":"conversation","body":"Bora","sent_at":"2026-10-06T12:01:00+00:00"}],
         "outgoing":[],"meta":{"has_more":false}}
        """.utf8))

        XCTAssertEqual(page.data[0].reactions, [.init(emoji: "❤️", count: 2, mine: false), .init(emoji: "👍", count: 1, mine: true)])
        XCTAssertEqual(page.data[0].myReaction, "👍")
        XCTAssertEqual(page.data[1].reactions, [])
        XCTAssertNil(page.data[1].myReaction)
    }

    func testReactingMovesTheNumbersOwnAndTakingItBackLeavesTheOthers() {
        let message = WhatsappConversationMessage(friendlyId: "wcm_1", fromMe: false, body: "Vamos?", sentAt: .now, reactions: [
            .init(emoji: "❤️", count: 2, mine: true),
            .init(emoji: "👍", count: 1, mine: false),
        ])

        XCTAssertEqual(message.reacting(with: "👍").reactions, [.init(emoji: "❤️", count: 1, mine: false), .init(emoji: "👍", count: 2, mine: true)])
        XCTAssertEqual(message.reacting(with: nil).reactions, [.init(emoji: "❤️", count: 1, mine: false), .init(emoji: "👍", count: 1, mine: false)])
        XCTAssertEqual(message.reacting(with: "🙏").reactions.last, .init(emoji: "🙏", count: 1, mine: true))
    }

    func testOnlyMessagesWithATextOrAFileAndNotDeletedAreForwarded() {
        XCTAssertTrue(WhatsappConversationMessage(friendlyId: "a", fromMe: false, body: "Oi", sentAt: .now).isForwardable)
        XCTAssertTrue(WhatsappConversationMessage(friendlyId: "b", fromMe: false, body: nil, media: .init(kind: .image), sentAt: .now).isForwardable)
        XCTAssertFalse(WhatsappConversationMessage(friendlyId: "c", fromMe: false, body: "Oi", revoked: true, sentAt: .now).isForwardable)
        XCTAssertFalse(WhatsappConversationMessage(friendlyId: "d", fromMe: false, type: "stickerMessage", body: "", sentAt: .now).isForwardable)
    }

    func testAReactionAndAForwardGoToTheirPathsWithTheirBodies() throws {
        let reaction = API.reactToWhatsappMessage(chatId: "wtgt_1", messageId: "wcm_1", emoji: "🙏")
        let forward = API.forwardWhatsappMessages(chatId: "wtgt_1", messageIds: ["wcm_1", "wcm_2"], to: ["wtgt_2"])

        XCTAssertEqual(reaction.method, .post)
        XCTAssertEqual(reaction.path, "/whatsapp/conversations/wtgt_1/messages/wcm_1/reaction")
        XCTAssertEqual(try JSONSerialization.jsonObject(with: XCTUnwrap(reaction.body)) as? NSDictionary, ["emoji": "🙏"])
        XCTAssertEqual(forward.path, "/whatsapp/conversations/wtgt_1/forward")
        XCTAssertEqual(try JSONSerialization.jsonObject(with: XCTUnwrap(forward.body)) as? NSDictionary, ["messages": ["wcm_1", "wcm_2"], "to": ["wtgt_2"]])
        XCTAssertEqual(API.whatsappConversations(search: "Bru", account: "wacc_1").query.map(\.name), ["page", "per_page", "search", "account"])
        XCTAssertNil(try JSONCoding.decoder.decode(WhatsappReaction.self, from: Data(#"{"emoji":null}"#.utf8)).emoji)
        XCTAssertEqual(try JSONCoding.decoder.decode(WhatsappForwarded.self, from: Data(#"{"deliveries":4}"#.utf8)).deliveries, 4)
    }
}
