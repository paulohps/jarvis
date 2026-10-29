import Foundation
import Networking

/// Signs the device in and out (US-1.5, US-1.3). A successful sign-in, with
/// e-mail and password or with the ID token of the native Google Sign-In,
/// saves the device's Sanctum token; the sign-out revokes it on the server
/// and always forgets it here, even without network.
public struct AuthService: Sendable {
    private let client: APIClient
    private let deviceName: @Sendable () -> String

    /// - Parameter deviceName: the name of the device as the user set it (`UIDevice.current.name`).
    public init(client: APIClient, deviceName: @escaping @Sendable () -> String) {
        self.client = client
        self.deviceName = deviceName
    }

    public var isSignedIn: Bool {
        client.tokenStore.token() != nil
    }

    public func login(email: String, password: String) async throws(APIError) -> User {
        let response = try await client.send(API.login(email: email, password: password, deviceName: deviceName()))

        return try keep(response)
    }

    public func loginWithGoogle(idToken: String) async throws(APIError) -> User {
        let response = try await client.send(API.google(idToken: idToken, deviceName: deviceName()))

        return try keep(response)
    }

    public func logout() async {
        if isSignedIn {
            _ = try? await client.send(API.logout())
        }

        client.tokenStore.clear()
    }

    private func keep(_ response: TokenResponse) throws(APIError) -> User {
        do {
            try client.tokenStore.save(response.token)
        } catch {
            throw .server(status: 0, message: String(describing: error))
        }

        return response.user
    }
}
