import Localization
import Networking
import SwiftUI

/// The household's agenda as on the web (US-9.1): a day, a week or a month
/// of everything scheduled, by source; the next 30 days of it as a list; or
/// the tasks under the filters pending, overdue and completed, with their
/// counts. "Only mine" narrows them to the reminders
/// and events the user is responsible for. A reminder is completed or
/// reopened from its row, created and edited in a form with its kind, who is
/// responsible, date, time and repetition, and opens from a push
/// (`jarvis://reminders/{id}`).
struct RemindersHomeView: View {
    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language
    @Environment(\.timeZone) private var timeZone

    @State private var store: RemindersStore?

    var body: some View {
        @Bindable var router = model.router

        NavigationStack(path: $router.remindersPath) {
            Group {
                if let store {
                    RemindersList(store: store)
                } else {
                    ProgressView(language.localized("Loading…"))
                }
            }
            .navigationTitle(language.localized("Agenda"))
            .shellToolbar()
            .navigationDestination(for: ReminderDestination.self) { destination in
                switch destination {
                case .occurrence(let id):
                    ReminderDetailView(id: id)
                }
            }
        }
        .task {
            if store == nil {
                let store = RemindersStore(client: model.client, timeZone: timeZone)
                self.store = store
                await store.load()
            }
        }
    }
}

private struct RemindersList: View {
    @Bindable var store: RemindersStore

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    @Environment(\.timeZone) private var timeZone
    @Environment(\.openURL) private var openURL

    @State private var isCreating = false

    /// When the new item starts: an hour tapped on the grid, or the calendar's day.
    @State private var newItemStartsAt: Date?
    @State private var reminderToEdit: Reminder?
    @State private var reminderToDelete: Reminder?
    @State private var entryToDelete: CalendarEntry?

    var body: some View {
        content
            .pinnedHeader {
                VStack(spacing: 8) {
                    Picker(selection: Binding { store.mode } set: { mode in Task { await store.show(mode) } }) {
                        language.text("Day").tag(RemindersStore.Mode.calendar(.day))
                        language.text("Week").tag(RemindersStore.Mode.calendar(.week))
                        language.text("Month").tag(RemindersStore.Mode.calendar(.month))
                        language.text("List").tag(RemindersStore.Mode.calendar(.schedule))
                        language.text("Tasks").tag(RemindersStore.Mode.list)
                    } label: {
                        EmptyView()
                    }
                    .pickerStyle(.segmented)
                    .accessibilityIdentifier("reminders.mode")

                    if store.mode == .list {
                        Picker(selection: Binding { store.filter } set: { filter in Task { await store.show(filter) } }) {
                            ForEach(ReminderFilter.allCases, id: \.self) { filter in
                                Text(verbatim: title(of: filter)).tag(filter)
                            }
                        } label: {
                            EmptyView()
                        }
                        .pickerStyle(.segmented)
                    }
                }
                .padding(.horizontal)
                .padding(.bottom, 8)
            }
            .toolbar {
                ToolbarItem(placement: .topBarLeading) {
                    Toggle(isOn: Binding { store.isMine } set: { isOn in Task { await store.showOnlyMine(isOn) } }) {
                        Label(language.localized("Only mine"), systemImage: store.isMine ? "person.fill" : "person")
                    }
                    .toggleStyle(.button)
                    .accessibilityIdentifier("reminders.mine")
                }
                if store.view != nil, !store.sources.isEmpty {
                    ToolbarItem(placement: .topBarTrailing) {
                        Menu {
                            ForEach(store.sources, id: \.self) { source in
                                Toggle(isOn: Binding { !store.hiddenSources.contains(source) } set: { _ in Task { await store.toggle(source) } }) {
                                    Text(verbatim: language.localized(source.titleKey))
                                }
                            }
                        } label: {
                            Label(
                                language.localized("Sources"),
                                systemImage: store.hiddenSources.isEmpty
                                    ? "line.3.horizontal.decrease.circle"
                                    : "line.3.horizontal.decrease.circle.fill"
                            )
                        }
                        .accessibilityIdentifier("reminders.sources")
                    }
                }
                ToolbarItem(placement: .primaryAction) {
                    Button(language.localized("New reminder"), systemImage: "plus") {
                        newItemStartsAt = store.view == nil ? nil : store.selectedDay
                        isCreating = true
                    }
                    .accessibilityIdentifier("reminders.new")
                }
            }
            .refreshable {
                await store.load()
            }
            .onChange(of: model.router.remindersPath) { _, path in
                // Back from a reminder: it may have been completed or edited.
                if path.isEmpty {
                    Task { await store.load() }
                }
            }
            .sheet(isPresented: $isCreating) {
                ReminderForm(reminder: nil, assignees: store.assignees, assigneesByWorkspace: store.assigneesByWorkspace, startsAt: newItemStartsAt) {
                    Task { await store.load() }
                }
            }
            .sheet(item: $reminderToEdit) { reminder in
                ReminderForm(reminder: reminder, assignees: store.assignees, assigneesByWorkspace: store.assigneesByWorkspace) {
                    Task { await store.load() }
                }
            }
            .confirmationDialog(
                language.localized("Delete this reminder?"),
                isPresented: Binding { reminderToDelete != nil } set: { if !$0 { reminderToDelete = nil } },
                titleVisibility: .visible,
                presenting: reminderToDelete
            ) { reminder in
                Button(language.localized("Delete"), role: .destructive) {
                    Task { await store.delete(reminder) }
                }
                Button(language.localized("Cancel"), role: .cancel) {}
            }
            .confirmationDialog(
                language.localized("Delete this reminder?"),
                isPresented: Binding { entryToDelete != nil } set: { if !$0 { entryToDelete = nil } },
                titleVisibility: .visible,
                presenting: entryToDelete
            ) { entry in
                Button(language.localized("Delete"), role: .destructive) {
                    Task { await store.deleteReminder(of: entry) }
                }
                Button(language.localized("Cancel"), role: .cancel) {}
            }
            .actionFailureAlert($store.actionFailure)
    }

    @ViewBuilder
    private var content: some View {
        if let view = store.view, store.entries != nil || store.loadFailure == nil {
            AgendaCalendarView(store: store, view: view, onOpen: open) { entry in
                entryToDelete = entry
            } onCreate: { startsAt in
                newItemStartsAt = startsAt
                isCreating = true
            }
        } else if store.mode == .list, let occurrences = store.occurrences {
            if occurrences.isEmpty {
                ContentUnavailableView(language.localized(emptyTitleKey), systemImage: "checkmark.circle")
            } else {
                List(occurrences) { occurrence in
                    occurrenceRow(occurrence, showsTimeOnly: false)
                }
            }
        } else if let failure = store.loadFailure {
            if case .forbidden = failure {
                NoAccessView()
            } else {
                ContentUnavailableView {
                    Label(failure.userMessage(in: language), systemImage: "wifi.exclamationmark")
                } actions: {
                    Button(language.localized("Try again")) {
                        Task { await store.load() }
                    }
                    .buttonStyle(.glass)
                }
            }
        } else {
            ProgressView(language.localized("Loading…"))
                .frame(maxWidth: .infinity, maxHeight: .infinity)
        }
    }

    /// A reminder or event opens in its form; the other items on the web.
    private func open(_ entry: CalendarEntry) {
        if entry.reminderId != nil {
            Task {
                if let reminder = await store.reminder(of: entry) {
                    reminderToEdit = reminder
                }
            }
        } else if let url = entry.url.flatMap(URL.init(string:)) {
            openURL(url)
        }
    }

    private func occurrenceRow(_ occurrence: ReminderOccurrence, showsTimeOnly: Bool) -> some View {
        HStack(spacing: 12) {
            CompletionButton(occurrence: occurrence) {
                await store.toggleCompletion(of: occurrence)
            }

            NavigationLink(value: ReminderDestination.occurrence(id: occurrence.friendlyId)) {
                ReminderRow(occurrence: occurrence, showsTimeOnly: showsTimeOnly)
            }
        }
        .swipeActions(edge: .leading) {
            Button(
                language.localized(occurrence.isCompleted ? "Reopen" : "Complete"),
                systemImage: occurrence.isCompleted ? "arrow.uturn.backward" : "checkmark"
            ) {
                Task { await store.toggleCompletion(of: occurrence) }
            }
            .tint(occurrence.isCompleted ? .orange : .green)
        }
        .swipeActions(edge: .trailing) {
            editAndDeleteActions(of: occurrence.reminder)
        }
    }

    @ViewBuilder
    private func editAndDeleteActions(of reminder: Reminder) -> some View {
        Button(language.localized("Delete"), systemImage: "trash", role: .destructive) {
            reminderToDelete = reminder
        }

        Button(language.localized("Edit"), systemImage: "pencil") {
            reminderToEdit = reminder
        }
        .tint(.blue)
    }

    /// "Pending (3)", as the web's tabs.
    private func title(of filter: ReminderFilter) -> String {
        guard let count = store.counts?.count(of: filter) else {
            return language.localized(filter.titleKey)
        }

        return "\(language.localized(filter.titleKey)) (\(count))"
    }

    private var emptyTitleKey: String {
        switch store.filter {
        case .pending: "Nothing pending"
        case .overdue: "Nothing overdue"
        case .completed: "Nothing completed yet"
        }
    }
}

/// The round check of a row: completes or reopens the occurrence.
struct CompletionButton: View {
    let occurrence: ReminderOccurrence
    let action: () async -> Void

    @Environment(\.appLanguage) private var language
    @State private var isWorking = false

    var body: some View {
        Button {
            isWorking = true
            Task {
                await action()
                isWorking = false
            }
        } label: {
            Image(systemName: occurrence.isCompleted ? "checkmark.circle.fill" : "circle")
                .font(.title2)
                .foregroundStyle(occurrence.isCompleted ? Color.green : Color.secondary)
                .frame(minWidth: 44, minHeight: 44)
                .contentShape(.rect)
        }
        .buttonStyle(.plain)
        .disabled(isWorking)
        .sensoryFeedback(.success, trigger: occurrence.isCompleted)
        .accessibilityLabel(Text(verbatim: language.localized(occurrence.isCompleted ? "Reopen" : "Complete")))
    }
}

struct ReminderRow: View {
    let reminder: Reminder
    let dueAt: Date?
    var isCompleted = false
    var isOverdue = false
    var completedAt: Date?

    /// Under an agenda day: the time alone.
    var showsTimeOnly = false

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    init(occurrence: ReminderOccurrence, showsTimeOnly: Bool = false) {
        reminder = occurrence.reminder
        dueAt = occurrence.dueAt
        isCompleted = occurrence.isCompleted
        isOverdue = occurrence.isOverdue
        completedAt = occurrence.completedAt
        self.showsTimeOnly = showsTimeOnly
    }

    init(reminder: Reminder, dueAt: Date?, showsTimeOnly: Bool = false) {
        self.reminder = reminder
        self.dueAt = dueAt
        self.showsTimeOnly = showsTimeOnly
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            HStack(spacing: 6) {
                if let workspace = reminder.workspace, model.user?.showsWorkspaces(forModule: ModuleSlug.reminders) == true {
                    WorkspaceDot(hex: workspace.colorHex, accessibilityName: language.localized("Workspace: %@", workspace.name))
                }

                Text(verbatim: reminder.title)
                    .strikethrough(isCompleted)
            }

            HStack(spacing: 6) {
                Group {
                    if let completedAt, isCompleted {
                        Text(verbatim: language.localized("Completed %@", completedAt.formatted(date: .abbreviated, time: .shortened)))
                    } else if let dueAt, showsTimeOnly {
                        Text(verbatim: dueAt.formatted(date: .omitted, time: .shortened))
                    } else if let dueAt {
                        Text(verbatim: language.localized("Due %@", dueAt.formatted(date: .abbreviated, time: .shortened)))
                    } else {
                        language.text("No date")
                    }
                }
                .foregroundStyle(isOverdue ? Color.red : Color.secondary)

                if reminder.isRecurring {
                    Image(systemName: "repeat")
                        .foregroundStyle(.secondary)
                        .accessibilityLabel(Text(verbatim: language.localized("Repeats")))
                }

                if let assignedTo = reminder.assignedTo {
                    Label {
                        Text(verbatim: assignedTo.name)
                    } icon: {
                        Image(systemName: "person")
                    }
                    .labelStyle(.titleAndIcon)
                    .foregroundStyle(.secondary)
                    .lineLimit(1)
                    .accessibilityLabel(Text(verbatim: language.localized("Responsible: %@", assignedTo.name)))
                }
            }
            .font(.subheadline)
        }
    }
}

extension ReminderFilter {
    var titleKey: String {
        switch self {
        case .pending: "Pending"
        case .overdue: "Overdue"
        case .completed: "Completed"
        }
    }
}
