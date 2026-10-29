import Localization
import Networking
import SwiftUI
import UIKit
import Voice

/// The global microphone button (US-10.1), in the tab bar's accessory so
/// every tab has it. Hidden for now, with the voice settings, while "Ask
/// Jarvis" is reworked (`isShown`); Siri's shortcuts stay.
struct VoiceButton: View {
    /// Whether "Ask Jarvis" shows anywhere in the app.
    static let isShown = false

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language
    @Environment(\.timeZone) private var timeZone

    var body: some View {
        Button {
            Task { await model.voice.begin(language: language, timeZone: timeZone) }
        } label: {
            Label(language.localized("Ask Jarvis"), systemImage: "mic.fill")
                .frame(maxWidth: .infinity)
        }
        .accessibilityIdentifier("voice.microphone")
    }
}

/// What the microphone heard and what came of it (US-10.1 to US-10.3): the
/// transcript while the user speaks, then the answer, with the options of a
/// clarification or the message to confirm before a WhatsApp send.
struct VoiceSheet: View {
    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language
    @Environment(\.openURL) private var openURL

    private var voice: VoiceController {
        model.voice
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 24) {
                    content
                }
                .padding()
                .frame(maxWidth: .infinity)
            }
            .navigationTitle(language.localized("Ask Jarvis"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(language.localized("Close"), systemImage: "xmark") {
                        voice.dismiss()
                    }
                }
            }
        }
        .presentationDetents([.medium, .large])
    }

    @ViewBuilder
    private var content: some View {
        switch voice.phase {
        case .idle:
            ProgressView()
        case .listening:
            listening
        case .accessDenied(let access):
            accessDenied(access)
        case .failed(let message):
            outcome(message, systemImage: "exclamationmark.bubble", tint: .orange)
            listenAgainButton
        case .interpreting(let text):
            heard(text)
            ProgressView(language.localized("Thinking…"))
        case .answered(let text, let flow):
            heard(text)
            answer(flow)
        }
    }

    // MARK: - Listening

    private var listening: some View {
        VStack(spacing: 24) {
            Text(verbatim: voice.transcriber.transcript.isEmpty ? language.localized("Listening…") : voice.transcriber.transcript)
                .font(.title3)
                .multilineTextAlignment(.center)
                .foregroundStyle(voice.transcriber.transcript.isEmpty ? .secondary : .primary)
                .frame(maxWidth: .infinity)
                .accessibilityIdentifier("voice.transcript")

            Button(language.localized("Stop"), systemImage: "mic.fill") {
                voice.stopListening()
            }
            .labelStyle(.iconOnly)
            .font(.largeTitle)
            .symbolEffect(.pulse, isActive: voice.transcriber.isListening)
            .buttonStyle(.glassProminent)
            .buttonBorderShape(.circle)
            .controlSize(.extraLarge)
            .accessibilityIdentifier("voice.stop")
        }
    }

    private func accessDenied(_ access: VoiceAccess) -> some View {
        ContentUnavailableView {
            Label(language.localized("Microphone off"), systemImage: "mic.slash")
        } description: {
            Text(verbatim: access.explanationKey.map { language.localized($0) } ?? "")
        } actions: {
            Button(language.localized("Open iOS Settings")) {
                if let url = URL(string: UIApplication.openSettingsURLString) {
                    openURL(url)
                }
            }
            .buttonStyle(.glassProminent)
        }
    }

    // MARK: - Answer

    private func heard(_ text: String) -> some View {
        Text(verbatim: "“\(text)”")
            .font(.callout)
            .multilineTextAlignment(.center)
            .foregroundStyle(.secondary)
            .accessibilityIdentifier("voice.transcript")
    }

    @ViewBuilder
    private func answer(_ flow: VoiceResponseFlow) -> some View {
        switch flow {
        case .done(let message):
            outcome(message, systemImage: "checkmark.circle.fill", tint: .green)
            listenAgainButton
        case .refused(let message):
            outcome(message, systemImage: "lock.fill", tint: .secondary)
            listenAgainButton
        case .failed(let message):
            outcome(message, systemImage: "exclamationmark.bubble", tint: .orange)
            listenAgainButton
        case .clarify(let message, let clarification):
            clarify(message, clarification)
        case .confirm(let message, let confirmation):
            confirm(message, confirmation.summary)
        }
    }

    private func outcome(_ message: String, systemImage: String, tint: Color) -> some View {
        VStack(spacing: 12) {
            Image(systemName: systemImage)
                .font(.largeTitle)
                .foregroundStyle(tint)

            Text(verbatim: message)
                .font(.title3)
                .multilineTextAlignment(.center)
                .accessibilityIdentifier("voice.message")
        }
    }

    /// `needs_clarification`: the candidates; nothing runs until one is picked.
    private func clarify(_ message: String, _ clarification: VoiceClarification) -> some View {
        VStack(spacing: 16) {
            Text(verbatim: message)
                .font(.title3)
                .multilineTextAlignment(.center)
                .accessibilityIdentifier("voice.message")

            ForEach(clarification.options) { option in
                Button {
                    voice.choose(option)
                } label: {
                    Text(verbatim: option.label)
                        .frame(maxWidth: .infinity)
                }
                .buttonStyle(.glass)
                .controlSize(.large)
                .disabled(voice.isSending)
                .accessibilityIdentifier("voice.option.\(option.id)")
            }

            cancelButton
        }
    }

    /// `needs_confirmation`: what would be sent; only the tap on Send sends it.
    private func confirm(_ message: String, _ summary: VoiceConfirmationSummary) -> some View {
        VStack(spacing: 16) {
            Text(verbatim: message)
                .font(.title3)
                .multilineTextAlignment(.center)
                .accessibilityIdentifier("voice.message")

            VStack(alignment: .leading, spacing: 8) {
                LabeledContent(language.localized(summary.targetType == .group ? "Group" : "Contact")) {
                    Text(verbatim: summary.target)
                }
                LabeledContent(language.localized("Account")) {
                    Text(verbatim: summary.account)
                }
                Text(verbatim: summary.body)
                    .padding(.top, 4)
            }
            .padding()
            .frame(maxWidth: .infinity, alignment: .leading)
            .glassEffect(.regular, in: .rect(cornerRadius: 20))

            Button {
                voice.confirm()
            } label: {
                Label(language.localized("Send"), systemImage: "paperplane.fill")
                    .frame(maxWidth: .infinity)
            }
            .buttonStyle(.glassProminent)
            .controlSize(.large)
            .disabled(voice.isSending)
            .accessibilityIdentifier("voice.confirm")

            cancelButton
        }
    }

    private var cancelButton: some View {
        Button(language.localized("Cancel"), role: .cancel) {
            voice.cancelAnswer()
        }
        .disabled(voice.isSending)
        .accessibilityIdentifier("voice.cancel")
    }

    private var listenAgainButton: some View {
        Button(language.localized("Try again"), systemImage: "mic.fill") {
            Task { await voice.listen() }
        }
        .buttonStyle(.glass)
        .accessibilityIdentifier("voice.listen-again")
    }
}

extension View {
    /// The microphone button in the tab bar's accessory, while "Ask Jarvis"
    /// is shown (`VoiceButton.isShown`).
    @ViewBuilder
    func voiceAccessory() -> some View {
        if VoiceButton.isShown {
            tabViewBottomAccessory {
                VoiceButton()
            }
        } else {
            self
        }
    }
}
