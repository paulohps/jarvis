import Foundation
import Networking

/// A transport that answers from a queue and keeps the requests it got. A
/// status of 0 is no network: the request fails without an answer.
final class StubTransport: HTTPTransport, @unchecked Sendable {
    private let lock = NSLock()
    private var responses: [(status: Int, body: String)]
    private(set) var requests: [URLRequest] = []

    init(_ responses: [(status: Int, body: String)]) {
        self.responses = responses
    }

    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        let next = lock.withLock {
            requests.append(request)

            return responses.isEmpty ? (status: 500, body: "{}") : responses.removeFirst()
        }

        if next.status == 0 {
            throw URLError(.notConnectedToInternet)
        }

        let response = HTTPURLResponse(url: request.url!, statusCode: next.status, httpVersion: "HTTP/1.1", headerFields: nil)!

        return (Data(next.body.utf8), response)
    }

    func respond(with more: [(status: Int, body: String)]) {
        lock.withLock { responses.append(contentsOf: more) }
    }

    var sentRequests: [URLRequest] {
        lock.withLock { requests }
    }
}
