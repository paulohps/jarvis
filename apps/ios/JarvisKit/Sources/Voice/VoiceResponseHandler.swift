import Foundation
import Networking

/// What the app does with the answer of a voice command (US-10.3). The
/// `message` comes translated, for the screen and the speech.
public enum VoiceResponseFlow: Equatable, Sendable {
    /// The action ran: show and speak the message.
    case done(message: String)

    /// Nothing ran yet: show what would be sent and wait for a tap.
    case confirm(message: String, confirmation: VoiceConfirmation)

    /// More than one candidate: show the options; the picked one runs the action again.
    case clarify(message: String, clarification: VoiceClarification)

    /// The action's module is switched off for the user.
    case refused(message: String)

    /// Not understood, nothing found, or refused by the validation.
    case failed(message: String)

    public var message: String {
        switch self {
        case .done(let message), .confirm(let message, _), .clarify(let message, _), .refused(let message), .failed(let message):
            message
        }
    }
}

/// Leads a voice answer to its flow and carries it on: a clarification goes
/// back to `POST /voice/actions` with the picked option, a confirmation to
/// `POST /voice/commands/confirm`, and never without the user's tap.
public struct VoiceResponseHandler: Sendable {
    private let service: VoiceService

    public init(service: VoiceService) {
        self.service = service
    }

    /// The flow of an answer; an answer missing what its status needs fails.
    public func flow(for result: VoiceCommandResult) -> VoiceResponseFlow {
        switch result.status {
        case .done:
            return .done(message: result.message)
        case .needsConfirmation:
            guard let confirmation = result.confirmation else {
                return .failed(message: result.message)
            }

            return .confirm(message: result.message, confirmation: confirmation)
        case .needsClarification:
            guard result.action != nil, let clarification = result.clarification, !clarification.options.isEmpty else {
                return .failed(message: result.message)
            }

            return .clarify(message: result.message, clarification: clarification)
        case .forbidden:
            return .refused(message: result.message)
        case .failed:
            return .failed(message: result.message)
        }
    }

    /// The user picked an option of a clarification.
    public func choose(_ option: VoiceOption, after result: VoiceCommandResult) async throws(APIError) -> VoiceResponseFlow {
        flow(for: try await service.choose(option, in: result))
    }

    /// The user tapped to send the confirmed message.
    public func confirm(_ confirmation: VoiceConfirmation) async throws(APIError) -> VoiceResponseFlow {
        flow(for: try await service.confirm(confirmation))
    }
}
