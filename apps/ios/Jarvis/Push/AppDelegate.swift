import FirebaseCore
import FirebaseMessaging
import UIKit
import UserNotifications

/// The UIKit side of the app: Firebase, the APNs token and the taps on a push.
/// It owns the `AppModel`, so a push that launches the app finds it.
final class AppDelegate: NSObject, UIApplicationDelegate, UNUserNotificationCenterDelegate, MessagingDelegate {
    let model = AppModel()

    func application(
        _ application: UIApplication,
        didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]? = nil
    ) -> Bool {
        UNUserNotificationCenter.current().delegate = self

        if PushCoordinator.isFirebaseConfigured {
            FirebaseApp.configure()
            Messaging.messaging().delegate = self
        }

        return true
    }

    func application(_ application: UIApplication, didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data) {
        // `FirebaseAppDelegateProxyEnabled` is off (Info.plist): the APNs token is handed over here.
        if PushCoordinator.isFirebaseConfigured {
            Messaging.messaging().apnsToken = deviceToken
        }
    }

    func application(_ application: UIApplication, didFailToRegisterForRemoteNotificationsWithError error: any Error) {
        // No APNs (simulator without push, no entitlement): the app goes on without push.
    }

    // MARK: - MessagingDelegate

    nonisolated func messaging(_ messaging: Messaging, didReceiveRegistrationToken fcmToken: String?) {
        Task { @MainActor in
            await self.model.push.didReceive(fcmToken: fcmToken)
        }
    }

    // MARK: - UNUserNotificationCenterDelegate

    /// A push while the app is open is shown too (the notification list
    /// updates in realtime), except a WhatsApp message of the chat the user
    /// is reading, as WhatsApp does, or any WhatsApp message while the
    /// realtime connection is up: the app shows its own banner then
    /// (`WhatsappLive`).
    nonisolated func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        willPresent notification: UNNotification
    ) async -> UNNotificationPresentationOptions {
        let thread = notification.request.content.userInfo["thread"] as? String
        let (openChatId, isLive) = await MainActor.run {
            (self.model.openWhatsappChatId, self.model.realtime.connectionState.isConnected)
        }

        if let thread, thread.hasPrefix("whatsapp:"), isLive || thread == openChatId.map({ "whatsapp:\($0)" }) {
            return []
        }

        return [.banner, .list, .sound]
    }

    /// Tapping a push opens its `deep_link` in the right tab and screen.
    nonisolated func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        didReceive response: UNNotificationResponse
    ) async {
        let deepLink = response.notification.request.content.userInfo["deep_link"] as? String

        await MainActor.run {
            self.model.open(deepLink: deepLink)
        }
    }
}
