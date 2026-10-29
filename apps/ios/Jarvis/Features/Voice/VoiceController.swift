import Foundation
import Localization
import Networking
import Observation
import Voice

/// Where a voice command is, as the voice sheet shows it.
enum VoicePhase: Equatable {
    case idle
    case listening

    /// A permission is missing: the sheet explains how to turn it on.
    case accessDenied(VoiceAccess)

    /// Nothing was heard, the recognizer or the interpreter is not available,
    /// or the call failed; the message is already in the user's language.
    case failed(message: String)

    /// The text goes to the interpreter.
    case interpreting(String)

    /// The answer of the server, and the text it came from.
    case answered(String, VoiceResponseFlow)
}

/// The microphone button (US-10.1 to US-10.3): asks the permissions,
/// listens with the Speech framework in the user's language, interprets the
/// text locally or remotely (the Settings' preference) and leads the answer
/// to its flow: shown and spoken, with the options of a clarification or the
/// confirmation of a WhatsApp send.
@MainActor
@Observable
final class VoiceController {
    private(set) var phase: VoicePhase = .idle

    /// A pick or a confirmation is on its way.
    private(set) var isSending = false
    var isPresented = false

    var preference: InterpreterPreference {
        didSet { preferenceStore.current = preference }
    }

    let transcriber = VoiceTranscriber()

    @ObservationIgnored private let permissions: VoicePermissions
    @ObservationIgnored private let interpreter: VoiceInterpreter
    @ObservationIgnored private let handler: VoiceResponseHandler
    @ObservationIgnored private let model: any OnDeviceVoiceModel
    @ObservationIgnored private let preferenceStore: InterpreterPreferenceStore
    @ObservationIgnored private let speaker = VoiceSpeaker()

    /// The answer the options of a clarification belong to.
    @ObservationIgnored private var lastResult: VoiceCommandResult?
    @ObservationIgnored private var language: AppLanguage = .english
    @ObservationIgnored private var timeZone: TimeZone = .current
    @ObservationIgnored private var work: Task<Void, Never>?

    init(
        client: APIClient,
        model: any OnDeviceVoiceModel = FoundationModelsVoiceModel(),
        permissions: VoicePermissions = VoicePermissions(authorizer: SystemVoiceAuthorizer()),
        preferenceStore: InterpreterPreferenceStore = InterpreterPreferenceStore()
    ) {
        let service = VoiceService(client: client)
        interpreter = VoiceInterpreter(service: service, model: model)
        handler = VoiceResponseHandler(service: service)
        self.model = model
        self.permissions = permissions
        self.preferenceStore = preferenceStore
        preference = preferenceStore.current
    }

    /// Whether this iPhone can interpret on the device in the language.
    func isLocalAvailable(in language: AppLanguage) -> Bool {
        model.isAvailable(for: language.locale)
    }

    /// The microphone button: opens the sheet and listens.
    func begin(language: AppLanguage, timeZone: TimeZone) async {
        self.language = language
        self.timeZone = timeZone
        isPresented = true
        await listen()
    }

    /// Listens again from the sheet.
    func listen() async {
        reset()
        phase = .idle

        let access = await permissions.requestAccess()

        guard access == .granted else {
            phase = .accessDenied(access)

            return
        }

        do {
            try transcriber.start(language: language) { [weak self] text in
                self?.didHear(text)
            }
            phase = .listening
        } catch {
            phase = .failed(message: language.localized("Speech recognition is not available right now. Try again in a moment."))
        }
    }

    /// The user tapped to stop: the final text comes from the recognizer.
    func stopListening() {
        transcriber.finish()
    }

    /// The user picked an option of a clarification.
    func choose(_ option: VoiceOption) {
        guard case .answered(let text, .clarify) = phase, let lastResult else {
            return
        }

        send(from: text) { (handler) throws(APIError) in
            try await handler.choose(option, after: lastResult)
        }
    }

    /// The user tapped to send the WhatsApp message: only now is it sent.
    func confirm() {
        guard case .answered(let text, .confirm(_, let confirmation)) = phase else {
            return
        }

        send(from: text) { (handler) throws(APIError) in
            try await handler.confirm(confirmation)
        }
    }

    /// The user did not confirm or pick: nothing runs.
    func cancelAnswer() {
        guard case .answered(let text, _) = phase else {
            return
        }

        show(text, .failed(message: language.localized("Command cancelled.")))
    }

    /// The sheet was closed.
    func dismiss() {
        reset()
        phase = .idle
        isPresented = false
    }

    private func reset() {
        work?.cancel()
        work = nil
        transcriber.cancel()
        speaker.stop()
        lastResult = nil
        isSending = false
    }

    private func didHear(_ text: String) {
        guard !text.isEmpty else {
            phase = .failed(message: language.localized("I did not hear anything. Tap the microphone and try again."))

            return
        }

        phase = .interpreting(text)
        work = Task { [weak self] in
            await self?.interpret(text)
        }
    }

    private func interpret(_ text: String) async {
        do throws(VoiceInterpreterError) {
            let result = try await interpreter.interpret(text, preference: preference, locale: language.locale, timeZone: timeZone)

            guard !Task.isCancelled else {
                return
            }

            lastResult = result
            show(text, handler.flow(for: result))
        } catch {
            guard !Task.isCancelled else {
                return
            }

            fail(message(for: error))
        }
    }

    private func send(from text: String, _ call: @escaping (VoiceResponseHandler) async throws(APIError) -> VoiceResponseFlow) {
        guard !isSending else {
            return
        }

        isSending = true
        let handler = handler
        work = Task { [weak self] in
            do throws(APIError) {
                let flow = try await call(handler)
                self?.isSending = false
                self?.show(text, flow)
            } catch {
                self?.isSending = false
                self?.fail(error.userMessage(in: self?.language ?? .english))
            }
        }
    }

    /// Shows the answer and speaks it.
    private func show(_ text: String, _ flow: VoiceResponseFlow) {
        phase = .answered(text, flow)
        speaker.speak(flow.message, in: language)
    }

    private func fail(_ message: String) {
        phase = .failed(message: message)
        speaker.speak(message, in: language)
    }

    private func message(for error: VoiceInterpreterError) -> String {
        switch error {
        case .localUnavailable:
            language.localized("On-device interpretation is not available on this iPhone. Choose “On the server” or “Automatic” in Settings.")
        case .notUnderstood:
            language.localized("I did not understand. Try saying it another way.")
        case .localFailed:
            language.localized("The on-device interpreter could not handle this command. Try again or choose “On the server” in Settings.")
        case .api(let error):
            error.userMessage(in: language)
        }
    }
}
