import AVFoundation
import Localization

/// Speaks the answer of a voice command (US-10.3) with `AVSpeechSynthesizer`,
/// in the user's language, ducking other audio while it speaks.
@MainActor
final class VoiceSpeaker: NSObject, AVSpeechSynthesizerDelegate {
    private let synthesizer = AVSpeechSynthesizer()

    override init() {
        super.init()
        synthesizer.delegate = self
    }

    func speak(_ text: String, in language: AppLanguage) {
        guard !text.isEmpty else {
            return
        }

        synthesizer.stopSpeaking(at: .immediate)

        let session = AVAudioSession.sharedInstance()
        try? session.setCategory(.playback, mode: .voicePrompt, options: [.duckOthers])
        try? session.setActive(true)

        let utterance = AVSpeechUtterance(string: text)
        utterance.voice = AVSpeechSynthesisVoice(language: language.languageTag)
        synthesizer.speak(utterance)
    }

    func stop() {
        synthesizer.stopSpeaking(at: .immediate)
    }

    /// The other apps' audio comes back up. A cancelled answer leaves the
    /// audio session alone: the microphone may be taking it over.
    nonisolated func speechSynthesizer(_ synthesizer: AVSpeechSynthesizer, didFinish utterance: AVSpeechUtterance) {
        guard !synthesizer.isSpeaking else {
            return
        }

        try? AVAudioSession.sharedInstance().setActive(false, options: .notifyOthersOnDeactivation)
    }
}
