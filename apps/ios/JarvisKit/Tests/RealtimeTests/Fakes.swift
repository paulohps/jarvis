import Foundation
@testable import Realtime

/// A WebSocket the test feeds frame by frame.
final class FakeConnection: WebSocketConnection, @unchecked Sendable {
    private let lock = NSLock()
    private var buffered: [Result<String, any Error>] = []
    private var waiting: CheckedContinuation<String, any Error>?
    private var sentFrames: [String] = []
    private(set) var isClosed = false

    func send(_ text: String) async throws {
        lock.withLock { sentFrames.append(text) }
    }

    func receive() async throws -> String {
        try await withCheckedThrowingContinuation { continuation in
            let next: Result<String, any Error>? = lock.withLock {
                if buffered.isEmpty {
                    waiting = continuation

                    return nil
                }

                return buffered.removeFirst()
            }

            if let next {
                continuation.resume(with: next)
            }
        }
    }

    func close() {
        lock.withLock { isClosed = true }
        deliver(.failure(URLError(.cancelled)))
    }

    /// A frame from the server.
    func push(_ text: String) {
        deliver(.success(text))
    }

    /// The network goes away.
    func drop() {
        deliver(.failure(URLError(.networkConnectionLost)))
    }

    var sent: [String] {
        lock.withLock { sentFrames }
    }

    func sentEvents(named event: String) -> [[String: Any]] {
        sent.compactMap { text in
            guard let frame = try? JSONSerialization.jsonObject(with: Data(text.utf8)) as? [String: Any],
                  frame["event"] as? String == event
            else {
                return nil
            }

            return frame["data"] as? [String: Any] ?? [:]
        }
    }

    private func deliver(_ result: Result<String, any Error>) {
        let continuation: CheckedContinuation<String, any Error>? = lock.withLock {
            if let waiting {
                self.waiting = nil

                return waiting
            }

            buffered.append(result)

            return nil
        }

        continuation?.resume(with: result)
    }
}

final class FakeConnector: WebSocketConnecting, @unchecked Sendable {
    private let lock = NSLock()
    private var opened: [FakeConnection] = []
    private var urls: [URL] = []

    func connect(to url: URL) -> any WebSocketConnection {
        let connection = FakeConnection()
        lock.withLock {
            opened.append(connection)
            urls.append(url)
        }

        return connection
    }

    var connections: [FakeConnection] {
        lock.withLock { opened }
    }

    var lastURL: URL? {
        lock.withLock { urls.last }
    }
}

final class FakeAuthorizer: ChannelAuthorizing, @unchecked Sendable {
    private let lock = NSLock()
    private var requests: [(socketId: String, channel: String)] = []
    var refusedChannels: Set<String> = []

    func authorize(socketId: String, channel: String) async throws -> String {
        lock.withLock { requests.append((socketId, channel)) }

        if refusedChannels.contains(channel) {
            throw URLError(.userAuthenticationRequired)
        }

        return "jarvis-local-key:signature-\(socketId)"
    }

    var calls: [(socketId: String, channel: String)] {
        lock.withLock { requests }
    }
}

/// Waits for a condition the client reaches asynchronously.
func eventually(timeout: Duration = .seconds(2), _ condition: @Sendable () async -> Bool) async -> Bool {
    let deadline = ContinuousClock.now + timeout

    while ContinuousClock.now < deadline {
        if await condition() {
            return true
        }

        try? await Task.sleep(for: .milliseconds(5))
    }

    return await condition()
}

func connectionEstablished(socketId: String, activityTimeout: Int = 120) -> String {
    #"{"event":"pusher:connection_established","data":"{\"socket_id\":\"\#(socketId)\",\"activity_timeout\":\#(activityTimeout)}"}"#
}
