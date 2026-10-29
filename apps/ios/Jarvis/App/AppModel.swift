import Auth
import Foundation
import Localization
import Navigation
import Networking
import Observation
import UIKit

/// Where the app is: opening, at the login, or signed in as a user.
enum SessionState: Equatable {
    case launching
    case signedOut(notice: String?)
    case signedIn(User)
}

/// The state of the app shell: the session, the language and the user from
/// `/me`. Any 401 of any call (`SessionEvent.sessionExpired`) takes the app
/// back to the login (US-1.5); the language follows the user's preference
/// read from `/me` (US-3.2).
@MainActor
@Observable
final class AppModel {
    let configuration: AppConfiguration
    let client: APIClient
    let auth: AuthService

    let router = Router()
    let push: PushCoordinator
    let deepLinks: DeepLinkRouter
    let notifications: NotificationsStore
    let realtime: RealtimeCoordinator
    let shoppingSync: ShoppingSync
    let voice: VoiceController
    let whatsapp: WhatsappLive

    private(set) var state: SessionState = .launching
    private(set) var language: AppLanguage

    /// The tabs of the signed-in user, from the modules of `/me` (US-11.1).
    private(set) var tabs: [AppTab] = []

    /// The badge of the Notifications tab.
    var unreadNotifications: Int {
        notifications.unreadCount
    }

    @ObservationIgnored private let languagePreference: LanguagePreference
    @ObservationIgnored private let userCache: UserCache
    @ObservationIgnored private var sessionEventsTask: Task<Void, Never>?

    /// A deep link opened before the session was ready (a push that launched the app).
    @ObservationIgnored private var pendingRoute: AppRoute?

    /// The WhatsApp chat on screen, if any: its pushes are not shown while
    /// the user reads it (`AppDelegate`).
    @ObservationIgnored var openWhatsappChatId: String?

    init(
        configuration: AppConfiguration = .current,
        tokenStore: any TokenStoring = KeychainTokenStore(),
        transport: any HTTPTransport = URLSessionTransport(),
        languagePreference: LanguagePreference = LanguagePreference(),
        userCache: UserCache = UserCache()
    ) {
        self.configuration = configuration
        self.languagePreference = languagePreference
        self.userCache = userCache
        language = languagePreference.current

        client = APIClient(
            baseURL: configuration.apiBaseURL,
            tokenStore: tokenStore,
            transport: transport,
            acceptLanguage: { languagePreference.current.languageTag }
        )

        let deviceName = UIDevice.current.name
        auth = AuthService(client: client, deviceName: { deviceName })
        push = PushCoordinator(client: client, deviceName: deviceName)
        deepLinks = DeepLinkRouter(webBaseURL: configuration.apiBaseURL)

        let notifications = NotificationsStore(client: client)
        self.notifications = notifications
        realtime = RealtimeCoordinator(configuration: configuration, client: client)
        shoppingSync = ShoppingSync(client: client)
        voice = VoiceController(client: client)
        let whatsapp = WhatsappLive(client: client)
        self.whatsapp = whatsapp
        realtime.onNotificationCreated = { event in
            await notifications.didReceive(event)
        }
        realtime.onUserEvent = { event in
            await whatsapp.handle(event)
        }
        whatsapp.openChatId = { [weak self] in self?.openWhatsappChatId }
        whatsapp.isAvailable = { [weak self] in self?.tabs.contains(.whatsapp) ?? false }
    }

    var user: User? {
        if case .signedIn(let user) = state {
            return user
        }

        return nil
    }

    /// Opens the session kept in the Keychain, if any, and starts listening to the client.
    func start() async {
        observeSessionEvents()

        guard auth.isSignedIn else {
            state = .signedOut(notice: nil)

            return
        }

        if let cachedUser = userCache.load() {
            apply(cachedUser)
        }

        await refreshUser()

        if state == .launching {
            state = .signedOut(notice: nil)
        }
    }

    /// Reads `/me` again: the modules, the language and the timezone may have changed.
    func refreshUser() async {
        guard auth.isSignedIn else {
            return
        }

        do {
            apply(try await client.send(API.me()).data)
        } catch {
            // A 401 already took the app to the login; other failures keep what is shown.
        }
    }

    /// After the login screen got a token.
    func didSignIn(as user: User) {
        apply(user)
    }

    func signOut() async {
        await auth.logout()
        endSession(notice: nil)
    }

    /// Back to the foreground: `/me` (the modules), the realtime connection,
    /// what arrived meanwhile and the push permission.
    func didBecomeActive() async {
        await refreshUser()

        guard let user else {
            return
        }

        await realtime.start(channel: user.broadcastChannel)
        await shoppingSync.flush()
        await notifications.reload()
        await whatsapp.prime()
        await push.refresh()
    }

    /// In the background iOS drops the socket; it is closed cleanly instead.
    func didEnterBackground() async {
        await realtime.pause()
    }

    /// Opens a deep link (US-5.2): a push's `deep_link` or a `jarvis://` URL.
    func open(deepLink: String?) {
        guard let deepLink, let url = URL(string: deepLink) else {
            return
        }

        open(url)
    }

    func open(_ url: URL) {
        guard let route = deepLinks.route(for: url) else {
            return
        }

        if case .web(let webURL) = route {
            UIApplication.shared.open(webURL)

            return
        }

        if user == nil {
            pendingRoute = route
        } else {
            router.open(route, in: tabs)
        }
    }

    /// Saves the language on the server (US-3.2); the web follows it too.
    func updateLanguage(_ newLanguage: AppLanguage) async throws(APIError) {
        apply(try await client.send(API.updatePreferences(locale: newLanguage.rawValue)).data)
    }

    /// Saves the user's own color for a workspace or makes it their default,
    /// then reads `/me` again: making one the default unsets the previous.
    func updateWorkspacePreference(id: String, _ input: WorkspacePreferenceInput) async throws(APIError) {
        try await client.send(API.updateWorkspacePreference(id: id, input))
        await refreshUser()
    }

    private func apply(_ user: User) {
        let isNewSession = self.user == nil

        userCache.save(user)
        language = languagePreference.apply(apiLocale: user.locale)
        tabs = TabBuilder.tabs(for: user.modules)
        state = .signedIn(user)

        if isNewSession {
            router.reset(to: tabs)
            Task {
                await realtime.start(channel: user.broadcastChannel)
                await shoppingSync.flush()
                await notifications.reload()
                await whatsapp.prime()
                await push.start()
            }
        } else {
            router.keepSelection(in: tabs)
        }

        if let pendingRoute {
            self.pendingRoute = nil
            router.open(pendingRoute, in: tabs)
        }
    }

    private func endSession(notice: String?) {
        Task {
            await realtime.stop()
            await shoppingSync.clear()
            await push.reset()
        }
        voice.dismiss()
        whatsapp.reset()
        notifications.clear()
        userCache.clear()
        languagePreference.reset()
        language = languagePreference.current
        tabs = []
        state = .signedOut(notice: notice)
    }

    private func observeSessionEvents() {
        guard sessionEventsTask == nil else {
            return
        }

        let events = client.sessionEvents()

        sessionEventsTask = Task { [weak self] in
            for await event in events {
                await self?.handle(event)
            }
        }
    }

    private func handle(_ event: SessionEvent) async {
        switch event {
        case .sessionExpired:
            guard state != .signedOut(notice: nil) else {
                return
            }

            endSession(notice: language.localized("Your session has expired. Sign in again."))
        case .accessDenied:
            await refreshUser()
        }
    }
}
