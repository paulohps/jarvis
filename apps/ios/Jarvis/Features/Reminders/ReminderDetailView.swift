import Localization
import Networking
import SwiftUI

/// One reminder, as a push opens it (`jarvis://reminders/{id}`, US-5.2): its
/// date, repetition and note, and completing, reopening, editing and
/// deleting it. The id is the occurrence's (`remo_…`) or the reminder's
/// (`rem_…`); the API has no single-reminder read, so it is looked up in the
/// filters, open ones first.
struct ReminderDetailView: View {
    let id: String

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language
    @Environment(\.dismiss) private var dismiss

    @State private var occurrence: ReminderOccurrence?
    @State private var assignees: [UserSummary] = []
    @State private var assigneesByWorkspace: [String: [UserSummary]] = [:]
    @State private var hasLoaded = false
    @State private var loadFailure: APIError?
    @State private var actionFailure: APIError?
    @State private var isEditing = false
    @State private var isConfirmingDelete = false

    var body: some View {
        content
            .navigationTitle(language.localized("Reminder"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                if let occurrence {
                    ToolbarItem(placement: .primaryAction) {
                        Button(language.localized("Edit")) {
                            isEditing = true
                        }
                    }
                    ToolbarItem(placement: .bottomBar) {
                        Button(
                            language.localized(occurrence.isCompleted ? "Reopen" : "Complete"),
                            systemImage: occurrence.isCompleted ? "arrow.uturn.backward" : "checkmark"
                        ) {
                            Task { await toggleCompletion(of: occurrence) }
                        }
                        .buttonStyle(.glassProminent)
                        .sensoryFeedback(.success, trigger: occurrence.isCompleted)
                    }
                    ToolbarItem(placement: .bottomBar) {
                        Button(language.localized("Delete"), systemImage: "trash", role: .destructive) {
                            isConfirmingDelete = true
                        }
                    }
                }
            }
            .task {
                await load()
            }
            .refreshable {
                await load()
            }
            .sheet(isPresented: $isEditing) {
                ReminderForm(reminder: occurrence?.reminder, assignees: assignees, assigneesByWorkspace: assigneesByWorkspace) {
                    Task { await load() }
                }
            }
            .confirmationDialog(language.localized("Delete this reminder?"), isPresented: $isConfirmingDelete, titleVisibility: .visible) {
                Button(language.localized("Delete"), role: .destructive) {
                    Task { await delete() }
                }
                Button(language.localized("Cancel"), role: .cancel) {}
            }
            .actionFailureAlert($actionFailure)
    }

    @ViewBuilder
    private var content: some View {
        if let occurrence {
            List {
                Section {
                    Text(verbatim: occurrence.reminder.title)
                        .font(.title3.weight(.semibold))
                        .strikethrough(occurrence.isCompleted)

                    LabeledContent(language.localized("Due")) {
                        if let dueAt = occurrence.dueAt {
                            Text(dueAt, format: .dateTime.day().month().year().hour().minute())
                                .foregroundStyle(occurrence.isOverdue ? Color.red : Color.primary)
                        } else {
                            language.text("No date")
                        }
                    }

                    if let recurrence = occurrence.reminder.recurrence, recurrence.frequency != .once {
                        Label(recurrence.summary(in: language), systemImage: "repeat")
                    }

                    LabeledContent(language.localized("Responsible")) {
                        if let assignedTo = occurrence.reminder.assignedTo {
                            Text(verbatim: assignedTo.name)
                        } else {
                            language.text("Nobody")
                        }
                    }

                    if let workspace = occurrence.reminder.workspace, model.user?.showsWorkspaces(forModule: ModuleSlug.reminders) == true {
                        LabeledContent(language.localized("Workspace")) {
                            HStack(spacing: 6) {
                                WorkspaceDot(hex: workspace.colorHex)
                                Text(verbatim: workspace.name)
                            }
                        }
                    }

                    if let completedAt = occurrence.completedAt, occurrence.isCompleted {
                        LabeledContent(language.localized("Completed")) {
                            Text(completedAt, format: .dateTime.day().month().year().hour().minute())
                        }

                        if let completedBy = occurrence.completedBy {
                            LabeledContent(language.localized("By"), value: completedBy.name)
                        }
                    }
                }

                if let note = occurrence.reminder.note, !note.isEmpty {
                    Section(language.localized("Note")) {
                        Text(verbatim: note)
                    }
                }
            }
        } else if let loadFailure {
            if case .forbidden = loadFailure {
                NoAccessView()
            } else {
                ContentUnavailableView {
                    Label(loadFailure.userMessage(in: language), systemImage: "wifi.exclamationmark")
                } actions: {
                    Button(language.localized("Try again")) {
                        Task { await load() }
                    }
                    .buttonStyle(.glass)
                }
            }
        } else if hasLoaded {
            ContentUnavailableView(
                language.localized("Reminder not found"),
                systemImage: "questionmark.circle",
                description: language.text("It may have been completed or deleted.")
            )
        } else {
            ProgressView(language.localized("Loading…"))
        }
    }

    private func load() async {
        do throws(APIError) {
            var found = try await findOccurrence(matching: occurrence?.friendlyId ?? id)

            if found == nil, let reminderId = occurrence?.reminder.friendlyId {
                found = try await findOccurrence(matching: reminderId)
            }

            occurrence = found
            loadFailure = nil
        } catch .unauthenticated {
            // The shell already went back to the login.
        } catch {
            loadFailure = error
        }

        hasLoaded = true
    }

    /// After completing a repeating reminder the next occurrence is the open
    /// one, so the reminder is looked up again by its own id.
    private func findOccurrence(matching id: String) async throws(APIError) -> ReminderOccurrence? {
        for filter in [ReminderFilter.pending, .overdue, .completed] {
            let page = try await model.client.send(API.reminderOccurrences(filter: filter))
            let occurrences = page.data
            assignees = page.meta.assignees ?? []
            assigneesByWorkspace = page.meta.assigneesByWorkspace?.values ?? [:]

            if let match = occurrences.first(where: { $0.friendlyId == id || $0.reminder.friendlyId == id }) {
                return match
            }
        }

        return nil
    }

    private func toggleCompletion(of occurrence: ReminderOccurrence) async {
        do throws(APIError) {
            let saved = occurrence.isCompleted
                ? try await model.client.send(API.reopenOccurrence(id: occurrence.friendlyId)).data
                : try await model.client.send(API.completeOccurrence(id: occurrence.friendlyId)).data
            self.occurrence = saved
        } catch {
            actionFailure = error
        }
    }

    private func delete() async {
        guard let occurrence else {
            return
        }

        do throws(APIError) {
            try await model.client.send(API.deleteReminder(id: occurrence.reminder.friendlyId))
            dismiss()
        } catch {
            actionFailure = error
        }
    }
}
