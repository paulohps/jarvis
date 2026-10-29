import Foundation

/// `components/schemas/WhatsappGroup`: a group as WhatsApp has it now — its
/// chat, who may send and edit its info, whether the number is an admin,
/// and its members.
public struct WhatsappGroup: Decodable, Equatable, Sendable {
    /// `components/schemas/WhatsappGroupMember`.
    public struct Member: Decodable, Equatable, Hashable, Sendable, Identifiable {
        public let jid: String
        public let lid: String?
        public let name: String
        /// E.164.
        public let number: String?
        public let admin: Bool
        /// The number itself.
        public let me: Bool

        public var id: String { jid }

        public init(jid: String, lid: String? = nil, name: String, number: String?, admin: Bool = false, me: Bool = false) {
            self.jid = jid
            self.lid = lid
            self.name = name
            self.number = number
            self.admin = admin
            self.me = me
        }
    }

    public let chat: WhatsappConversation
    /// Only admins send messages.
    public let adminsOnly: Bool
    /// Only admins edit the subject, description, picture and timer.
    public let infoAdminsOnly: Bool
    public let isAdmin: Bool
    public private(set) var members: [Member]

    /// Whether the number may change the subject, description, picture and timer.
    public var canEditInfo: Bool {
        isAdmin || !infoAdminsOnly
    }

    /// The same group with its members now (after adding, removing,
    /// promoting or demoting some).
    public func withMembers(_ members: [Member]) -> WhatsappGroup {
        var copy = self
        copy.members = members

        return copy
    }
}

/// The timers of disappearing messages WhatsApp offers, in seconds (0: off).
public enum WhatsappEphemeralTimer {
    public static let choices = [0, 86_400, 604_800, 7_776_000]
}

/// The body of `PATCH /whatsapp/conversations/{chat}/group`: only what is
/// set changes.
public struct WhatsappGroupUpdate: Encodable, Equatable, Sendable {
    public var subject: String?
    /// Empty clears it.
    public var description: String?
    public var adminsOnly: Bool?
    public var infoAdminsOnly: Bool?
    /// One of `WhatsappEphemeralTimer.choices`.
    public var ephemeralSeconds: Int?

    public init(subject: String? = nil, description: String? = nil, adminsOnly: Bool? = nil, infoAdminsOnly: Bool? = nil, ephemeralSeconds: Int? = nil) {
        self.subject = subject
        self.description = description
        self.adminsOnly = adminsOnly
        self.infoAdminsOnly = infoAdminsOnly
        self.ephemeralSeconds = ephemeralSeconds
    }
}

/// What `POST /whatsapp/conversations/{chat}/group/members` does to them.
public enum WhatsappGroupMemberAction: String, Encodable, Sendable {
    case add
    case remove
    case promote
    case demote
}

/// The body of `POST /whatsapp/conversations/{chat}/group/members`:
/// numbers with the country code, or JIDs.
public struct WhatsappGroupMembersInput: Encodable, Equatable, Sendable {
    public let action: WhatsappGroupMemberAction
    public let participants: [String]
}

/// The answer of the members' change: whether WhatsApp did it for each (by
/// JID), and the members now.
public struct WhatsappGroupMembersChange: Decodable, Equatable, Sendable {
    public let results: [String: Bool]
    public let members: [WhatsappGroup.Member]

    /// How many WhatsApp refused.
    public var refused: Int {
        results.values.filter { !$0 }.count
    }
}

/// The body of `POST /whatsapp/conversations/{chat}/group/invite`.
public struct WhatsappGroupInviteInput: Encodable, Equatable, Sendable {
    public let revoke: Bool
}

/// The link to join a group.
public struct WhatsappGroupInvite: Decodable, Equatable, Sendable {
    public let url: String
}

/// The body of `POST /whatsapp/groups`: contact chats of the number become
/// its members, the number its admin.
public struct WhatsappGroupCreateInput: Encodable, Equatable, Sendable {
    public let account: String
    public let subject: String
    public let members: [String]
    public let description: String?
}

public extension API {
    /// A new group on one of the user's numbers; WhatsApp leaves out,
    /// without a word, who is not on it.
    static func createWhatsappGroup(accountId: String, subject: String, memberIds: [String], description: String? = nil) -> Endpoint<DataEnvelope<WhatsappConversation>> {
        let trimmed = description?.trimmingCharacters(in: .whitespacesAndNewlines)

        return Endpoint(
            method: .post,
            path: "/whatsapp/groups",
            json: WhatsappGroupCreateInput(
                account: accountId,
                subject: subject.trimmingCharacters(in: .whitespacesAndNewlines),
                members: memberIds,
                description: trimmed?.isEmpty == false ? trimmed : nil
            )
        )
    }

    /// The group's info, permissions and members, asked to WhatsApp now.
    static func whatsappGroup(chatId: String) -> Endpoint<DataEnvelope<WhatsappGroup>> {
        Endpoint(method: .get, path: "/whatsapp/conversations/\(chatId)/group")
    }

    /// WhatsApp refuses (502) what only an admin may do when the number is none.
    static func updateWhatsappGroup(chatId: String, _ update: WhatsappGroupUpdate) -> Endpoint<DataEnvelope<WhatsappGroup>> {
        Endpoint(method: .patch, path: "/whatsapp/conversations/\(chatId)/group", json: update)
    }

    /// A new picture (JPEG or PNG, up to 5 MB); it shows with the chat's next refresh.
    static func updateWhatsappGroupPicture(chatId: String, file: MultipartForm.File) -> Endpoint<EmptyResponse> {
        var form = MultipartForm()
        form.add(file)

        return Endpoint(method: .post, path: "/whatsapp/conversations/\(chatId)/group/picture", form: form)
    }

    static func changeWhatsappGroupMembers(chatId: String, action: WhatsappGroupMemberAction, participants: [String]) -> Endpoint<WhatsappGroupMembersChange> {
        Endpoint(method: .post, path: "/whatsapp/conversations/\(chatId)/group/members", json: WhatsappGroupMembersInput(action: action, participants: participants))
    }

    /// The group's invite link; a new one, the old one no longer working, with `revoke`.
    static func whatsappGroupInvite(chatId: String, revoke: Bool = false) -> Endpoint<WhatsappGroupInvite> {
        Endpoint(method: .post, path: "/whatsapp/conversations/\(chatId)/group/invite", json: WhatsappGroupInviteInput(revoke: revoke))
    }

    /// The number leaves the group; the chat stays, archived.
    static func leaveWhatsappGroup(chatId: String) -> Endpoint<DataEnvelope<WhatsappConversation>> {
        Endpoint(method: .post, path: "/whatsapp/conversations/\(chatId)/group/leave")
    }
}
