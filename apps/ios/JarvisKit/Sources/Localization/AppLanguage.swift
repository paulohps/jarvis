import Foundation

/// The languages of Jarvis (US-3.2): English by default and Brazilian
/// Portuguese. The raw value is the server's locale (`/me` → `locale`).
public enum AppLanguage: String, CaseIterable, Sendable, Identifiable {
    case english = "en"
    case brazilianPortuguese = "pt_BR"

    public var id: String { rawValue }

    /// The language of a server locale (`pt_BR`, `pt-BR`); English for any other.
    public init(apiLocale: String) {
        switch apiLocale.replacingOccurrences(of: "-", with: "_").lowercased() {
        case "pt_br", "pt":
            self = .brazilianPortuguese
        default:
            self = .english
        }
    }

    /// The first supported language of the device, before the user signs in.
    public static func matching(preferredLanguages: [String]) -> AppLanguage {
        for identifier in preferredLanguages {
            if identifier.lowercased().hasPrefix("pt") {
                return .brazilianPortuguese
            }

            if identifier.lowercased().hasPrefix("en") {
                return .english
            }
        }

        return .english
    }

    /// The BCP 47 tag: the `.lproj` folder, the `Accept-Language` header and the `Locale`.
    public var languageTag: String {
        switch self {
        case .english: "en"
        case .brazilianPortuguese: "pt-BR"
        }
    }

    public var locale: Locale {
        Locale(identifier: languageTag)
    }

    /// The name of the language in itself, as the language picker shows it.
    public var nativeName: String {
        switch self {
        case .english: "English"
        case .brazilianPortuguese: "Português (Brasil)"
        }
    }

    /// The text of a key of the String Catalog in this language, the key itself when missing.
    public func localized(_ key: String) -> String {
        bundle.localizedString(forKey: key, value: key, table: "Localizable")
    }

    /// The text of a key with `%@` / `%lld` placeholders filled in.
    public func localized(_ key: String, _ arguments: any CVarArg...) -> String {
        String(format: localized(key), locale: locale, arguments: arguments)
    }

    private var bundle: Bundle {
        Self.bundles[self] ?? .module
    }

    private static let bundles: [AppLanguage: Bundle] = Dictionary(
        uniqueKeysWithValues: AppLanguage.allCases.compactMap { language in
            Bundle.module.path(forResource: language.languageTag, ofType: "lproj")
                .flatMap(Bundle.init(path:))
                .map { (language, $0) }
        }
    )
}
