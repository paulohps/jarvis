import Foundation
import XCTest
import Networking
@testable import Offline

final class OfflineQueueTests: XCTestCase {
    private var fileURL = FileManager.default.temporaryDirectory.appending(path: "offline-mutations.json")

    override func setUp() async throws {
        fileURL = FileManager.default.temporaryDirectory
            .appending(components: "OfflineQueueTests-\(UUID().uuidString)", "offline-mutations.json")
    }

    override func tearDown() async throws {
        try? FileManager.default.removeItem(at: fileURL.deletingLastPathComponent())
    }

    func testMutationsPersistBetweenRestarts() async throws {
        let clock = TestClock(start: Date(timeIntervalSince1970: 1_791_288_000.25))
        let queue = makeQueue(transport: StubTransport([]), clock: clock)

        await queue.enqueue(.toggle(checked: true), itemId: "shi_milk", listId: "shl_market")
        clock.advance(by: 2.5)
        await queue.enqueue(.update(name: "Bread", quantityNote: "2 loaves"), itemId: "shi_bread", listId: "shl_market")
        clock.advance(by: 1)
        await queue.enqueue(.remove, itemId: "shi_rice", listId: "shl_other")
        let made = await queue.pending

        // A new queue on the same file, as after the app was killed and launched again.
        let relaunched = makeQueue(transport: StubTransport([]), clock: TestClock(start: .distantFuture))
        let kept = await relaunched.pending

        XCTAssertEqual(kept, made)
        XCTAssertEqual(kept.map(\.itemId), ["shi_milk", "shi_bread", "shi_rice"])
        XCTAssertEqual(kept.map(\.clientUpdatedAt), [
            Date(timeIntervalSince1970: 1_791_288_000.25),
            Date(timeIntervalSince1970: 1_791_288_002.75),
            Date(timeIntervalSince1970: 1_791_288_003.75),
        ])
        let marketOnly = await relaunched.pending(forList: "shl_market")
        XCTAssertEqual(marketOnly.count, 2)
    }

    func testMutationsAreSentInOrderWithTheirOriginalClientUpdatedAt() async throws {
        let clock = TestClock(start: try XCTUnwrap(JSONCoding.parseDate("2026-10-06T12:00:00+00:00")))
        let offline = makeQueue(transport: StubTransport([]), clock: clock)
        await offline.enqueue(.toggle(checked: true), itemId: "shi_milk", listId: "shl_market")
        clock.advance(by: 60)
        await offline.enqueue(.toggle(checked: true), itemId: "shi_bread", listId: "shl_market")
        clock.advance(by: 60)
        await offline.enqueue(.toggle(checked: false), itemId: "shi_milk", listId: "shl_market")

        // Sent later, after a relaunch: the moments are those of the taps, not of the sending.
        let transport = StubTransport([
            (200, Self.itemBody(id: "shi_milk", checked: true)),
            (200, Self.itemBody(id: "shi_bread", checked: true)),
            (200, Self.itemBody(id: "shi_milk", checked: false)),
        ])
        let queue = makeQueue(transport: transport, clock: TestClock(start: Date(timeIntervalSince1970: 1_900_000_000)))

        let outcomes = await queue.flush()

        let requests = transport.sentRequests
        XCTAssertEqual(requests.map { $0.url?.path() }, [
            "/api/v1/shopping/items/shi_milk/toggle",
            "/api/v1/shopping/items/shi_bread/toggle",
            "/api/v1/shopping/items/shi_milk/toggle",
        ])
        let bodies = try requests.map { try Self.json(of: $0) }
        XCTAssertEqual(bodies.map { $0["client_updated_at"] as? String }, [
            "2026-10-06T12:00:00Z",
            "2026-10-06T12:01:00Z",
            "2026-10-06T12:02:00Z",
        ])
        XCTAssertEqual(bodies.map { $0["checked"] as? Bool }, [true, true, false])
        XCTAssertEqual(outcomes.count, 3)
        let left = await queue.pending
        XCTAssertTrue(left.isEmpty)
        XCTAssertTrue(FileMutationStore(fileURL: fileURL).load().isEmpty)
    }

    func testWithoutNetworkTheMutationsStayAndAreSentWhenItComesBack() async throws {
        let transport = StubTransport([(0, "")])
        let queue = makeQueue(transport: transport, clock: TestClock(start: Date(timeIntervalSince1970: 1_791_288_000)))
        await queue.enqueue(.toggle(checked: true), itemId: "shi_milk", listId: "shl_market")
        await queue.enqueue(.toggle(checked: true), itemId: "shi_bread", listId: "shl_market")

        let offlineOutcomes = await queue.flush()

        // The first failure stops the sending: the order is kept for the next try.
        XCTAssertTrue(offlineOutcomes.isEmpty)
        XCTAssertEqual(transport.sentRequests.count, 1)
        let stillPending = await queue.pending
        XCTAssertEqual(stillPending.map(\.itemId), ["shi_milk", "shi_bread"])

        transport.respond(with: [
            (200, Self.itemBody(id: "shi_milk", checked: true)),
            (200, Self.itemBody(id: "shi_bread", checked: true)),
        ])
        let onlineOutcomes = await queue.flush()

        XCTAssertEqual(onlineOutcomes.count, 2)
        XCTAssertEqual(transport.sentRequests.map { $0.url?.path() }, [
            "/api/v1/shopping/items/shi_milk/toggle",
            "/api/v1/shopping/items/shi_milk/toggle",
            "/api/v1/shopping/items/shi_bread/toggle",
        ])
        let left = await queue.pending
        XCTAssertTrue(left.isEmpty)
    }

    func testResentMutationsDoNotDuplicateItems() async throws {
        // The answer to the toggle is lost: it is sent again, with the state to end in.
        let transport = StubTransport([
            (0, ""),
            (200, Self.itemBody(id: "shi_milk", checked: true)),
            (404, #"{"message":"Not found."}"#),
        ])
        let queue = makeQueue(transport: transport, clock: TestClock(start: Date(timeIntervalSince1970: 1_791_288_000)))
        await queue.enqueue(.toggle(checked: true), itemId: "shi_milk", listId: "shl_market")
        await queue.enqueue(.remove, itemId: "shi_rice", listId: "shl_market")

        await queue.flush()
        let outcomes = await queue.flush()

        // No call ever adds an item; a removal that finds the item gone is done.
        let requests = transport.sentRequests
        XCTAssertFalse(requests.contains { $0.url?.path().hasSuffix("/items") == true })
        XCTAssertEqual(requests.map(\.httpMethod), ["POST", "POST", "DELETE"])
        let bodies = try requests.prefix(2).map { try Self.json(of: $0) }
        XCTAssertEqual(bodies.map { $0["checked"] as? Bool }, [true, true])
        XCTAssertEqual(bodies[0]["client_updated_at"] as? String, bodies[1]["client_updated_at"] as? String)
        XCTAssertEqual(outcomes.map(\.isApplied), [true, true])
        let left = await queue.pending
        XCTAssertTrue(left.isEmpty)
    }

    func testARefusedMutationIsDroppedAndTheNextOnesGoOn() async throws {
        let transport = StubTransport([
            (422, #"{"message":"The list is archived.","errors":{"list":["The list is archived."]}}"#),
            (200, Self.itemBody(id: "shi_bread", checked: true)),
        ])
        let queue = makeQueue(transport: transport, clock: TestClock(start: Date(timeIntervalSince1970: 1_791_288_000)))
        await queue.enqueue(.toggle(checked: true), itemId: "shi_milk", listId: "shl_archived")
        await queue.enqueue(.toggle(checked: true), itemId: "shi_bread", listId: "shl_market")

        let outcomes = await queue.flush()

        XCTAssertEqual(outcomes.map(\.isApplied), [false, true])
        let left = await queue.pending
        XCTAssertTrue(left.isEmpty)
    }

    func testThePendingMutationsAreShownOverTheServerState() {
        let tappedAt = Date(timeIntervalSince1970: 1_791_288_000)
        let fromServer = [
            ShoppingListItem(friendlyId: "shi_milk", name: "Milk", position: 1),
            ShoppingListItem(friendlyId: "shi_bread", name: "Bread", position: 2),
            ShoppingListItem(friendlyId: "shi_rice", name: "Rice", position: 3),
        ]
        let pending = [
            PendingMutation(listId: "shl_market", itemId: "shi_milk", change: .toggle(checked: true), clientUpdatedAt: tappedAt),
            PendingMutation(listId: "shl_market", itemId: "shi_bread", change: .update(name: "Bread", quantityNote: "2"), clientUpdatedAt: tappedAt),
            PendingMutation(listId: "shl_market", itemId: "shi_rice", change: .remove, clientUpdatedAt: tappedAt),
        ]

        let shown = fromServer.applying(pending).sortedForDisplay()

        XCTAssertEqual(shown.map(\.name), ["Bread", "Milk"])
        XCTAssertEqual(shown.first?.quantityNote, "2")
        XCTAssertEqual(shown.last?.isChecked, true)
        XCTAssertEqual(shown.last?.checkedAt, tappedAt)
    }

    func testClearForgetsTheQueueOfThePreviousUser() async {
        let queue = makeQueue(transport: StubTransport([]), clock: TestClock(start: .now))
        await queue.enqueue(.toggle(checked: true), itemId: "shi_milk", listId: "shl_market")

        await queue.clear()

        XCTAssertTrue(FileMutationStore(fileURL: fileURL).load().isEmpty)
    }

    // MARK: - Helpers

    private func makeQueue(transport: StubTransport, clock: TestClock) -> OfflineQueue {
        OfflineQueue(
            client: APIClient(baseURL: URL(string: "https://jarvis.test")!, tokenStore: InMemoryTokenStore(token: "1|t"), transport: transport),
            store: FileMutationStore(fileURL: fileURL),
            now: { clock.now }
        )
    }

    private static func itemBody(id: String, checked: Bool) -> String {
        #"{"data":{"friendly_id":"\#(id)","name":"x","position":1,"is_checked":\#(checked)}}"#
    }

    private static func json(of request: URLRequest) throws -> [String: Any] {
        try XCTUnwrap(JSONSerialization.jsonObject(with: XCTUnwrap(request.httpBody)) as? [String: Any])
    }
}

/// A clock the test moves by hand.
private final class TestClock: @unchecked Sendable {
    private let lock = NSLock()
    private var current: Date

    init(start: Date) {
        current = start
    }

    var now: Date {
        lock.withLock { current }
    }

    func advance(by seconds: TimeInterval) {
        lock.withLock { current += seconds }
    }
}

private extension MutationOutcome {
    var isApplied: Bool {
        if case .applied = self {
            return true
        }

        return false
    }
}
