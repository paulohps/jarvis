import Foundation

/// Where a deep link takes the app.
public enum AppRoute: Equatable, Hashable, Sendable {
    /// A tab at its root.
    case tab(AppTab)

    /// A reminder, by the friendly id of the reminder (`rem_…`) or of its occurrence (`remo_…`).
    case reminder(id: String)

    /// A shopping list, by its friendly id (`shl_…`).
    case shoppingList(id: String)

    /// A WhatsApp chat, by the friendly id of its contact or group (`wtgt_…`).
    case whatsappChat(id: String)

    /// A page that exists only on the web (an invoice, the admin area): opened in the browser.
    case web(URL)

    /// The tab the route lives in, `nil` for the web.
    public var tab: AppTab? {
        switch self {
        case .tab(let tab): tab
        case .reminder: .reminders
        case .shoppingList: .shopping
        case .whatsappChat: .whatsapp
        case .web: nil
        }
    }
}

/// Resolves a deep link (US-5.2): the `deep_link` of a push or a link of a
/// notification. It reads the app scheme (`jarvis://reminders/{id}`) and the
/// same paths on the server's web URL (`https://jarvis.example/reminders`),
/// which is what the notification actions carry.
public struct DeepLinkRouter: Sendable {
    public static let scheme = "jarvis"

    private let webHosts: Set<String>

    /// - Parameter webBaseURL: the server; its links are read as app links.
    public init(webBaseURL: URL?) {
        webHosts = Set([webBaseURL?.host()?.lowercased()].compactMap { $0 })
    }

    public func route(for url: URL) -> AppRoute? {
        let scheme = url.scheme?.lowercased()
        let segments: [String]

        if scheme == Self.scheme {
            segments = ([url.host()].compactMap { $0 } + url.pathComponents).filter { $0 != "/" && !$0.isEmpty }
        } else if scheme == "https" || scheme == "http", let host = url.host()?.lowercased(), webHosts.contains(host) {
            segments = url.pathComponents.filter { $0 != "/" && !$0.isEmpty }
        } else {
            return nil
        }

        return route(for: segments.map { $0.removingPercentEncoding ?? $0 }, originalURL: url, isWeb: scheme != Self.scheme)
    }

    /// The value of a query item of the link, if it has one.
    private func query(_ name: String, of url: URL) -> String? {
        URLComponents(url: url, resolvingAgainstBaseURL: false)?
            .queryItems?
            .first { $0.name == name }?
            .value
            .flatMap { $0.isEmpty ? nil : $0 }
    }

    private func route(for segments: [String], originalURL: URL, isWeb: Bool) -> AppRoute? {
        guard let first = segments.first?.lowercased() else {
            return isWeb ? nil : .tab(.notifications)
        }

        let rest = Array(segments.dropFirst())

        switch first {
        case "reminders", "reminder-occurrences":
            return rest.first.map { .reminder(id: $0) } ?? .tab(.reminders)
        case "shopping":
            // `shopping/lists/{id}` (the web's path) or `shopping/{id}`.
            let id = rest.first?.lowercased() == "lists" ? rest.dropFirst().first : rest.first

            return id.map { .shoppingList(id: $0) } ?? .tab(.shopping)
        case "whatsapp":
            // `whatsapp/chat?chat={id}`: the chat a push is about.
            if rest.first?.lowercased() == "chat", let id = query("chat", of: originalURL) {
                return .whatsappChat(id: id)
            }

            return .tab(.whatsapp)
        case "notifications":
            return .tab(.notifications)
        case "settings", "profile":
            return .tab(.settings)
        case "invoices", "admin":
            return isWeb ? .web(originalURL) : nil
        default:
            return nil
        }
    }
}
