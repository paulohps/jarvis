import Foundation

/// `whatsapp.chat.updated` on the user's private channel: a chat got a
/// message (received, or sent from any device) or a message's ticks moved.
public struct WhatsappChatUpdatedEvent: Decodable, Equatable, Sendable {
    public static let name = "whatsapp.chat.updated"

    public let accountId: String
    public let chatId: String

    public init(accountId: String, chatId: String) {
        self.accountId = accountId
        self.chatId = chatId
    }
}

/// `whatsapp.chat.presence` on the user's private channel: someone of a chat
/// is typing, recording an audio, online or gone. `who` names the member in
/// a group (nil in a one-to-one chat); `lastSeen` is when a contact was last
/// online, when WhatsApp shares it. WhatsApp only sends them for the chats
/// the number subscribed to.
public struct WhatsappChatPresenceEvent: Decodable, Equatable, Sendable {
    public static let name = "whatsapp.chat.presence"

    public let accountId: String
    public let chatId: String
    public let presence: String
    public let who: String?
    public let lastSeen: Date?

    public init(accountId: String, chatId: String, presence: String, who: String? = nil, lastSeen: Date? = nil) {
        self.accountId = accountId
        self.chatId = chatId
        self.presence = presence
        self.who = who
        self.lastSeen = lastSeen
    }
}

/// What a chat's header (and its row in the list) says about the contact.
public enum WhatsappPresenceStatus: Equatable, Sendable {
    /// Typing; `who` in a group.
    case typing(who: String?)
    /// Recording an audio; `who` in a group.
    case recording(who: String?)
    case online
    case lastSeen(Date)

    /// Typing or recording: shown in place of the last message in the list.
    public var isActivity: Bool {
        switch self {
        case .typing, .recording:
            true
        case .online, .lastSeen:
            false
        }
    }
}

/// The latest presence of each chat, as the broadcasts tell it. Typing and
/// recording fade after `activityLifetime` without news (WhatsApp repeats
/// them while they go on), online after `onlineLifetime`; the last time a
/// contact was seen is kept. Nothing outlives the app.
public struct WhatsappPresenceBook: Equatable, Sendable {
    private struct Entry: Equatable, Sendable {
        var presence: String
        var who: String?
        var at: Date
        var lastSeen: Date?
    }

    public let activityLifetime: TimeInterval
    public let onlineLifetime: TimeInterval

    private var entries: [String: Entry] = [:]

    public init(activityLifetime: TimeInterval = 10, onlineLifetime: TimeInterval = 300) {
        self.activityLifetime = activityLifetime
        self.onlineLifetime = onlineLifetime
    }

    public mutating func receive(_ event: WhatsappChatPresenceEvent, at now: Date = .now) {
        let lastSeen = event.lastSeen ?? entries[event.chatId]?.lastSeen

        entries[event.chatId] = Entry(presence: event.presence, who: event.who, at: now, lastSeen: lastSeen)
    }

    public mutating func clear() {
        entries = [:]
    }

    public func hasNews(for chatId: String) -> Bool {
        entries[chatId] != nil
    }

    public func status(of chatId: String, at now: Date = .now) -> WhatsappPresenceStatus? {
        guard let entry = entries[chatId] else {
            return nil
        }

        let age = now.timeIntervalSince(entry.at)

        switch entry.presence {
        case "composing" where age < activityLifetime:
            return .typing(who: entry.who)
        case "recording" where age < activityLifetime:
            return .recording(who: entry.who)
        default:
            break
        }

        // Online and last seen are a contact's; a group member's say nothing of the group.
        guard entry.who == nil else {
            return nil
        }

        if ["available", "composing", "recording", "paused"].contains(entry.presence), age < onlineLifetime {
            return .online
        }

        return entry.lastSeen.map(WhatsappPresenceStatus.lastSeen)
    }
}

/// Tells which chats got a new message from the contact (or a group member)
/// since the last look at the latest chats, for the in-app banner: the first
/// look only learns where each chat was, so nothing that came before shows.
/// What the number sent itself never counts.
public struct WhatsappIncomingDetector: Equatable, Sendable {
    private var lastMessageIds: [String: String]?

    public init() {}

    public var isPrimed: Bool {
        lastMessageIds != nil
    }

    /// Forgets everything: the next look only learns again (after the app
    /// was in the background, what came meanwhile is not news any more).
    public mutating func reset() {
        lastMessageIds = nil
    }

    /// The chats whose latest message is new and was received, newest first.
    public mutating func newlyReceived(in chats: [WhatsappConversation]) -> [WhatsappConversation] {
        let isFirstLook = lastMessageIds == nil
        var known = lastMessageIds ?? [:]
        var news: [WhatsappConversation] = []

        for chat in chats {
            guard let message = chat.lastMessage else {
                continue
            }

            if !isFirstLook, known[chat.friendlyId] != message.friendlyId, !message.fromMe {
                news.append(chat)
            }

            known[chat.friendlyId] = message.friendlyId
        }

        lastMessageIds = known

        return news.sorted { ($0.lastMessage?.sentAt ?? .distantPast) > ($1.lastMessage?.sentAt ?? .distantPast) }
    }
}
