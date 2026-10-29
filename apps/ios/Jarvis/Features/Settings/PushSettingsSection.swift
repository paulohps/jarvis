import Localization
import Push
import SwiftUI
import UIKit

/// The push permission in the Settings tab (US-5.2). A refusal is only shown,
/// with the way back through the iOS Settings.
struct PushSettingsSection: View {
    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language
    @Environment(\.openURL) private var openURL

    var body: some View {
        Section {
            LabeledContent(language.localized("Push notifications")) {
                Text(verbatim: language.localized(statusKey))
            }

            switch model.push.authorization {
            case .notDetermined:
                Button(language.localized("Allow notifications")) {
                    Task { await model.push.start() }
                }
            case .denied:
                Button(language.localized("Open iOS Settings")) {
                    if let url = URL(string: UIApplication.openSettingsURLString) {
                        openURL(url)
                    }
                }
            case .authorized:
                EmptyView()
            }
        } footer: {
            if model.push.authorization == .denied {
                language.text("Notifications are off. You can turn them on in the iOS Settings.")
            }
        }
    }

    private var statusKey: String {
        switch model.push.authorization {
        case .authorized: "On"
        case .denied: "Off"
        case .notDetermined: "Not asked yet"
        }
    }
}
