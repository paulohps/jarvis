import Foundation

/// `components/schemas/User`: the signed-in user and their modules (`GET /me`).
public struct User: Codable, Equatable, Sendable {
    public let friendlyId: String
    public let name: String
    public let email: String
    public let avatarUrl: String?
    public let locale: String
    public let timezone: String
    public let isSuperAdmin: Bool
    public let modules: [UserModule]

    /// The workspaces the user is in; empty from a server older than them
    /// (and from a `/me` cached before).
    public let workspaces: [UserWorkspace]

    /// The private Reverb channel of the user (`private-App.Models.User.{id}`).
    public let broadcastChannel: String?

    public init(
        friendlyId: String,
        name: String,
        email: String,
        avatarUrl: String? = nil,
        locale: String = "en",
        timezone: String = "UTC",
        isSuperAdmin: Bool = false,
        modules: [UserModule] = [],
        workspaces: [UserWorkspace] = [],
        broadcastChannel: String? = nil
    ) {
        self.friendlyId = friendlyId
        self.name = name
        self.email = email
        self.avatarUrl = avatarUrl
        self.locale = locale
        self.timezone = timezone
        self.isSuperAdmin = isSuperAdmin
        self.modules = modules
        self.workspaces = workspaces
        self.broadcastChannel = broadcastChannel
    }

    private enum CodingKeys: String, CodingKey {
        case friendlyId, name, email, avatarUrl, locale, timezone, isSuperAdmin, modules, workspaces, broadcastChannel
    }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        friendlyId = try container.decode(String.self, forKey: .friendlyId)
        name = try container.decode(String.self, forKey: .name)
        email = try container.decode(String.self, forKey: .email)
        avatarUrl = try container.decodeIfPresent(String.self, forKey: .avatarUrl)
        locale = try container.decode(String.self, forKey: .locale)
        timezone = try container.decode(String.self, forKey: .timezone)
        isSuperAdmin = try container.decode(Bool.self, forKey: .isSuperAdmin)
        modules = try container.decode([UserModule].self, forKey: .modules)
        workspaces = try container.decodeIfPresent([UserWorkspace].self, forKey: .workspaces) ?? []
        broadcastChannel = try container.decodeIfPresent(String.self, forKey: .broadcastChannel)
    }
}

/// A module switched on for the user.
public struct UserModule: Codable, Equatable, Sendable {
    public let slug: String
    public let name: String
    public let isAvailableOnMobile: Bool

    public init(slug: String, name: String, isAvailableOnMobile: Bool) {
        self.slug = slug
        self.name = name
        self.isAvailableOnMobile = isAvailableOnMobile
    }
}

/// `responses/TokenIssued`: the token of this device and the user.
public struct TokenResponse: Decodable, Equatable, Sendable {
    public let token: String
    public let user: User
}

/// `components/schemas/Device`.
public struct Device: Decodable, Equatable, Sendable {
    public let friendlyId: String
    public let name: String
    public let platform: String
    public let hasPushToken: Bool
    public let isCurrent: Bool
}

/// The body of `POST /devices`.
public struct DeviceRegistration: Encodable, Equatable, Sendable {
    public let platform: String
    public let name: String
    public let pushToken: String?

    public init(platform: String = "ios", name: String, pushToken: String?) {
        self.platform = platform
        self.name = name
        self.pushToken = pushToken
    }

    public func encode(to encoder: any Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        try container.encode(platform, forKey: .platform)
        try container.encode(name, forKey: .name)
        // `null` clears the saved token on the server; it is sent on purpose.
        try container.encode(pushToken, forKey: .pushToken)
    }

    enum CodingKeys: String, CodingKey {
        case platform
        case name
        case pushToken
    }
}

/// `components/schemas/Notification`: an in-app notification.
public struct NotificationItem: Decodable, Equatable, Sendable, Identifiable {
    public let id: String
    public let title: String
    public let body: String?
    public let url: String?
    public let actions: [NotificationAction]
    public var isRead: Bool
    public let createdAt: Date

    public init(
        id: String,
        title: String,
        body: String? = nil,
        url: String? = nil,
        actions: [NotificationAction] = [],
        isRead: Bool = false,
        createdAt: Date = .now
    ) {
        self.id = id
        self.title = title
        self.body = body
        self.url = url
        self.actions = actions
        self.isRead = isRead
        self.createdAt = createdAt
    }
}

extension NotificationItem {
    /// The body without its HTML (it comes sanitized, for the web), as plain text.
    public var plainBody: String? {
        guard let body, !body.isEmpty else {
            return nil
        }

        var text = body
            .replacing(/<br\s*\/?>/.ignoresCase(), with: "\n")
            .replacing(/<\/p>\s*<p[^>]*>/.ignoresCase(), with: "\n\n")
            .replacing(/<[^>]+>/, with: "")

        for (entity, character) in [("&nbsp;", " "), ("&lt;", "<"), ("&gt;", ">"), ("&quot;", "\""), ("&#039;", "'"), ("&#39;", "'"), ("&amp;", "&")] {
            text = text.replacingOccurrences(of: entity, with: character)
        }

        let trimmed = text.trimmingCharacters(in: .whitespacesAndNewlines)

        return trimmed.isEmpty ? nil : trimmed
    }
}

/// The `notification.created` event of the user's private channel.
public struct NotificationCreatedEvent: Decodable, Equatable, Sendable {
    public static let name = "notification.created"

    public let id: String
    public let title: String
    public let unreadCount: Int
}

public struct NotificationAction: Decodable, Equatable, Sendable {
    public let label: String
    public let url: String
}

/// A page of `GET /notifications`.
public struct NotificationPage: Decodable, Equatable, Sendable {
    public let data: [NotificationItem]
    public let meta: Meta

    public struct Meta: Decodable, Equatable, Sendable {
        public let currentPage: Int
        public let lastPage: Int
        public let unreadCount: Int
    }

    public var hasMorePages: Bool {
        meta.currentPage < meta.lastPage
    }
}

/// `responses/NotificationsMarked`.
public struct NotificationsMarked: Decodable, Equatable, Sendable {
    public let marked: Int
    public let unreadCount: Int
}

/// The answer of `POST /broadcasting/auth`.
public struct BroadcastAuthorization: Decodable, Equatable, Sendable {
    public let auth: String
    public let channelData: String?
}

/// `components/schemas/UserSummary`.
public struct UserSummary: Decodable, Equatable, Sendable {
    public let friendlyId: String
    public let name: String

    public init(friendlyId: String, name: String) {
        self.friendlyId = friendlyId
        self.name = name
    }
}

/// `components/schemas/Reminder`: a reminder or task of the household (US-9.1).
public struct Reminder: Decodable, Equatable, Sendable, Identifiable {
    public let friendlyId: String
    public let title: String
    public let note: String?
    public let isRecurring: Bool

    /// A reminder or an event; absent before the server knew it: a reminder.
    public let kind: ReminderKind?
    public let isAllDay: Bool?

    /// How long a timed event lasts; nil otherwise.
    public let durationMinutes: Int?

    /// Nil for an undated task; `once` for a dated one.
    public let recurrence: Recurrence?
    public let createdBy: UserSummary?

    /// Who is responsible, and receives `reminder_due`; nil for nobody (the
    /// creator receives it then).
    public let assignedTo: UserSummary?

    /// The workspace it is shared in; nil from a server older than them.
    public let workspace: WorkspaceSummary?

    public var id: String { friendlyId }

    public var isEvent: Bool { kind == .event }

    public init(
        friendlyId: String,
        title: String,
        note: String? = nil,
        isRecurring: Bool = false,
        kind: ReminderKind? = nil,
        isAllDay: Bool? = nil,
        durationMinutes: Int? = nil,
        recurrence: Recurrence? = nil,
        createdBy: UserSummary? = nil,
        assignedTo: UserSummary? = nil,
        workspace: WorkspaceSummary? = nil
    ) {
        self.friendlyId = friendlyId
        self.title = title
        self.note = note
        self.isRecurring = isRecurring
        self.kind = kind
        self.isAllDay = isAllDay
        self.durationMinutes = durationMinutes
        self.recurrence = recurrence
        self.createdBy = createdBy
        self.assignedTo = assignedTo
        self.workspace = workspace
    }
}

/// What a reminder is: something to do and complete, or an event on the
/// calendar, with a duration and nothing to complete.
public enum ReminderKind: String, Codable, CaseIterable, Sendable {
    case reminder
    case event
}

/// The answer of `GET /reminders/{id}`: the reminder and who may be made
/// responsible, for the form.
public struct ReminderPage: Decodable, Equatable, Sendable {
    public let data: Reminder
    public let meta: Meta

    public struct Meta: Decodable, Equatable, Sendable {
        public let timezone: String
        public let assignees: [UserSummary]?

        /// Who may be made responsible in each of the user's workspaces.
        public let assigneesByWorkspace: AssigneesByWorkspace?
    }
}

/// The sources of the agenda's calendar, as `CalendarEntry.source` names them.
public enum CalendarSource: String, CaseIterable, Sendable {
    case reminders
    case events
    case invoices
    case whatsapp
    case shopping
    case google
}

/// `components/schemas/CalendarEntry`: one item of the agenda's calendar,
/// from any source. A reminder or event names its `reminderId` (to edit it)
/// and, once it has one, its `occurrenceId` (to complete a reminder); the
/// other sources link to their web page (`url`).
public struct CalendarEntry: Decodable, Equatable, Sendable, Identifiable {
    public let key: String
    public let source: String
    public let title: String
    public let subtitle: String?
    public let startsAt: Date
    public let endsAt: Date?
    public let isAllDay: Bool
    public let isProjected: Bool
    public let isCompletable: Bool
    public let isCompleted: Bool
    public let isOverdue: Bool
    public let reminderId: String?
    public let occurrenceId: String?
    public let url: String?

    /// The workspace of a shared item (a reminder, an event, a list); nil for
    /// a personal source (WhatsApp, Google) and from a server older than them.
    public let workspace: WorkspaceSummary?

    public var id: String { key }

    /// The known source, nil for one this app does not know yet.
    public var calendarSource: CalendarSource? { CalendarSource(rawValue: source) }

    public init(
        key: String,
        source: String,
        title: String,
        subtitle: String? = nil,
        startsAt: Date,
        endsAt: Date? = nil,
        isAllDay: Bool = false,
        isProjected: Bool = false,
        isCompletable: Bool = false,
        isCompleted: Bool = false,
        isOverdue: Bool = false,
        reminderId: String? = nil,
        occurrenceId: String? = nil,
        url: String? = nil,
        workspace: WorkspaceSummary? = nil
    ) {
        self.key = key
        self.source = source
        self.title = title
        self.subtitle = subtitle
        self.startsAt = startsAt
        self.endsAt = endsAt
        self.isAllDay = isAllDay
        self.isProjected = isProjected
        self.isCompletable = isCompletable
        self.isCompleted = isCompleted
        self.isOverdue = isOverdue
        self.reminderId = reminderId
        self.occurrenceId = occurrenceId
        self.url = url
        self.workspace = workspace
    }

    private enum CodingKeys: String, CodingKey {
        case key, source, title, subtitle, startsAt, endsAt, isAllDay, isProjected, isCompletable, isCompleted, isOverdue, reminderId, occurrenceId, url, workspace
    }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        key = try container.decode(String.self, forKey: .key)
        source = try container.decode(String.self, forKey: .source)
        title = try container.decode(String.self, forKey: .title)
        subtitle = try container.decodeIfPresent(String.self, forKey: .subtitle)
        startsAt = try container.decode(Date.self, forKey: .startsAt)
        endsAt = try container.decodeIfPresent(Date.self, forKey: .endsAt)
        isAllDay = try container.decode(Bool.self, forKey: .isAllDay)
        isProjected = try container.decode(Bool.self, forKey: .isProjected)
        isCompletable = try container.decode(Bool.self, forKey: .isCompletable)
        isCompleted = try container.decode(Bool.self, forKey: .isCompleted)
        isOverdue = try container.decode(Bool.self, forKey: .isOverdue)
        reminderId = try container.decodeIfPresent(String.self, forKey: .reminderId)
        occurrenceId = try container.decodeIfPresent(String.self, forKey: .occurrenceId)
        url = try container.decodeIfPresent(String.self, forKey: .url)
        // Absent before the workspaces; a malformed one is not worth losing the entry for.
        workspace = try? container.decodeIfPresent(WorkspaceSummary.self, forKey: .workspace)
    }
}

/// The answer of `GET /agenda`: the items starting in the period, all-day
/// ones first in each day, and the sources the user may see.
public struct CalendarPage: Decodable, Equatable, Sendable {
    public let data: [CalendarEntry]
    public let meta: Meta

    public struct Meta: Decodable, Equatable, Sendable {
        public let from: Date
        public let to: Date
        public let mine: Bool
        public let sources: [String]
        public let timezone: String

        /// Who may be made responsible; absent before the server knew it.
        public let assignees: [UserSummary]?

        /// Who may be made responsible in each of the user's workspaces.
        public let assigneesByWorkspace: AssigneesByWorkspace?
    }
}

/// `components/schemas/ReminderOccurrence`.
public struct ReminderOccurrence: Decodable, Equatable, Sendable, Identifiable {
    public let friendlyId: String
    public let dueAt: Date?
    public let isCompleted: Bool
    public let isOverdue: Bool
    public let completedAt: Date?
    public let completedBy: UserSummary?
    public let reminder: Reminder

    public var id: String { friendlyId }

    public init(
        friendlyId: String,
        dueAt: Date? = nil,
        isCompleted: Bool = false,
        isOverdue: Bool = false,
        completedAt: Date? = nil,
        completedBy: UserSummary? = nil,
        reminder: Reminder
    ) {
        self.friendlyId = friendlyId
        self.dueAt = dueAt
        self.isCompleted = isCompleted
        self.isOverdue = isOverdue
        self.completedAt = completedAt
        self.completedBy = completedBy
        self.reminder = reminder
    }
}

/// The answer of `GET /reminders`: the occurrences under the filter and how
/// many each filter has, as the web's tabs show them, and who may be made
/// responsible (absent before the server knew it: none).
public struct ReminderOccurrencePage: Decodable, Equatable, Sendable {
    public let data: [ReminderOccurrence]
    public let meta: Meta

    public struct Meta: Decodable, Equatable, Sendable {
        public let filter: String
        public let counts: Counts
        public let timezone: String
        public let assignees: [UserSummary]?

        /// Who may be made responsible in each of the user's workspaces.
        public let assigneesByWorkspace: AssigneesByWorkspace?
    }

    public struct Counts: Decodable, Equatable, Sendable {
        public let pending: Int
        public let overdue: Int
        public let completed: Int

        public func count(of filter: ReminderFilter) -> Int {
            switch filter {
            case .pending: pending
            case .overdue: overdue
            case .completed: completed
            }
        }
    }
}

/// The filters of `GET /reminders`, as on the web.
public enum ReminderFilter: String, CaseIterable, Sendable {
    case pending
    case overdue
    case completed
}

/// The answer of `GET /reminders/agenda`: the open occurrences by day, as the
/// web's agenda, with the coming runs of the repeating reminders projected
/// for the next `meta.projectionDays`.
public struct ReminderAgendaPage: Decodable, Equatable, Sendable {
    public let data: [ReminderAgendaGroup]
    public let meta: Meta

    public struct Meta: Decodable, Equatable, Sendable {
        public let mine: Bool
        public let projectionDays: Int
        public let assignees: [UserSummary]
        public let timezone: String

        /// Who may be made responsible in each of the user's workspaces.
        public let assigneesByWorkspace: AssigneesByWorkspace?
    }
}

/// A group of the agenda: `overdue` first, then each day (`date`, a
/// `YYYY-MM-DD` in the user's timezone), then `undated`.
public struct ReminderAgendaGroup: Decodable, Equatable, Sendable, Identifiable {
    public static let overdueKey = "overdue"
    public static let undatedKey = "undated"

    public let key: String
    public let date: String?
    public let entries: [ReminderAgendaEntry]

    public var id: String { key }

    public init(key: String, date: String?, entries: [ReminderAgendaEntry]) {
        self.key = key
        self.date = date
        self.entries = entries
    }

    /// The day of a day group, at its midnight in `timeZone`.
    public func day(in timeZone: TimeZone) -> Date? {
        guard let date else {
            return nil
        }

        let parts = date.split(separator: "-").compactMap { Int($0) }

        guard parts.count == 3 else {
            return nil
        }

        var calendar = Calendar(identifier: .gregorian)
        calendar.timeZone = timeZone

        return calendar.date(from: DateComponents(year: parts[0], month: parts[1], day: parts[2]))
    }
}

/// A line of the agenda: an open occurrence, or a coming run of a repeating
/// reminder projected without one (`isProjected`), which cannot be completed
/// until its occurrence exists.
public struct ReminderAgendaEntry: Decodable, Equatable, Sendable, Identifiable {
    public let dueAt: Date?
    public let isProjected: Bool
    public let isOverdue: Bool
    public let occurrence: ReminderOccurrence?
    public let reminder: Reminder

    public var id: String {
        occurrence?.friendlyId ?? "\(reminder.friendlyId)@\(dueAt?.timeIntervalSince1970 ?? 0)"
    }

    public init(dueAt: Date?, isProjected: Bool, isOverdue: Bool = false, occurrence: ReminderOccurrence?, reminder: Reminder) {
        self.dueAt = dueAt
        self.isProjected = isProjected
        self.isOverdue = isOverdue
        self.occurrence = occurrence
        self.reminder = reminder
    }
}

/// `components/schemas/ShoppingList`: a list of the household (US-8.1). The
/// counts come on the index; the items only on a single list. `isDefault`
/// marks the household's default, the list a voice command that names none
/// adds to (absent before the server knew it: `false`).
public struct ShoppingList: Decodable, Equatable, Sendable, Identifiable {
    public let friendlyId: String
    public let name: String
    public let isArchived: Bool
    public let archivedAt: Date?
    public let isDefault: Bool
    public let itemsCount: Int?
    public let uncheckedItemsCount: Int?

    /// Made by a recurring list (`scheduleId`): it has a deadline, and is
    /// completed — by hand or at the deadline — instead of archived.
    public let scheduleId: String?
    public let dueAt: Date?
    public let isOverdue: Bool
    public let completedAt: Date?

    /// Unchecked first by position, checked at the end in the order they were checked.
    public let items: [ShoppingListItem]?

    /// The workspace it is shared in; nil from a server older than them.
    public let workspace: WorkspaceSummary?

    public var id: String { friendlyId }

    public init(
        friendlyId: String,
        name: String,
        isArchived: Bool = false,
        archivedAt: Date? = nil,
        isDefault: Bool = false,
        itemsCount: Int? = nil,
        uncheckedItemsCount: Int? = nil,
        scheduleId: String? = nil,
        dueAt: Date? = nil,
        isOverdue: Bool = false,
        completedAt: Date? = nil,
        items: [ShoppingListItem]? = nil,
        workspace: WorkspaceSummary? = nil
    ) {
        self.friendlyId = friendlyId
        self.name = name
        self.isArchived = isArchived
        self.archivedAt = archivedAt
        self.isDefault = isDefault
        self.itemsCount = itemsCount
        self.uncheckedItemsCount = uncheckedItemsCount
        self.scheduleId = scheduleId
        self.dueAt = dueAt
        self.isOverdue = isOverdue
        self.completedAt = completedAt
        self.items = items
        self.workspace = workspace
    }

    private enum CodingKeys: String, CodingKey {
        case friendlyId, name, isArchived, archivedAt, isDefault, itemsCount, uncheckedItemsCount, scheduleId, dueAt, isOverdue, completedAt, items, workspace
    }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        friendlyId = try container.decode(String.self, forKey: .friendlyId)
        name = try container.decode(String.self, forKey: .name)
        isArchived = try container.decode(Bool.self, forKey: .isArchived)
        archivedAt = try container.decodeIfPresent(Date.self, forKey: .archivedAt)
        isDefault = try container.decodeIfPresent(Bool.self, forKey: .isDefault) ?? false
        itemsCount = try container.decodeIfPresent(Int.self, forKey: .itemsCount)
        uncheckedItemsCount = try container.decodeIfPresent(Int.self, forKey: .uncheckedItemsCount)
        // Absent before the server had recurring lists.
        scheduleId = try container.decodeIfPresent(String.self, forKey: .scheduleId)
        dueAt = try container.decodeIfPresent(Date.self, forKey: .dueAt)
        isOverdue = try container.decodeIfPresent(Bool.self, forKey: .isOverdue) ?? false
        completedAt = try container.decodeIfPresent(Date.self, forKey: .completedAt)
        items = try container.decodeIfPresent([ShoppingListItem].self, forKey: .items)
        // Absent before the workspaces.
        workspace = try container.decodeIfPresent(WorkspaceSummary.self, forKey: .workspace)
    }

    /// The private Reverb channel of the list (`ShoppingListChanged`, US-8.2).
    public var broadcastChannel: String {
        Self.broadcastChannel(listId: friendlyId)
    }

    public static func broadcastChannel(listId: String) -> String {
        "private-shopping-lists.\(listId)"
    }
}

/// What a recurring list's next list starts with.
public enum ShoppingListNextContent: String, Codable, CaseIterable, Sendable {
    case pending
    case all
    case templateAndPending = "template_and_pending"
    case empty
}

/// `components/schemas/ShoppingListSchedule`: a shopping list made again on
/// a schedule (the market every Friday), each run the deadline of a list.
/// `openList` is the list it made that is still open, if any.
public struct ShoppingListSchedule: Decodable, Equatable, Sendable, Identifiable {
    public struct OpenList: Decodable, Equatable, Sendable {
        public let friendlyId: String
        public let name: String
        public let dueAt: Date?
        public let isOverdue: Bool

        public init(friendlyId: String, name: String, dueAt: Date? = nil, isOverdue: Bool = false) {
            self.friendlyId = friendlyId
            self.name = name
            self.dueAt = dueAt
            self.isOverdue = isOverdue
        }
    }

    public let friendlyId: String
    public let name: String
    public let nextListContent: ShoppingListNextContent
    public let closesAutomatically: Bool
    public let waitsForClose: Bool
    public let templateItems: [String]
    public let recurrence: Recurrence?
    public let openList: OpenList?

    /// The workspace its lists are made in; nil from a server older than them.
    public let workspace: WorkspaceSummary?

    public var id: String { friendlyId }

    public init(
        friendlyId: String,
        name: String,
        nextListContent: ShoppingListNextContent = .pending,
        closesAutomatically: Bool = true,
        waitsForClose: Bool = false,
        templateItems: [String] = [],
        recurrence: Recurrence? = nil,
        openList: OpenList? = nil,
        workspace: WorkspaceSummary? = nil
    ) {
        self.friendlyId = friendlyId
        self.name = name
        self.nextListContent = nextListContent
        self.closesAutomatically = closesAutomatically
        self.waitsForClose = waitsForClose
        self.templateItems = templateItems
        self.recurrence = recurrence
        self.openList = openList
        self.workspace = workspace
    }
}

/// The `filter` of `GET /shopping/lists`.
public enum ShoppingListFilter: String, CaseIterable, Sendable {
    case active
    case archived
}

/// `components/schemas/ShoppingListItem`.
public struct ShoppingListItem: Decodable, Equatable, Sendable, Identifiable {
    public let friendlyId: String
    public var name: String
    public var quantityNote: String?
    public var position: Int
    public var isChecked: Bool
    public var checkedAt: Date?
    public var checkedBy: UserSummary?
    public var clientUpdatedAt: Date?

    public var id: String { friendlyId }

    public init(
        friendlyId: String,
        name: String,
        quantityNote: String? = nil,
        position: Int = 0,
        isChecked: Bool = false,
        checkedAt: Date? = nil,
        checkedBy: UserSummary? = nil,
        clientUpdatedAt: Date? = nil
    ) {
        self.friendlyId = friendlyId
        self.name = name
        self.quantityNote = quantityNote
        self.position = position
        self.isChecked = isChecked
        self.checkedAt = checkedAt
        self.checkedBy = checkedBy
        self.clientUpdatedAt = clientUpdatedAt
    }
}

extension [ShoppingListItem] {
    /// As the API orders them: unchecked by position, then checked in the order they were checked.
    public func sortedForDisplay() -> [ShoppingListItem] {
        let unchecked = filter { !$0.isChecked }.sorted { $0.position < $1.position }
        let checked = filter(\.isChecked).sorted {
            ($0.checkedAt ?? .distantFuture, $0.position) < ($1.checkedAt ?? .distantFuture, $1.position)
        }

        return unchecked + checked
    }

    /// The items with `order` (friendly ids) in the first positions, as
    /// `POST /shopping/lists/{list}/reorder` leaves them: the items left out
    /// keep their relative order after them, unknown ids are ignored.
    public func reordered(_ order: [String]) -> [ShoppingListItem] {
        let rank = Dictionary(order.enumerated().map { ($1, $0) }, uniquingKeysWith: { first, _ in first })
        let current = sorted { $0.position < $1.position }
        let given = current.filter { rank[$0.friendlyId] != nil }.sorted { rank[$0.friendlyId]! < rank[$1.friendlyId]! }
        let others = current.filter { rank[$0.friendlyId] == nil }

        return (given + others).enumerated().map { position, item in
            var item = item
            item.position = position

            return item
        }
    }
}

/// The answer of `POST /shopping/lists/{list}/clear-checked`.
public struct ClearedCheckedItems: Decodable, Equatable, Sendable {
    public let data: ShoppingList
    public let meta: Meta

    public struct Meta: Decodable, Equatable, Sendable {
        public let removed: Int
    }
}

/// The `shopping-list.changed` event of a list's channel: a hint of what
/// changed; the screen reads the list again.
public struct ShoppingListChangedEvent: Decodable, Equatable, Sendable {
    public static let name = "shopping-list.changed"

    public let listId: String
    public let change: String
    public let itemId: String?
}

/// `components/schemas/WhatsappAccount`: one of the user's own numbers (read only in the app).
public struct WhatsappAccount: Decodable, Equatable, Sendable, Identifiable {
    public let friendlyId: String
    public let nickname: String
    public let phoneNumber: String?
    public let status: String
    public let isOpen: Bool

    public var id: String { friendlyId }
}

/// The presets of a schedule (US-12.1).
public enum RecurrenceFrequency: String, Codable, CaseIterable, Sendable {
    case once
    case daily
    case weekly
    case monthly
    case customCron = "custom_cron"
}

/// The weekday slugs of a weekly schedule, Monday first (ISO).
public enum Weekday: String, Codable, CaseIterable, Sendable {
    case monday
    case tuesday
    case wednesday
    case thursday
    case friday
    case saturday
    case sunday
}

/// `components/schemas/Recurrence`: a schedule as saved. `timeOfDay` and the
/// weekdays are read in `timezone`; the instants are in UTC.
public struct Recurrence: Decodable, Equatable, Sendable {
    public let frequency: RecurrenceFrequency
    public let timeOfDay: String?
    public let weekdays: [Weekday]
    public let dayOfMonth: Int?
    public let isLastDayOfMonth: Bool
    public let cronExpression: String?
    public let timezone: String
    public let startsAt: Date
    public let endsAt: Date?
    public let nextRunAt: Date?
    public let isPaused: Bool

    public init(
        frequency: RecurrenceFrequency,
        timeOfDay: String? = nil,
        weekdays: [Weekday] = [],
        dayOfMonth: Int? = nil,
        isLastDayOfMonth: Bool = false,
        cronExpression: String? = nil,
        timezone: String = "UTC",
        startsAt: Date,
        endsAt: Date? = nil,
        nextRunAt: Date? = nil,
        isPaused: Bool = false
    ) {
        self.frequency = frequency
        self.timeOfDay = timeOfDay
        self.weekdays = weekdays
        self.dayOfMonth = dayOfMonth
        self.isLastDayOfMonth = isLastDayOfMonth
        self.cronExpression = cronExpression
        self.timezone = timezone
        self.startsAt = startsAt
        self.endsAt = endsAt
        self.nextRunAt = nextRunAt
        self.isPaused = isPaused
    }
}

/// The `meta` of the paginated answers.
public struct PageMeta: Decodable, Equatable, Sendable {
    public let currentPage: Int
    public let lastPage: Int

    public var hasMorePages: Bool {
        currentPage < lastPage
    }
}

/// A page of a paginated list.
public struct Page<Item: Decodable & Sendable & Equatable>: Decodable, Equatable, Sendable {
    public let data: [Item]
    public let meta: PageMeta
}

public enum WhatsappTargetType: String, Decodable, Sendable {
    case contact
    case group
}

/// `components/schemas/WhatsappTarget`: a contact or group of an account.
public struct WhatsappTarget: Decodable, Equatable, Hashable, Sendable, Identifiable {
    public let friendlyId: String
    public let name: String
    public let type: WhatsappTargetType
    public let phoneNumber: String?
    public let isSynced: Bool

    public var id: String { friendlyId }

    public init(friendlyId: String, name: String, type: WhatsappTargetType = .contact, phoneNumber: String? = nil, isSynced: Bool = true) {
        self.friendlyId = friendlyId
        self.name = name
        self.type = type
        self.phoneNumber = phoneNumber
        self.isSynced = isSynced
    }
}

/// The account a message or delivery is sent from.
public struct WhatsappAccountSummary: Decodable, Equatable, Sendable {
    public let friendlyId: String
    public let nickname: String
}

public enum WhatsappMessageState: String, Decodable, Sendable {
    case active
    case paused
    case cancelled
    case finished
}

/// `components/schemas/WhatsappMessage`: a scheduled message of the user (US-7.3).
public struct WhatsappMessage: Decodable, Equatable, Sendable, Identifiable {
    public let friendlyId: String
    public let account: WhatsappAccountSummary
    public let targets: [WhatsappTarget]
    public let body: String
    public let state: WhatsappMessageState
    public let recurrence: Recurrence?
    public let cancelledAt: Date?

    public var id: String { friendlyId }

    /// Only a one-time message not sent yet can be cancelled; a recurring one is paused.
    public var canBeCancelled: Bool {
        state == .active && recurrence?.frequency == .once
    }

    public var canBePaused: Bool {
        state == .active
    }

    public var canBeResumed: Bool {
        state == .paused
    }

    /// A cancelled message cannot be edited.
    public var canBeEdited: Bool {
        state != .cancelled
    }
}

public enum WhatsappDeliveryStatus: String, Decodable, Sendable {
    case pending
    case sent
    case failed
}

/// `components/schemas/WhatsappDelivery`: one sending to one target (US-7.5).
public struct WhatsappDelivery: Decodable, Equatable, Sendable {
    public let account: WhatsappAccountSummary
    public let target: WhatsappTarget
    public let messageId: String?
    public let body: String
    public let source: String
    public let status: WhatsappDeliveryStatus
    public let errorMessage: String?
    public let scheduledFor: Date?
    public let sentAt: Date?
    public let createdAt: Date?
}

