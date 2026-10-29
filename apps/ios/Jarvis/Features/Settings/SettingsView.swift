import Localization
import Networking
import SwiftUI

/// The account, the language (saved on the server, US-3.2), the workspaces
/// (own color, default), WhatsApp's priority colors, the push, the
/// voice interpreter (US-10.2), the super admin's administration and the sign-out.
struct SettingsView: View {
    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    @State private var isConfirmingSignOut = false
    @State private var errorMessage: String?

    var body: some View {
        NavigationStack {
            Form {
                if let user = model.user {
                    Section(language.localized("Account")) {
                        LabeledContent {
                            Text(verbatim: user.email)
                        } label: {
                            Text(verbatim: user.name)
                        }
                    }
                }

                Section {
                    Picker(language.localized("Language"), selection: languageBinding) {
                        ForEach(AppLanguage.allCases) { option in
                            Text(verbatim: option.nativeName).tag(option)
                        }
                    }
                } footer: {
                    if let errorMessage {
                        Text(verbatim: errorMessage).foregroundStyle(.red)
                    }
                }

                WorkspacesSettingsSection()

                WhatsappSettingsSection()

                PushSettingsSection()

                VoiceSettingsSection()

                AdminSettingsSection()

                Section {
                    Button(language.localized("Sign out"), role: .destructive) {
                        isConfirmingSignOut = true
                    }
                    .accessibilityIdentifier("settings.sign-out")
                } footer: {
                    Text(verbatim: "\(language.localized("Version")) \(Self.appVersion)")
                }
            }
            .navigationTitle(language.localized("Settings"))
            .toolbar {
                ShellCloseButton()
            }
            .confirmationDialog(
                language.localized("Sign out of Jarvis on this iPhone?"),
                isPresented: $isConfirmingSignOut,
                titleVisibility: .visible
            ) {
                Button(language.localized("Sign out"), role: .destructive) {
                    Task { await model.signOut() }
                }
                Button(language.localized("Cancel"), role: .cancel) {}
            }
        }
    }

    private var languageBinding: Binding<AppLanguage> {
        Binding {
            model.language
        } set: { newLanguage in
            errorMessage = nil

            Task {
                do throws(APIError) {
                    try await model.updateLanguage(newLanguage)
                } catch {
                    errorMessage = error.displayMessage ?? language.localized("Something went wrong. Try again.")
                }
            }
        }
    }

    private static var appVersion: String {
        let info = Bundle.main.infoDictionary
        let version = info?["CFBundleShortVersionString"] as? String ?? "–"
        let build = info?["CFBundleVersion"] as? String ?? "–"

        return "\(version) (\(build))"
    }
}
