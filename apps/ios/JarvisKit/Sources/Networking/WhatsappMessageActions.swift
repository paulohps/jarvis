import Foundation

/// The body of `PATCH /whatsapp/conversations/{chat}/messages/{message}`:
/// the new text (or caption).
public struct WhatsappMessageEditInput: Encodable, Equatable, Sendable {
    public let text: String
}

/// The body of `POST /whatsapp/conversations/{chat}/messages/{message}/star`.
public struct WhatsappStarInput: Encodable, Equatable, Sendable {
    public let starred: Bool
}

/// The answer of `GET /whatsapp/conversations/{chat}/messages/{message}/info`:
/// how far a message the number sent got, and when. The times are only
/// known in a one-to-one chat (`perMember` false); in a group WhatsApp does
/// not tell Jarvis who received or read it.
public struct WhatsappMessageInfo: Decodable, Equatable, Sendable {
    public let friendlyId: String
    public let sentAt: Date
    public let receipt: WhatsappConversationMessage.Receipt?
    public let deliveredAt: Date?
    public let readAt: Date?
    public let playedAt: Date?
    public let perMember: Bool

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)

        friendlyId = try container.decode(String.self, forKey: .friendlyId)
        sentAt = try container.decode(Date.self, forKey: .sentAt)
        receipt = (try? container.decodeIfPresent(String.self, forKey: .receipt)).flatMap { $0.flatMap(WhatsappConversationMessage.Receipt.init(rawValue:)) }
        deliveredAt = try container.decodeIfPresent(Date.self, forKey: .deliveredAt)
        readAt = try container.decodeIfPresent(Date.self, forKey: .readAt)
        playedAt = try container.decodeIfPresent(Date.self, forKey: .playedAt)
        perMember = try container.decodeIfPresent(Bool.self, forKey: .perMember) ?? false
    }

    private enum CodingKeys: String, CodingKey {
        case friendlyId, sentAt, receipt, deliveredAt, readAt, playedAt, perMember
    }
}

/// What the chat's gallery lists: photos and videos, documents or links.
public enum WhatsappGalleryKind: String, CaseIterable, Sendable {
    case media
    case documents
    case links
}

/// A page of the gallery, newest first.
public struct WhatsappGalleryPage: Decodable, Equatable, Sendable {
    public struct Meta: Decodable, Equatable, Sendable {
        public let hasMore: Bool
    }

    public let data: [WhatsappConversationMessage]
    public let meta: Meta
}

/// The body of `POST /whatsapp/conversations/{chat}/poll`.
public struct WhatsappPollInput: Encodable, Equatable, Sendable {
    public let question: String
    public let options: [String]
    public let multiple: Bool
    public let replyTo: String?
}

/// How many options a poll takes, as WhatsApp.
public enum WhatsappPollLimits {
    public static let options = 2...10
}

/// The body of `POST /whatsapp/conversations/{chat}/location`.
public struct WhatsappLocationInput: Encodable, Equatable, Sendable {
    public let latitude: Double
    public let longitude: Double
    public let name: String?
    public let address: String?
    public let replyTo: String?
}

/// The body of `POST /whatsapp/conversations/{chat}/contacts`: contact
/// chats of the same number, sent as cards.
public struct WhatsappContactsInput: Encodable, Equatable, Sendable {
    public let contacts: [String]
}

/// How many contact cards one send takes, and the biggest sticker.
public enum WhatsappContentLimits {
    public static let contacts = 10
    public static let stickerBytes = 1024 * 1024
}

/// Mentions in a group's composer: typing `@` and part of a member's name
/// or number suggests the members; picking one writes `@` and the member's
/// number with its country code, which is what the server reads as a mention.
public enum WhatsappMentions {
    /// What follows the `@` being typed at the end of the text, nil when
    /// the text does not end in a mention being typed.
    public static func query(in text: String) -> String? {
        guard let at = text.lastIndex(of: "@") else {
            return nil
        }

        if at > text.startIndex, !text[text.index(before: at)].isWhitespace {
            return nil
        }

        let typed = text[text.index(after: at)...]

        return typed.contains(where: \.isWhitespace) ? nil : String(typed)
    }

    /// The digits that mention a member: the number without its `+`, else
    /// the user part of the JID.
    public static func handle(of member: WhatsappGroup.Member) -> String {
        let source = member.number ?? String(member.jid.prefix { $0 != "@" })

        return source.filter(\.isNumber)
    }

    /// The members matching what was typed after the `@` (by name or
    /// number), the number itself left out.
    public static func suggestions(for query: String, in members: [WhatsappGroup.Member]) -> [WhatsappGroup.Member] {
        let needle = query.lowercased()

        return members.filter { member in
            guard !member.me else {
                return false
            }

            return needle.isEmpty || member.name.lowercased().contains(needle) || handle(of: member).contains(needle)
        }
    }

    /// The text with the mention being typed replaced by the member's handle.
    public static func inserting(_ member: WhatsappGroup.Member, into text: String) -> String {
        guard query(in: text) != nil, let at = text.lastIndex(of: "@") else {
            return text
        }

        return String(text[..<at]) + "@" + handle(of: member) + " "
    }
}

public extension API {
    /// Edits the text or caption of a message the number sent, within
    /// WhatsApp's 15 minutes (`canEdit`).
    static func editWhatsappMessage(chatId: String, messageId: String, text: String) -> Endpoint<DataEnvelope<WhatsappConversationMessage>> {
        Endpoint(method: .patch, path: "/whatsapp/conversations/\(chatId)/messages/\(messageId)", json: WhatsappMessageEditInput(text: text.trimmingCharacters(in: .whitespacesAndNewlines)))
    }

    /// Deletes for everyone a message the number sent (`canDeleteForEveryone`);
    /// Jarvis keeps it, marked as deleted.
    static func deleteWhatsappMessageForEveryone(chatId: String, messageId: String) -> Endpoint<DataEnvelope<WhatsappConversationMessage>> {
        Endpoint(method: .delete, path: "/whatsapp/conversations/\(chatId)/messages/\(messageId)")
    }

    static func whatsappMessageInfo(chatId: String, messageId: String) -> Endpoint<DataEnvelope<WhatsappMessageInfo>> {
        Endpoint(method: .get, path: "/whatsapp/conversations/\(chatId)/messages/\(messageId)/info")
    }

    /// Stars or unstars a message, in Jarvis only.
    static func starWhatsappMessage(chatId: String, messageId: String, starred: Bool) -> Endpoint<DataEnvelope<WhatsappConversationMessage>> {
        Endpoint(method: .post, path: "/whatsapp/conversations/\(chatId)/messages/\(messageId)/star", json: WhatsappStarInput(starred: starred))
    }

    /// The messages of the chat with every word of `query` (2 letters at
    /// least), newest first, up to 50.
    static func searchWhatsappConversation(chatId: String, query: String) -> Endpoint<DataEnvelope<[WhatsappConversationMessage]>> {
        Endpoint(
            method: .get,
            path: "/whatsapp/conversations/\(chatId)/search",
            query: [URLQueryItem(name: "q", value: query.trimmingCharacters(in: .whitespacesAndNewlines))]
        )
    }

    /// The starred messages of the chat, the latest starred first.
    static func whatsappStarredMessages(chatId: String) -> Endpoint<DataEnvelope<[WhatsappConversationMessage]>> {
        Endpoint(method: .get, path: "/whatsapp/conversations/\(chatId)/starred")
    }

    /// 50 photos and videos, documents or links of the chat, newest first,
    /// older than `before` when given.
    static func whatsappGallery(chatId: String, kind: WhatsappGalleryKind, before: String? = nil) -> Endpoint<WhatsappGalleryPage> {
        var query = [URLQueryItem(name: "kind", value: kind.rawValue)]

        if let before {
            query.append(URLQueryItem(name: "before", value: before))
        }

        return Endpoint(method: .get, path: "/whatsapp/conversations/\(chatId)/gallery", query: query)
    }

    /// A poll, one answer per person unless `multiple`; nobody votes from Jarvis.
    static func sendWhatsappPoll(chatId: String, question: String, options: [String], multiple: Bool, replyTo: String? = nil) -> Endpoint<DataEnvelope<WhatsappDelivery>> {
        Endpoint(
            method: .post,
            path: "/whatsapp/conversations/\(chatId)/poll",
            json: WhatsappPollInput(
                question: question.trimmingCharacters(in: .whitespacesAndNewlines),
                options: options.map { $0.trimmingCharacters(in: .whitespacesAndNewlines) }.filter { !$0.isEmpty },
                multiple: multiple,
                replyTo: replyTo
            )
        )
    }

    /// A place, with its name and address when given.
    static func sendWhatsappLocation(chatId: String, latitude: Double, longitude: Double, name: String? = nil, address: String? = nil, replyTo: String? = nil) -> Endpoint<DataEnvelope<WhatsappDelivery>> {
        Endpoint(
            method: .post,
            path: "/whatsapp/conversations/\(chatId)/location",
            json: WhatsappLocationInput(latitude: latitude, longitude: longitude, name: name.nilIfBlank, address: address.nilIfBlank, replyTo: replyTo)
        )
    }

    /// Cards of contact chats of the same number.
    static func sendWhatsappContacts(chatId: String, contactIds: [String]) -> Endpoint<DataEnvelope<WhatsappDelivery>> {
        Endpoint(method: .post, path: "/whatsapp/conversations/\(chatId)/contacts", json: WhatsappContactsInput(contacts: contactIds))
    }

    /// An image (WebP, PNG or JPEG, up to 1 MB) sent as a sticker.
    static func sendWhatsappSticker(chatId: String, file: MultipartForm.File, replyTo: String? = nil) -> Endpoint<DataEnvelope<WhatsappDelivery>> {
        var form = MultipartForm()

        if let replyTo {
            form.add("reply_to", replyTo)
        }

        form.add(file)

        return Endpoint(method: .post, path: "/whatsapp/conversations/\(chatId)/sticker", form: form)
    }
}

private extension Optional where Wrapped == String {
    /// Trimmed, and nil when nothing is left.
    var nilIfBlank: String? {
        guard let trimmed = self?.trimmingCharacters(in: .whitespacesAndNewlines), !trimmed.isEmpty else {
            return nil
        }

        return trimmed
    }
}
