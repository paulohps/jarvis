import Foundation

/// The language the app speaks. Before the sign-in it follows the device;
/// afterwards it follows the user's preference from `/me`, saved so the next
/// launch already opens in it (US-3.2).
public final class LanguagePreference: @unchecked Sendable {
    static let storageKey = "jarvis.language"

    private let defaults: UserDefaults
    private let preferredLanguages: @Sendable () -> [String]

    public init(
        defaults: UserDefaults = .standard,
        preferredLanguages: @escaping @Sendable () -> [String] = { Locale.preferredLanguages }
    ) {
        self.defaults = defaults
        self.preferredLanguages = preferredLanguages
    }

    /// The saved language, otherwise the device's.
    public var current: AppLanguage {
        if let saved = defaults.string(forKey: Self.storageKey), let language = AppLanguage(rawValue: saved) {
            return language
        }

        return AppLanguage.matching(preferredLanguages: preferredLanguages())
    }

    /// Keeps the user's server locale (`/me` → `locale`) and gives back the language.
    @discardableResult
    public func apply(apiLocale: String) -> AppLanguage {
        let language = AppLanguage(apiLocale: apiLocale)
        defaults.set(language.rawValue, forKey: Self.storageKey)

        return language
    }

    /// Back to the device's language (after the sign-out).
    public func reset() {
        defaults.removeObject(forKey: Self.storageKey)
    }
}
