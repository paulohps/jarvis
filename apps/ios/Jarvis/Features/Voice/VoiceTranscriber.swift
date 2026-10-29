import AVFoundation
import Localization
import Observation
import Speech
import Voice

/// The microphone and speech recognition permissions of iOS.
struct SystemVoiceAuthorizer: VoiceAuthorizing {
    func microphoneAuthorization() async -> VoiceAuthorization {
        switch AVAudioApplication.shared.recordPermission {
        case .undetermined:
            .notDetermined
        case .granted:
            .authorized
        default:
            .denied
        }
    }

    func requestMicrophone() async -> Bool {
        await AVAudioApplication.requestRecordPermission()
    }

    func speechAuthorization() async -> VoiceAuthorization {
        switch SFSpeechRecognizer.authorizationStatus() {
        case .notDetermined:
            .notDetermined
        case .authorized:
            .authorized
        default:
            .denied
        }
    }

    func requestSpeech() async -> Bool {
        await withCheckedContinuation { continuation in
            SFSpeechRecognizer.requestAuthorization { status in
                continuation.resume(returning: status == .authorized)
            }
        }
    }
}

enum VoiceTranscriberError: Error {
    /// No recognizer for the language, or it is unavailable right now (e.g. no network for a server one).
    case recognizerUnavailable
}

/// Live speech-to-text with the Speech framework (US-10.1), in the user's
/// language: the transcript grows while the user speaks, and the listening
/// ends on a tap or after a short silence once something was said.
@MainActor
@Observable
final class VoiceTranscriber {
    private(set) var transcript = ""
    private(set) var isListening = false

    /// The silence after the last word that ends the listening.
    @ObservationIgnored var silenceTimeout: Duration = .seconds(1.6)

    @ObservationIgnored private let audioEngine = AVAudioEngine()
    @ObservationIgnored private var request: SFSpeechAudioBufferRecognitionRequest?
    @ObservationIgnored private var recognitionTask: SFSpeechRecognitionTask?
    @ObservationIgnored private var silenceTask: Task<Void, Never>?
    @ObservationIgnored private var onFinish: ((String) -> Void)?
    @ObservationIgnored private var hasTap = false

    /// Starts listening; `onFinish` gets the final text (empty when nothing was understood).
    func start(language: AppLanguage, onFinish: @escaping (String) -> Void) throws {
        cancel()

        guard let recognizer = SFSpeechRecognizer(locale: language.locale), recognizer.isAvailable else {
            throw VoiceTranscriberError.recognizerUnavailable
        }

        let session = AVAudioSession.sharedInstance()
        try session.setCategory(.playAndRecord, mode: .measurement, options: [.duckOthers, .defaultToSpeaker])
        try session.setActive(true, options: .notifyOthersOnDeactivation)

        let request = SFSpeechAudioBufferRecognitionRequest()
        request.shouldReportPartialResults = true
        request.addsPunctuation = true

        Self.installTap(on: audioEngine.inputNode, feeding: request)
        hasTap = true
        audioEngine.prepare()

        do {
            try audioEngine.start()
        } catch {
            stopAudio()

            throw error
        }

        self.request = request
        self.onFinish = onFinish
        transcript = ""
        isListening = true
        recognitionTask = Self.recognize(with: recognizer, request: request) { [weak self] text, isFinal in
            Task { @MainActor in
                self?.didRecognize(text, isFinal: isFinal)
            }
        }
    }

    /// The user is done: the recognizer gives its final text.
    func finish() {
        guard isListening else {
            return
        }

        stopAudio()
        request?.endAudio()
    }

    /// Stops without a result (the sheet was closed).
    func cancel() {
        onFinish = nil
        stopAudio()
        recognitionTask?.cancel()
        recognitionTask = nil
        request = nil
    }

    private func didRecognize(_ text: String?, isFinal: Bool) {
        if let text {
            transcript = text
            restartSilenceTimer()
        }

        guard isFinal else {
            return
        }

        let finish = onFinish
        let finalText = transcript.trimmingCharacters(in: .whitespacesAndNewlines)
        onFinish = nil
        stopAudio()
        recognitionTask = nil
        request = nil
        finish?(finalText)
    }

    private func restartSilenceTimer() {
        silenceTask?.cancel()

        guard !transcript.isEmpty else {
            return
        }

        let timeout = silenceTimeout
        silenceTask = Task { [weak self] in
            try? await Task.sleep(for: timeout)

            if !Task.isCancelled {
                self?.finish()
            }
        }
    }

    private func stopAudio() {
        silenceTask?.cancel()
        silenceTask = nil

        guard hasTap else {
            return
        }

        if audioEngine.isRunning {
            audioEngine.stop()
        }

        audioEngine.inputNode.removeTap(onBus: 0)
        hasTap = false
        isListening = false
        try? AVAudioSession.sharedInstance().setActive(false, options: .notifyOthersOnDeactivation)
    }

    /// Outside the main actor: the tap runs on the audio thread.
    private nonisolated static func installTap(on node: AVAudioInputNode, feeding request: SFSpeechAudioBufferRecognitionRequest) {
        let format = node.outputFormat(forBus: 0)
        node.installTap(onBus: 0, bufferSize: 1024, format: format) { buffer, _ in
            request.append(buffer)
        }
    }

    /// Outside the main actor: the recognizer answers on its own queue.
    private nonisolated static func recognize(
        with recognizer: SFSpeechRecognizer,
        request: SFSpeechAudioBufferRecognitionRequest,
        update: @escaping @Sendable (String?, Bool) -> Void
    ) -> SFSpeechRecognitionTask {
        recognizer.recognitionTask(with: request) { result, error in
            update(result?.bestTranscription.formattedString, result?.isFinal ?? (error != nil))
        }
    }
}
