import Localization
import Networking
import SwiftUI

/// Creates a recurring shopping list, or edits one, as the web's
/// "Recurring" tab: its name, its schedule — each run is a list's
/// deadline — what the next list starts with (the template typed one item
/// per line), and how a list closes: by itself at its deadline, or by hand,
/// optionally holding the next one back until then. For a user in two or
/// more workspaces with shopping, also where its lists are made (the
/// default workspace on a new one); changing it on an edit moves it. The
/// server checks it; a refusal comes translated and shows under the form.
struct ShoppingScheduleForm: View {
    let schedule: ShoppingListSchedule?
    let onSave: () -> Void

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language
    @Environment(\.timeZone) private var timeZone
    @Environment(\.dismiss) private var dismiss

    @State private var name = ""
    @State private var draft: ScheduleDraft?
    @State private var nextListContent = ShoppingListNextContent.pending
    @State private var template = ""
    @State private var closesAutomatically = true
    @State private var waitsForClose = false
    @State private var workspaceId: String?
    @State private var isSaving = false
    @State private var failure: APIError?

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    TextField(language.localized("Name"), text: $name, prompt: Text(verbatim: language.localized("Market, Pharmacy…")))
                        .accessibilityIdentifier("shopping.schedule.name")
                }

                if !workspaceOptions.isEmpty {
                    WorkspacePickerSection(workspaces: workspaceOptions, selection: $workspaceId)
                }

                if let draft {
                    ScheduleEditor(
                        draft: Binding { draft } set: { self.draft = $0 },
                        kinds: ScheduleDraft.Kind.allCases.filter { $0 != .none }
                    )
                }

                Section {
                    Picker(language.localized("The next list starts with"), selection: $nextListContent) {
                        ForEach(ShoppingListNextContent.allCases, id: \.self) { content in
                            Text(verbatim: language.localized(content.titleKey)).tag(content)
                        }
                    }
                    .accessibilityIdentifier("shopping.schedule.next")

                    if nextListContent == .templateAndPending {
                        TextField(language.localized("Template"), text: $template, axis: .vertical)
                            .lineLimit(3...10)
                            .accessibilityIdentifier("shopping.schedule.template")
                    }
                } footer: {
                    if nextListContent == .templateAndPending {
                        language.text("One item per line.")
                    }
                }

                Section {
                    Toggle(language.localized("Close the list by itself at its deadline"), isOn: $closesAutomatically.animation())

                    if !closesAutomatically {
                        Toggle(language.localized("Make the next list only after this one is closed"), isOn: $waitsForClose)
                    }
                } footer: {
                    language.text("The time of each run is the list's deadline.")
                }

                if let failure {
                    Section {
                        Label(failure.userMessage(in: language), systemImage: "exclamationmark.triangle.fill")
                            .foregroundStyle(.red)
                            .accessibilityIdentifier("shopping.schedule.error")
                    }
                }
            }
            .navigationTitle(language.localized(schedule == nil ? "New recurring list" : "Edit recurring list"))
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
                    .disabled(name.trimmingCharacters(in: .whitespaces).isEmpty || isSaving)
                }
            }
            .onAppear(perform: fill)
        }
    }

    /// The workspaces offered: none (no picker) with shopping in one only.
    private var workspaceOptions: [UserWorkspace] {
        model.user?.workspacePickerOptions(forModule: ModuleSlug.shopping) ?? []
    }

    private func fill() {
        guard draft == nil else {
            return
        }

        workspaceId = schedule?.workspace?.id ?? model.user?.defaultWorkspace(forModule: ModuleSlug.shopping)?.id

        guard let schedule else {
            draft = ScheduleDraft(kind: .weekly, timeZone: timeZone)

            return
        }

        name = schedule.name
        draft = ScheduleDraft(recurrence: schedule.recurrence, timeZone: timeZone)
        nextListContent = schedule.nextListContent
        template = schedule.templateItems.joined(separator: "\n")
        closesAutomatically = schedule.closesAutomatically
        waitsForClose = schedule.waitsForClose
    }

    private func save() async {
        guard let recurrence = draft?.recurrenceInput(in: timeZone) else {
            return
        }

        isSaving = true
        defer { isSaving = false }

        let input = ShoppingListScheduleInput(
            name: name.trimmingCharacters(in: .whitespacesAndNewlines),
            recurrence: recurrence,
            nextListContent: nextListContent,
            closesAutomatically: closesAutomatically,
            waitsForClose: !closesAutomatically && waitsForClose,
            templateItems: ShoppingListScheduleInput.templateItems(fromLines: template),
            workspace: workspaceOptions.isEmpty ? nil : workspaceId
        )

        do throws(APIError) {
            if let schedule {
                try await model.client.send(API.updateShoppingSchedule(id: schedule.friendlyId, input))
            } else {
                try await model.client.send(API.createShoppingSchedule(input))
            }

            onSave()
            dismiss()
        } catch {
            failure = error
        }
    }
}

extension ShoppingListNextContent {
    var titleKey: String {
        switch self {
        case .pending: "The items left unchecked"
        case .all: "All the items of the last list"
        case .templateAndPending: "The template, plus the items left unchecked"
        case .empty: "Nothing"
        }
    }
}
