import Foundation
import XCTest
@testable import Networking
@testable import Realtime

final class PusherClientTests: XCTestCase {
    private let userChannel = "private-App.Models.User.42"

    private func makeClient(
        connector: FakeConnector,
        authorizer: FakeAuthorizer = FakeAuthorizer(),
        delays: [Duration] = [.milliseconds(10)]
    ) -> PusherClient {
        PusherClient(
            serverURL: URL(string: "ws://localhost:8080")!,
            appKey: "jarvis-local-key",
            authorizer: authorizer,
            connector: connector,
            policy: ReconnectPolicy(delays: delays)
        )
    }

    func testConnectsToReverbWithTheAppKeyAndProtocol7() async throws {
        let connector = FakeConnector()
        let client = makeClient(connector: connector)

        await client.connect()

        let url = try XCTUnwrap(connector.lastURL)
        XCTAssertEqual(url.scheme, "ws")
        XCTAssertEqual(url.host(), "localhost")
        XCTAssertEqual(url.port, 8080)
        XCTAssertEqual(url.path(), "/app/jarvis-local-key")
        XCTAssertTrue(url.query()?.contains("protocol=7") ?? false)
        let state = await client.state
        XCTAssertEqual(state, .connecting)
    }

    func testPrivateChannelsAreSignedThroughBroadcastingAuth() async throws {
        let connector = FakeConnector()
        let authorizer = FakeAuthorizer()
        let client = makeClient(connector: connector, authorizer: authorizer)

        await client.subscribe(userChannel)
        await client.connect()
        let connection = try XCTUnwrap(connector.connections.first)
        connection.push(connectionEstablished(socketId: "123.456"))

        let subscribed = await eventually { connection.sentEvents(named: "pusher:subscribe").count == 1 }
        XCTAssertTrue(subscribed)
        XCTAssertEqual(authorizer.calls.map(\.socketId), ["123.456"])
        XCTAssertEqual(authorizer.calls.map(\.channel), [userChannel])
        let frame = try XCTUnwrap(connection.sentEvents(named: "pusher:subscribe").first)
        XCTAssertEqual(frame["channel"] as? String, userChannel)
        XCTAssertEqual(frame["auth"] as? String, "jarvis-local-key:signature-123.456")
        let state = await client.state
        XCTAssertEqual(state, .connected(socketId: "123.456"))
    }

    func testChannelEventsReachTheListenersRightAway() async throws {
        let connector = FakeConnector()
        let client = makeClient(connector: connector)
        var events = await client.events().makeAsyncIterator()

        await client.subscribe(userChannel)
        await client.connect()
        let connection = try XCTUnwrap(connector.connections.first)
        connection.push(connectionEstablished(socketId: "1.1"))
        connection.push(#"{"event":"pusher_internal:subscription_succeeded","channel":"private-App.Models.User.42","data":"{}"}"#)
        connection.push(#"{"event":"notification.created","channel":"private-App.Models.User.42","data":"{\"id\":\"9b1c\",\"title\":\"Reminder: milk\",\"unread_count\":3}"}"#)

        let next = await events.next()
        let event = try XCTUnwrap(next)
        XCTAssertEqual(event.channel, userChannel)
        XCTAssertEqual(event.name, "notification.created")
        let payload = try XCTUnwrap(JSONSerialization.jsonObject(with: event.data) as? [String: Any])
        XCTAssertEqual(payload["unread_count"] as? Int, 3)
        let subscribedChannels = await client.subscribedChannels
        XCTAssertEqual(subscribedChannels, [userChannel])
    }

    func testAnswersTheServersPing() async throws {
        let connector = FakeConnector()
        let client = makeClient(connector: connector)

        await client.connect()
        let connection = try XCTUnwrap(connector.connections.first)
        connection.push(connectionEstablished(socketId: "1.1"))
        connection.push(#"{"event":"pusher:ping","data":"{}"}"#)

        let answered = await eventually { connection.sentEvents(named: "pusher:pong").count == 1 }
        XCTAssertTrue(answered)
    }

    func testReconnectsAfterLosingTheNetworkAndSubscribesAgain() async throws {
        let connector = FakeConnector()
        let authorizer = FakeAuthorizer()
        let client = makeClient(connector: connector, authorizer: authorizer)

        await client.subscribe(userChannel)
        await client.connect()
        let first = try XCTUnwrap(connector.connections.first)
        first.push(connectionEstablished(socketId: "1.1"))
        _ = await eventually { first.sentEvents(named: "pusher:subscribe").count == 1 }

        first.drop()

        let reconnected = await eventually { connector.connections.count == 2 }
        XCTAssertTrue(reconnected)
        let second = connector.connections[1]
        second.push(connectionEstablished(socketId: "2.2"))

        let resubscribed = await eventually { second.sentEvents(named: "pusher:subscribe").count == 1 }
        XCTAssertTrue(resubscribed)
        XCTAssertEqual(authorizer.calls.map(\.socketId), ["1.1", "2.2"])
        let state = await client.state
        XCTAssertEqual(state, .connected(socketId: "2.2"))
    }

    func testTheNetworkComingBackReconnectsWithoutWaitingTheBackoff() async throws {
        let connector = FakeConnector()
        let client = makeClient(connector: connector, delays: [.seconds(60)])

        await client.connect()
        let first = try XCTUnwrap(connector.connections.first)
        first.push(connectionEstablished(socketId: "1.1"))
        _ = await eventually { await client.state.isConnected }

        first.drop()
        let waiting = await eventually { await client.state == .waitingToReconnect(attempt: 1) }
        XCTAssertTrue(waiting)
        XCTAssertEqual(connector.connections.count, 1)

        await client.networkBecameAvailable()

        XCTAssertEqual(connector.connections.count, 2)
        let state = await client.state
        XCTAssertEqual(state, .connecting)
    }

    func testARefusedChannelIsSkippedAndTheOthersSubscribe() async throws {
        let connector = FakeConnector()
        let authorizer = FakeAuthorizer()
        authorizer.refusedChannels = ["private-shopping-lists.shl_1"]
        let client = makeClient(connector: connector, authorizer: authorizer)

        await client.subscribe("private-shopping-lists.shl_1")
        await client.subscribe(userChannel)
        await client.connect()
        let connection = try XCTUnwrap(connector.connections.first)
        connection.push(connectionEstablished(socketId: "1.1"))

        let done = await eventually { authorizer.calls.count == 2 }
        XCTAssertTrue(done)
        _ = await eventually { connection.sentEvents(named: "pusher:subscribe").count == 1 }
        XCTAssertEqual(connection.sentEvents(named: "pusher:subscribe").compactMap { $0["channel"] as? String }, [userChannel])
    }

    func testDisconnectClosesAndStopsReconnecting() async throws {
        let connector = FakeConnector()
        let client = makeClient(connector: connector)

        await client.connect()
        let connection = try XCTUnwrap(connector.connections.first)
        connection.push(connectionEstablished(socketId: "1.1"))
        _ = await eventually { await client.state.isConnected }

        await client.disconnect()
        try await Task.sleep(for: .milliseconds(50))

        XCTAssertTrue(connection.isClosed)
        XCTAssertEqual(connector.connections.count, 1)
        let state = await client.state
        XCTAssertEqual(state, .disconnected)
    }

    func testAFatalServerErrorStopsTheClient() async throws {
        let connector = FakeConnector()
        let client = makeClient(connector: connector)

        await client.connect()
        let connection = try XCTUnwrap(connector.connections.first)
        connection.push(#"{"event":"pusher:error","data":{"code":4001,"message":"Application does not exist"}}"#)

        let stopped = await eventually { await client.state == .disconnected }
        XCTAssertTrue(stopped)
        try await Task.sleep(for: .milliseconds(50))
        XCTAssertEqual(connector.connections.count, 1)
    }

    func testTheApiAuthorizerSignsWithTheBearerToken() async throws {
        let transport = StubTransport([(200, #"{"auth":"jarvis-local-key:abc"}"#)])
        let client = APIClient(baseURL: URL(string: "https://jarvis.test")!, tokenStore: InMemoryTokenStore(token: "1|secret"), transport: transport)

        let auth = try await APIChannelAuthorizer(client: client).authorize(socketId: "1.1", channel: userChannel)

        XCTAssertEqual(auth, "jarvis-local-key:abc")
        let request = try XCTUnwrap(transport.lastRequest)
        XCTAssertEqual(request.url?.path, "/api/v1/broadcasting/auth")
        XCTAssertEqual(request.value(forHTTPHeaderField: "Authorization"), "Bearer 1|secret")
        let body = try XCTUnwrap(JSONSerialization.jsonObject(with: XCTUnwrap(request.httpBody)) as? [String: String])
        XCTAssertEqual(body, ["socket_id": "1.1", "channel_name": userChannel])
    }
}
