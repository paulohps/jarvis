import Foundation
import XCTest
@testable import Networking
@testable import Voice

final class IntentMappingTests: XCTestCase {
    private let saoPaulo = TimeZone(identifier: "America/Sao_Paulo")!

    private let done = (200, #"{"action":"shopping.add_item","status":"done","message":"Added \"leite\" to \"Compras da semana\".","parameters":{"item":"leite","quantity":null,"list":"dessa semana"},"clarification":null,"confirmation":null}"#)

    private func makeService(_ transport: StubTransport) -> VoiceService {
        VoiceService(client: APIClient(baseURL: URL(string: "https://jarvis.test")!, tokenStore: InMemoryTokenStore(token: "1|secret"), transport: transport))
    }

    private func body(of request: URLRequest?) throws -> [String: Any] {
        try XCTUnwrap(JSONSerialization.jsonObject(with: XCTUnwrap(request?.httpBody)) as? [String: Any])
    }

    func testAddShoppingItemProducesTheAddItemAction() {
        let action = VoiceIntentRequest.addShoppingItem(item: " leite ", quantity: "2 litros", list: "dessa semana").voiceAction(in: saoPaulo)

        XCTAssertEqual(action, VoiceAction(name: .shoppingAddItem, parameters: ["item": "leite", "quantity": "2 litros", "list": "dessa semana"]))
    }

    func testAddShoppingItemLeavesOutTheListNotSpoken() {
        let action = VoiceIntentRequest.addShoppingItem(item: "pão", quantity: nil, list: "  ").voiceAction(in: saoPaulo)

        XCTAssertEqual(action.name, .shoppingAddItem)
        XCTAssertEqual(action.parameters, ["item": "pão"])
    }

    func testCreateReminderProducesTheCreateActionOnTheUsersWallClock() {
        // 12:00 UTC is 09:00 in São Paulo.
        let dueAt = Date(timeIntervalSince1970: 1_791_288_000)
        let action = VoiceIntentRequest.createReminder(title: "Pagar a luz", dueAt: dueAt, note: nil).voiceAction(in: saoPaulo)

        XCTAssertEqual(action.name, .remindersCreate)
        XCTAssertEqual(action.parameters, ["title": "Pagar a luz", "due_at": WallClock.dateTime(dueAt, in: saoPaulo)])
        XCTAssertEqual(action.parameters["due_at"]?.hasSuffix("T09:00"), true)
    }

    func testCreateReminderWithoutADateIsAnUndatedReminder() {
        let action = VoiceIntentRequest.createReminder(title: "Ligar pro João", dueAt: nil, note: "sobre o churrasco").voiceAction(in: saoPaulo)

        XCTAssertEqual(action, VoiceAction(name: .remindersCreate, parameters: ["title": "Ligar pro João", "note": "sobre o churrasco"]))
    }

    func testCompleteReminderProducesTheCompleteAction() {
        let action = VoiceIntentRequest.completeReminder(reminder: "pagar a luz").voiceAction(in: saoPaulo)

        XCTAssertEqual(action, VoiceAction(name: .remindersComplete, parameters: ["reminder": "pagar a luz"]))
    }

    func testSendWhatsappMessageProducesTheSendAction() {
        let action = VoiceIntentRequest.sendWhatsappMessage(target: "Família", body: "Chego às 8", account: nil).voiceAction(in: saoPaulo)

        XCTAssertEqual(action, VoiceAction(name: .whatsappSend, parameters: ["target": "Família", "body": "Chego às 8"]))
    }

    func testEachIntentCallsVoiceActions() async throws {
        let requests: [VoiceIntentRequest] = [
            .addShoppingItem(item: "leite", quantity: nil, list: "dessa semana"),
            .createReminder(title: "Pagar a luz", dueAt: nil, note: nil),
            .completeReminder(reminder: "pagar a luz"),
            .sendWhatsappMessage(target: "Família", body: "Chego às 8", account: "pessoal"),
        ]
        let transport = StubTransport(Array(repeating: done, count: requests.count))
        let service = makeService(transport)

        for request in requests {
            _ = try await service.run(request, in: saoPaulo)

            XCTAssertEqual(transport.lastRequest?.httpMethod, "POST")
            XCTAssertEqual(transport.lastRequest?.url?.path, "/api/v1/voice/actions")

            let json = try body(of: transport.lastRequest)
            let expected = request.voiceAction(in: saoPaulo)
            XCTAssertEqual(json["action"] as? String, expected.name.rawValue)
            XCTAssertEqual(json["parameters"] as? [String: String], expected.parameters)
        }

        XCTAssertEqual(transport.requests.count, 4)
    }

    func testTheResultOfAnIntentIsDecodedWithTheApiParameterNames() async throws {
        let transport = StubTransport([(200, #"{"action":"reminders.create","status":"done","message":"Reminder created.","parameters":{"title":"Pagar a luz","due_at":"2026-10-06T09:00","note":null},"clarification":null,"confirmation":null}"#)])

        let result = try await makeService(transport).run(.createReminder(title: "Pagar a luz", dueAt: nil, note: nil), in: saoPaulo)

        XCTAssertEqual(result.status, .done)
        XCTAssertEqual(result.action, .remindersCreate)
        XCTAssertEqual(result.message, "Reminder created.")
        XCTAssertEqual(result.parameters, ["title": "Pagar a luz", "due_at": "2026-10-06T09:00"])
    }

    func testAWhatsappIntentConfirmsWithTheTokenOfTheAnswer() async throws {
        let transport = StubTransport([
            (200, #"{"action":"whatsapp.send","status":"needs_confirmation","message":"Send \"Chego às 8\" to Família?","parameters":{"target":"Família","body":"Chego às 8","account":null},"clarification":null,"confirmation":{"token":"tok-1","expires_at":"2026-10-04T12:05:00+00:00","summary":{"account":"Pessoal","target":"Família","target_type":"group","body":"Chego às 8"}}}"#),
            (200, #"{"action":"whatsapp.send","status":"done","message":"Sending to Família.","parameters":{"target":"Família","body":"Chego às 8","account":null},"clarification":null,"confirmation":null}"#),
        ])
        let service = makeService(transport)

        let asked = try await service.run(.sendWhatsappMessage(target: "Família", body: "Chego às 8", account: nil), in: saoPaulo)
        XCTAssertEqual(asked.status, .needsConfirmation)
        XCTAssertEqual(transport.requests.count, 1)

        let confirmation = try XCTUnwrap(asked.confirmation)
        XCTAssertEqual(confirmation.summary, VoiceConfirmationSummary(account: "Pessoal", target: "Família", targetType: .group, body: "Chego às 8"))

        let sent = try await service.confirm(confirmation)

        XCTAssertEqual(sent.status, .done)
        XCTAssertEqual(transport.lastRequest?.url?.path, "/api/v1/voice/commands/confirm")
        XCTAssertEqual(try body(of: transport.lastRequest)["confirmation_token"] as? String, "tok-1")
    }
}
