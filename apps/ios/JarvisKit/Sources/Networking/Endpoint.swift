import Foundation

public enum HTTPMethod: String, Sendable {
    case get = "GET"
    case post = "POST"
    case put = "PUT"
    case patch = "PATCH"
    case delete = "DELETE"
}

/// A call to the API v1: the path under `/api/v1` and what it answers.
public struct Endpoint<Response: Decodable & Sendable>: Sendable {
    public let method: HTTPMethod
    public let path: String
    public let query: [URLQueryItem]
    public let body: Data?
    /// The type of `body`: JSON unless it is a multipart form.
    public let contentType: String
    public let isAuthenticated: Bool

    public init(
        method: HTTPMethod,
        path: String,
        query: [URLQueryItem] = [],
        body: Data? = nil,
        contentType: String = "application/json",
        isAuthenticated: Bool = true
    ) {
        self.method = method
        self.path = path
        self.query = query
        self.body = body
        self.contentType = contentType
        self.isAuthenticated = isAuthenticated
    }

    /// An endpoint with a `multipart/form-data` body (text fields and files).
    public init(
        method: HTTPMethod,
        path: String,
        form: MultipartForm,
        isAuthenticated: Bool = true
    ) {
        self.init(
            method: method,
            path: path,
            body: form.body(),
            contentType: form.contentType,
            isAuthenticated: isAuthenticated
        )
    }

    /// An endpoint with a JSON body.
    public init(
        method: HTTPMethod,
        path: String,
        json: some Encodable,
        isAuthenticated: Bool = true
    ) {
        self.init(
            method: method,
            path: path,
            body: try? JSONCoding.encoder.encode(json),
            isAuthenticated: isAuthenticated
        )
    }
}

/// A `multipart/form-data` body: text fields, then files, in the order added.
public struct MultipartForm: Sendable, Equatable {
    public struct File: Sendable, Equatable {
        public let name: String
        public let fileName: String
        public let mimeType: String
        public let data: Data

        public init(name: String, fileName: String, mimeType: String, data: Data) {
            self.name = name
            self.fileName = fileName
            self.mimeType = mimeType
            self.data = data
        }
    }

    public let boundary: String
    public private(set) var fields: [(name: String, value: String)] = []
    public private(set) var files: [File] = []

    public init(boundary: String = "jarvis-\(UUID().uuidString)") {
        self.boundary = boundary
    }

    public var contentType: String {
        "multipart/form-data; boundary=\(boundary)"
    }

    public mutating func add(_ name: String, _ value: String) {
        fields.append((name, value))
    }

    public mutating func add(_ file: File) {
        files.append(file)
    }

    public func body() -> Data {
        var data = Data()
        let line = "\r\n"

        for field in fields {
            data.append(Data("--\(boundary)\(line)Content-Disposition: form-data; name=\"\(field.name)\"\(line)\(line)\(field.value)\(line)".utf8))
        }

        for file in files {
            let name = file.fileName.replacingOccurrences(of: "\"", with: "")
            data.append(Data("--\(boundary)\(line)Content-Disposition: form-data; name=\"\(file.name)\"; filename=\"\(name)\"\(line)Content-Type: \(file.mimeType)\(line)\(line)".utf8))
            data.append(file.data)
            data.append(Data(line.utf8))
        }

        data.append(Data("--\(boundary)--\(line)".utf8))

        return data
    }

    public static func == (lhs: MultipartForm, rhs: MultipartForm) -> Bool {
        lhs.boundary == rhs.boundary && lhs.files == rhs.files
            && lhs.fields.map(\.name) == rhs.fields.map(\.name) && lhs.fields.map(\.value) == rhs.fields.map(\.value)
    }
}

/// The answer of an endpoint with no body worth reading (204).
public struct EmptyResponse: Decodable, Sendable, Equatable {
    public init() {}
}

/// The `{ "data": … }` envelope of the API Resources.
public struct DataEnvelope<Wrapped: Decodable & Sendable>: Decodable, Sendable {
    public let data: Wrapped
}

/// The JSON of the API: snake_case keys and ISO-8601 dates.
public enum JSONCoding {
    public static let decoder: JSONDecoder = {
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        decoder.dateDecodingStrategy = .custom { decoder in
            let container = try decoder.singleValueContainer()
            let text = try container.decode(String.self)

            guard let date = parseDate(text) else {
                throw DecodingError.dataCorruptedError(in: container, debugDescription: "Not an ISO-8601 date: \(text)")
            }

            return date
        }

        return decoder
    }()

    public static let encoder: JSONEncoder = {
        let encoder = JSONEncoder()
        encoder.keyEncodingStrategy = .convertToSnakeCase
        encoder.dateEncodingStrategy = .iso8601

        return encoder
    }()

    /// `2026-10-06T12:00:00+00:00`, with or without the fraction of a second.
    public static func parseDate(_ text: String) -> Date? {
        if let date = try? Date.ISO8601FormatStyle().parse(text) {
            return date
        }

        return try? Date.ISO8601FormatStyle(includingFractionalSeconds: true).parse(text)
    }
}
