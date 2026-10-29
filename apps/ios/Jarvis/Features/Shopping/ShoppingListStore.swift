import Foundation
import Networking
import Observation
import Offline
import Realtime

/// One shopping list as a screen shows it (US-8.1, US-8.2): its items with
/// the checked ones at the end, the changes made on this iPhone, and the
/// ones made by anyone else through the list's Reverb channel — each
/// `shopping-list.changed` reads the list again.
///
/// Checking, editing and removing an item go through the offline queue
/// (`ShoppingSync`): the screen shows them at once, with or without network,
/// as the server's state with the waiting changes on top.
@MainActor
@Observable
final class ShoppingListStore {
    enum LoadState: Equatable {
        case loading
        case loaded
        case forbidden
        case notFound
        case failed(APIError)
    }

    let listId: String

    private(set) var list: ShoppingList?
    private(set) var items: [ShoppingListItem] = []
    private(set) var loadState: LoadState = .loading

    /// The failure of the last change, for an alert.
    var actionFailure: APIError?

    var isArchived: Bool {
        list?.isArchived ?? false
    }

    /// The household's default list: a voice command that names no list adds here.
    var isDefault: Bool {
        list?.isDefault ?? false
    }

    var uncheckedItems: [ShoppingListItem] {
        items.filter { !$0.isChecked }
    }

    var checkedItems: [ShoppingListItem] {
        items.filter(\.isChecked)
    }

    @ObservationIgnored private let client: APIClient
    @ObservationIgnored private let sync: ShoppingSync

    /// The items as the server last told them.
    @ObservationIgnored private var serverItems: [ShoppingListItem] = []
    @ObservationIgnored private var isReloading = false
    @ObservationIgnored private var needsReload = false

    init(listId: String, client: APIClient, sync: ShoppingSync) {
        self.listId = listId
        self.client = client
        self.sync = sync
    }

    /// Reads the list; a reload asked meanwhile (a burst of events) runs once after it.
    func reload() async {
        guard !isReloading else {
            needsReload = true

            return
        }

        isReloading = true
        defer { isReloading = false }

        repeat {
            needsReload = false
            await load()
        } while needsReload
    }

    /// Listens to the list's channel until the screen goes away.
    func listen(to events: AsyncStream<RealtimeEvent>) async {
        for await event in events where event.name == ShoppingListChangedEvent.name {
            await reload()
        }
    }

    // MARK: - Items

    /// The quick add: an item at the end of the unchecked ones (its note is
    /// edited afterwards). It needs the network: an add sent twice would add
    /// the item twice, so it is not queued.
    /// - Returns: whether it was added (the field is cleared then).
    func addItem(named rawName: String) async -> Bool {
        let name = rawName.trimmingCharacters(in: .whitespacesAndNewlines)

        guard !name.isEmpty else {
            return false
        }

        do throws(APIError) {
            let item = try await client.send(API.addShoppingItem(listId: listId, name: name, quantityNote: nil)).data
            if !serverItems.contains(where: { $0.id == item.id }) {
                serverItems.append(item)
            }
            await showItems()

            return true
        } catch {
            actionFailure = error

            return false
        }
    }

    /// Checks or unchecks an item: shown right away, sent now or when the network is back.
    func toggle(_ item: ShoppingListItem) async {
        await record(.toggle(checked: !item.isChecked), on: item)
    }

    func update(_ item: ShoppingListItem, name: String, quantityNote: String?) async {
        await record(.update(name: name, quantityNote: quantityNote), on: item)
    }

    func remove(_ item: ShoppingListItem) async {
        await record(.remove, on: item)
    }

    /// The unchecked items dragged into a new order (friendly ids): shown
    /// right away and sent to the server. It needs the network, like the
    /// quick add; a failure shows the list as the server has it.
    func reorderUnchecked(_ order: [String]) async {
        serverItems = serverItems.reordered(order)
        items = items.reordered(order).sortedForDisplay()

        do throws(APIError) {
            await show(try await client.send(API.reorderShoppingItems(listId: listId, itemIds: order)).data)
        } catch {
            actionFailure = error
            await reload()
        }
    }

    // MARK: - The list

    func clearChecked() async {
        do throws(APIError) {
            let cleared = try await client.send(API.clearCheckedItems(listId: listId))
            await show(cleared.data)
        } catch {
            actionFailure = error
        }
    }

    func setArchived(_ archived: Bool) async {
        do throws(APIError) {
            let saved = try await client.send(archived ? API.archiveShoppingList(id: listId) : API.unarchiveShoppingList(id: listId)).data
            list = saved
        } catch {
            actionFailure = error
        }
    }

    /// Completes a list of a recurring one, as the web does: archived as
    /// done, and the next list made at once. Answers the next list's id, to
    /// open it; `nil` when there is none (it waits) or it failed.
    func complete() async -> String? {
        do throws(APIError) {
            let completed = try await client.send(API.completeShoppingList(id: listId)).data
            list = completed

            guard let scheduleId = completed.scheduleId else {
                return nil
            }

            let next = try await client.send(API.shoppingSchedule(id: scheduleId)).data.openList

            return next?.friendlyId == listId ? nil : next?.friendlyId
        } catch {
            actionFailure = error

            return nil
        }
    }

    func setDefault(_ isDefault: Bool) async {
        do throws(APIError) {
            list = try await client.send(API.setDefaultShoppingList(id: listId, isDefault: isDefault)).data
        } catch {
            actionFailure = error
        }
    }

    func rename(to name: String) async -> Bool {
        do throws(APIError) {
            list = try await client.send(API.renameShoppingList(id: listId, name: name)).data

            return true
        } catch {
            actionFailure = error

            return false
        }
    }

    /// Moves the list to another of the user's workspaces (its name stays).
    func move(toWorkspace workspaceId: String) async {
        guard let list, list.workspace?.id != workspaceId else {
            return
        }

        do throws(APIError) {
            self.list = try await client.send(API.renameShoppingList(id: listId, name: list.name, workspace: workspaceId)).data
        } catch {
            actionFailure = error
        }
    }

    private func record(_ change: ShoppingItemChange, on item: ShoppingListItem) async {
        items = items.applying([PendingMutation(listId: listId, itemId: item.friendlyId, change: change, clientUpdatedAt: .now)]).sortedForDisplay()

        apply(await sync.record(change, itemId: item.friendlyId, listId: listId))
        await showItems()
    }

    /// What the server answered to the queued changes of this list.
    func apply(_ outcomes: [MutationOutcome]) {
        for outcome in outcomes {
            switch outcome {
            case .applied(let mutation, let saved) where mutation.listId == listId:
                if let saved, let index = serverItems.firstIndex(where: { $0.id == saved.id }) {
                    serverItems[index] = saved
                } else if mutation.change == .remove {
                    serverItems.removeAll { $0.id == mutation.itemId }
                }
            case .rejected(let mutation, _) where mutation.listId == listId:
                // The list as it really is (the item may be gone, the list archived).
                needsReload = true
            default:
                break
            }
        }

        if needsReload, !isReloading {
            Task { await reload() }
        }
    }

    private func load() async {
        do throws(APIError) {
            await show(try await client.send(API.shoppingList(id: listId)).data)
        } catch .forbidden {
            loadState = .forbidden
        } catch .notFound {
            loadState = .notFound
        } catch .unauthenticated {
            // The shell already went back to the login.
        } catch {
            // What is on screen stays (offline, the queue keeps the changes);
            // only a first load shows the failure.
            if list == nil {
                loadState = .failed(error)
            }
        }
    }

    private func show(_ loaded: ShoppingList) async {
        list = loaded
        serverItems = loaded.items ?? []
        await showItems()
        loadState = .loaded
    }

    /// The server's items with the waiting changes on top.
    private func showItems() async {
        let pending = await sync.pending(forList: listId)
        items = serverItems.applying(pending).sortedForDisplay()
    }
}
