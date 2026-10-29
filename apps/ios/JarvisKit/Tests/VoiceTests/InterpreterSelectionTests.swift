import Foundation
import XCTest
@testable import Networking
@testable import Voice

final class InterpreterSelectionTests: XCTestCase {
    private let portuguese = Locale(identifier: "pt-BR")
    private let saoPaulo = TimeZone(identifier: "America/Sao_Paulo")!

    private let done = (200, #"{"action":"shopping.add_item","status":"done","message":"Adicionei \"leite\" em \"Compras da semana\".","parameters":{"item":"leite","quantity":null,"list":"dessa semana"},"clarification":null,"confirmation":null}"#)

    private func makeInterpreter(_ transport: StubTransport, model: FakeOnDeviceModel) -> VoiceInterpreter {
        let client = APIClient(baseURL: URL(string: "https://jarvis.test")!, tokenStore: InMemoryTokenStore(token: "1|secret"), transport: transport)

        return VoiceInterpreter(service: VoiceService(client: client), model: model)
    }

    func testThePreferenceAndTheAvailabilityPickTheInterpreter() {
        XCTAssertEqual(InterpreterSelection.choose(preference: .local, isLocalAvailable: true), .local)
        XCTAssertEqual(InterpreterSelection.choose(preference: .local, isLocalAvailable: false), .localUnavailable)
        XCTAssertEqual(InterpreterSelection.choose(preference: .remote, isLocalAvailable: true), .remote)
        XCTAssertEqual(InterpreterSelection.choose(preference: .remote, isLocalAvailable: false), .remote)
        XCTAssertEqual(InterpreterSelection.choose(preference: .automatic, isLocalAvailable: true), .local)
        XCTAssertEqual(InterpreterSelection.choose(preference: .automatic, isLocalAvailable: false), .remote)
    }

    func testTheStoredPreferenceIsAutomaticUntilChosen() throws {
        let defaults = try XCTUnwrap(UserDefaults(suiteName: "InterpreterSelectionTests"))
        defaults.removePersistentDomain(forName: "InterpreterSelectionTests")
        let store = InterpreterPreferenceStore(defaults: defaults)

        XCTAssertEqual(store.current, .automatic)

        store.current = .local
        XCTAssertEqual(InterpreterPreferenceStore(defaults: defaults).current, .local)

        defaults.removePersistentDomain(forName: "InterpreterSelectionTests")
    }

    func testAutomaticOnADeviceWithoutSupportFallsBackToTheRemoteInterpreter() async throws {
        let transport = StubTransport([done])
        let model = FakeOnDeviceModel(isAvailable: false, action: VoiceAction(name: .shoppingAddItem, parameters: ["item": "leite"]))

        let result = try await makeInterpreter(transport, model: model).interpret("adicione leite na lista dessa semana", preference: .automatic, locale: portuguese, timeZone: saoPaulo)

        XCTAssertEqual(result.status, .done)
        XCTAssertEqual(model.calls, 0)
        XCTAssertEqual(transport.lastRequest?.url?.path, "/api/v1/voice/commands")
        let json = try XCTUnwrap(JSONSerialization.jsonObject(with: XCTUnwrap(transport.lastRequest?.httpBody)) as? [String: Any])
        XCTAssertEqual(json["text"] as? String, "adicione leite na lista dessa semana")
    }

    func testAutomaticOnASupportedDeviceRunsTheLocalActionThroughVoiceActions() async throws {
        let transport = StubTransport([done])
        let action = VoiceAction(name: .shoppingAddItem, parameters: ["item": "leite", "list": "dessa semana"])
        let model = FakeOnDeviceModel(isAvailable: true, action: action)

        _ = try await makeInterpreter(transport, model: model).interpret("adicione leite na lista dessa semana", preference: .automatic, locale: portuguese, timeZone: saoPaulo)

        XCTAssertEqual(model.calls, 1)
        XCTAssertEqual(transport.requests.count, 1)
        XCTAssertEqual(transport.lastRequest?.url?.path, "/api/v1/voice/actions")
        let json = try XCTUnwrap(JSONSerialization.jsonObject(with: XCTUnwrap(transport.lastRequest?.httpBody)) as? [String: Any])
        XCTAssertEqual(json["action"] as? String, "shopping.add_item")
        XCTAssertEqual(json["parameters"] as? [String: String], ["item": "leite", "list": "dessa semana"])
    }

    func testRemoteNeverAsksTheLocalModel() async throws {
        let transport = StubTransport([done])
        let model = FakeOnDeviceModel(isAvailable: true, action: VoiceAction(name: .shoppingAddItem, parameters: ["item": "leite"]))

        _ = try await makeInterpreter(transport, model: model).interpret("adicione leite", preference: .remote, locale: portuguese, timeZone: saoPaulo)

        XCTAssertEqual(model.calls, 0)
        XCTAssertEqual(transport.lastRequest?.url?.path, "/api/v1/voice/commands")
    }

    func testLocalOnADeviceWithoutSupportDoesNotSendTheTextToTheServer() async {
        let transport = StubTransport([done])
        let model = FakeOnDeviceModel(isAvailable: false, action: nil)

        do {
            _ = try await makeInterpreter(transport, model: model).interpret("adicione leite", preference: .local, locale: portuguese, timeZone: saoPaulo)
            XCTFail("The local interpreter is not available.")
        } catch {
            XCTAssertEqual(error, .localUnavailable)
        }

        XCTAssertEqual(transport.requests.count, 0)
    }

    func testATextTheLocalModelDoesNotUnderstandGoesToTheServerOnlyInAutomatic() async throws {
        let automaticTransport = StubTransport([done])
        _ = try await makeInterpreter(automaticTransport, model: FakeOnDeviceModel(isAvailable: true, action: nil))
            .interpret("bom dia", preference: .automatic, locale: portuguese, timeZone: saoPaulo)
        XCTAssertEqual(automaticTransport.lastRequest?.url?.path, "/api/v1/voice/commands")

        let localTransport = StubTransport([done])

        do {
            _ = try await makeInterpreter(localTransport, model: FakeOnDeviceModel(isAvailable: true, action: nil))
                .interpret("bom dia", preference: .local, locale: portuguese, timeZone: saoPaulo)
            XCTFail("Nothing was understood.")
        } catch {
            XCTAssertEqual(error, .notUnderstood)
        }

        XCTAssertEqual(localTransport.requests.count, 0)
    }
}

final class FakeOnDeviceModel: OnDeviceVoiceModel, @unchecked Sendable {
    private let lock = NSLock()
    private let available: Bool
    private let result: VoiceAction?
    private var count = 0

    init(isAvailable: Bool, action: VoiceAction?) {
        available = isAvailable
        result = action
    }

    var calls: Int {
        lock.withLock { count }
    }

    func isAvailable(for locale: Locale) -> Bool {
        available
    }

    func action(for text: String, locale: Locale, now: Date, timeZone: TimeZone) async throws -> VoiceAction? {
        lock.withLock { count += 1 }

        return result
    }
}
