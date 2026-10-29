import Foundation

/// A failed call to the API v1, by what the app does about it.
public enum APIError: Error, Equatable, Sendable {
    /// 401: no token or a revoked one. The session is over (back to the login).
    case unauthenticated

    /// 403: the module is switched off for the user, or the record is someone else's.
    case forbidden(message: String)

    /// 404.
    case notFound(message: String)

    /// 422, in the Laravel format; the messages come in the user's language.
    case validation(message: String, errors: [String: [String]])

    /// 429.
    case tooManyRequests(message: String)

    /// Any other status.
    case server(status: Int, message: String)

    /// No answer (no network, timeout).
    case transport(description: String)

    /// An answer the app could not read.
    case decoding(description: String)

    /// The message to show: the first validation error, otherwise the server's message.
    public var displayMessage: String? {
        switch self {
        case .validation(let message, let errors):
            errors.values.lazy.compactMap(\.first).first ?? message
        case .forbidden(let message), .notFound(let message), .tooManyRequests(let message), .server(_, let message):
            message.isEmpty ? nil : message
        case .unauthenticated, .transport, .decoding:
            nil
        }
    }

    /// The first message of one validation field.
    public func firstError(for field: String) -> String? {
        guard case .validation(_, let errors) = self else {
            return nil
        }

        return errors[field]?.first
    }
}
