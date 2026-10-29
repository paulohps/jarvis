import Foundation

/// The settings of this build, from `Support/Info.plist`, filled by
/// `Config/Base.xcconfig` and the machine's `Config/Local.xcconfig`.
struct AppConfiguration: Sendable {
    let apiBaseURL: URL
    let reverbURL: URL
    let reverbAppKey: String
    let googleClientID: String?

    static let current = AppConfiguration(bundle: .main)

    init(bundle: Bundle) {
        func value(_ key: String) -> String? {
            let text = (bundle.object(forInfoDictionaryKey: key) as? String)?.trimmingCharacters(in: .whitespaces)

            return text?.isEmpty == false ? text : nil
        }

        apiBaseURL = value("JarvisAPIBaseURL").flatMap(URL.init(string:)) ?? URL(string: "http://localhost")!
        reverbURL = value("JarvisReverbURL").flatMap(URL.init(string:)) ?? URL(string: "ws://localhost:8080")!
        reverbAppKey = value("JarvisReverbAppKey") ?? ""
        googleClientID = value("GIDClientID")
    }

    init(apiBaseURL: URL, reverbURL: URL, reverbAppKey: String, googleClientID: String?) {
        self.apiBaseURL = apiBaseURL
        self.reverbURL = reverbURL
        self.reverbAppKey = reverbAppKey
        self.googleClientID = googleClientID
    }
}
