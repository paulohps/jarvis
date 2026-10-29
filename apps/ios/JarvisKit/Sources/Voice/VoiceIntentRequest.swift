import Foundation
import Networking

/// What a Siri App Intent of Jarvis asks for (US-10.1, US-10.3), with the
/// values Siri resolved. iOS does not allow a wake word of its own, so the
/// trigger is "Siri, …" with the app's name.
public enum VoiceIntentRequest: Equatable, Sendable {
    case addShoppingItem(item: String, quantity: String?, list: String?)
    case createReminder(title: String, dueAt: Date?, note: String?)
    case completeReminder(reminder: String)
    case sendWhatsappMessage(target: String, body: String, account: String?)

    /// The action `POST /voice/actions` runs. A due date goes on the user's
    /// wall clock (`2026-10-06T09:00`), read by the server in their timezone.
    public func voiceAction(in timeZone: TimeZone) -> VoiceAction {
        switch self {
        case .addShoppingItem(let item, let quantity, let list):
            VoiceAction(name: .shoppingAddItem, parameters: ["item": item, "quantity": quantity, "list": list])
        case .createReminder(let title, let dueAt, let note):
            VoiceAction(
                name: .remindersCreate,
                parameters: ["title": title, "due_at": dueAt.map { WallClock.dateTime($0, in: timeZone) }, "note": note]
            )
        case .completeReminder(let reminder):
            VoiceAction(name: .remindersComplete, parameters: ["reminder": reminder])
        case .sendWhatsappMessage(let target, let body, let account):
            VoiceAction(name: .whatsappSend, parameters: ["target": target, "body": body, "account": account])
        }
    }
}

/// The voice endpoints of the API v1, shared by the Siri intents and the
/// microphone button: the same actions on the server, never a parallel logic.
public struct VoiceService: Sendable {
    public let client: APIClient

    public init(client: APIClient) {
        self.client = client
    }

    /// Runs an action structured on the device (`POST /voice/actions`).
    public func run(_ action: VoiceAction) async throws(APIError) -> VoiceCommandResult {
        try await client.send(API.runVoiceAction(action))
    }

    /// Runs what a Siri intent asks for.
    public func run(_ request: VoiceIntentRequest, in timeZone: TimeZone) async throws(APIError) -> VoiceCommandResult {
        try await run(request.voiceAction(in: timeZone))
    }

    /// Sends the spoken text to the server's interpreter (`POST /voice/commands`).
    public func interpret(_ text: String) async throws(APIError) -> VoiceCommandResult {
        try await client.send(API.interpretVoiceCommand(text: text))
    }

    /// Answers a `needs_clarification` with the option the user picked: the
    /// same action and parameters, with the option's id as the parameter asked.
    public func choose(_ option: VoiceOption, in result: VoiceCommandResult) async throws(APIError) -> VoiceCommandResult {
        guard let action = result.action(choosing: option) else {
            return result
        }

        return try await run(action)
    }

    /// Sends the WhatsApp message the user confirmed (`POST /voice/commands/confirm`).
    public func confirm(_ confirmation: VoiceConfirmation) async throws(APIError) -> VoiceCommandResult {
        try await client.send(API.confirmVoiceCommand(token: confirmation.token))
    }
}
