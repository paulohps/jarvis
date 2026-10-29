import Foundation
import XCTest
@testable import Networking

final class WhatsappChatsTests: XCTestCase {
    func testAChatsPageCarriesTheLastReceivedMessage() throws {
        let page = try JSONCoding.decoder.decode(Page<WhatsappChat>.self, from: Data("""
        {"data":[{"friendly_id":"wtgt_1","name":"Ana","phone_number":"+5511911110000",
          "account":{"friendly_id":"wacc_1","nickname":"Personal"},
          "last_received_message":{"friendly_id":"wchm_1","from_me":false,"type":"conversation",
            "body":"Vamos jantar sexta?","sent_at":"2026-10-05T11:00:00+00:00"},
          "awaiting_reply":true},
         {"friendly_id":"wtgt_2","name":"81234567890123@lid","phone_number":null,
          "account":{"friendly_id":"wacc_1","nickname":"Personal"},
          "last_received_message":{"friendly_id":"wchm_2","from_me":false,"type":"audioMessage",
            "body":null,"sent_at":"2026-10-05T10:00:00+00:00"},
          "awaiting_reply":false}],
        "meta":{"current_page":1,"last_page":1}}
        """.utf8))

        XCTAssertEqual(page.data.map(\.name), ["Ana", "81234567890123@lid"])
        XCTAssertEqual(page.data.first?.lastReceivedMessage?.body, "Vamos jantar sexta?")
        XCTAssertTrue(try XCTUnwrap(page.data.first).awaitingReply)
        XCTAssertNil(page.data.last?.lastReceivedMessage?.body)
        XCTAssertNil(page.data.last?.phoneNumber)
    }

    func testADraftSendsTheInstructionTrimmedAndLeavesABlankOneOut() throws {
        let endpoint = API.draftWhatsappReply(messageId: "wchm_1", instruction: "  aceita \n")
        let json = try XCTUnwrap(JSONSerialization.jsonObject(with: XCTUnwrap(endpoint.body)) as? [String: Any])

        XCTAssertEqual(endpoint.method, .post)
        XCTAssertEqual(endpoint.path, "/whatsapp/chat-messages/wchm_1/reply")
        XCTAssertEqual(json["instruction"] as? String, "aceita")

        let blank = API.draftWhatsappReply(messageId: "wchm_1", instruction: "   ")
        let blankJson = try XCTUnwrap(JSONSerialization.jsonObject(with: XCTUnwrap(blank.body)) as? [String: Any])

        XCTAssertNil(blankJson["instruction"])
    }
}
