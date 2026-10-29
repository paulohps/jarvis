import Foundation

/// A frame of the Pusher protocol 7, as Reverb speaks it:
/// `{"event": "…", "channel": "…", "data": "<JSON as a string>"}`.
struct PusherMessage {
    let event: String
    let channel: String?

    /// The payload as JSON bytes (`data` comes as a JSON string or, rarely, as an object).
    let data: Data

    init?(text: String) {
        guard let object = try? JSONSerialization.jsonObject(with: Data(text.utf8)) as? [String: Any],
              let event = object["event"] as? String
        else {
            return nil
        }

        self.event = event
        channel = object["channel"] as? String

        switch object["data"] {
        case let string as String:
            data = Data(string.utf8)
        case let value? where JSONSerialization.isValidJSONObject(value):
            data = (try? JSONSerialization.data(withJSONObject: value)) ?? Data()
        default:
            data = Data()
        }
    }

    /// The payload as a dictionary, for the protocol's own events.
    var payload: [String: Any] {
        (try? JSONSerialization.jsonObject(with: data) as? [String: Any]) ?? [:]
    }

    static func encode(event: String, data: [String: Any]) -> String {
        let frame: [String: Any] = ["event": event, "data": data]
        let bytes = (try? JSONSerialization.data(withJSONObject: frame, options: [.sortedKeys])) ?? Data()

        return String(decoding: bytes, as: UTF8.self)
    }
}
