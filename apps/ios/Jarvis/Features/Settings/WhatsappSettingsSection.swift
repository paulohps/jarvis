import Localization
import SwiftUI

/// WhatsApp's settings that hold for every chat of every number: the color
/// of each priority. Only with the `whatsapp` module.
struct WhatsappSettingsSection: View {
    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    var body: some View {
        if model.tabs.contains(.whatsapp) {
            Section {
                NavigationLink {
                    WhatsappPriorityColorsEditor()
                } label: {
                    Label(language.localized("Priority colors"), systemImage: "paintpalette")
                }
                .accessibilityIdentifier("settings.whatsapp-priority-colors")
            } header: {
                language.text("WhatsApp")
            } footer: {
                language.text("The same colors show on every chat, of every number.")
            }
        }
    }
}
