import Localization
import Networking
import SwiftUI

/// The household's shopping lists (US-8.1): the active ones by name, the
/// recurring ones (made again on a schedule) or the archived ones; a new
/// list, archive and unarchive — complete, for a list with a deadline — and
/// the default list (swipe right). A list opens from a push too
/// (`jarvis://shopping/lists/{id}`).
struct ShoppingHomeView: View {
    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    @State private var section = ShoppingSection.active
    @State private var lists: [ShoppingList]?
    @State private var schedules: [ShoppingListSchedule]?
    @State private var loadFailure: APIError?
    @State private var actionFailure: APIError?
    @State private var isCreatingList = false
    @State private var isCreatingListInWorkspace = false
    @State private var newListName = ""
    @State private var listToDelete: ShoppingList?
    @State private var scheduleForm: ScheduleFormTarget?
    @State private var scheduleToStop: ShoppingListSchedule?

    var body: some View {
        @Bindable var router = model.router

        NavigationStack(path: $router.shoppingPath) {
            content
                .pinnedHeader {
                    Picker(selection: $section) {
                        ForEach(ShoppingSection.allCases, id: \.self) { section in
                            Text(verbatim: language.localized(section.titleKey)).tag(section)
                        }
                    } label: {
                        EmptyView()
                    }
                    .pickerStyle(.segmented)
                    .padding(.horizontal)
                    .padding(.bottom, 8)
                }
                .navigationTitle(language.localized("Shopping"))
                .shellToolbar()
                .toolbar {
                    ToolbarItem(placement: .primaryAction) {
                        Button(language.localized(section == .recurring ? "New recurring list" : "New list"), systemImage: "plus") {
                            if section == .recurring {
                                scheduleForm = .new
                            } else if workspaceOptions.isEmpty {
                                newListName = ""
                                isCreatingList = true
                            } else {
                                isCreatingListInWorkspace = true
                            }
                        }
                        .accessibilityIdentifier("shopping.new-list")
                    }
                }
                .navigationDestination(for: ShoppingDestination.self) { destination in
                    switch destination {
                    case .list(let id):
                        ShoppingListView(listId: id)
                    }
                }
                .task(id: section) {
                    lists = nil
                    schedules = nil
                    loadFailure = nil
                    await load()
                }
                .refreshable {
                    await load()
                }
                .onChange(of: router.shoppingPath) { _, path in
                    // Back from a list: its counts may have changed.
                    if path.isEmpty {
                        Task { await load() }
                    }
                }
                .alert(language.localized("New list"), isPresented: $isCreatingList) {
                    TextField(language.localized("Name"), text: $newListName)
                    Button(language.localized("Create")) {
                        Task { await createList() }
                    }
                    Button(language.localized("Cancel"), role: .cancel) {}
                }
                .confirmationDialog(
                    language.localized("Delete this list?"),
                    isPresented: Binding { listToDelete != nil } set: { if !$0 { listToDelete = nil } },
                    titleVisibility: .visible,
                    presenting: listToDelete
                ) { list in
                    Button(language.localized("Delete"), role: .destructive) {
                        Task { await delete(list) }
                    }
                    Button(language.localized("Cancel"), role: .cancel) {}
                }
                .confirmationDialog(
                    language.localized("Stop this recurring list?"),
                    isPresented: Binding { scheduleToStop != nil } set: { if !$0 { scheduleToStop = nil } },
                    titleVisibility: .visible,
                    presenting: scheduleToStop
                ) { schedule in
                    Button(language.localized("Stop"), role: .destructive) {
                        Task { await stop(schedule) }
                    }
                    Button(language.localized("Cancel"), role: .cancel) {}
                } message: { _ in
                    language.text("No new list is made. The lists already made stay.")
                }
                .sheet(isPresented: $isCreatingListInWorkspace) {
                    NewShoppingListForm(
                        workspaces: workspaceOptions,
                        defaultWorkspaceId: model.user?.defaultWorkspace(forModule: ModuleSlug.shopping)?.id
                    ) { name, workspaceId in
                        await createList(named: name, inWorkspace: workspaceId)
                    }
                }
                .sheet(item: $scheduleForm) { target in
                    ShoppingScheduleForm(schedule: target.schedule) {
                        Task { await load() }
                    }
                }
                .actionFailureAlert($actionFailure)
        }
    }

    @ViewBuilder
    private var content: some View {
        if section == .recurring, let schedules {
            if schedules.isEmpty {
                ContentUnavailableView(
                    language.localized("No recurring lists"),
                    systemImage: "arrow.clockwise",
                    description: language.text("Make a list again on a schedule — the market every Friday — closed when you are done or at its deadline.")
                )
            } else {
                List(schedules) { schedule in
                    Button {
                        scheduleForm = .edit(schedule)
                    } label: {
                        ShoppingScheduleRow(schedule: schedule, showsWorkspace: !workspaceOptions.isEmpty)
                    }
                    .foregroundStyle(.primary)
                    .swipeActions(edge: .trailing) {
                        Button(language.localized("Stop"), systemImage: "stop.circle", role: .destructive) {
                            scheduleToStop = schedule
                        }
                    }
                    .accessibilityIdentifier("shopping.schedule.\(schedule.friendlyId)")
                }
            }
        } else if section != .recurring, let lists {
            if lists.isEmpty {
                ContentUnavailableView(
                    language.localized(section == .active ? "No shopping lists" : "No archived lists"),
                    systemImage: section == .active ? "cart" : "archivebox"
                )
            } else {
                List(lists) { list in
                    NavigationLink(value: ShoppingDestination.list(id: list.friendlyId)) {
                        ShoppingListRow(list: list, showsWorkspace: !workspaceOptions.isEmpty)
                    }
                    .swipeActions(edge: .leading) {
                        if !list.isArchived {
                            Button(
                                language.localized(list.isDefault ? "Unset default" : "Make default"),
                                systemImage: list.isDefault ? "star.slash" : "star"
                            ) {
                                Task { await setDefault(list, !list.isDefault) }
                            }
                            .tint(.yellow)
                        }
                    }
                    .swipeActions(edge: .trailing) {
                        Button(language.localized("Delete"), systemImage: "trash", role: .destructive) {
                            listToDelete = list
                        }

                        if list.dueAt != nil, !list.isArchived {
                            // A list of a recurring one is completed: the next one is made.
                            Button(language.localized("Complete"), systemImage: "checkmark.circle") {
                                Task { await complete(list) }
                            }
                            .tint(.green)
                        } else if list.completedAt == nil {
                            Button(
                                language.localized(list.isArchived ? "Unarchive" : "Archive"),
                                systemImage: list.isArchived ? "tray.and.arrow.up" : "archivebox"
                            ) {
                                Task { await setArchived(list, !list.isArchived) }
                            }
                            .tint(.indigo)
                        }
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
        } else {
            ProgressView(language.localized("Loading…"))
                .frame(maxWidth: .infinity, maxHeight: .infinity)
        }
    }

    private func load() async {
        do throws(APIError) {
            if let filter = section.filter {
                lists = try await model.client.send(API.shoppingLists(filter: filter)).data
            } else {
                schedules = try await model.client.send(API.shoppingSchedules()).data
            }

            loadFailure = nil
        } catch .unauthenticated {
            // The shell already went back to the login.
        } catch {
            if lists == nil, schedules == nil {
                loadFailure = error
            }
        }
    }

    /// The user's workspaces with the shopping module, when there are two or
    /// more: the lists show their workspace's color and a new one asks where.
    private var workspaceOptions: [UserWorkspace] {
        model.user?.workspacePickerOptions(forModule: ModuleSlug.shopping) ?? []
    }

    private func createList() async {
        let name = newListName.trimmingCharacters(in: .whitespacesAndNewlines)

        guard !name.isEmpty else {
            return
        }

        if let failure = await createList(named: name, inWorkspace: nil) {
            actionFailure = failure
        }
    }

    /// Makes the list (in the user's default workspace without one) and opens
    /// it; answers the failure, if any, for the form to show.
    private func createList(named name: String, inWorkspace workspaceId: String?) async -> APIError? {
        do throws(APIError) {
            let list = try await model.client.send(API.createShoppingList(name: name, workspace: workspaceId)).data
            section = .active
            model.router.shoppingPath = [.list(id: list.friendlyId)]

            return nil
        } catch {
            return error
        }
    }

    private func setArchived(_ list: ShoppingList, _ archived: Bool) async {
        do throws(APIError) {
            try await model.client.send(archived ? API.archiveShoppingList(id: list.friendlyId) : API.unarchiveShoppingList(id: list.friendlyId))
            lists?.removeAll { $0.id == list.id }
        } catch {
            actionFailure = error
        }
    }

    /// The previous default stops being it, so the lists are read again.
    private func setDefault(_ list: ShoppingList, _ isDefault: Bool) async {
        do throws(APIError) {
            try await model.client.send(API.setDefaultShoppingList(id: list.friendlyId, isDefault: isDefault))
            await load()
        } catch {
            actionFailure = error
        }
    }

    /// The next list comes in its place, so the lists are read again.
    private func complete(_ list: ShoppingList) async {
        do throws(APIError) {
            try await model.client.send(API.completeShoppingList(id: list.friendlyId))
            await load()
        } catch {
            actionFailure = error
        }
    }

    private func stop(_ schedule: ShoppingListSchedule) async {
        do throws(APIError) {
            try await model.client.send(API.deleteShoppingSchedule(id: schedule.friendlyId))
            schedules?.removeAll { $0.id == schedule.id }
        } catch {
            actionFailure = error
        }
    }

    private func delete(_ list: ShoppingList) async {
        do throws(APIError) {
            try await model.client.send(API.deleteShoppingList(id: list.friendlyId))
            lists?.removeAll { $0.id == list.id }
        } catch {
            actionFailure = error
        }
    }
}

private struct ShoppingListRow: View {
    let list: ShoppingList

    /// The dot of the list's workspace, for a user in two or more with shopping.
    var showsWorkspace = false

    @Environment(\.appLanguage) private var language

    var body: some View {
        LabeledContent {
            HStack(spacing: 8) {
                if let unchecked = list.uncheckedItemsCount {
                    Text(verbatim: language.localized("%lld left", unchecked))
                        .monospacedDigit()
                }

                if showsWorkspace, let workspace = list.workspace {
                    WorkspaceDot(hex: workspace.colorHex, accessibilityName: language.localized("Workspace: %@", workspace.name))
                }
            }
        } label: {
            if list.isDefault {
                Label {
                    Text(verbatim: list.name)
                } icon: {
                    Image(systemName: "star.fill")
                        .foregroundStyle(.yellow)
                        .accessibilityLabel(language.localized("Default list"))
                }
            } else {
                Text(verbatim: list.name)
            }

            if let dueAt = list.dueAt, !list.isArchived {
                ShoppingDeadline(dueAt: dueAt, isOverdue: list.isOverdue)
                    .font(.caption)
            }

            if let archivedAt = list.archivedAt, list.isArchived {
                Text(verbatim: language.localized("Archived %@", archivedAt.formatted(date: .abbreviated, time: .omitted)))
            }
        }
        .padding(.vertical, 4)
    }
}

/// A recurring list: its name, its schedule and its open list's deadline.
private struct ShoppingScheduleRow: View {
    let schedule: ShoppingListSchedule

    /// The workspace's dot and name, for a user in two or more with shopping.
    var showsWorkspace = false

    @Environment(\.appLanguage) private var language

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(verbatim: schedule.name)

            if showsWorkspace, let workspace = schedule.workspace {
                WorkspaceBadge(workspace: workspace)
            }

            if let recurrence = schedule.recurrence {
                Text(verbatim: recurrence.summary(in: language))
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }

            if let openList = schedule.openList, let dueAt = openList.dueAt {
                ShoppingDeadline(dueAt: dueAt, isOverdue: openList.isOverdue)
                    .font(.caption)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .contentShape(.rect)
        .padding(.vertical, 4)
    }
}

/// A new list for a user in two or more workspaces with shopping: its name
/// and where it goes, the user's default workspace picked at first. A
/// refusal shows under the form.
private struct NewShoppingListForm: View {
    let workspaces: [UserWorkspace]
    let defaultWorkspaceId: String?
    let create: (String, String?) async -> APIError?

    @Environment(\.appLanguage) private var language
    @Environment(\.dismiss) private var dismiss

    @State private var name = ""
    @State private var workspaceId: String?
    @State private var isSaving = false
    @State private var failure: APIError?

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    TextField(language.localized("Name"), text: $name)
                        .accessibilityIdentifier("shopping.new-list.name")
                }

                WorkspacePickerSection(workspaces: workspaces, selection: $workspaceId)

                if let failure {
                    Section {
                        Label(failure.userMessage(in: language), systemImage: "exclamationmark.triangle.fill")
                            .foregroundStyle(.red)
                    }
                }
            }
            .navigationTitle(language.localized("New list"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(language.localized("Cancel"), role: .cancel) {
                        dismiss()
                    }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button(language.localized("Create"), role: .confirm) {
                        Task { await save() }
                    }
                    .disabled(trimmedName.isEmpty || isSaving)
                }
            }
            .onAppear {
                if workspaceId == nil {
                    workspaceId = defaultWorkspaceId ?? workspaces.first?.id
                }
            }
        }
        .presentationDetents([.medium, .large])
    }

    private var trimmedName: String {
        name.trimmingCharacters(in: .whitespacesAndNewlines)
    }

    private func save() async {
        isSaving = true
        defer { isSaving = false }

        failure = await create(trimmedName, workspaceId)

        if failure == nil {
            dismiss()
        }
    }
}

/// The segments of the shopping tab, as the web's tabs.
enum ShoppingSection: Hashable, CaseIterable {
    case active
    case recurring
    case archived

    var titleKey: String {
        switch self {
        case .active: "Lists"
        case .recurring: "Recurring"
        case .archived: "Archived lists"
        }
    }

    /// The lists it shows; `nil` for the recurring ones.
    var filter: ShoppingListFilter? {
        switch self {
        case .active: .active
        case .recurring: nil
        case .archived: .archived
        }
    }
}

/// The recurring list the form opens on: a new one, or one to edit.
private enum ScheduleFormTarget: Identifiable {
    case new
    case edit(ShoppingListSchedule)

    var id: String {
        switch self {
        case .new: "new"
        case .edit(let schedule): schedule.friendlyId
        }
    }

    var schedule: ShoppingListSchedule? {
        switch self {
        case .new: nil
        case .edit(let schedule): schedule
        }
    }
}
