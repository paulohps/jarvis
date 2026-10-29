import Localization
import SwiftUI

/// What a module screen shows when the server answers 403 (US-11.1).
struct NoAccessView: View {
    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    var body: some View {
        ContentUnavailableView {
            Label(language.localized("No access"), systemImage: "lock.fill")
        } description: {
            language.text("This module is not enabled for your account. Ask the admin to switch it on.")
        } actions: {
            Button(language.localized("Try again")) {
                Task { await model.refreshUser() }
            }
            .buttonStyle(.glass)
        }
        .accessibilityIdentifier("no-access")
    }
}
