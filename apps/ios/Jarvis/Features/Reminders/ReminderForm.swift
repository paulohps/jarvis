import Localization
import Networking
import SwiftUI

/// Creates a reminder or event, or edits one (US-9.1): what it is, title,
/// note, who is responsible — the user, by default, on a new one — and
/// schedule — no date (a task, not for an event), a date and time, or a
/// repetition — all day or, for an event, how long it lasts.
/// For a user in two or more workspaces with reminders, also the workspace
/// it is shared in (the default one on a new reminder); changing it on an
/// edit moves it. The server checks the schedule; a refusal (a date in the
/// past) comes translated and is shown under the form.
struct ReminderForm: View {
    let reminder: Reminder?

    /// Who may be made responsible; empty from a server that does not know it.
    let assignees: [UserSummary]

    /// Who may be made responsible in each of the user's workspaces; the
    /// picker follows the workspace picked. Empty from an older server.
    var assigneesByWorkspace: [String: [UserSummary]] = [:]

    /// For a new one from the calendar: the hour tapped on the grid, or the
    /// day picked (at its midnight).
    var startsAt: Date?

    let onSave: () -> Void

    /// The durations offered for an event, as on the web.
    static let durations = [15, 30, 45, 60, 90, 120, 180, 240, 480]

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language
    @Environment(\.timeZone) private var timeZone
    @Environment(\.dismiss) private var dismiss

    @State private var kind = ReminderKind.reminder
    @State private var isAllDay = false
    @State private var durationMinutes = 60
    @State private var title = ""
    @State private var note = ""
    @State private var assignedTo: String?
    @State private var schedule: ScheduleDraft?
    @State private var workspaceId: String?
    @State private var isSaving = false
    @State private var failure: APIError?

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    Picker(language.localized("Kind"), selection: $kind) {
                        language.text("Reminder").tag(ReminderKind.reminder)
                        language.text("Event").tag(ReminderKind.event)
                    }
                    .pickerStyle(.segmented)
                    .accessibilityIdentifier("reminder.kind")
                }

                Section {
                    TextField(language.localized("Title"), text: $title)
                        .accessibilityIdentifier("reminder.title")
                    TextField(language.localized("Note"), text: $note, axis: .vertical)
                        .lineLimit(2...6)
                }

                if !workspaceOptions.isEmpty {
                    WorkspacePickerSection(workspaces: workspaceOptions, selection: $workspaceId)
                }

                if !assigneeOptions.isEmpty {
                    Section {
                        Picker(language.localized("Responsible"), selection: $assignedTo) {
                            language.text("Nobody").tag(String?.none)
                            ForEach(assigneeOptions, id: \.friendlyId) { assignee in
                                Text(verbatim: assignee.name).tag(String?.some(assignee.friendlyId))
                            }
                        }
                        .accessibilityIdentifier("reminder.assignedTo")
                    }
                }

                if let schedule {
                    if schedule.kind != .none {
                        Section {
                            Toggle(language.localized("All day"), isOn: $isAllDay)
                                .accessibilityIdentifier("reminder.allDay")

                            if kind == .event, !isAllDay {
                                Picker(language.localized("Duration"), selection: $durationMinutes) {
                                    ForEach(durationOptions, id: \.self) { minutes in
                                        Text(verbatim: durationText(minutes)).tag(minutes)
                                    }
                                }
                                .accessibilityIdentifier("reminder.duration")
                            }
                        }
                    }

                    ScheduleEditor(
                        draft: Binding { schedule } set: { self.schedule = $0 },
                        kinds: kind == .event ? ScheduleDraft.Kind.allCases.filter { $0 != .none } : ScheduleDraft.Kind.allCases
                    )
                }

                if let failure {
                    Section {
                        Label(failure.userMessage(in: language), systemImage: "exclamationmark.triangle.fill")
                            .foregroundStyle(.red)
                            .accessibilityIdentifier("reminder.error")
                    }
                }
            }
            .navigationTitle(language.localized(navigationTitleKey))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(language.localized("Cancel"), role: .cancel) {
                        dismiss()
                    }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button(language.localized("Save"), role: .confirm) {
                        Task { await save() }
                    }
                    .disabled(title.trimmingCharacters(in: .whitespaces).isEmpty || isSaving)
                }
            }
            .onAppear(perform: fill)
            .onChange(of: workspaceId) { _, _ in
                // Only members of the workspace may be responsible (422 otherwise).
                if let assignedTo, !assigneeOptions.contains(where: { $0.friendlyId == assignedTo }) {
                    let me = model.user?.friendlyId
                    self.assignedTo = assigneeOptions.contains { $0.friendlyId == me } ? me : nil
                }
            }
            .onChange(of: kind) { _, newKind in
                // An event needs a date.
                if newKind == .event, schedule?.kind == ScheduleDraft.Kind.none {
                    schedule?.kind = .once
                }
            }
        }
    }

    private var navigationTitleKey: String {
        switch (reminder == nil, kind) {
        case (true, .reminder): "New reminder"
        case (true, .event): "New event"
        case (false, .reminder): "Edit reminder"
        case (false, .event): "Edit event"
        }
    }

    /// The offered durations, plus the saved one when it is not among them.
    private var durationOptions: [Int] {
        Self.durations.contains(durationMinutes) ? Self.durations : (Self.durations + [durationMinutes]).sorted()
    }

    /// "30 min", "1 h 30 min".
    private func durationText(_ minutes: Int) -> String {
        Duration.seconds(minutes * 60).formatted(.units(allowed: [.hours, .minutes], width: .abbreviated).locale(language.locale))
    }

    /// The assignees of the picked workspace (of the default one, from a
    /// server without them by workspace), plus the one responsible now when
    /// they no longer are one and the reminder stays where it is (the server
    /// keeps them through an edit).
    private var assigneeOptions: [UserSummary] {
        let options = workspaceId.flatMap { assigneesByWorkspace[$0] } ?? assignees

        guard let current = reminder?.assignedTo, !options.isEmpty, !options.contains(current), workspaceId == reminder?.workspace?.id else {
            return options
        }

        return options + [current]
    }

    /// The workspaces offered: none (no picker) with reminders in one only.
    private var workspaceOptions: [UserWorkspace] {
        model.user?.workspacePickerOptions(forModule: ModuleSlug.reminders) ?? []
    }

    private func fill() {
        guard schedule == nil else {
            return
        }

        workspaceId = reminder?.workspace?.id ?? model.user?.defaultWorkspace(forModule: ModuleSlug.reminders)?.id

        if let reminder {
            title = reminder.title
            note = reminder.note ?? ""
            assignedTo = reminder.assignedTo?.friendlyId
            kind = reminder.kind ?? .reminder
            isAllDay = reminder.isAllDay ?? false
            durationMinutes = reminder.durationMinutes ?? 60
            schedule = ScheduleDraft(recurrence: reminder.recurrence, timeZone: timeZone)
        } else {
            assignedTo = model.user?.friendlyId
            schedule = ScheduleDraft(kind: .none, timeZone: timeZone)

            if let startsAt {
                schedule = draft(startingAt: startsAt)
            }
        }
    }

    /// A new schedule once from the calendar: at the hour tapped; on a day
    /// alone, the next round hour today and 09:00 on another day.
    private func draft(startingAt startsAt: Date) -> ScheduleDraft {
        var calendar = Calendar(identifier: .gregorian)
        calendar.timeZone = timeZone

        var draft = ScheduleDraft(kind: .once, timeZone: timeZone)
        let isDayAlone = calendar.startOfDay(for: startsAt) == startsAt

        if !isDayAlone {
            draft.date = startsAt
            draft.time = startsAt
        } else if !calendar.isDateInToday(startsAt), let nine = calendar.date(bySettingHour: 9, minute: 0, second: 0, of: startsAt) {
            draft.date = nine
            draft.time = nine
        }

        return draft
    }

    private func save() async {
        guard let schedule else {
            return
        }

        isSaving = true
        defer { isSaving = false }

        let trimmedNote = note.trimmingCharacters(in: .whitespacesAndNewlines)
        let recurrence = schedule.recurrenceInput(in: timeZone)
        // A single date goes as `due_at`, as the web form sends it (its errors under `due_at`).
        let input = ReminderInput(
            title: title.trimmingCharacters(in: .whitespacesAndNewlines),
            note: trimmedNote.isEmpty ? nil : trimmedNote,
            dueAt: recurrence?.frequency == .once ? recurrence?.startsAt : nil,
            recurrence: recurrence?.frequency == .once ? nil : recurrence,
            assignedTo: assignedTo,
            kind: kind,
            isAllDay: isAllDay && recurrence != nil,
            durationMinutes: kind == .event && !isAllDay ? durationMinutes : nil,
            workspace: workspaceOptions.isEmpty ? nil : workspaceId
        )

        do throws(APIError) {
            if let reminder {
                try await model.client.send(API.updateReminder(id: reminder.friendlyId, input))
            } else {
                try await model.client.send(API.createReminder(input))
            }

            onSave()
            dismiss()
        } catch {
            failure = error
        }
    }
}
