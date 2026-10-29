import Foundation
import Networking

/// The last `/me` read, so the app opens its tabs without network; `/me` is
/// read again right after.
struct UserCache {
    private static let key = "jarvis.user"

    let defaults: UserDefaults

    init(defaults: UserDefaults = .standard) {
        self.defaults = defaults
    }

    func load() -> User? {
        defaults.data(forKey: Self.key).flatMap { try? JSONCoding.decoder.decode(User.self, from: $0) }
    }

    func save(_ user: User) {
        defaults.set(try? JSONCoding.encoder.encode(user), forKey: Self.key)
    }

    func clear() {
        defaults.removeObject(forKey: Self.key)
    }
}
