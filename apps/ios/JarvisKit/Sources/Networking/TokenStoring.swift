import Foundation

/// Where the Sanctum token of this device lives (the Keychain in the app, see
/// `Auth.KeychainTokenStore`). The client reads it on every call and clears it
/// when the server answers 401.
public protocol TokenStoring: Sendable {
    func token() -> String?

    func save(_ token: String) throws

    func clear()
}

/// A token store in memory, for previews and tests.
public final class InMemoryTokenStore: TokenStoring, @unchecked Sendable {
    private let lock = NSLock()
    private var storedToken: String?

    public init(token: String? = nil) {
        storedToken = token
    }

    public func token() -> String? {
        lock.withLock { storedToken }
    }

    public func save(_ token: String) throws {
        lock.withLock { storedToken = token }
    }

    public func clear() {
        lock.withLock { storedToken = nil }
    }
}
