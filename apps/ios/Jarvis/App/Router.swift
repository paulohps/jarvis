import Navigation
import Observation

/// The screens a module tab can push.
enum ReminderDestination: Hashable {
    case occurrence(id: String)
}

enum ShoppingDestination: Hashable {
    case list(id: String)
}

/// Which tab is shown, what each tab has pushed, and whether Notifications
/// or Settings is open over them (the toolbar's bell and gear).
@MainActor
@Observable
final class Router {
    var selectedTab: AppTab = .shopping
    var presentedScreen: AppTab?
    var remindersPath: [ReminderDestination] = []
    var shoppingPath: [ShoppingDestination] = []
    var whatsappPath: [WhatsappDestination] = []

    /// Keeps the selection valid after the tabs changed (a module switched off).
    func keepSelection(in tabs: [AppTab]) {
        selectedTab = TabBuilder.selection(keeping: selectedTab, in: tabs.filter(\.isModule))
    }

    /// Shows a deep link's tab and screen (US-5.2).
    /// - Returns: `false` when the user has no such tab (a module switched off).
    @discardableResult
    func open(_ route: AppRoute, in tabs: [AppTab]) -> Bool {
        guard let tab = route.tab, tabs.contains(tab) else {
            return false
        }

        guard tab.isModule else {
            presentedScreen = tab
            return true
        }

        selectedTab = tab
        presentedScreen = nil

        switch route {
        case .reminder(let id):
            remindersPath = [.occurrence(id: id)]
        case .tab(.reminders):
            remindersPath = []
        case .shoppingList(let id):
            shoppingPath = [.list(id: id)]
        case .tab(.shopping):
            shoppingPath = []
        case .whatsappChat(let id):
            whatsappPath = [.chat(id: id, preview: nil)]
        case .tab, .web:
            break
        }

        return true
    }

    func reset(to tabs: [AppTab]) {
        selectedTab = tabs.first(where: \.isModule) ?? .shopping
        presentedScreen = nil
        remindersPath = []
        shoppingPath = []
        whatsappPath = []
    }
}
