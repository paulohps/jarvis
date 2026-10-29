import Localization
import SwiftUI

private struct AppLanguageKey: EnvironmentKey {
    static let defaultValue: AppLanguage = .english
}

extension EnvironmentValues {
    /// The language the app speaks (the user's, from `/me`), for the String Catalog lookups.
    var appLanguage: AppLanguage {
        get { self[AppLanguageKey.self] }
        set { self[AppLanguageKey.self] = newValue }
    }
}

extension AppLanguage {
    /// A `Text` with the key's translation.
    func text(_ key: String) -> Text {
        Text(verbatim: localized(key))
    }
}
