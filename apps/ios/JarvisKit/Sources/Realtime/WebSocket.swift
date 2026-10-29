import Foundation

/// One WebSocket connection, text frames only (the Pusher protocol is JSON).
public protocol WebSocketConnection: Sendable {
    func send(_ text: String) async throws

    /// The next frame; throws when the connection is lost or closed.
    func receive() async throws -> String

    func close()
}

/// Opens WebSocket connections. `URLSession` in the app, a fake in the tests.
public protocol WebSocketConnecting: Sendable {
    func connect(to url: URL) -> any WebSocketConnection
}

public struct URLSessionWebSocketConnector: WebSocketConnecting {
    private let session: URLSession

    public init(session: URLSession = .shared) {
        self.session = session
    }

    public func connect(to url: URL) -> any WebSocketConnection {
        let task = session.webSocketTask(with: url)
        task.resume()

        return URLSessionWebSocketConnection(task: task)
    }
}

final class URLSessionWebSocketConnection: WebSocketConnection, @unchecked Sendable {
    private let task: URLSessionWebSocketTask

    init(task: URLSessionWebSocketTask) {
        self.task = task
    }

    func send(_ text: String) async throws {
        try await task.send(.string(text))
    }

    func receive() async throws -> String {
        switch try await task.receive() {
        case .string(let text):
            return text
        case .data(let data):
            return String(decoding: data, as: UTF8.self)
        @unknown default:
            return ""
        }
    }

    func close() {
        task.cancel(with: .goingAway, reason: nil)
    }
}
