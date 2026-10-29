import Auth
import Foundation
import Localization
import Networking
import Voice

/// What the Siri intents need when iOS runs them, with or without the app
/// open: the API client on the Keychain's token, the app's language and the
/// user's timezone (from the last `/me`).
enum VoiceIntentServices {
    static let languagePreference = LanguagePreference()

    static let service = VoiceService(
        client: APIClient(
            baseURL: AppConfiguration.current.apiBaseURL,
            tokenStore: KeychainTokenStore(),
            acceptLanguage: { languagePreference.current.languageTag }
        )
    )

    static var language: AppLanguage {
        languagePreference.current
    }

    static var timeZone: TimeZone {
        UserCache().load().flatMap { TimeZone(identifier: $0.timezone) } ?? .current
    }
}
