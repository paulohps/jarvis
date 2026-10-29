import Foundation

/// What the client tells the app shell outside the answer of a call.
public enum SessionEvent: Equatable, Sendable {
    /// A call answered 401: the token was cleared and the app goes back to the login.
    case sessionExpired

    /// A call answered 403: the user's modules may have changed, so `/me` is read again.
    case accessDenied
}

/// The client of the API v1 (`docs/api/v1.yaml`).
///
/// Every call carries the Sanctum token of the device as `Bearer`, and the
/// app's language as `Accept-Language` (the server answers its messages in
/// it before the sign-in; afterwards it uses the user's saved locale). Any
/// 401 clears the token and emits `SessionEvent.sessionExpired`.
public final class APIClient: Sendable {
    public let baseURL: URL
    public let tokenStore: any TokenStoring

    private let transport: any HTTPTransport
    private let acceptLanguage: @Sendable () -> String?
    private let events = SessionEventBroadcaster()

    /// - Parameter baseURL: the server, e.g. `https://jarvis.example.com` (the client appends `/api/v1`).
    public init(
        baseURL: URL,
        tokenStore: any TokenStoring,
        transport: any HTTPTransport = URLSessionTransport(),
        acceptLanguage: @escaping @Sendable () -> String? = { nil }
    ) {
        self.baseURL = baseURL
        self.tokenStore = tokenStore
        self.transport = transport
        self.acceptLanguage = acceptLanguage
    }

    /// A new stream of the session events; each subscriber gets its own.
    public func sessionEvents() -> AsyncStream<SessionEvent> {
        events.subscribe()
    }

    /// Calls the endpoint and decodes its answer.
    @discardableResult
    public func send<Response>(_ endpoint: Endpoint<Response>) async throws(APIError) -> Response {
        let request = makeRequest(for: endpoint)
        let data: Data
        let response: HTTPURLResponse

        do {
            (data, response) = try await transport.send(request)
        } catch {
            throw .transport(description: error.localizedDescription)
        }

        try handleFailure(status: response.statusCode, data: data, isAuthenticated: endpoint.isAuthenticated)

        if Response.self == EmptyResponse.self || data.isEmpty {
            if let empty = EmptyResponse() as? Response {
                return empty
            }
        }

        do {
            return try JSONCoding.decoder.decode(Response.self, from: data)
        } catch {
            throw .decoding(description: String(describing: error))
        }
    }

    /// The URL of a path under `/api/v1`.
    public func url(for path: String, query: [URLQueryItem] = []) -> URL {
        var components = URLComponents(url: baseURL.appending(path: "api/v1"), resolvingAgainstBaseURL: false)!
        components.path += path.hasPrefix("/") ? path : "/\(path)"
        components.queryItems = query.isEmpty ? nil : query

        return components.url!
    }

    private func makeRequest<Response>(for endpoint: Endpoint<Response>) -> URLRequest {
        var request = URLRequest(url: url(for: endpoint.path, query: endpoint.query))
        request.httpMethod = endpoint.method.rawValue
        request.setValue("application/json", forHTTPHeaderField: "Accept")

        if let body = endpoint.body {
            request.httpBody = body
            request.setValue(endpoint.contentType, forHTTPHeaderField: "Content-Type")
        }

        if let language = acceptLanguage() {
            request.setValue(language, forHTTPHeaderField: "Accept-Language")
        }

        if endpoint.isAuthenticated, let token = tokenStore.token() {
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }

        return request
    }

    private func handleFailure(status: Int, data: Data, isAuthenticated: Bool) throws(APIError) {
        guard !(200..<300).contains(status) else {
            return
        }

        // A plain decoder: the `errors` keys are field names and must stay as sent.
        let body = try? JSONDecoder().decode(ErrorBody.self, from: data)
        let message = body?.message ?? ""

        switch status {
        case 401:
            // A 401 on the sign-in itself is not a lost session.
            if isAuthenticated {
                tokenStore.clear()
                events.emit(.sessionExpired)
            }

            throw .unauthenticated
        case 403:
            events.emit(.accessDenied)

            throw .forbidden(message: message)
        case 404:
            throw .notFound(message: message)
        case 422:
            throw .validation(message: message, errors: body?.errors ?? [:])
        case 429:
            throw .tooManyRequests(message: message)
        default:
            throw .server(status: status, message: message)
        }
    }
}

/// The error body of the API: `message` and, on a 422, `errors`.
private struct ErrorBody: Decodable {
    let message: String?
    let errors: [String: [String]]?
}

/// Hands each subscriber its own stream of the session events.
final class SessionEventBroadcaster: @unchecked Sendable {
    private let lock = NSLock()
    private var continuations: [UUID: AsyncStream<SessionEvent>.Continuation] = [:]

    func subscribe() -> AsyncStream<SessionEvent> {
        let (stream, continuation) = AsyncStream.makeStream(of: SessionEvent.self, bufferingPolicy: .bufferingNewest(8))
        let id = UUID()

        lock.withLock { continuations[id] = continuation }
        continuation.onTermination = { [weak self] _ in
            self?.lock.withLock { _ = self?.continuations.removeValue(forKey: id) }
        }

        return stream
    }

    func emit(_ event: SessionEvent) {
        let subscribers = lock.withLock { Array(continuations.values) }

        for continuation in subscribers {
            continuation.yield(event)
        }
    }
}
