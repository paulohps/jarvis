import Localization
import Networking

/// The question before a voice WhatsApp send (US-10.3), the same in the Siri
/// conversation and in the voice sheet.
enum VoiceConfirmationText {
    static func prompt(for summary: VoiceConfirmationSummary, in language: AppLanguage) -> String {
        language.localized("Send “%@” to %@ from the account %@?", summary.body, summary.target, summary.account)
    }
}
