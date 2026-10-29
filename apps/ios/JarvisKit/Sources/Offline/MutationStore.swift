import Foundation

/// Where the queued changes are kept between launches.
public protocol MutationStoring: Sendable {
    func load() -> [PendingMutation]

    func save(_ mutations: [PendingMutation]) throws
}

/// The queue in a JSON file (Application Support by default), written
/// atomically on every change, so a killed app loses nothing it showed.
public struct FileMutationStore: MutationStoring {
    public let fileURL: URL

    public init(fileURL: URL = FileMutationStore.defaultFileURL) {
        self.fileURL = fileURL
    }

    public static var defaultFileURL: URL {
        URL.applicationSupportDirectory.appending(components: "Jarvis", "offline-mutations.json")
    }

    public func load() -> [PendingMutation] {
        guard let data = try? Data(contentsOf: fileURL) else {
            return []
        }

        return (try? Self.decoder.decode([PendingMutation].self, from: data)) ?? []
    }

    public func save(_ mutations: [PendingMutation]) throws {
        if mutations.isEmpty {
            try? FileManager.default.removeItem(at: fileURL)

            return
        }

        try FileManager.default.createDirectory(at: fileURL.deletingLastPathComponent(), withIntermediateDirectories: true)
        try Self.encoder.encode(mutations).write(to: fileURL, options: [.atomic, .completeFileProtectionUntilFirstUserAuthentication])
    }

    // The moment of the tap is kept to the fraction of a second.
    private static let encoder: JSONEncoder = {
        let encoder = JSONEncoder()
        encoder.dateEncodingStrategy = .secondsSince1970

        return encoder
    }()

    private static let decoder: JSONDecoder = {
        let decoder = JSONDecoder()
        decoder.dateDecodingStrategy = .secondsSince1970

        return decoder
    }()
}

/// A queue store in memory, for previews and tests.
public final class InMemoryMutationStore: MutationStoring, @unchecked Sendable {
    private let lock = NSLock()
    private var mutations: [PendingMutation]

    public init(_ mutations: [PendingMutation] = []) {
        self.mutations = mutations
    }

    public func load() -> [PendingMutation] {
        lock.withLock { mutations }
    }

    public func save(_ mutations: [PendingMutation]) throws {
        lock.withLock { self.mutations = mutations }
    }
}
