import Localization
import Navigation
import SwiftUI

/// The tabs of the signed-in user (US-11.1): the mobile modules they have,
/// with the microphone button above the tab bar (US-10.1). Notifications and
/// Settings open as sheets from the toolbar's bell and gear, so the tab bar
/// keeps room for the modules.
struct MainTabView: View {
    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    var body: some View {
        @Bindable var router = model.router

        tabs
            .sheet(item: $router.presentedScreen) { screen in
                self.screen(for: screen)
            }
            .sheet(isPresented: voicePresented) {
                VoiceSheet()
            }
    }

    @ViewBuilder
    private var tabs: some View {
        @Bindable var router = model.router
        let moduleTabs = model.tabs.filter(\.isModule)

        if moduleTabs.isEmpty {
            NavigationStack {
                ContentUnavailableView(
                    language.localized("No modules on mobile"),
                    systemImage: "square.grid.2x2",
                    description: language.text("Ask the admin to switch a module on for your account.")
                )
                .shellToolbar()
            }
        } else {
            TabView(selection: $router.selectedTab) {
                ForEach(moduleTabs, id: \.self) { tab in
                    Tab(language.localized(tab.titleKey), systemImage: tab.systemImage, value: tab) {
                        screen(for: tab)
                    }
                    .accessibilityIdentifier("tab.\(tab.rawValue)")
                }
            }
            .tabBarMinimizeBehavior(.onScrollDown)
            .voiceAccessory()
        }
    }

    /// The voice sheet; closing it by a swipe stops the listening too.
    private var voicePresented: Binding<Bool> {
        Binding {
            model.voice.isPresented
        } set: { isPresented in
            if !isPresented {
                model.voice.dismiss()
            }
        }
    }

    @ViewBuilder
    private func screen(for tab: AppTab) -> some View {
        switch tab {
        case .shopping:
            ShoppingHomeView()
        case .reminders:
            RemindersHomeView()
        case .whatsapp:
            WhatsAppHomeView()
        case .notifications:
            NotificationsView()
        case .settings:
            SettingsView()
        }
    }
}
