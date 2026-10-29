import Foundation

/// The order of the chats of the WhatsApp screen after the pinned ones.
public enum WhatsappChatSort: String, CaseIterable, Sendable {
    /// The latest message first.
    case recent
    /// The highest priority (1) first, the chats without one last.
    case priority
}

/// The answer of `GET /whatsapp/conversations/{chat}`: the chat, its
/// messages scheduled and not sent yet, and its open reminders, the next
/// first.
public struct WhatsappChatDetails: Decodable, Equatable, Sendable {
    public struct Scheduled: Decodable, Equatable, Hashable, Sendable, Identifiable {
        /// The file a scheduled message sends, the body its caption.
        public struct Media: Decodable, Equatable, Hashable, Sendable {
            public let mime: String?
            public let name: String?
            public let size: Int?

            public var isAudio: Bool { mime?.hasPrefix("audio/") == true }
            public var isImage: Bool { mime?.hasPrefix("image/") == true }
            public var isVideo: Bool { mime?.hasPrefix("video/") == true }
        }

        public let friendlyId: String
        public let body: String
        public let nextRunAt: Date?
        public let media: Media?

        public var id: String { friendlyId }

        public init(friendlyId: String, body: String, nextRunAt: Date?, media: Media? = nil) {
            self.friendlyId = friendlyId
            self.body = body
            self.nextRunAt = nextRunAt
            self.media = media
        }
    }

    public struct Reminder: Decodable, Equatable, Hashable, Sendable, Identifiable {
        public let friendlyId: String
        public let title: String
        public let dueAt: Date?

        public var id: String { friendlyId }
    }

    public let data: WhatsappConversation
    public let scheduled: [Scheduled]
    public let reminders: [Reminder]

    public init(data: WhatsappConversation, scheduled: [Scheduled], reminders: [Reminder]) {
        self.data = data
        self.scheduled = scheduled
        self.reminders = reminders
    }
}

/// A change of `PATCH /whatsapp/conversations/{chat}`: only what is set is
/// sent; `.set(nil)` sends `null` (no priority, no nickname, or the setting
/// following the number).
public enum WhatsappChange<Value: Encodable & Equatable & Sendable>: Equatable, Sendable {
    case unchanged
    case set(Value?)
}

/// How long a chat's pushes stay silent, as WhatsApp offers it.
public enum WhatsappMute: String, Encodable, CaseIterable, Sendable {
    case eightHours = "8h"
    case oneWeek = "1w"
    case always
}

/// The body of `PATCH /whatsapp/conversations/{chat}`. Archiving and
/// marking unread reach WhatsApp while the number is connected; muting is
/// Jarvis' own (`.set(nil)` unmutes); blocking is for a contact only.
public struct WhatsappConversationUpdate: Encodable, Equatable, Sendable {
    public var pinned: WhatsappChange<Bool> = .unchanged
    public var archived: WhatsappChange<Bool> = .unchanged
    public var markedUnread: WhatsappChange<Bool> = .unchanged
    public var muted: WhatsappChange<WhatsappMute> = .unchanged
    public var blocked: WhatsappChange<Bool> = .unchanged
    /// 1 (highest) to 5.
    public var priority: WhatsappChange<Int> = .unchanged
    public var nickname: WhatsappChange<String> = .unchanged
    /// The friendly ids of the user's tags; the chat gets exactly these.
    public var tags: WhatsappChange<[String]> = .unchanged
    public var sendsReadReceipts: WhatsappChange<Bool> = .unchanged
    public var sendsTyping: WhatsappChange<Bool> = .unchanged
    public var notifiesMessages: WhatsappChange<Bool> = .unchanged

    public init() {}

    public func encode(to encoder: any Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)

        try encode(pinned, .pinned, in: &container)
        try encode(priority, .priority, in: &container)
        try encode(nickname, .nickname, in: &container)
        try encode(tags, .tags, in: &container)
        try encode(sendsReadReceipts, .sendsReadReceipts, in: &container)
        try encode(sendsTyping, .sendsTyping, in: &container)
        try encode(notifiesMessages, .notifiesMessages, in: &container)
        try encode(archived, .archived, in: &container)
        try encode(markedUnread, .markedUnread, in: &container)
        try encode(muted, .muted, in: &container)
        try encode(blocked, .blocked, in: &container)
    }

    private func encode<Value>(_ change: WhatsappChange<Value>, _ key: CodingKeys, in container: inout KeyedEncodingContainer<CodingKeys>) throws {
        guard case .set(let value) = change else {
            return
        }

        if let value {
            try container.encode(value, forKey: key)
        } else {
            try container.encodeNil(forKey: key)
        }
    }

    private enum CodingKeys: String, CodingKey {
        case pinned, priority, nickname, tags, sendsReadReceipts, sendsTyping, notifiesMessages
        case archived, markedUnread, muted, blocked
    }
}

/// The body of `POST /whatsapp/conversations`.
public struct WhatsappChatStartInput: Encodable, Equatable, Sendable {
    public let account: String
    public let number: String
}

/// The body of `POST /whatsapp/conversations/{chat}/draft`: the picked
/// messages (none for the latest received) and, optionally, what the
/// answer must say; `withoutContext` writes a new message from the
/// instruction alone, without reading the chat.
public struct WhatsappDraftInput: Encodable, Equatable, Sendable {
    public let messages: [String]
    public let instruction: String?
    public let withoutContext: Bool?
}

/// The answer of `POST /whatsapp/conversations/{chat}/draft`.
public struct WhatsappDraft: Decodable, Equatable, Sendable {
    public let text: String
}

/// The answer of `POST /whatsapp/conversations/{chat}/sync`.
public struct WhatsappChatSynced: Decodable, Equatable, Sendable {
    public let added: Int
}

/// The body of `POST /whatsapp/conversations/{chat}/reminders`; `dueAt` as
/// `2026-10-06T09:00` in the user's timezone.
public struct WhatsappChatReminderInput: Encodable, Equatable, Sendable {
    public let title: String
    public let dueAt: String
    public let messages: [String]
}

/// The body of `POST /whatsapp/conversations/{chat}/schedule`; `at` as
/// `2026-10-06T09:00` in the user's timezone.
public struct WhatsappChatScheduleInput: Encodable, Equatable, Sendable {
    public let text: String
    public let at: String
}

/// The body of `POST /whatsapp/conversations/{chat}/messages/{message}/reaction`:
/// an emoji, or empty to take the number's reaction back.
public struct WhatsappReactionInput: Encodable, Equatable, Sendable {
    public let emoji: String
}

/// The answer of the reaction: the number's emoji now, nil when it was taken back
/// (the same emoji again takes it back).
public struct WhatsappReaction: Decodable, Equatable, Sendable {
    public let emoji: String?
}

/// The body of `POST /whatsapp/conversations/{chat}/forward`: the messages
/// (up to 20) and the chats of the same number they go to (up to 5).
public struct WhatsappForwardInput: Encodable, Equatable, Sendable {
    public let messages: [String]
    public let to: [String]
}

/// The answer of the forward: how many sends were queued.
public struct WhatsappForwarded: Decodable, Equatable, Sendable {
    public let deliveries: Int
}

/// How many messages, and to how many chats, one forward takes.
public enum WhatsappForwardLimits {
    public static let messages = 20
    public static let chats = 5
}

/// The body of `POST /whatsapp/tags` and `PATCH /whatsapp/tags/{tag}`: the
/// color by name, out of the priority colors' palette.
public struct WhatsappTagInput: Encodable, Equatable, Sendable {
    public let name: String
    public let color: String
}

/// `GET /whatsapp/priority-colors`: the palette (`{name: #rrggbb}`) and the
/// color of each priority, 1 to 5 (`{"1": name, …}`).
public struct WhatsappPriorityColors: Decodable, Equatable, Sendable {
    /// The names of the palette in the web's order.
    public static let paletteOrder = ["red", "orange", "yellow", "green", "blue", "pink", "brown", "gray"]
    public static let priorities = Array(1...5)

    public let palette: [String: String]
    public let colors: [String: String]

    public init(palette: [String: String], colors: [String: String]) {
        self.palette = palette
        self.colors = colors
    }

    /// The color names, the known ones in the web's order, any new one after.
    public var names: [String] {
        Self.paletteOrder.filter { palette[$0] != nil } + palette.keys.filter { !Self.paletteOrder.contains($0) }.sorted()
    }

    /// The color name of a priority.
    public func name(of priority: Int) -> String? {
        colors[String(priority)]
    }

    /// The `#rrggbb` of a priority.
    public func hex(of priority: Int) -> String? {
        name(of: priority).flatMap { palette[$0] }
    }
}

/// The body of `PUT /whatsapp/priority-colors`.
public struct WhatsappPriorityColorsInput: Encodable, Equatable, Sendable {
    public let colors: [String: String]
}

/// One of the user's numbers with the defaults of its chats, read from
/// `GET /whatsapp/accounts` (`chat_settings`).
public struct WhatsappAccountChatSettings: Decodable, Equatable, Sendable, Identifiable {
    public struct Defaults: Codable, Equatable, Sendable {
        public var sendsReadReceipts: Bool
        public var sendsTyping: Bool
        public var notifiesMessages: Bool
        /// A chat read on the phone (or WhatsApp Web) is read in Jarvis too.
        public var syncsPhoneReads: Bool

        public init(sendsReadReceipts: Bool, sendsTyping: Bool, notifiesMessages: Bool, syncsPhoneReads: Bool = true) {
            self.sendsReadReceipts = sendsReadReceipts
            self.sendsTyping = sendsTyping
            self.notifiesMessages = notifiesMessages
            self.syncsPhoneReads = syncsPhoneReads
        }

        public init(from decoder: any Decoder) throws {
            let container = try decoder.container(keyedBy: CodingKeys.self)
            sendsReadReceipts = try container.decode(Bool.self, forKey: .sendsReadReceipts)
            sendsTyping = try container.decode(Bool.self, forKey: .sendsTyping)
            notifiesMessages = try container.decode(Bool.self, forKey: .notifiesMessages)
            syncsPhoneReads = try container.decodeIfPresent(Bool.self, forKey: .syncsPhoneReads) ?? true
        }

        private enum CodingKeys: String, CodingKey {
            case sendsReadReceipts, sendsTyping, notifiesMessages, syncsPhoneReads
        }
    }

    public let friendlyId: String
    public let nickname: String
    public let phoneNumber: String?
    public let isOpen: Bool
    public let chatSettings: Defaults

    public var id: String { friendlyId }
}

public extension API {
    /// The chat with a number on one of the user's numbers, started when there is none yet.
    static func startWhatsappConversation(accountId: String, number: String) -> Endpoint<DataEnvelope<WhatsappConversation>> {
        Endpoint(method: .post, path: "/whatsapp/conversations", json: WhatsappChatStartInput(account: accountId, number: number.trimmingCharacters(in: .whitespacesAndNewlines)))
    }

    static func whatsappConversation(chatId: String) -> Endpoint<WhatsappChatDetails> {
        Endpoint(method: .get, path: "/whatsapp/conversations/\(chatId)")
    }

    static func updateWhatsappConversation(chatId: String, _ update: WhatsappConversationUpdate) -> Endpoint<DataEnvelope<WhatsappConversation>> {
        Endpoint(method: .patch, path: "/whatsapp/conversations/\(chatId)", json: update)
    }

    /// Fetches the chat's messages again from WhatsApp.
    static func syncWhatsappConversation(chatId: String) -> Endpoint<WhatsappChatSynced> {
        Endpoint(method: .post, path: "/whatsapp/conversations/\(chatId)/sync")
    }

    /// An answer drafted by the AI to the picked messages, or to the latest
    /// ones the contact sent (no messages), following `instruction` when
    /// there is one; nothing is sent.
    static func draftWhatsappReply(chatId: String, messageIds: [String] = [], instruction: String? = nil, withoutContext: Bool = false) -> Endpoint<WhatsappDraft> {
        let trimmed = instruction?.trimmingCharacters(in: .whitespacesAndNewlines)

        return Endpoint(
            method: .post,
            path: "/whatsapp/conversations/\(chatId)/draft",
            json: WhatsappDraftInput(
                messages: withoutContext ? [] : messageIds,
                instruction: trimmed?.isEmpty == false ? trimmed : nil,
                withoutContext: withoutContext ? true : nil
            )
        )
    }

    /// A reminder about the chat, or one of its messages (quoted in the note).
    static func remindWhatsappConversation(chatId: String, title: String, dueAt: String, messageIds: [String] = []) -> Endpoint<DataEnvelope<WhatsappChatDetails.Reminder>> {
        Endpoint(method: .post, path: "/whatsapp/conversations/\(chatId)/reminders", json: WhatsappChatReminderInput(title: title, dueAt: dueAt, messages: messageIds))
    }

    /// The text sent to the chat from its number once, at `at`.
    static func scheduleWhatsappConversationText(chatId: String, text: String, at: String) -> Endpoint<DataEnvelope<WhatsappChatDetails.Scheduled>> {
        Endpoint(method: .post, path: "/whatsapp/conversations/\(chatId)/schedule", json: WhatsappChatScheduleInput(text: text, at: at))
    }

    /// A file sent to the chat from its number once, at `at`: the text its
    /// caption (optional; an audio goes as a voice note, without text).
    static func scheduleWhatsappConversationFile(chatId: String, text: String, at: String, file: MultipartForm.File) -> Endpoint<DataEnvelope<WhatsappChatDetails.Scheduled>> {
        var form = MultipartForm()
        form.add("at", at)

        if !text.isEmpty {
            form.add("text", text)
        }

        form.add(file)

        return Endpoint(method: .post, path: "/whatsapp/conversations/\(chatId)/schedule", form: form)
    }

    /// Reacts to a message from its chat's number; the same emoji again, or an
    /// empty one, takes the reaction back.
    static func reactToWhatsappMessage(chatId: String, messageId: String, emoji: String) -> Endpoint<WhatsappReaction> {
        Endpoint(method: .post, path: "/whatsapp/conversations/\(chatId)/messages/\(messageId)/reaction", json: WhatsappReactionInput(emoji: emoji))
    }

    /// Sends the messages again (their text, or their file with its caption)
    /// to other chats of the same number, in the order they were sent.
    /// WhatsApp does not show them as "Forwarded".
    static func forwardWhatsappMessages(chatId: String, messageIds: [String], to chatIds: [String]) -> Endpoint<WhatsappForwarded> {
        Endpoint(method: .post, path: "/whatsapp/conversations/\(chatId)/forward", json: WhatsappForwardInput(messages: messageIds, to: chatIds))
    }

    /// The user's tags, by name.
    static func whatsappTags() -> Endpoint<DataEnvelope<[WhatsappConversation.Tag]>> {
        Endpoint(method: .get, path: "/whatsapp/tags")
    }

    static func createWhatsappTag(name: String, color: String) -> Endpoint<DataEnvelope<WhatsappConversation.Tag>> {
        Endpoint(method: .post, path: "/whatsapp/tags", json: WhatsappTagInput(name: name, color: color))
    }

    static func updateWhatsappTag(id: String, name: String, color: String) -> Endpoint<DataEnvelope<WhatsappConversation.Tag>> {
        Endpoint(method: .patch, path: "/whatsapp/tags/\(id)", json: WhatsappTagInput(name: name, color: color))
    }

    /// Deletes the tag, off every chat.
    static func deleteWhatsappTag(id: String) -> Endpoint<EmptyResponse> {
        Endpoint(method: .delete, path: "/whatsapp/tags/\(id)")
    }

    static func whatsappPriorityColors() -> Endpoint<WhatsappPriorityColors> {
        Endpoint(method: .get, path: "/whatsapp/priority-colors")
    }

    /// `colors` by priority (`"1"`…`"5"`); one left out takes its default.
    static func saveWhatsappPriorityColors(_ colors: [String: String]) -> Endpoint<WhatsappPriorityColors> {
        Endpoint(method: .put, path: "/whatsapp/priority-colors", json: WhatsappPriorityColorsInput(colors: colors))
    }

    /// The user's numbers with the defaults of their chats.
    static func whatsappAccountChatSettings() -> Endpoint<DataEnvelope<[WhatsappAccountChatSettings]>> {
        Endpoint(method: .get, path: "/whatsapp/accounts")
    }

    static func updateWhatsappAccountChatSettings(accountId: String, _ defaults: WhatsappAccountChatSettings.Defaults) -> Endpoint<DataEnvelope<WhatsappAccountChatSettings>> {
        Endpoint(method: .patch, path: "/whatsapp/chat-settings/\(accountId)", json: defaults)
    }
}
