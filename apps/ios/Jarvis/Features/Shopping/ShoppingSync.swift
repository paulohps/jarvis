import Foundation
import Networking
import Observation
import Offline
import Realtime

/// The app side of the offline queue (US-8.2): item changes are kept on
/// disk and sent right away, and again when the network comes back, the app
/// returns to the foreground or the realtime connection is up again.
@MainActor
@Observable
final class ShoppingSync {
    /// The changes not yet confirmed by the server, by list.
    private(set) var pendingByList: [String: Int] = [:]

    /// Bumped after a sending not asked by a screen, so the open list reads itself again.
    private(set) var revision = 0

    /// The last change the server refused for good, for an alert.
    var rejection: APIError?

    @ObservationIgnored private let queue: OfflineQueue
    @ObservationIgnored private let networkMonitor = NetworkMonitor()

    init(client: APIClient, store: any MutationStoring = FileMutationStore()) {
        queue = OfflineQueue(client: client, store: store)

        networkMonitor.start { [weak self] in
            Task { @MainActor in await self?.flush() }
        }
    }

    /// Keeps a change with the moment of the tap and tries to send it.
    /// - Returns: what the server did with what was sent now.
    func record(_ change: ShoppingItemChange, itemId: String, listId: String) async -> [MutationOutcome] {
        await queue.enqueue(change, itemId: itemId, listId: listId)
        await updateCounts()

        return await send(isBackground: false)
    }

    func pending(forList listId: String) async -> [PendingMutation] {
        await queue.pending(forList: listId)
    }

    /// Sends what is waiting, in order (the network is back, the app is in
    /// the foreground again); the open list reads itself again afterwards.
    func flush() async {
        await send(isBackground: true)
    }

    @discardableResult
    private func send(isBackground: Bool) async -> [MutationOutcome] {
        let outcomes = await queue.flush()
        await updateCounts()

        for case .rejected(_, let error) in outcomes {
            rejection = error
        }

        if isBackground, !outcomes.isEmpty {
            revision += 1
        }

        return outcomes
    }

    /// After the sign-out.
    func clear() async {
        await queue.clear()
        await updateCounts()
    }

    private func updateCounts() async {
        let pending = await queue.pending
        pendingByList = Dictionary(grouping: pending, by: \.listId).mapValues(\.count)
    }
}
