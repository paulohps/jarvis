import Foundation
import Networking

/// A change to a shopping item made on this iPhone and not yet confirmed by
/// the server (US-8.2). Only changes that can be sent twice with the same
/// result are queued: the toggle carries the state to end in, the edit the
/// whole item and a removal of a removed item answers 404. Adding an item is
/// not queued, as sending it again would add it twice.
public enum ShoppingItemChange: Codable, Equatable, Sendable {
    case toggle(checked: Bool)
    case update(name: String, quantityNote: String?)
    case remove
}

public struct PendingMutation: Codable, Equatable, Sendable, Identifiable {
    public let id: UUID
    public let listId: String
    public let itemId: String
    public let change: ShoppingItemChange

    /// The moment of the tap, sent as `client_updated_at`: the server keeps
    /// the last write (US-8.2), however late this one arrives.
    public let clientUpdatedAt: Date

    public init(id: UUID = UUID(), listId: String, itemId: String, change: ShoppingItemChange, clientUpdatedAt: Date) {
        self.id = id
        self.listId = listId
        self.itemId = itemId
        self.change = change
        self.clientUpdatedAt = clientUpdatedAt
    }

    /// Applies the change on the server.
    /// - Returns: the item as saved; `nil` after a removal.
    func send(with client: APIClient) async throws(APIError) -> ShoppingListItem? {
        switch change {
        case .toggle(let checked):
            try await client.send(API.toggleShoppingItem(id: itemId, checked: checked, clientUpdatedAt: clientUpdatedAt)).data
        case .update(let name, let quantityNote):
            try await client.send(API.updateShoppingItem(id: itemId, name: name, quantityNote: quantityNote)).data
        case .remove:
            try await removeItem(with: client)
        }
    }

    private func removeItem(with client: APIClient) async throws(APIError) -> ShoppingListItem? {
        try await client.send(API.deleteShoppingItem(id: itemId))

        return nil
    }
}

extension [ShoppingListItem] {
    /// The items as the user left them: the server's state with the changes
    /// still waiting in the queue on top, in their order.
    public func applying(_ mutations: [PendingMutation]) -> [ShoppingListItem] {
        var items = self

        for mutation in mutations {
            guard let index = items.firstIndex(where: { $0.friendlyId == mutation.itemId }) else {
                continue
            }

            switch mutation.change {
            case .toggle(let checked):
                if items[index].isChecked != checked {
                    items[index].checkedAt = checked ? mutation.clientUpdatedAt : nil
                }
                items[index].isChecked = checked
                items[index].clientUpdatedAt = mutation.clientUpdatedAt
            case .update(let name, let quantityNote):
                items[index].name = name
                items[index].quantityNote = quantityNote
            case .remove:
                items.remove(at: index)
            }
        }

        return items
    }
}
