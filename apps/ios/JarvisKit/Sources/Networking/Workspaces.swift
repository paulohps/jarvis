import Foundation

/// `components/schemas/WorkspaceSummary`: the workspace a shared record
/// (a shopping list, a recurring list, a reminder, an agenda entry) belongs
/// to, in the color the signed-in user sees it (their own, when they chose
/// one). Absent from a server older than the workspaces.
public struct WorkspaceSummary: Codable, Equatable, Hashable, Sendable, Identifiable {
    public let id: String
    public let name: String
    public let color: String
    public let colorHex: String

    public init(id: String, name: String, color: String, colorHex: String) {
        self.id = id
        self.name = name
        self.color = color
        self.colorHex = colorHex
    }
}

/// A workspace of the signed-in user, as `GET /me` lists it: the color they
/// see (`color`, their own `ownColor` when set, otherwise the workspace's
/// `workspaceColor`), whether it is their default, and the modules they have
/// in it. The family's shared data (lists, reminders) lives in a workspace;
/// the user sees every one of theirs together.
public struct UserWorkspace: Codable, Equatable, Hashable, Sendable, Identifiable {
    public let id: String
    public let name: String
    public let color: String
    public let colorHex: String
    public let workspaceColor: String
    public let ownColor: String?
    public let isDefault: Bool
    public let modules: [String]

    public init(
        id: String,
        name: String,
        color: String,
        colorHex: String,
        workspaceColor: String? = nil,
        ownColor: String? = nil,
        isDefault: Bool = false,
        modules: [String] = []
    ) {
        self.id = id
        self.name = name
        self.color = color
        self.colorHex = colorHex
        self.workspaceColor = workspaceColor ?? color
        self.ownColor = ownColor
        self.isDefault = isDefault
        self.modules = modules
    }

    private enum CodingKeys: String, CodingKey {
        case id, name, color, colorHex, workspaceColor, ownColor, isDefault, modules
    }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        id = try container.decode(String.self, forKey: .id)
        name = try container.decode(String.self, forKey: .name)
        color = try container.decode(String.self, forKey: .color)
        colorHex = try container.decode(String.self, forKey: .colorHex)
        workspaceColor = try container.decodeIfPresent(String.self, forKey: .workspaceColor) ?? color
        ownColor = try container.decodeIfPresent(String.self, forKey: .ownColor)
        isDefault = try container.decodeIfPresent(Bool.self, forKey: .isDefault) ?? false
        modules = try container.decodeIfPresent([String].self, forKey: .modules) ?? []
    }

    public func hasModule(_ slug: String) -> Bool {
        modules.contains(slug)
    }

    /// The workspace as a shared record names it.
    public var summary: WorkspaceSummary {
        WorkspaceSummary(id: id, name: name, color: color, colorHex: colorHex)
    }
}

extension User {
    /// The user's workspaces where they have the module, in the order of `/me`.
    public func workspaces(withModule slug: String) -> [UserWorkspace] {
        workspaces.filter { $0.hasModule(slug) }
    }

    /// Whether the module's screens show which workspace a record is in and
    /// ask for one: only with two or more workspaces where the user has it.
    /// With one, everything is in it and the screens stay quiet.
    public func showsWorkspaces(forModule slug: String) -> Bool {
        workspaces(withModule: slug).count > 1
    }

    /// Where a new record of the module goes when the user picks none: their
    /// default workspace when they have the module there, otherwise the first
    /// one with the module (as the server picks it).
    public func defaultWorkspace(forModule slug: String) -> UserWorkspace? {
        let candidates = workspaces(withModule: slug)

        return candidates.first(where: \.isDefault) ?? candidates.first
    }
}

/// `meta.assignees_by_workspace` of the reminder answers: who may be made
/// responsible in each of the user's workspaces, by its friendly id (the
/// keys stay as sent: the decoder leaves a dictionary's keys alone). Read
/// leniently: an empty one comes as `[]` from PHP, and anything unreadable
/// is no list rather than a failed screen.
public struct AssigneesByWorkspace: Decodable, Equatable, Sendable {
    public let values: [String: [UserSummary]]

    public init(_ values: [String: [UserSummary]] = [:]) {
        self.values = values
    }

    public init(from decoder: any Decoder) throws {
        values = (try? decoder.singleValueContainer().decode([String: [UserSummary]].self)) ?? [:]
    }

    public subscript(workspaceId: String) -> [UserSummary]? {
        values[workspaceId]
    }
}

/// `GET /workspace-colors`: a color a workspace, or a member's view of it,
/// may have.
public struct WorkspaceColorOption: Decodable, Equatable, Hashable, Sendable, Identifiable {
    public let name: String
    public let hex: String

    public var id: String { name }

    public init(name: String, hex: String) {
        self.name = name
        self.hex = hex
    }
}

/// The body of `PATCH /me/workspaces/{workspace}`: the user's own color for
/// a workspace and/or making it their default. Only what is set is sent;
/// `.workspaceColor` sends `color: null`, back to the workspace's color.
public struct WorkspacePreferenceInput: Encodable, Equatable, Sendable {
    public enum ColorChoice: Equatable, Sendable {
        case keep
        case workspaceColor
        case own(String)
    }

    public let color: ColorChoice
    public let makeDefault: Bool

    public init(color: ColorChoice = .keep, makeDefault: Bool = false) {
        self.color = color
        self.makeDefault = makeDefault
    }

    public func encode(to encoder: any Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)

        switch color {
        case .keep:
            break
        case .workspaceColor:
            try container.encodeNil(forKey: .color)
        case .own(let name):
            try container.encode(name, forKey: .color)
        }

        if makeDefault {
            try container.encode(true, forKey: .isDefault)
        }
    }

    enum CodingKeys: String, CodingKey {
        case color
        case isDefault
    }
}

// MARK: - Admin (super admin only)

/// A module as the admin area lists it (`GET /admin/modules`).
public struct AdminModule: Decodable, Equatable, Hashable, Sendable, Identifiable {
    public let slug: String
    public let name: String
    public let isActive: Bool
    public let isAvailableOnMobile: Bool

    public var id: String { slug }

    public init(slug: String, name: String, isActive: Bool = true, isAvailableOnMobile: Bool = true) {
        self.slug = slug
        self.name = name
        self.isActive = isActive
        self.isAvailableOnMobile = isAvailableOnMobile
    }
}

/// A workspace of `GET /admin/workspaces`.
public struct AdminWorkspace: Decodable, Equatable, Hashable, Sendable, Identifiable {
    public let id: String
    public let name: String
    public let color: String
    public let colorHex: String
    public let membersCount: Int
    public let createdAt: Date?

    public init(id: String, name: String, color: String, colorHex: String, membersCount: Int = 0, createdAt: Date? = nil) {
        self.id = id
        self.name = name
        self.color = color
        self.colorHex = colorHex
        self.membersCount = membersCount
        self.createdAt = createdAt
    }
}

/// `GET /admin/workspaces/{workspace}`: the workspace and its members.
/// `hasData` tells it cannot be deleted (the server refuses with a 422).
public struct AdminWorkspaceDetail: Decodable, Equatable, Sendable, Identifiable {
    public let id: String
    public let name: String
    public let color: String
    public let colorHex: String
    public let membersCount: Int
    public let hasData: Bool
    public let members: [AdminWorkspaceMember]

    public init(
        id: String,
        name: String,
        color: String,
        colorHex: String,
        membersCount: Int = 0,
        hasData: Bool = false,
        members: [AdminWorkspaceMember] = []
    ) {
        self.id = id
        self.name = name
        self.color = color
        self.colorHex = colorHex
        self.membersCount = membersCount
        self.hasData = hasData
        self.members = members
    }

    private enum CodingKeys: String, CodingKey {
        case id, name, color, colorHex, membersCount, hasData, members
    }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        id = try container.decode(String.self, forKey: .id)
        name = try container.decode(String.self, forKey: .name)
        color = try container.decode(String.self, forKey: .color)
        colorHex = try container.decode(String.self, forKey: .colorHex)
        members = try container.decodeIfPresent([AdminWorkspaceMember].self, forKey: .members) ?? []
        membersCount = try container.decodeIfPresent(Int.self, forKey: .membersCount) ?? members.count
        hasData = try container.decodeIfPresent(Bool.self, forKey: .hasData) ?? false
    }
}

/// The user of a membership.
public struct AdminMemberUser: Decodable, Equatable, Hashable, Sendable, Identifiable {
    public let id: String
    public let name: String
    public let email: String
    public let avatarUrl: String?
    public let isSuperAdmin: Bool
    public let isDeactivated: Bool

    public init(id: String, name: String, email: String, avatarUrl: String? = nil, isSuperAdmin: Bool = false, isDeactivated: Bool = false) {
        self.id = id
        self.name = name
        self.email = email
        self.avatarUrl = avatarUrl
        self.isSuperAdmin = isSuperAdmin
        self.isDeactivated = isDeactivated
    }
}

/// A member of a workspace: their own color for it (`color`, nil for the
/// workspace's), the color they see, whether it is their default and their
/// modules there.
public struct AdminWorkspaceMember: Decodable, Equatable, Hashable, Sendable, Identifiable {
    public let user: AdminMemberUser
    public let color: String?
    public let effectiveColor: String
    public let effectiveColorHex: String
    public let isDefault: Bool
    public let modules: [String]

    public var id: String { user.id }

    public init(
        user: AdminMemberUser,
        color: String? = nil,
        effectiveColor: String,
        effectiveColorHex: String,
        isDefault: Bool = false,
        modules: [String] = []
    ) {
        self.user = user
        self.color = color
        self.effectiveColor = effectiveColor
        self.effectiveColorHex = effectiveColorHex
        self.isDefault = isDefault
        self.modules = modules
    }
}

/// A user of `GET /admin/users`, with the workspaces they are in.
public struct AdminUser: Decodable, Equatable, Hashable, Sendable, Identifiable {
    public let id: String
    public let name: String
    public let email: String
    public let avatarUrl: String?
    public let isSuperAdmin: Bool
    public let isDeactivated: Bool
    public let lastSeenAt: Date?
    public let workspaces: [WorkspaceSummary]

    public init(
        id: String,
        name: String,
        email: String,
        avatarUrl: String? = nil,
        isSuperAdmin: Bool = false,
        isDeactivated: Bool = false,
        lastSeenAt: Date? = nil,
        workspaces: [WorkspaceSummary] = []
    ) {
        self.id = id
        self.name = name
        self.email = email
        self.avatarUrl = avatarUrl
        self.isSuperAdmin = isSuperAdmin
        self.isDeactivated = isDeactivated
        self.lastSeenAt = lastSeenAt
        self.workspaces = workspaces
    }

    private enum CodingKeys: String, CodingKey {
        case id, name, email, avatarUrl, isSuperAdmin, isDeactivated, lastSeenAt, workspaces
    }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        id = try container.decode(String.self, forKey: .id)
        name = try container.decode(String.self, forKey: .name)
        email = try container.decode(String.self, forKey: .email)
        avatarUrl = try container.decodeIfPresent(String.self, forKey: .avatarUrl)
        isSuperAdmin = try container.decodeIfPresent(Bool.self, forKey: .isSuperAdmin) ?? false
        isDeactivated = try container.decodeIfPresent(Bool.self, forKey: .isDeactivated) ?? false
        lastSeenAt = try container.decodeIfPresent(Date.self, forKey: .lastSeenAt)
        workspaces = try container.decodeIfPresent([WorkspaceSummary].self, forKey: .workspaces) ?? []
    }
}

/// The state of an invitation.
public enum InvitationStatus: String, Decodable, Sendable {
    case pending
    case accepted
    case expired
    case revoked
}

/// An invitation of `GET /admin/invitations`: who is invited, to which
/// workspace and with which modules there.
public struct AdminInvitation: Decodable, Equatable, Hashable, Sendable, Identifiable {
    public let id: String
    public let email: String

    /// The raw status; `knownStatus` is nil for one this app does not know.
    public let status: String
    public let workspace: WorkspaceSummary?
    public let modules: [String]
    public let locale: String?
    public let expiresAt: Date?
    public let createdAt: Date?

    public var knownStatus: InvitationStatus? { InvitationStatus(rawValue: status) }

    /// Only an open invitation can be sent again or revoked.
    public var isPending: Bool { knownStatus == .pending }

    public init(
        id: String,
        email: String,
        status: String = InvitationStatus.pending.rawValue,
        workspace: WorkspaceSummary? = nil,
        modules: [String] = [],
        locale: String? = nil,
        expiresAt: Date? = nil,
        createdAt: Date? = nil
    ) {
        self.id = id
        self.email = email
        self.status = status
        self.workspace = workspace
        self.modules = modules
        self.locale = locale
        self.expiresAt = expiresAt
        self.createdAt = createdAt
    }

    private enum CodingKeys: String, CodingKey {
        case id, email, status, workspace, modules, locale, expiresAt, createdAt
    }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        id = try container.decode(String.self, forKey: .id)
        email = try container.decode(String.self, forKey: .email)
        status = try container.decode(String.self, forKey: .status)
        workspace = try container.decodeIfPresent(WorkspaceSummary.self, forKey: .workspace)
        modules = try container.decodeIfPresent([String].self, forKey: .modules) ?? []
        locale = try container.decodeIfPresent(String.self, forKey: .locale)
        expiresAt = try container.decodeIfPresent(Date.self, forKey: .expiresAt)
        createdAt = try container.decodeIfPresent(Date.self, forKey: .createdAt)
    }
}

/// The body of `POST /admin/workspaces` and `PATCH /admin/workspaces/{workspace}`.
public struct AdminWorkspaceInput: Encodable, Equatable, Sendable {
    public let name: String
    public let color: String

    public init(name: String, color: String) {
        self.name = name
        self.color = color
    }
}

/// The body of `POST /admin/workspaces/{workspace}/members` (with `user`)
/// and of `PATCH …/members/{user}` (without). The color is sent always,
/// `null` for the workspace's own; `isDefault` only to make it the default.
public struct AdminMemberInput: Encodable, Equatable, Sendable {
    public let user: String?
    public let modules: [String]
    public let color: String?
    public let isDefault: Bool

    public init(user: String? = nil, modules: [String], color: String?, isDefault: Bool = false) {
        self.user = user
        self.modules = modules
        self.color = color
        self.isDefault = isDefault
    }

    public func encode(to encoder: any Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        try container.encodeIfPresent(user, forKey: .user)
        try container.encode(modules, forKey: .modules)
        try container.encode(color, forKey: .color)

        if isDefault {
            try container.encode(true, forKey: .isDefault)
        }
    }

    enum CodingKeys: String, CodingKey {
        case user
        case modules
        case color
        case isDefault
    }
}

/// The body of `POST /admin/invitations`.
public struct AdminInvitationInput: Encodable, Equatable, Sendable {
    public let email: String
    public let workspace: String
    public let modules: [String]
    public let locale: String

    public init(email: String, workspace: String, modules: [String], locale: String) {
        self.email = email
        self.workspace = workspace
        self.modules = modules
        self.locale = locale
    }
}
