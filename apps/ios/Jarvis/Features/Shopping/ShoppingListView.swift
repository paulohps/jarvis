import Localization
import Networking
import SwiftUI

/// A shopping list in the store (US-8.1, US-8.2), made for one hand: the
/// whole row checks an item (with a haptic), a long press drags an item to
/// pick into a new place, the checked ones sink to their own group at the
/// end, and the quick add sits at the bottom, under the thumb. What anyone
/// else checks shows up through the list's Reverb channel.
struct ShoppingListView: View {
    let listId: String

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    @State private var store: ShoppingListStore?

    var body: some View {
        Group {
            if let store {
                ShoppingListContent(store: store)
            } else {
                ProgressView(language.localized("Loading…"))
            }
        }
        .navigationBarTitleDisplayMode(.inline)
        .task(id: listId) {
            let store = ShoppingListStore(listId: listId, client: model.client, sync: model.shoppingSync)
            self.store = store
            await store.reload()
        }
    }
}

private struct ShoppingListContent: View {
    @Bindable var store: ShoppingListStore

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    @State private var newItemName = ""
    @State private var isAdding = false
    @State private var toggleFeedback = 0
    @State private var itemToEdit: ShoppingListItem?
    @State private var isRenaming = false
    @State private var newName = ""
    @State private var isConfirmingClear = false
    @FocusState private var isAddFieldFocused: Bool

    var body: some View {
        content
            .navigationTitle(store.list?.name ?? language.localized("Shopping list"))
            .toolbar {
                if store.loadState == .loaded {
                    ToolbarItem(placement: .primaryAction) {
                        menu
                    }
                }
            }
            .safeAreaInset(edge: .bottom) {
                if store.loadState == .loaded, !store.isArchived {
                    quickAdd
                }
            }
            .sensoryFeedback(.selection, trigger: toggleFeedback)
            .refreshable {
                await store.reload()
            }
            .task(id: store.listId) {
                await store.listen(to: model.realtime.events(on: ShoppingList.broadcastChannel(listId: store.listId)))
            }
            .onChange(of: model.realtime.connectionState.isConnected) { _, isConnected in
                // Back online: what changed while the socket was down is read now.
                if isConnected {
                    Task { await store.reload() }
                }
            }
            .onChange(of: model.shoppingSync.revision) {
                // The changes made offline were sent.
                Task { await store.reload() }
            }
            .sheet(item: $itemToEdit) { item in
                ShoppingItemForm(item: item) { name, note in
                    Task { await store.update(item, name: name, quantityNote: note) }
                }
            }
            .alert(language.localized("Rename list"), isPresented: $isRenaming) {
                TextField(language.localized("Name"), text: $newName)
                Button(language.localized("Save")) {
                    Task { _ = await store.rename(to: newName.trimmingCharacters(in: .whitespacesAndNewlines)) }
                }
                Button(language.localized("Cancel"), role: .cancel) {}
            }
            .confirmationDialog(
                language.localized("Remove every checked item?"),
                isPresented: $isConfirmingClear,
                titleVisibility: .visible
            ) {
                Button(language.localized("Clear checked"), role: .destructive) {
                    Task { await store.clearChecked() }
                }
                Button(language.localized("Cancel"), role: .cancel) {}
            }
            .actionFailureAlert($store.actionFailure)
            .background {
                // A change refused once the network was back (the item was removed, the list archived).
                Color.clear.actionFailureAlert(Bindable(model.shoppingSync).rejection)
            }
    }

    @ViewBuilder
    private var content: some View {
        switch store.loadState {
        case .loading:
            ProgressView(language.localized("Loading…"))
                .frame(maxWidth: .infinity, maxHeight: .infinity)
        case .forbidden:
            NoAccessView()
        case .notFound:
            ContentUnavailableView(
                language.localized("List not found"),
                systemImage: "questionmark.circle",
                description: language.text("It may have been deleted.")
            )
        case .failed(let failure):
            ContentUnavailableView {
                Label(failure.userMessage(in: language), systemImage: "wifi.exclamationmark")
            } actions: {
                Button(language.localized("Try again")) {
                    Task { await store.reload() }
                }
                .buttonStyle(.glass)
            }
        case .loaded:
            items
        }
    }

    private var items: some View {
        List {
            if let completedAt = store.list?.completedAt {
                Section {
                    Label(language.localized("Completed %@", completedAt.formatted(date: .abbreviated, time: .shortened)), systemImage: "checkmark.circle")
                    .foregroundStyle(.green)
                }
            } else if store.isArchived {
                Section {
                    Label(language.localized("This list is archived. Its items are read only."), systemImage: "archivebox")
                        .foregroundStyle(.secondary)
                }
            } else if let list = store.list, let dueAt = list.dueAt {
                Section {
                    ShoppingDeadline(dueAt: dueAt, isOverdue: list.isOverdue)
                        .accessibilityIdentifier("shopping.deadline")
                }
            }

            if showsWorkspace, let workspace = store.list?.workspace {
                Section {
                    WorkspaceBadge(workspace: workspace)
                        .accessibilityIdentifier("shopping.list.workspace")
                }
            }

            if pendingCount > 0 {
                Section {
                    Label(language.localized("Changes waiting for the network: %lld", pendingCount), systemImage: "icloud.slash")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                }
            }

            if store.items.isEmpty {
                ContentUnavailableView(language.localized("The list is empty"), systemImage: "cart")
                    .listRowBackground(Color.clear)
            }

            Section {
                ForEach(store.uncheckedItems) { item in
                    row(for: item)
                        .moveDisabled(store.isArchived)
                }
                .onMove(perform: moveUnchecked)
            }

            if !store.checkedItems.isEmpty {
                Section {
                    ForEach(store.checkedItems) { item in
                        row(for: item)
                    }
                } header: {
                    Text(verbatim: language.localized("Checked (%lld)", store.checkedItems.count))
                } footer: {
                    if !store.isArchived {
                        Button(language.localized("Clear checked"), systemImage: "trash", role: .destructive) {
                            isConfirmingClear = true
                        }
                        .buttonStyle(.glass)
                        .frame(maxWidth: .infinity)
                        .padding(.top, 8)
                        .accessibilityIdentifier("shopping.clear-checked")
                    }
                }
            }
        }
        .animation(.snappy, value: store.items.map(\.id))
        .scrollDismissesKeyboard(.interactively)
    }

    /// A long press drags an item to pick into a new place.
    private func moveUnchecked(from source: IndexSet, to destination: Int) {
        var order = store.uncheckedItems.map(\.friendlyId)
        order.move(fromOffsets: source, toOffset: destination)
        Task { await store.reorderUnchecked(order) }
    }

    private var pendingCount: Int {
        model.shoppingSync.pendingByList[store.listId] ?? 0
    }

    private func row(for item: ShoppingListItem) -> some View {
        Button {
            toggleFeedback += 1
            Task { await store.toggle(item) }
        } label: {
            ShoppingItemRow(item: item)
        }
        .buttonStyle(.plain)
        .disabled(store.isArchived)
        .swipeActions(edge: .trailing) {
            if !store.isArchived {
                Button(language.localized("Delete"), systemImage: "trash", role: .destructive) {
                    Task { await store.remove(item) }
                }

                Button(language.localized("Edit"), systemImage: "pencil") {
                    itemToEdit = item
                }
                .tint(.blue)
            }
        }
        .accessibilityIdentifier("shopping.item.\(item.friendlyId)")
    }

    private var quickAdd: some View {
        HStack(spacing: 8) {
            TextField(language.localized("Add an item"), text: $newItemName)
                .focused($isAddFieldFocused)
                .submitLabel(.done)
                .onSubmit(add)
                .padding(.horizontal, 16)
                .padding(.vertical, 12)
                .accessibilityIdentifier("shopping.quick-add")

            Button(language.localized("Add"), systemImage: "plus") {
                add()
            }
            .labelStyle(.iconOnly)
            .font(.title3.weight(.semibold))
            .buttonStyle(.glassProminent)
            .buttonBorderShape(.circle)
            .disabled(newItemName.trimmingCharacters(in: .whitespaces).isEmpty || isAdding)
        }
        .padding(4)
        .glassEffect(.regular.interactive(), in: .capsule)
        .padding(.horizontal)
        .padding(.bottom, 8)
    }

    private var menu: some View {
        Menu(language.localized("More"), systemImage: "ellipsis") {
            if !store.isArchived {
                Button(language.localized("Rename"), systemImage: "pencil") {
                    newName = store.list?.name ?? ""
                    isRenaming = true
                }

                Button(
                    language.localized(store.isDefault ? "Unset default" : "Make default"),
                    systemImage: store.isDefault ? "star.slash" : "star"
                ) {
                    Task { await store.setDefault(!store.isDefault) }
                }
                .accessibilityIdentifier("shopping.list.default")

                if showsWorkspace {
                    Menu(language.localized("Move to workspace"), systemImage: "square.stack.3d.up") {
                        ForEach(workspaceOptions) { workspace in
                            Button {
                                Task { await store.move(toWorkspace: workspace.id) }
                            } label: {
                                if workspace.id == store.list?.workspace?.id {
                                    Label(workspace.name, systemImage: "checkmark")
                                } else {
                                    Text(verbatim: workspace.name)
                                }
                            }
                        }
                    }
                    .accessibilityIdentifier("shopping.list.move")
                }

                Button(language.localized("Clear checked"), systemImage: "checkmark.circle.badge.xmark", role: .destructive) {
                    isConfirmingClear = true
                }
                .disabled(store.checkedItems.isEmpty)
            }

            if store.list?.dueAt != nil, !store.isArchived {
                // A list of a recurring one is completed, as on the web: the next one is made.
                Button(language.localized("Complete"), systemImage: "checkmark.circle") {
                    Task { await complete() }
                }
                .accessibilityIdentifier("shopping.complete")
            } else if store.list?.completedAt == nil {
                Button(
                    language.localized(store.isArchived ? "Unarchive" : "Archive"),
                    systemImage: store.isArchived ? "tray.and.arrow.up" : "archivebox"
                ) {
                    Task { await store.setArchived(!store.isArchived) }
                }
            }
        }
    }

    /// The workspaces the list may move to: with two or more with the
    /// shopping module only.
    private var workspaceOptions: [UserWorkspace] {
        model.user?.workspacePickerOptions(forModule: ModuleSlug.shopping) ?? []
    }

    private var showsWorkspace: Bool {
        !workspaceOptions.isEmpty
    }

    /// Opens the next list in its place; back to the lists when there is none yet.
    private func complete() async {
        let nextId = await store.complete()

        guard store.actionFailure == nil else {
            return
        }

        model.router.shoppingPath = nextId.map { [.list(id: $0)] } ?? []
    }

    private func add() {
        let name = newItemName

        guard !name.trimmingCharacters(in: .whitespaces).isEmpty, !isAdding else {
            return
        }

        isAdding = true

        Task {
            if await store.addItem(named: name) {
                newItemName = ""
            }

            isAdding = false
            // The keyboard stays up for the next item.
            isAddFieldFocused = true
        }
    }
}

private struct ShoppingItemRow: View {
    let item: ShoppingListItem

    @Environment(\.appLanguage) private var language

    var body: some View {
        HStack(spacing: 14) {
            Image(systemName: item.isChecked ? "checkmark.circle.fill" : "circle")
                .font(.title2)
                .foregroundStyle(item.isChecked ? Color.green : Color.secondary)
                .contentTransition(.symbolEffect(.replace))

            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: item.name)
                    .strikethrough(item.isChecked)
                    .foregroundStyle(item.isChecked ? .secondary : .primary)

                if let note = item.quantityNote, !note.isEmpty {
                    Text(verbatim: note)
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                }

                if item.isChecked, let checkedBy = item.checkedBy {
                    Text(verbatim: language.localized("Checked by %@", checkedBy.name))
                        .font(.caption)
                        .foregroundStyle(.tertiary)
                }
            }

            Spacer(minLength: 0)
        }
        .frame(minHeight: 44)
        .contentShape(.rect)
        .accessibilityElement(children: .combine)
        .accessibilityAddTraits(item.isChecked ? [.isButton, .isSelected] : .isButton)
    }
}

/// Edits an item's name and quantity or note; saved through the offline queue.
private struct ShoppingItemForm: View {
    let item: ShoppingListItem
    let save: (String, String?) -> Void

    @Environment(\.dismiss) private var dismiss
    @Environment(\.appLanguage) private var language

    @State private var name: String
    @State private var note: String

    init(item: ShoppingListItem, save: @escaping (String, String?) -> Void) {
        self.item = item
        self.save = save
        _name = State(initialValue: item.name)
        _note = State(initialValue: item.quantityNote ?? "")
    }

    var body: some View {
        NavigationStack {
            Form {
                TextField(language.localized("Name"), text: $name)
                TextField(language.localized("Quantity or note"), text: $note)
            }
            .navigationTitle(language.localized("Edit item"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(language.localized("Cancel"), role: .cancel) {
                        dismiss()
                    }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button(language.localized("Save"), role: .confirm) {
                        let trimmedNote = note.trimmingCharacters(in: .whitespaces)
                        save(name.trimmingCharacters(in: .whitespaces), trimmedNote.isEmpty ? nil : trimmedNote)
                        dismiss()
                    }
                    .disabled(name.trimmingCharacters(in: .whitespaces).isEmpty)
                }
            }
        }
        .presentationDetents([.medium])
    }
}

/// The deadline of a list of a recurring one: "Recurring list, until …", or
/// in red once it passed.
struct ShoppingDeadline: View {
    let dueAt: Date
    let isOverdue: Bool

    @Environment(\.appLanguage) private var language

    var body: some View {
        Label {
            Text(verbatim: "\(language.localized(isOverdue ? "Overdue since" : "Recurring list, until")) \(dueAt.formatted(date: .abbreviated, time: .shortened))")
        } icon: {
            Image(systemName: "arrow.clockwise")
        }
        .font(.subheadline)
        .foregroundStyle(isOverdue ? Color.red : Color.secondary)
    }
}
