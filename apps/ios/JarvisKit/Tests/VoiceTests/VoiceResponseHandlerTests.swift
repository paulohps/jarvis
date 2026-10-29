import Foundation
import XCTest
@testable import Networking
@testable import Voice

final class VoiceResponseHandlerTests: XCTestCase {
    private func makeHandler(_ transport: StubTransport) -> VoiceResponseHandler {
        let client = APIClient(baseURL: URL(string: "https://jarvis.test")!, tokenStore: InMemoryTokenStore(token: "1|secret"), transport: transport)

        return VoiceResponseHandler(service: VoiceService(client: client))
    }

    private func decode(_ json: String) throws -> VoiceCommandResult {
        try JSONCoding.decoder.decode(VoiceCommandResult.self, from: Data(json.utf8))
    }

    private let clarification = #"{"action":"shopping.add_item","status":"needs_clarification","message":"Which list?","parameters":{"item":"leite","quantity":null,"list":"compras"},"clarification":{"parameter":"list","options":[{"id":"lst_1","label":"Compras da semana"},{"id":"lst_2","label":"Compras do mês"}]},"confirmation":null}"#

    private let confirmation = #"{"action":"whatsapp.send","status":"needs_confirmation","message":"Send \"Chego às 8\" to Família?","parameters":{"target":"Família","body":"Chego às 8","account":null},"clarification":null,"confirmation":{"token":"tok-1","expires_at":"2026-10-04T12:05:00+00:00","summary":{"account":"Pessoal","target":"Família","target_type":"group","body":"Chego às 8"}}}"#

    func testDoneShowsAndSpeaksTheMessage() throws {
        let flow = makeHandler(StubTransport([])).flow(for: try decode(#"{"action":"reminders.complete","status":"done","message":"Completed \"Pagar a luz\".","parameters":{"reminder":"pagar a luz"},"clarification":null,"confirmation":null}"#))

        XCTAssertEqual(flow, .done(message: "Completed \"Pagar a luz\"."))
        XCTAssertEqual(flow.message, "Completed \"Pagar a luz\".")
    }

    func testNeedsClarificationShowsTheOptions() throws {
        let flow = makeHandler(StubTransport([])).flow(for: try decode(clarification))

        guard case .clarify(let message, let shown) = flow else {
            return XCTFail("Expected the options, got \(flow).")
        }

        XCTAssertEqual(message, "Which list?")
        XCTAssertEqual(shown.parameter, "list")
        XCTAssertEqual(shown.options, [VoiceOption(id: "lst_1", label: "Compras da semana"), VoiceOption(id: "lst_2", label: "Compras do mês")])
    }

    func testPickingAnOptionSendsTheActionAgainWithItsId() async throws {
        let transport = StubTransport([(200, #"{"action":"shopping.add_item","status":"done","message":"Added.","parameters":{"item":"leite","quantity":null,"list":"lst_2"},"clarification":null,"confirmation":null}"#)])
        let result = try decode(clarification)

        let flow = try await makeHandler(transport).choose(VoiceOption(id: "lst_2", label: "Compras do mês"), after: result)

        XCTAssertEqual(flow, .done(message: "Added."))
        XCTAssertEqual(transport.lastRequest?.url?.path, "/api/v1/voice/actions")
        let json = try XCTUnwrap(JSONSerialization.jsonObject(with: XCTUnwrap(transport.lastRequest?.httpBody)) as? [String: Any])
        XCTAssertEqual(json["action"] as? String, "shopping.add_item")
        XCTAssertEqual(json["parameters"] as? [String: String], ["item": "leite", "list": "lst_2"])
    }

    func testNeedsConfirmationWaitsForATapBeforeSending() async throws {
        let transport = StubTransport([(200, #"{"action":"whatsapp.send","status":"done","message":"Sending to Família.","parameters":{"target":"Família","body":"Chego às 8","account":null},"clarification":null,"confirmation":null}"#)])
        let handler = makeHandler(transport)

        let flow = handler.flow(for: try decode(confirmation))

        guard case .confirm(_, let pending) = flow else {
            return XCTFail("Expected a confirmation, got \(flow).")
        }

        XCTAssertEqual(pending.summary.target, "Família")
        XCTAssertEqual(pending.summary.body, "Chego às 8")
        XCTAssertEqual(transport.requests.count, 0, "Nothing is sent before the tap.")

        let sent = try await handler.confirm(pending)

        XCTAssertEqual(sent, .done(message: "Sending to Família."))
        XCTAssertEqual(transport.requests.count, 1)
        XCTAssertEqual(transport.lastRequest?.url?.path, "/api/v1/voice/commands/confirm")
    }

    func testForbiddenIsRefusedWithTheMessage() throws {
        let flow = makeHandler(StubTransport([])).flow(for: try decode(#"{"action":"whatsapp.send","status":"forbidden","message":"You do not have access to WhatsApp.","parameters":{"target":"Família","body":"oi","account":null},"clarification":null,"confirmation":null}"#))

        XCTAssertEqual(flow, .refused(message: "You do not have access to WhatsApp."))
    }

    func testFailedShowsTheMessage() throws {
        let flow = makeHandler(StubTransport([])).flow(for: try decode(#"{"action":null,"status":"failed","message":"I did not understand.","parameters":null,"clarification":null,"confirmation":null}"#))

        XCTAssertEqual(flow, .failed(message: "I did not understand."))
    }

    func testAnAnswerMissingWhatItsStatusNeedsFails() {
        let handler = makeHandler(StubTransport([]))

        XCTAssertEqual(handler.flow(for: VoiceCommandResult(action: .whatsappSend, status: .needsConfirmation, message: "Confirm?")), .failed(message: "Confirm?"))
        XCTAssertEqual(
            handler.flow(for: VoiceCommandResult(action: .shoppingAddItem, status: .needsClarification, message: "Which?", clarification: VoiceClarification(parameter: "list", options: []))),
            .failed(message: "Which?")
        )
    }
}
