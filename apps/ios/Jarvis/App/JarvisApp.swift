import Localization
import SwiftUI

@main
struct JarvisApp: App {
    @UIApplicationDelegateAdaptor(AppDelegate.self) private var appDelegate
    @Environment(\.scenePhase) private var scenePhase

    private var model: AppModel {
        appDelegate.model
    }

    var body: some Scene {
        WindowGroup {
            RootView()
                .environment(model)
                .environment(\.appLanguage, model.language)
                .environment(\.locale, model.language.locale)
                .task {
                    await model.start()
                }
                .onOpenURL { url in
                    if !GoogleSignInProvider.handle(url) {
                        model.open(url)
                    }
                }
                .onChange(of: scenePhase) { _, phase in
                    switch phase {
                    case .active:
                        // Back to the foreground: the modules may have changed (US-11.1).
                        Task { await model.didBecomeActive() }
                    case .background:
                        Task { await model.didEnterBackground() }
                    default:
                        break
                    }
                }
        }
    }
}

/// The login or the app, by the session.
struct RootView: View {
    @Environment(AppModel.self) private var model

    var body: some View {
        ZStack {
            switch model.state {
            case .launching:
                SplashView()
                    .transition(.opacity)
            case .signedOut(let notice):
                LoginView(notice: notice)
            case .signedIn(let user):
                MainTabView()
                    .overlay(alignment: .top) {
                        WhatsappBannerOverlay()
                    }
                    .environment(\.timeZone, TimeZone(identifier: user.timezone) ?? .current)
            }
        }
        // Only the way out of the splash fades; the sign-in and sign-out swap at once.
        .animation(.easeOut(duration: 0.35), value: model.state == .launching)
    }
}
