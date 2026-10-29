import Foundation

/// `components/schemas/WhatsappChatMessage`: a message of a one-to-one chat,
/// received or sent; `body` is nil for an audio or a media without caption.
public struct WhatsappChatMessage: Decodable, Equatable, Hashable, Sendable, Identifiable {
    public let friendlyId: String
    public let fromMe: Bool
    public let type: String
    public let body: String?
    public let sentAt: Date

    public var id: String { friendlyId }

    public init(friendlyId: String, fromMe: Bool, type: String, body: String?, sentAt: Date) {
        self.friendlyId = friendlyId
        self.fromMe = fromMe
        self.type = type
        self.body = body
        self.sentAt = sentAt
    }
}

/// `components/schemas/WhatsappChat`: a chat for the reply keyboard, with
/// the latest message the contact sent.
public struct WhatsappChat: Decodable, Equatable, Hashable, Sendable, Identifiable {
    public struct Account: Decodable, Equatable, Hashable, Sendable {
        public let friendlyId: String
        public let nickname: String
    }

    public let friendlyId: String
    public let name: String
    public let phoneNumber: String?
    public let account: Account
    public let lastReceivedMessage: WhatsappChatMessage?
    public let awaitingReply: Bool

    public var id: String { friendlyId }
}

/// The answer of `POST /whatsapp/chat-messages/{message}/reply`.
public struct WhatsappReplyDraft: Decodable, Equatable, Sendable {
    public let text: String
}

/// The body of `POST /whatsapp/chat-messages/{message}/reply`.
public struct WhatsappReplyInput: Encodable, Equatable, Sendable {
    public let instruction: String?

    public init(instruction: String?) {
        self.instruction = instruction
    }
}

public extension API {
    /// The chats where the contact sent something, the latest received first.
    static func whatsappChats(page: Int = 1, perPage: Int = 20) -> Endpoint<Page<WhatsappChat>> {
        Endpoint(method: .get, path: "/whatsapp/chats", query: [
            URLQueryItem(name: "page", value: String(page)),
            URLQueryItem(name: "per_page", value: String(perPage)),
        ])
    }

    /// The latest messages of a chat, newest first.
    static func whatsappChatMessages(chatId: String) -> Endpoint<DataEnvelope<[WhatsappChatMessage]>> {
        Endpoint(method: .get, path: "/whatsapp/chats/\(chatId)/messages")
    }

    /// The answer to a message the contact sent, drafted in the user's style;
    /// `instruction` is what it must say, in the user's words.
    static func draftWhatsappReply(messageId: String, instruction: String?) -> Endpoint<WhatsappReplyDraft> {
        let trimmed = instruction?.trimmingCharacters(in: .whitespacesAndNewlines)

        return Endpoint(
            method: .post,
            path: "/whatsapp/chat-messages/\(messageId)/reply",
            json: WhatsappReplyInput(instruction: trimmed?.isEmpty == false ? trimmed : nil)
        )
    }
}
