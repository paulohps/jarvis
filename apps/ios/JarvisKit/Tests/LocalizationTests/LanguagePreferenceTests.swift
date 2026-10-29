import Foundation
import XCTest
@testable import Localization

final class LanguagePreferenceTests: XCTestCase {
    private var defaults: UserDefaults!
    private var suiteName = ""

    override func setUp() async throws {
        suiteName = "app.jarvis.ios.tests.\(UUID().uuidString)"
        defaults = UserDefaults(suiteName: suiteName)
    }

    override func tearDown() async throws {
        defaults.removePersistentDomain(forName: suiteName)
    }

    func testBeforeSignInTheLanguageFollowsTheDevice() {
        XCTAssertEqual(LanguagePreference(defaults: defaults, preferredLanguages: { ["pt-BR", "en-US"] }).current, .brazilianPortuguese)
        XCTAssertEqual(LanguagePreference(defaults: defaults, preferredLanguages: { ["fr-FR", "en-GB"] }).current, .english)
        XCTAssertEqual(LanguagePreference(defaults: defaults, preferredLanguages: { ["de-DE"] }).current, .english)
    }

    func testTheUsersLocaleFromMeWinsOverTheDevice() {
        let preference = LanguagePreference(defaults: defaults, preferredLanguages: { ["en-US"] })

        XCTAssertEqual(preference.apply(apiLocale: "pt_BR"), .brazilianPortuguese)
        XCTAssertEqual(preference.current, .brazilianPortuguese)
        XCTAssertEqual(LanguagePreference(defaults: defaults, preferredLanguages: { ["en-US"] }).current, .brazilianPortuguese)

        preference.reset()
        XCTAssertEqual(preference.current, .english)
    }

    func testTheStringCatalogHasBothLanguages() {
        XCTAssertEqual(AppLanguage.english.localized("Sign in"), "Sign in")
        XCTAssertEqual(AppLanguage.brazilianPortuguese.localized("Sign in"), "Entrar")
        XCTAssertEqual(AppLanguage.brazilianPortuguese.localized("Due %@", "amanhã"), "Vence amanhã")
        XCTAssertEqual(AppLanguage.brazilianPortuguese.languageTag, "pt-BR")
    }
}
