import Foundation

/// `components/schemas/WhatsappConversationMessage`: a message of a chat of
/// the WhatsApp screen, received or sent, with its ticks and its media.
public struct WhatsappConversationMessage: Decodable, Equatable, Hashable, Sendable, Identifiable {
    /// How far a message the number sent got, as WhatsApp's ticks show it.
    public enum Receipt: String, Decodable, Sendable {
        case failed
        case pending
        case sent
        case delivered
        case read
        case played
    }

    public enum MediaKind: String, Decodable, Sendable {
        case image
        case video
        case audio
        case document
    }

    /// What WhatsApp said about the file, and the signed URL that hands it over
    /// (temporary, no token; nil when the file is too big to fetch).
    public struct Media: Decodable, Equatable, Hashable, Sendable {
        public let kind: MediaKind
        public let mime: String?
        public let name: String?
        public let size: Int?
        public let seconds: Int?
        public let waveform: [Int]?
        public let thumbnail: String?
        public let url: URL?

        public init(kind: MediaKind, mime: String? = nil, name: String? = nil, size: Int? = nil, seconds: Int? = nil, waveform: [Int]? = nil, thumbnail: String? = nil, url: URL? = nil) {
            self.kind = kind
            self.mime = mime
            self.name = name
            self.size = size
            self.seconds = seconds
            self.waveform = waveform
            self.thumbnail = thumbnail
            self.url = url
        }

        /// The tiny JPEG preview WhatsApp sends with an image or video.
        public var thumbnailData: Data? {
            thumbnail.flatMap { Data(base64Encoded: $0) }
        }
    }

    /// One emoji people reacted with: how many, and whether the number is one of them.
    public struct Reaction: Decodable, Equatable, Hashable, Sendable, Identifiable {
        public let emoji: String
        public let count: Int
        public let mine: Bool

        public var id: String { emoji }

        public init(emoji: String, count: Int, mine: Bool) {
            self.emoji = emoji
            self.count = count
            self.mine = mine
        }
    }

    /// The message it answers, as WhatsApp quotes it: `friendlyId` when
    /// Jarvis keeps it (to go to it), nil otherwise.
    public struct Quoted: Decodable, Equatable, Hashable, Sendable {
        public let friendlyId: String?
        public let sender: String?
        public let fromMe: Bool
        public let type: String?
        public let body: String?

        public init(friendlyId: String?, sender: String? = nil, fromMe: Bool = false, type: String? = nil, body: String?) {
            self.friendlyId = friendlyId
            self.sender = sender
            self.fromMe = fromMe
            self.type = type
            self.body = body
        }
    }

    /// A group member the text mentions (shown as `@name` in `body`).
    public struct Mention: Decodable, Equatable, Hashable, Sendable {
        public let jid: String
        public let name: String?
    }

    /// A place sent, or shared live (`live`).
    public struct Location: Decodable, Equatable, Hashable, Sendable {
        public let latitude: Double
        public let longitude: Double
        public let name: String?
        public let address: String?
        public let url: String?
        public let live: Bool?

        public var isLive: Bool { live ?? false }
    }

    /// A contact card: the name and the numbers it carries.
    public struct ContactCard: Decodable, Equatable, Hashable, Sendable {
        public let name: String?
        public let numbers: [String]

        public init(from decoder: any Decoder) throws {
            let container = try decoder.container(keyedBy: CodingKeys.self)
            name = try container.decodeIfPresent(String.self, forKey: .name)
            numbers = try container.decodeIfPresent([String].self, forKey: .numbers) ?? []
        }

        private enum CodingKeys: String, CodingKey {
            case name, numbers
        }
    }

    /// A poll and its votes as WhatsApp reported them; only read (there is
    /// no way to vote from Jarvis).
    public struct Poll: Decodable, Equatable, Hashable, Sendable {
        public struct Option: Decodable, Equatable, Hashable, Sendable {
            public let name: String
            public let votes: Int
            public let mine: Bool
        }

        public let name: String
        /// 1: one answer per person; 0: any number of them.
        public let selectableCount: Int
        public let options: [Option]

        public var allowsMultipleAnswers: Bool { selectableCount != 1 }

        public var totalVotes: Int { options.reduce(0) { $0 + $1.votes } }
    }

    public let friendlyId: String
    public let fromMe: Bool
    public let type: String
    public let sender: String?
    /// The text or caption, each mention as `@name`.
    public let body: String?
    public let receipt: Receipt?
    public let revoked: Bool
    public let media: Media?
    public let sentAt: Date
    /// Grouped by emoji, the most used first.
    public private(set) var reactions: [Reaction]
    public let quoted: Quoted?
    public let mentions: [Mention]
    public let forwarded: Bool
    public let edited: Bool
    /// The number sent it and WhatsApp's 15 minutes to edit are not over.
    public let canEdit: Bool
    /// The number sent it and WhatsApp still deletes it for everyone.
    public let canDeleteForEveryone: Bool
    /// Pinned for everyone in the chat (set on the phone; Jarvis only shows it).
    public let pinned: Bool
    /// Starred by the owner, in Jarvis only.
    public private(set) var starred: Bool
    public let isSticker: Bool
    public let location: Location?
    public let contacts: [ContactCard]?
    public let poll: Poll?

    public var id: String { friendlyId }

    /// The emoji the number reacted with, if any (one per person, as on WhatsApp).
    public var myReaction: String? {
        reactions.first(where: \.mine)?.emoji
    }

    /// Whether it can be sent on to another chat: not deleted, with a text or a file.
    public var isForwardable: Bool {
        !revoked && (media != nil || !(body ?? "").isEmpty)
    }

    public init(
        friendlyId: String,
        fromMe: Bool,
        type: String = "conversation",
        sender: String? = nil,
        body: String?,
        receipt: Receipt? = nil,
        revoked: Bool = false,
        media: Media? = nil,
        sentAt: Date,
        reactions: [Reaction] = [],
        quoted: Quoted? = nil,
        forwarded: Bool = false,
        edited: Bool = false,
        canEdit: Bool = false,
        canDeleteForEveryone: Bool = false,
        pinned: Bool = false,
        starred: Bool = false,
        isSticker: Bool = false,
        location: Location? = nil,
        contacts: [ContactCard]? = nil,
        poll: Poll? = nil
    ) {
        self.friendlyId = friendlyId
        self.fromMe = fromMe
        self.type = type
        self.sender = sender
        self.body = body
        self.receipt = receipt
        self.revoked = revoked
        self.media = media
        self.sentAt = sentAt
        self.reactions = reactions
        self.quoted = quoted
        self.mentions = []
        self.forwarded = forwarded
        self.edited = edited
        self.canEdit = canEdit
        self.canDeleteForEveryone = canDeleteForEveryone
        self.pinned = pinned
        self.starred = starred
        self.isSticker = isSticker
        self.location = location
        self.contacts = contacts
        self.poll = poll
    }

    /// The same message with the number's reaction set to `emoji`, or removed
    /// when nil, while WhatsApp is asked.
    public func reacting(with emoji: String?) -> WhatsappConversationMessage {
        var summary: [Reaction] = reactions.compactMap { reaction in
            guard reaction.mine else { return reaction }
            return reaction.count > 1 ? Reaction(emoji: reaction.emoji, count: reaction.count - 1, mine: false) : nil
        }

        if let emoji {
            if let index = summary.firstIndex(where: { $0.emoji == emoji }) {
                summary[index] = Reaction(emoji: emoji, count: summary[index].count + 1, mine: true)
            } else {
                summary.append(Reaction(emoji: emoji, count: 1, mine: true))
            }
        }

        var reacted = self
        reacted.reactions = summary

        return reacted
    }

    /// The same message starred or not, while the server is asked.
    public func starring(_ starred: Bool) -> WhatsappConversationMessage {
        var copy = self
        copy.starred = starred

        return copy
    }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)

        friendlyId = try container.decode(String.self, forKey: .friendlyId)
        fromMe = try container.decode(Bool.self, forKey: .fromMe)
        type = try container.decode(String.self, forKey: .type)
        sender = try container.decodeIfPresent(String.self, forKey: .sender)
        body = try container.decodeIfPresent(String.self, forKey: .body)
        // A receipt the app does not know yet shows no ticks rather than failing the page.
        receipt = (try? container.decodeIfPresent(String.self, forKey: .receipt)).flatMap { $0.flatMap(Receipt.init(rawValue:)) }
        revoked = try container.decodeIfPresent(Bool.self, forKey: .revoked) ?? false
        media = try container.decodeIfPresent(Media.self, forKey: .media)
        sentAt = try container.decode(Date.self, forKey: .sentAt)
        // The chats list's last message carries none.
        reactions = try container.decodeIfPresent([Reaction].self, forKey: .reactions) ?? []
        quoted = try container.decodeIfPresent(Quoted.self, forKey: .quoted)
        mentions = try container.decodeIfPresent([Mention].self, forKey: .mentions) ?? []
        forwarded = try container.decodeIfPresent(Bool.self, forKey: .forwarded) ?? false
        edited = try container.decodeIfPresent(Bool.self, forKey: .edited) ?? false
        canEdit = try container.decodeIfPresent(Bool.self, forKey: .canEdit) ?? false
        canDeleteForEveryone = try container.decodeIfPresent(Bool.self, forKey: .canDeleteForEveryone) ?? false
        pinned = try container.decodeIfPresent(Bool.self, forKey: .pinned) ?? false
        starred = try container.decodeIfPresent(Bool.self, forKey: .starred) ?? false
        isSticker = try container.decodeIfPresent(Bool.self, forKey: .sticker) ?? false
        // A payload the app cannot read leaves the card out rather than failing the page.
        location = try? container.decodeIfPresent(Location.self, forKey: .location)
        contacts = try? container.decodeIfPresent([ContactCard].self, forKey: .contacts)
        poll = try? container.decodeIfPresent(Poll.self, forKey: .poll)
    }

    private enum CodingKeys: String, CodingKey {
        case friendlyId, fromMe, type, sender, body, receipt, revoked, media, sentAt, reactions
        case quoted, mentions, forwarded, edited, canEdit, canDeleteForEveryone, pinned, starred, sticker, location, contacts, poll
    }
}

/// `components/schemas/WhatsappConversation`: a chat of the WhatsApp screen,
/// a contact or a group.
public struct WhatsappConversation: Decodable, Equatable, Hashable, Sendable, Identifiable {
    public struct Account: Decodable, Equatable, Hashable, Sendable {
        public let friendlyId: String
        public let nickname: String
    }

    public struct Tag: Decodable, Equatable, Hashable, Sendable, Identifiable {
        public let friendlyId: String
        public let name: String
        /// `#rrggbb`.
        public let color: String
        /// The color by name, a key of the priority colors' palette (what saving a tag takes).
        public let colorName: String?

        public var id: String { friendlyId }

        public init(friendlyId: String, name: String, color: String, colorName: String? = nil) {
            self.friendlyId = friendlyId
            self.name = name
            self.color = color
            self.colorName = colorName
        }
    }

    /// What reaches WhatsApp from the chat (read receipts, "typing…") and
    /// whether its messages are pushed: each nil to follow its number, with
    /// what is in effect.
    public struct Settings: Decodable, Equatable, Hashable, Sendable {
        public struct Effective: Decodable, Equatable, Hashable, Sendable {
            public let sendsReadReceipts: Bool
            public let sendsTyping: Bool
            public let notifiesMessages: Bool
        }

        public let sendsReadReceipts: Bool?
        public let sendsTyping: Bool?
        public let notifiesMessages: Bool?
        public let effective: Effective
    }

    public let friendlyId: String
    /// The user's nickname for it, else the WhatsApp name, else the number.
    public let name: String
    public let nickname: String?
    /// The name WhatsApp gives.
    public let whatsappName: String?
    public let isGroup: Bool
    public let phoneNumber: String?
    public let pictureUrl: URL?
    public let account: Account
    public let unreadCount: Int
    /// Marked unread by hand (WhatsApp's "Mark as unread").
    public let markedUnread: Bool
    public let pinned: Bool
    public let archived: Bool
    /// Its pushes stay silent until then (far ahead: always); nil when not muted.
    public let mutedUntil: Date?
    /// A contact the number blocked.
    public let blocked: Bool
    /// The timer of disappearing messages; 0 when off.
    public let ephemeralSeconds: Int
    /// A group's description.
    public let description: String?
    public let priority: Int?
    /// The user's color for the priority, `#rrggbb`.
    public let priorityColor: String?
    public let tags: [Tag]
    public let lastMessage: WhatsappConversationMessage?
    public let lastMessageAt: Date?
    public let settings: Settings?

    public var id: String { friendlyId }

    /// The first letter of the name, for a chat without a picture.
    public var initial: String {
        name.first(where: { $0.isLetter || $0.isNumber }).map { String($0).uppercased() } ?? "#"
    }

    /// Whether its pushes are silent now.
    public func isMuted(at now: Date = .now) -> Bool {
        mutedUntil.map { $0 > now } ?? false
    }

    /// Unread messages, or marked unread by hand.
    public var looksUnread: Bool {
        unreadCount > 0 || markedUnread
    }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)

        friendlyId = try container.decode(String.self, forKey: .friendlyId)
        name = try container.decode(String.self, forKey: .name)
        nickname = try container.decodeIfPresent(String.self, forKey: .nickname)
        whatsappName = try container.decodeIfPresent(String.self, forKey: .whatsappName)
        isGroup = try container.decode(Bool.self, forKey: .isGroup)
        phoneNumber = try container.decodeIfPresent(String.self, forKey: .phoneNumber)
        pictureUrl = try? container.decodeIfPresent(URL.self, forKey: .pictureUrl)
        account = try container.decode(Account.self, forKey: .account)
        unreadCount = try container.decode(Int.self, forKey: .unreadCount)
        markedUnread = try container.decodeIfPresent(Bool.self, forKey: .markedUnread) ?? false
        pinned = try container.decode(Bool.self, forKey: .pinned)
        archived = try container.decodeIfPresent(Bool.self, forKey: .archived) ?? false
        mutedUntil = try container.decodeIfPresent(Date.self, forKey: .mutedUntil)
        blocked = try container.decodeIfPresent(Bool.self, forKey: .blocked) ?? false
        ephemeralSeconds = try container.decodeIfPresent(Int.self, forKey: .ephemeralSeconds) ?? 0
        description = try container.decodeIfPresent(String.self, forKey: .description)
        priority = try container.decodeIfPresent(Int.self, forKey: .priority)
        priorityColor = try container.decodeIfPresent(String.self, forKey: .priorityColor)
        tags = try container.decodeIfPresent([Tag].self, forKey: .tags) ?? []
        lastMessage = try container.decodeIfPresent(WhatsappConversationMessage.self, forKey: .lastMessage)
        lastMessageAt = try container.decodeIfPresent(Date.self, forKey: .lastMessageAt)
        settings = try container.decodeIfPresent(Settings.self, forKey: .settings)
    }

    private enum CodingKeys: String, CodingKey {
        case friendlyId, name, nickname, whatsappName, isGroup, phoneNumber, pictureUrl, account, unreadCount, markedUnread
        case pinned, archived, mutedUntil, blocked, ephemeralSeconds, description, priority, priorityColor, tags
        case lastMessage, lastMessageAt, settings
    }
}

/// The answer of `GET /whatsapp/conversations/{chat}/messages`: newest first,
/// with what Jarvis is still sending (first page only).
public struct WhatsappConversationMessagesPage: Decodable, Equatable, Sendable {
    public struct Meta: Decodable, Equatable, Sendable {
        public let hasMore: Bool
    }

    public let data: [WhatsappConversationMessage]
    public let outgoing: [WhatsappDelivery]
    public let meta: Meta

    public init(data: [WhatsappConversationMessage], outgoing: [WhatsappDelivery] = [], hasMore: Bool) {
        self.data = data
        self.outgoing = outgoing
        self.meta = Meta(hasMore: hasMore)
    }
}

/// The answer of `POST /whatsapp/conversations/{chat}/typing`.
public struct WhatsappTypingShown: Decodable, Equatable, Sendable {
    public let shown: Bool
}

/// The body of `POST /whatsapp/conversations/{chat}/messages`: the text
/// and the message it answers (quoted), if any.
public struct WhatsappConversationTextInput: Encodable, Equatable, Sendable {
    public let text: String
    public let replyTo: String?
}

public extension API {
    /// Every chat of the user's numbers, the pinned first, then the latest
    /// message first (or the highest priority first, `sort: .priority`);
    /// `tag` keeps the chats with that tag, `account` the chats of that number;
    /// `archived` lists only the archived ones (left out otherwise, unless searching).
    static func whatsappConversations(page: Int = 1, perPage: Int = 30, search: String? = nil, sort: WhatsappChatSort = .recent, tag: String? = nil, account: String? = nil, archived: Bool = false) -> Endpoint<Page<WhatsappConversation>> {
        var query = [
            URLQueryItem(name: "page", value: String(page)),
            URLQueryItem(name: "per_page", value: String(perPage)),
        ]

        if let search = search?.trimmingCharacters(in: .whitespacesAndNewlines), !search.isEmpty {
            query.append(URLQueryItem(name: "search", value: search))
        }

        if sort == .priority {
            query.append(URLQueryItem(name: "sort", value: "priority"))
        }

        if let tag {
            query.append(URLQueryItem(name: "tag", value: tag))
        }

        if let account {
            query.append(URLQueryItem(name: "account", value: account))
        }

        if archived {
            query.append(URLQueryItem(name: "archived", value: "1"))
        }

        return Endpoint(method: .get, path: "/whatsapp/conversations", query: query)
    }

    /// 40 messages of a chat, newest first, older than `before` when given.
    static func whatsappConversationMessages(chatId: String, before: String? = nil) -> Endpoint<WhatsappConversationMessagesPage> {
        Endpoint(
            method: .get,
            path: "/whatsapp/conversations/\(chatId)/messages",
            query: before.map { [URLQueryItem(name: "before", value: $0)] } ?? []
        )
    }

    /// Sends a text to the chat now, from the chat's own number, quoting
    /// the message it answers (`replyTo`). In a group, each `@` followed by
    /// a number with its country code mentions that member.
    static func sendWhatsappConversationText(chatId: String, text: String, replyTo: String? = nil) -> Endpoint<DataEnvelope<WhatsappDelivery>> {
        Endpoint(
            method: .post,
            path: "/whatsapp/conversations/\(chatId)/messages",
            json: WhatsappConversationTextInput(text: text.trimmingCharacters(in: .whitespacesAndNewlines), replyTo: replyTo)
        )
    }

    /// The user saw the chat: its unread count goes back to 0 (and WhatsApp
    /// is told, when the chat sends read receipts).
    static func markWhatsappConversationRead(chatId: String) -> Endpoint<EmptyResponse> {
        Endpoint(method: .post, path: "/whatsapp/conversations/\(chatId)/read")
    }

    /// The user is typing (the contact sees "typing…" when the chat sends it).
    static func whatsappConversationTyping(chatId: String) -> Endpoint<WhatsappTypingShown> {
        Endpoint(method: .post, path: "/whatsapp/conversations/\(chatId)/typing")
    }
}

/// The messages of an open chat, oldest first, built from the pages of
/// `GET /whatsapp/conversations/{chat}/messages`: polling the latest page
/// adds the new messages and refreshes the ones already shown (ticks,
/// deleted, a renewed media URL); loading older ones goes back from the
/// oldest shown.
public struct WhatsappConversationTimeline: Equatable, Sendable {
    public private(set) var messages: [WhatsappConversationMessage] = []
    public private(set) var outgoing: [WhatsappDelivery] = []
    public private(set) var hasOlder = false

    public init() {}

    /// The friendly id to ask the page before, for loading older messages.
    public var oldestId: String? {
        messages.first?.friendlyId
    }

    /// The newest message the contact (or a group member) sent, to tell
    /// when the chat must be marked read again.
    public var newestReceivedId: String? {
        messages.last(where: { !$0.fromMe })?.friendlyId
    }

    /// Merges the latest page (no `before`). On the first page it also tells
    /// whether there are older messages.
    public mutating func mergeLatest(_ page: WhatsappConversationMessagesPage) {
        let isFirstLoad = messages.isEmpty

        merge(page.data)
        outgoing = page.outgoing

        if isFirstLoad {
            hasOlder = page.meta.hasMore
        }
    }

    /// Merges a page of older messages (`before` the oldest shown).
    public mutating func mergeOlder(_ page: WhatsappConversationMessagesPage) {
        merge(page.data)
        hasOlder = page.meta.hasMore
    }

    /// Whether the message is among the ones loaded.
    public func contains(_ messageId: String) -> Bool {
        messages.contains { $0.friendlyId == messageId }
    }

    /// Puts the changed copy of a message in place (a reaction while WhatsApp
    /// is asked, an edit, a star); nothing when it is not shown.
    public mutating func replace(_ message: WhatsappConversationMessage) {
        if let index = messages.firstIndex(where: { $0.friendlyId == message.friendlyId }) {
            messages[index] = message
        }
    }

    private mutating func merge(_ incoming: [WhatsappConversationMessage]) {
        var byId = Dictionary(messages.map { ($0.friendlyId, $0) }, uniquingKeysWith: { _, new in new })

        for message in incoming {
            byId[message.friendlyId] = message
        }

        messages = byId.values.sorted { lhs, rhs in
            lhs.sentAt == rhs.sentAt ? lhs.friendlyId < rhs.friendlyId : lhs.sentAt < rhs.sentAt
        }
    }
}

/// Lets "typing…" reach the server at most once per `interval` while the
/// user types.
public struct WhatsappTypingThrottle: Sendable {
    public let interval: TimeInterval
    private var lastSentAt: Date?

    public init(interval: TimeInterval = 4) {
        self.interval = interval
    }

    /// Whether to send now; when it says yes, it counts as sent.
    public mutating func shouldSend(at now: Date = .now) -> Bool {
        if let lastSentAt, now.timeIntervalSince(lastSentAt) < interval {
            return false
        }

        lastSentAt = now

        return true
    }

    /// After sending a message: the next keystroke may tell again at once.
    public mutating func reset() {
        lastSentAt = nil
    }
}

/// A `#rrggbb` color of the API as red, green and blue from 0 to 1.
public struct HexColor: Equatable, Sendable {
    public let red: Double
    public let green: Double
    public let blue: Double

    public init?(_ hex: String) {
        let digits = hex.hasPrefix("#") ? String(hex.dropFirst()) : hex

        guard digits.count == 6, let value = UInt32(digits, radix: 16) else {
            return nil
        }

        red = Double((value >> 16) & 0xFF) / 255
        green = Double((value >> 8) & 0xFF) / 255
        blue = Double(value & 0xFF) / 255
    }
}
