import Foundation

/// `components/schemas/ShoppingListItemInput`. A `nil` note is sent as `null`,
/// so editing an item can clear it.
public struct ShoppingItemInput: Encodable, Equatable, Sendable {
    public let name: String
    public let quantityNote: String?

    public init(name: String, quantityNote: String?) {
        self.name = name
        self.quantityNote = quantityNote
    }

    public func encode(to encoder: any Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        try container.encode(name, forKey: .name)
        try container.encode(quantityNote, forKey: .quantityNote)
    }

    enum CodingKeys: String, CodingKey {
        case name
        case quantityNote
    }
}

/// The body of `POST /shopping/items/{item}/toggle`.
public struct ShoppingItemToggle: Encodable, Equatable, Sendable {
    public let checked: Bool
    public let clientUpdatedAt: Date?

    public init(checked: Bool, clientUpdatedAt: Date?) {
        self.checked = checked
        self.clientUpdatedAt = clientUpdatedAt
    }
}

/// `components/schemas/ShoppingListScheduleInput`: the whole recurring list,
/// as the web form sends it. Each run of `recurrence` is a list's deadline;
/// `templateItems` only count for `templateAndPending`, and `waitsForClose`
/// only for a list closed by hand (the server ignores it otherwise).
/// `workspace` (a friendly id) is sent only when set: on a new one, nil
/// means the user's default workspace; on an edit, it moves the schedule.
public struct ShoppingListScheduleInput: Encodable, Equatable, Sendable {
    public let name: String
    public let recurrence: RecurrenceInput
    public let nextListContent: ShoppingListNextContent
    public let closesAutomatically: Bool
    public let waitsForClose: Bool
    public let templateItems: [String]
    public let workspace: String?

    public init(
        name: String,
        recurrence: RecurrenceInput,
        nextListContent: ShoppingListNextContent,
        closesAutomatically: Bool,
        waitsForClose: Bool,
        templateItems: [String],
        workspace: String? = nil
    ) {
        self.name = name
        self.recurrence = recurrence
        self.nextListContent = nextListContent
        self.closesAutomatically = closesAutomatically
        self.waitsForClose = waitsForClose
        self.templateItems = templateItems
        self.workspace = workspace
    }

    /// The template typed one item per line, without the blank lines.
    public static func templateItems(fromLines text: String) -> [String] {
        text.split(whereSeparator: \.isNewline)
            .map { $0.trimmingCharacters(in: .whitespaces) }
            .filter { !$0.isEmpty }
    }
}

/// `components/schemas/RecurrenceInput`: a schedule to save. The dates go
/// without an offset (`2026-10-06T09:00`), so the server reads them in the
/// user's timezone, as the web form sends them.
public struct RecurrenceInput: Encodable, Equatable, Sendable {
    public let frequency: RecurrenceFrequency
    public let timeOfDay: String?
    public let weekdays: [Weekday]?
    public let dayOfMonth: Int?
    public let isLastDayOfMonth: Bool?
    public let cronExpression: String?
    public let startsAt: String?
    public let endsAt: String?

    public init(
        frequency: RecurrenceFrequency,
        timeOfDay: String? = nil,
        weekdays: [Weekday]? = nil,
        dayOfMonth: Int? = nil,
        isLastDayOfMonth: Bool? = nil,
        cronExpression: String? = nil,
        startsAt: String? = nil,
        endsAt: String? = nil
    ) {
        self.frequency = frequency
        self.timeOfDay = timeOfDay
        self.weekdays = weekdays
        self.dayOfMonth = dayOfMonth
        self.isLastDayOfMonth = isLastDayOfMonth
        self.cronExpression = cronExpression
        self.startsAt = startsAt
        self.endsAt = endsAt
    }
}

/// `components/schemas/ReminderInput`: the whole reminder. No `dueAt` nor
/// `recurrence` makes an undated task; both are sent as `null` on purpose,
/// as an edit replaces the reminder. So is `assignedTo`: `null` leaves the
/// reminder with nobody responsible. An event needs a date or a repeat, and
/// a timed one its `durationMinutes`; an all-day item is due at the
/// server's all-day time, whatever time is sent. `workspace` (a friendly id)
/// is sent only when set: on a new one, nil means the user's default
/// workspace; on an edit, it moves the reminder.
public struct ReminderInput: Encodable, Equatable, Sendable {
    public let title: String
    public let note: String?
    public let dueAt: String?
    public let recurrence: RecurrenceInput?
    public let assignedTo: String?
    public let kind: ReminderKind
    public let isAllDay: Bool
    public let durationMinutes: Int?
    public let workspace: String?

    public init(
        title: String,
        note: String?,
        dueAt: String?,
        recurrence: RecurrenceInput?,
        assignedTo: String?,
        kind: ReminderKind = .reminder,
        isAllDay: Bool = false,
        durationMinutes: Int? = nil,
        workspace: String? = nil
    ) {
        self.title = title
        self.note = note
        self.dueAt = dueAt
        self.recurrence = recurrence
        self.assignedTo = assignedTo
        self.kind = kind
        self.isAllDay = isAllDay
        self.durationMinutes = durationMinutes
        self.workspace = workspace
    }

    public func encode(to encoder: any Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        try container.encode(title, forKey: .title)
        try container.encode(note, forKey: .note)
        try container.encode(dueAt, forKey: .dueAt)
        try container.encode(recurrence, forKey: .recurrence)
        try container.encode(assignedTo, forKey: .assignedTo)
        try container.encode(kind, forKey: .kind)
        try container.encode(isAllDay, forKey: .isAllDay)
        try container.encode(durationMinutes, forKey: .durationMinutes)
        try container.encodeIfPresent(workspace, forKey: .workspace)
    }

    enum CodingKeys: String, CodingKey {
        case title
        case note
        case dueAt
        case recurrence
        case assignedTo
        case kind
        case isAllDay
        case durationMinutes
        case workspace
    }
}

/// `components/schemas/WhatsappSendingInput`, and with a schedule
/// `WhatsappMessageInput` (US-7.3, US-7.5).
public struct WhatsappMessageInput: Encodable, Equatable, Sendable {
    public let account: String
    public let targets: [String]
    public let numbers: [String]
    public let body: String
    public let recurrence: RecurrenceInput?

    public init(account: String, targets: [String], numbers: [String], body: String, recurrence: RecurrenceInput?) {
        self.account = account
        self.targets = targets
        self.numbers = numbers
        self.body = body
        self.recurrence = recurrence
    }
}

/// A date and time as the user reads it on a wall clock in their timezone.
public enum WallClock {
    /// `2026-10-06T09:00`.
    public static func dateTime(_ date: Date, in timeZone: TimeZone) -> String {
        format(date, "yyyy-MM-dd'T'HH:mm", timeZone)
    }

    /// `09:00`.
    public static func time(_ date: Date, in timeZone: TimeZone) -> String {
        format(date, "HH:mm", timeZone)
    }

    /// Today at `09:00` in the timezone (a saved `time_of_day`), for a time picker.
    public static func date(atTime timeOfDay: String, in timeZone: TimeZone, on day: Date = .now) -> Date? {
        let parts = timeOfDay.split(separator: ":").compactMap { Int($0) }

        guard parts.count >= 2 else {
            return nil
        }

        var calendar = Calendar(identifier: .gregorian)
        calendar.timeZone = timeZone

        return calendar.date(bySettingHour: parts[0], minute: parts[1], second: 0, of: day)
    }

    private static func format(_ date: Date, _ pattern: String, _ timeZone: TimeZone) -> String {
        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.calendar = Calendar(identifier: .gregorian)
        formatter.timeZone = timeZone
        formatter.dateFormat = pattern

        return formatter.string(from: date)
    }
}
