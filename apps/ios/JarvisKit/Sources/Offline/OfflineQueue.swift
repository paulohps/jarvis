import Foundation
import Networking

/// What became of a queued change once the server answered.
public enum MutationOutcome: Equatable, Sendable {
    /// Saved; the item as the server keeps it (`nil` after a removal).
    case applied(PendingMutation, ShoppingListItem?)

    /// Refused for good (the item is gone, the list was archived, the module
    /// was switched off): it is dropped and the screen reads the list again.
    case rejected(PendingMutation, APIError)
}

/// The offline queue of shopping item changes (US-8.2).
///
/// A change is kept on disk the moment it is made, with the moment of the
/// tap (`client_updated_at`), so the screen shows it right away with or
/// without network. `flush()` sends the queue in order, each change once:
/// it is removed only after the server answered it, and the sending stops
/// at the first change that could not reach the server, to be tried again
/// when the network comes back — so the order is kept and nothing is lost.
/// Conflicts are settled by the server with last-write-wins.
public actor OfflineQueue {
    private let client: APIClient
    private let store: any MutationStoring
    private let now: @Sendable () -> Date

    public private(set) var pending: [PendingMutation]
    private var isFlushing = false

    public init(client: APIClient, store: any MutationStoring = FileMutationStore(), now: @escaping @Sendable () -> Date = { .now }) {
        self.client = client
        self.store = store
        self.now = now
        pending = store.load()
    }

    /// The changes still waiting for one list.
    public func pending(forList listId: String) -> [PendingMutation] {
        pending.filter { $0.listId == listId }
    }

    /// Keeps a change, stamped with the moment of the tap.
    @discardableResult
    public func enqueue(_ change: ShoppingItemChange, itemId: String, listId: String) -> PendingMutation {
        let mutation = PendingMutation(listId: listId, itemId: itemId, change: change, clientUpdatedAt: now())
        pending.append(mutation)
        persist()

        return mutation
    }

    /// Sends the waiting changes in order (on each change, when the network
    /// comes back, back to the foreground). A flush already running takes
    /// the changes made meanwhile.
    /// - Returns: what the server did with each change sent now.
    @discardableResult
    public func flush() async -> [MutationOutcome] {
        guard !isFlushing else {
            return []
        }

        isFlushing = true
        defer { isFlushing = false }

        var outcomes: [MutationOutcome] = []

        while let mutation = pending.first {
            do {
                let saved = try await mutation.send(with: client)
                remove(mutation)
                outcomes.append(.applied(mutation, saved))
            } catch {
                if Self.isRetryable(error) {
                    break
                }

                if Self.wasApplied(error, by: mutation) {
                    remove(mutation)
                    outcomes.append(.applied(mutation, nil))
                } else {
                    remove(mutation)
                    outcomes.append(.rejected(mutation, error))
                }
            }
        }

        return outcomes
    }

    /// After the sign-out: nothing of the previous user is sent.
    public func clear() {
        pending = []
        persist()
    }

    /// No answer, or one that may change later: the change is kept for the next flush.
    static func isRetryable(_ error: APIError) -> Bool {
        switch error {
        case .transport, .unauthenticated, .tooManyRequests:
            true
        case .server(let status, _):
            status >= 500
        case .forbidden, .notFound, .validation, .decoding:
            false
        }
    }

    /// A removal answered 404 was removed already (by someone else, or by
    /// this change on a try whose answer was lost); an answer that could not
    /// be read was still saved.
    static func wasApplied(_ error: APIError, by mutation: PendingMutation) -> Bool {
        switch error {
        case .notFound:
            mutation.change == .remove
        case .decoding:
            true
        default:
            false
        }
    }

    private func remove(_ mutation: PendingMutation) {
        pending.removeAll { $0.id == mutation.id }
        persist()
    }

    private func persist() {
        try? store.save(pending)
    }
}
