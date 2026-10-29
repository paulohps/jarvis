import Localization
import Navigation
import SwiftUI

extension View {
    /// The gear (Settings) and the bell (Notifications, with a dot while
    /// there are unread ones) on the toolbar of a module tab's root screen:
    /// they are not in the tab bar, so the module tabs keep room there.
    func shellToolbar() -> some View {
        modifier(ShellToolbar())
    }
}

private struct ShellToolbar: ViewModifier {
    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    func body(content: Content) -> some View {
        content
            .toolbar {
                ToolbarItem(placement: .topBarLeading) {
                    Button(language.localized("Settings"), systemImage: AppTab.settings.systemImage) {
                        model.router.presentedScreen = .settings
                    }
                    .accessibilityIdentifier("shell.settings")
                }

                ToolbarItem(placement: .topBarTrailing) {
                    Button(
                        language.localized("Notifications"),
                        systemImage: model.unreadNotifications > 0 ? "bell.badge" : AppTab.notifications.systemImage
                    ) {
                        model.router.presentedScreen = .notifications
                    }
                    .accessibilityValue(model.unreadNotifications > 0 ? Text(verbatim: "\(model.unreadNotifications)") : Text(verbatim: ""))
                    .accessibilityIdentifier("shell.notifications")
                }
            }
    }
}

/// Closes Notifications or Settings, opened as a sheet from the toolbar.
struct ShellCloseButton: ToolbarContent {
    @Environment(\.appLanguage) private var language
    @Environment(\.dismiss) private var dismiss

    var body: some ToolbarContent {
        ToolbarItem(placement: .cancellationAction) {
            Button(language.localized("Close"), systemImage: "xmark", role: .close) {
                dismiss()
            }
        }
    }
}
