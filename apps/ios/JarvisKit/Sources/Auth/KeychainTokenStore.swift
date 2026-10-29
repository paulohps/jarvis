import Foundation
import Networking
import Security

/// The Sanctum token of this device in the Keychain (US-1.5): a generic
/// password readable after the first unlock, never synced to iCloud nor
/// restored on another device, as the token is bound to this one.
public final class KeychainTokenStore: TokenStoring {
    public static let defaultService = "app.jarvis.ios.sanctum"

    private let service: String
    private let account: String

    public init(service: String = KeychainTokenStore.defaultService, account: String = "api-token") {
        self.service = service
        self.account = account
    }

    public func token() -> String? {
        var query = baseQuery
        query[kSecReturnData as String] = true
        query[kSecMatchLimit as String] = kSecMatchLimitOne

        var item: CFTypeRef?

        guard SecItemCopyMatching(query as CFDictionary, &item) == errSecSuccess,
              let data = item as? Data
        else {
            return nil
        }

        return String(data: data, encoding: .utf8)
    }

    public func save(_ token: String) throws {
        let data = Data(token.utf8)
        let attributes: [String: Any] = [
            kSecValueData as String: data,
            kSecAttrAccessible as String: kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly,
        ]

        var status = SecItemUpdate(baseQuery as CFDictionary, attributes as CFDictionary)

        if status == errSecItemNotFound {
            let item = baseQuery.merging(attributes) { _, new in new }
            status = SecItemAdd(item as CFDictionary, nil)
        }

        guard status == errSecSuccess else {
            throw KeychainError(status: status)
        }
    }

    public func clear() {
        SecItemDelete(baseQuery as CFDictionary)
    }

    private var baseQuery: [String: Any] {
        [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
        ]
    }
}

/// A Keychain call that failed, with its `OSStatus`.
public struct KeychainError: Error, Equatable {
    public let status: OSStatus
}
