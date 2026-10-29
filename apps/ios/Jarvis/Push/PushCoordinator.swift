import Networking
import Observation
import Push
import UIKit
import UserNotifications

/// The push of this device (US-5.2): the permission, the APNs registration
/// (FCM maps it to its own token) and `POST /devices` through `PushRegistration`.
@MainActor
@Observable
final class PushCoordinator {
    private(set) var authorization: PushAuthorization = .notDetermined

    @ObservationIgnored private let registration: PushRegistration

    /// Firebase needs `GoogleService-Info.plist` (git-ignored); without it the app runs without push.
    static let isFirebaseConfigured = Bundle.main.path(forResource: "GoogleService-Info", ofType: "plist") != nil

    init(client: APIClient, deviceName: String) {
        registration = PushRegistration(client: client, deviceName: deviceName, authorizer: SystemPushAuthorizer())
    }

    /// After the sign-in: asks the permission once; a refusal only leaves push off.
    func start() async {
        await apply(shouldRegister: await registration.start())
    }

    /// Back to the foreground: the permission may have changed in the iOS Settings.
    func refresh() async {
        await apply(shouldRegister: await registration.refreshAuthorization())
    }

    func didReceive(fcmToken: String?) async {
        await registration.didReceive(fcmToken: fcmToken)
    }

    func reset() async {
        await registration.reset()
        authorization = .notDetermined
    }

    private func apply(shouldRegister: Bool) async {
        authorization = await registration.authorization

        if shouldRegister, Self.isFirebaseConfigured {
            UIApplication.shared.registerForRemoteNotifications()
        }
    }
}

/// The notification permission of iOS.
struct SystemPushAuthorizer: PushAuthorizing {
    func currentAuthorization() async -> PushAuthorization {
        switch await UNUserNotificationCenter.current().notificationSettings().authorizationStatus {
        case .notDetermined:
            .notDetermined
        case .denied:
            .denied
        default:
            .authorized
        }
    }

    func requestAuthorization() async -> Bool {
        (try? await UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .badge, .sound])) ?? false
    }
}
