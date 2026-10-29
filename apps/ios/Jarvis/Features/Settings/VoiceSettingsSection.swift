import Localization
import SwiftUI
import Voice

/// Where the voice commands are interpreted (US-10.2): on this iPhone, on
/// the server, or automatically (on this iPhone when it can).
struct VoiceSettingsSection: View {
    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    var body: some View {
        if VoiceButton.isShown {
            section
        }
    }

    private var section: some View {
        @Bindable var voice = model.voice

        return Section {
            Picker(language.localized("Interpret commands"), selection: $voice.preference) {
                ForEach(InterpreterPreference.allCases) { preference in
                    Text(verbatim: language.localized(preference.titleKey)).tag(preference)
                }
            }
            .accessibilityIdentifier("settings.voice-interpreter")
        } header: {
            language.text("Voice")
        } footer: {
            language.text(footerKey)
        }
    }

    private var footerKey: String {
        guard model.voice.isLocalAvailable(in: language) else {
            return "This iPhone cannot interpret on the device (it needs Apple Intelligence on and your language). Automatic uses the server."
        }

        return switch model.voice.preference {
        case .local:
            "What you say stays on this iPhone."
        case .remote:
            "What you say is sent to the household's AI provider."
        case .automatic:
            "On this iPhone when it can, otherwise on the server."
        }
    }
}
