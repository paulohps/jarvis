import Foundation
import Networking

/// What the user answered to the notification permission.
public enum PushAuthorization: Equatable, Sendable {
    case notDetermined
    case denied
    case authorized
}

/// The system's notification permission (`UNUserNotificationCenter` in the app).
public protocol PushAuthorizing: Sendable {
    func currentAuthorization() async -> PushAuthorization

    /// Shows the system prompt; `true` when the user allowed.
    func requestAuthorization() async -> Bool
}

/// Registers this device for push (US-5.2): asks the permission once, and
/// sends the FCM token to `POST /devices`, bound to the device's Sanctum token.
///
/// A refused permission never breaks the app: the device is still registered,
/// with no push token, so the server stops sending to an old one.
public actor PushRegistration {
    private let client: APIClient
    private let deviceName: String
    private let authorizer: any PushAuthorizing

    private var latestToken: String?
    private var sentToken: String??
    private(set) public var authorization: PushAuthorization = .notDetermined

    public init(client: APIClient, deviceName: String, authorizer: any PushAuthorizing) {
        self.client = client
        self.deviceName = deviceName
        self.authorizer = authorizer
    }

    /// After the sign-in: asks the permission when it was never asked.
    /// - Returns: whether the app should register for remote notifications.
    @discardableResult
    public func start() async -> Bool {
        authorization = await authorizer.currentAuthorization()

        if authorization == .notDetermined {
            authorization = await authorizer.requestAuthorization() ? .authorized : .denied
        }

        return await sync()
    }

    /// The permission may have changed in the iOS Settings (back to the foreground).
    @discardableResult
    public func refreshAuthorization() async -> Bool {
        authorization = await authorizer.currentAuthorization()

        return await sync()
    }

    /// A new FCM token from Firebase Messaging (at launch and whenever it rotates).
    public func didReceive(fcmToken: String?) async {
        latestToken = fcmToken
        await sync()
    }

    /// After the sign-out: the next session registers its own device. The FCM
    /// token belongs to the app install, so it is kept for that session.
    public func reset() {
        sentToken = nil
        authorization = .notDetermined
    }

    /// Sends what the server should know, once per value.
    @discardableResult
    private func sync() async -> Bool {
        guard client.tokenStore.token() != nil else {
            return authorization == .authorized
        }

        switch authorization {
        case .authorized:
            if let latestToken {
                await send(pushToken: latestToken)
            }

            return true
        case .denied:
            await send(pushToken: nil)

            return false
        case .notDetermined:
            return false
        }
    }

    private func send(pushToken: String?) async {
        guard sentToken != .some(pushToken) else {
            return
        }

        do {
            try await client.send(API.registerDevice(DeviceRegistration(name: deviceName, pushToken: pushToken)))
            sentToken = .some(pushToken)
        } catch {
            // Tried again on the next token or foreground.
        }
    }
}
