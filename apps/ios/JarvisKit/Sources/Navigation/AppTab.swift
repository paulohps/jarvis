import Networking

/// A tab of the app (US-11.1). The module tabs exist only for the modules the
/// user has; Notifications and Settings are always there, opened from the
/// toolbar rather than the tab bar. Invoices and the
/// admin area are web only and have no tab.
public enum AppTab: String, CaseIterable, Hashable, Identifiable, Sendable {
    case shopping
    case reminders
    case whatsapp
    case notifications
    case settings

    public var id: String {
        rawValue
    }

    /// The module slug of `/me` the tab needs, `nil` for the tabs everyone has.
    public var moduleSlug: String? {
        switch self {
        case .shopping: "shopping"
        case .reminders: "reminders"
        case .whatsapp: "whatsapp"
        case .notifications, .settings: nil
        }
    }

    /// A module's tab is in the tab bar; Notifications and Settings open from
    /// the toolbar (the bell and the gear) over it.
    public var isModule: Bool {
        moduleSlug != nil
    }

    /// The tab title, a key of the String Catalog.
    public var titleKey: String {
        switch self {
        case .shopping: "Shopping"
        case .reminders: "Agenda"
        case .whatsapp: "WhatsApp"
        case .notifications: "Notifications"
        case .settings: "Settings"
        }
    }

    public var systemImage: String {
        switch self {
        case .shopping: "cart"
        case .reminders: "calendar"
        case .whatsapp: "bubble.left.and.text.bubble.right"
        case .notifications: "bell"
        case .settings: "gearshape"
        }
    }
}

/// Builds the tabs from the modules of `/me`.
public enum TabBuilder {
    /// Shopping, Reminders and WhatsApp, in this order, for each module the
    /// user has that is available on mobile; then Notifications and Settings.
    /// Any other slug (invoices, a module the app does not know yet) has no tab.
    public static func tabs(for modules: [UserModule]) -> [AppTab] {
        let mobileSlugs = Set(modules.filter(\.isAvailableOnMobile).map(\.slug))

        return AppTab.allCases.filter { tab in
            guard let slug = tab.moduleSlug else {
                return true
            }

            return mobileSlugs.contains(slug)
        }
    }

    /// The tab to show after the tabs changed: the same one while it exists,
    /// otherwise the first.
    public static func selection(keeping selected: AppTab, in tabs: [AppTab]) -> AppTab {
        tabs.contains(selected) ? selected : (tabs.first ?? .settings)
    }
}
