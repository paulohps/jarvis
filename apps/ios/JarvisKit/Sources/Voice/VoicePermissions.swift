import Foundation

/// What the user answered to one of the permissions the microphone button needs.
public enum VoiceAuthorization: Equatable, Sendable {
    case notDetermined
    case denied
    case authorized
}

/// The two system permissions of the microphone button (US-10.1): the
/// microphone (`AVAudioApplication`) and the speech recognition (`SFSpeechRecognizer`).
public protocol VoiceAuthorizing: Sendable {
    func microphoneAuthorization() async -> VoiceAuthorization

    /// Shows the system prompt; `true` when the user allowed.
    func requestMicrophone() async -> Bool

    func speechAuthorization() async -> VoiceAuthorization

    /// Shows the system prompt; `true` when the user allowed.
    func requestSpeech() async -> Bool
}

/// Whether the microphone button can listen, and when not, which permission is missing.
public enum VoiceAccess: Equatable, Sendable {
    case granted
    case microphoneDenied
    case speechRecognitionDenied

    /// The key of the String Catalog that tells how to turn the permission back on.
    public var explanationKey: String? {
        switch self {
        case .granted:
            nil
        case .microphoneDenied:
            "Jarvis needs the microphone to hear your command. Turn on Microphone for Jarvis in the iOS Settings."
        case .speechRecognitionDenied:
            "Jarvis needs speech recognition to understand your command. Turn on Speech Recognition for Jarvis in the iOS Settings."
        }
    }
}

/// Asks the microphone and then the speech recognition permission, each only
/// while it was never answered. A refusal is never asked again (iOS would not
/// show the prompt): the app explains how to turn it on in the iOS Settings.
public struct VoicePermissions: Sendable {
    private let authorizer: any VoiceAuthorizing

    public init(authorizer: any VoiceAuthorizing) {
        self.authorizer = authorizer
    }

    /// Before listening: asks what was never asked and tells what is missing.
    public func requestAccess() async -> VoiceAccess {
        var microphone = await authorizer.microphoneAuthorization()

        if microphone == .notDetermined {
            microphone = await authorizer.requestMicrophone() ? .authorized : .denied
        }

        guard microphone == .authorized else {
            return .microphoneDenied
        }

        var speech = await authorizer.speechAuthorization()

        if speech == .notDetermined {
            speech = await authorizer.requestSpeech() ? .authorized : .denied
        }

        return speech == .authorized ? .granted : .speechRecognitionDenied
    }

    /// The access as it is, without prompting (Settings and the button's state).
    public func currentAccess() async -> VoiceAccess? {
        let microphone = await authorizer.microphoneAuthorization()
        let speech = await authorizer.speechAuthorization()

        if microphone == .denied {
            return .microphoneDenied
        }

        if speech == .denied {
            return .speechRecognitionDenied
        }

        return microphone == .authorized && speech == .authorized ? .granted : nil
    }
}
