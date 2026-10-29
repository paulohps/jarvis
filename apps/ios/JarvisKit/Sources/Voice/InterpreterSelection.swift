import Foundation
import Networking

/// Where the user wants a spoken command to be interpreted (US-10.2).
public enum InterpreterPreference: String, CaseIterable, Sendable, Identifiable {
    /// On this iPhone, with Apple Foundation Models: the text never leaves the device.
    case local

    /// On the server, with the household's AI provider (`POST /voice/commands`).
    case remote

    /// Local when this iPhone can, otherwise remote.
    case automatic

    public var id: String { rawValue }

    /// The key of the String Catalog for the picker.
    public var titleKey: String {
        switch self {
        case .local: "On this iPhone"
        case .remote: "On the server"
        case .automatic: "Automatic"
        }
    }
}

/// The saved preference of this device; automatic until the user picks one.
public final class InterpreterPreferenceStore: @unchecked Sendable {
    static let storageKey = "jarvis.voice.interpreter"

    private let defaults: UserDefaults

    public init(defaults: UserDefaults = .standard) {
        self.defaults = defaults
    }

    public var current: InterpreterPreference {
        get { defaults.string(forKey: Self.storageKey).flatMap(InterpreterPreference.init(rawValue:)) ?? .automatic }
        set { defaults.set(newValue.rawValue, forKey: Self.storageKey) }
    }
}

/// The interpreter a command goes to.
public enum InterpreterChoice: Equatable, Sendable {
    case local
    case remote

    /// The user asked for the local one and this iPhone cannot run it; the
    /// text is not sent to the server behind their back.
    case localUnavailable
}

/// Picks the interpreter from the preference and whether the on-device
/// model can run now (Apple Intelligence on, model ready, language supported).
public enum InterpreterSelection {
    public static func choose(preference: InterpreterPreference, isLocalAvailable: Bool) -> InterpreterChoice {
        switch preference {
        case .local:
            isLocalAvailable ? .local : .localUnavailable
        case .remote:
            .remote
        case .automatic:
            isLocalAvailable ? .local : .remote
        }
    }
}

/// Turns a spoken text into the structured action, on the device.
public protocol OnDeviceVoiceModel: Sendable {
    /// Whether the model can run now, for the language of the command.
    func isAvailable(for locale: Locale) -> Bool

    /// The action the text asks for; `nil` when no action fits.
    func action(for text: String, locale: Locale, now: Date, timeZone: TimeZone) async throws -> VoiceAction?
}

/// Why a command could not be interpreted on the device.
public enum VoiceInterpreterError: Error, Equatable, Sendable {
    /// The user wants the local interpreter and this iPhone cannot run it.
    case localUnavailable

    /// The on-device model found no action in the text.
    case notUnderstood

    /// The on-device model failed (e.g. a guardrail or the context window).
    case localFailed

    case api(APIError)
}

/// Interprets a spoken text with the interpreter the preference picks and
/// runs it: the local model structures the action and `POST /voice/actions`
/// runs it, the remote one is `POST /voice/commands`. Both end in the same
/// action on the server.
public struct VoiceInterpreter: Sendable {
    private let service: VoiceService
    private let model: any OnDeviceVoiceModel

    public init(service: VoiceService, model: any OnDeviceVoiceModel) {
        self.service = service
        self.model = model
    }

    public func choice(for preference: InterpreterPreference, locale: Locale) -> InterpreterChoice {
        InterpreterSelection.choose(preference: preference, isLocalAvailable: model.isAvailable(for: locale))
    }

    public func interpret(
        _ text: String,
        preference: InterpreterPreference,
        locale: Locale,
        now: Date = .now,
        timeZone: TimeZone
    ) async throws(VoiceInterpreterError) -> VoiceCommandResult {
        switch choice(for: preference, locale: locale) {
        case .localUnavailable:
            throw .localUnavailable
        case .remote:
            return try await remote(text)
        case .local:
            let action: VoiceAction?

            do {
                action = try await model.action(for: text, locale: locale, now: now, timeZone: timeZone)
            } catch {
                // In automatic mode the server gets another try at it.
                if preference == .automatic {
                    return try await remote(text)
                }

                throw .localFailed
            }

            guard let action else {
                if preference == .automatic {
                    return try await remote(text)
                }

                throw .notUnderstood
            }

            do {
                return try await service.run(action)
            } catch {
                throw .api(error)
            }
        }
    }

    private func remote(_ text: String) async throws(VoiceInterpreterError) -> VoiceCommandResult {
        do {
            return try await service.interpret(text)
        } catch {
            throw .api(error)
        }
    }
}
