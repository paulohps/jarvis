import Foundation
import Networking

/// An event of a channel: Laravel's `broadcastAs` name and its JSON payload.
public struct RealtimeEvent: Equatable, Sendable {
    public let channel: String
    public let name: String
    public let data: Data

    public init(channel: String, name: String, data: Data) {
        self.channel = channel
        self.name = name
        self.data = data
    }
}

public enum RealtimeConnectionState: Equatable, Sendable {
    case disconnected
    case connecting
    case connected(socketId: String)
    case waitingToReconnect(attempt: Int)

    public var isConnected: Bool {
        if case .connected = self {
            return true
        }

        return false
    }
}

/// Signs a private channel for a socket (the `auth` of `pusher:subscribe`).
public protocol ChannelAuthorizing: Sendable {
    func authorize(socketId: String, channel: String) async throws -> String
}

/// `POST /api/v1/broadcasting/auth` with the device's Bearer token.
public struct APIChannelAuthorizer: ChannelAuthorizing {
    private let client: APIClient

    public init(client: APIClient) {
        self.client = client
    }

    public func authorize(socketId: String, channel: String) async throws -> String {
        try await client.send(API.broadcastingAuth(socketId: socketId, channelName: channel)).auth
    }
}

/// How long to wait before each new attempt after the connection was lost.
public struct ReconnectPolicy: Sendable {
    public var delays: [Duration]

    public init(delays: [Duration] = [.seconds(1), .seconds(2), .seconds(5), .seconds(10), .seconds(30)]) {
        self.delays = delays
    }

    public func delay(forAttempt attempt: Int) -> Duration {
        delays.isEmpty ? .zero : delays[min(attempt, delays.count - 1)]
    }
}

/// A client of the Pusher protocol 7 against Laravel Reverb (US-5.1, US-8.2).
///
/// It keeps the channels it was asked for and subscribes them on every
/// connection, signing the private ones through `/broadcasting/auth`. It
/// answers the server's pings and pings an idle connection itself; a lost
/// connection is opened again with backoff, and right away when the network
/// comes back (`networkBecameAvailable()`).
public actor PusherClient {
    private let socketURL: URL
    private let authorizer: any ChannelAuthorizing
    private let connector: any WebSocketConnecting
    private let policy: ReconnectPolicy
    private let pongTimeout: Duration

    private var connection: (any WebSocketConnection)?
    private var generation = 0
    private var shouldBeConnected = false
    private var attempt = 0
    private var socketId: String?
    private var channels: Set<String> = []
    private var lastActivity = ContinuousClock.now
    private var activityTimeout: Duration = .seconds(120)

    private var receiveTask: Task<Void, Never>?
    private var watchdogTask: Task<Void, Never>?
    private var reconnectTask: Task<Void, Never>?

    private var eventContinuations: [UUID: AsyncStream<RealtimeEvent>.Continuation] = [:]
    private var stateContinuations: [UUID: AsyncStream<RealtimeConnectionState>.Continuation] = [:]

    public private(set) var state: RealtimeConnectionState = .disconnected
    public private(set) var subscribedChannels: Set<String> = []

    /// - Parameters:
    ///   - serverURL: Reverb, e.g. `wss://jarvis.example.com:443` (`REVERB_SCHEME/HOST/PORT`).
    ///   - appKey: `REVERB_APP_KEY`.
    public init(
        serverURL: URL,
        appKey: String,
        authorizer: any ChannelAuthorizing,
        connector: any WebSocketConnecting = URLSessionWebSocketConnector(),
        policy: ReconnectPolicy = ReconnectPolicy(),
        pongTimeout: Duration = .seconds(30)
    ) {
        var components = URLComponents(url: serverURL.appending(path: "app/\(appKey)"), resolvingAgainstBaseURL: false)!
        components.queryItems = [
            URLQueryItem(name: "protocol", value: "7"),
            URLQueryItem(name: "client", value: "jarvis-ios"),
            URLQueryItem(name: "version", value: "1.0"),
            URLQueryItem(name: "flash", value: "false"),
        ]

        socketURL = components.url!
        self.authorizer = authorizer
        self.connector = connector
        self.policy = policy
        self.pongTimeout = pongTimeout
    }

    // MARK: - Public API

    public func events() -> AsyncStream<RealtimeEvent> {
        let (stream, continuation) = AsyncStream.makeStream(of: RealtimeEvent.self, bufferingPolicy: .bufferingNewest(50))
        let id = UUID()
        eventContinuations[id] = continuation
        continuation.onTermination = { [weak self] _ in
            Task { await self?.removeEventContinuation(id) }
        }

        return stream
    }

    public func states() -> AsyncStream<RealtimeConnectionState> {
        let (stream, continuation) = AsyncStream.makeStream(of: RealtimeConnectionState.self, bufferingPolicy: .bufferingNewest(10))
        let id = UUID()
        stateContinuations[id] = continuation
        continuation.yield(state)
        continuation.onTermination = { [weak self] _ in
            Task { await self?.removeStateContinuation(id) }
        }

        return stream
    }

    public func connect() {
        shouldBeConnected = true

        guard connection == nil, reconnectTask == nil else {
            return
        }

        open()
    }

    public func disconnect() {
        shouldBeConnected = false
        reconnectTask?.cancel()
        reconnectTask = nil
        attempt = 0
        dropConnection()
        set(.disconnected)
    }

    /// Listens to a channel (`private-…` ones are signed), now and after every reconnection.
    public func subscribe(_ channel: String) async {
        guard channels.insert(channel).inserted else {
            return
        }

        if let socketId {
            await sendSubscribe(channel, socketId: socketId, generation: generation)
        }
    }

    public func unsubscribe(_ channel: String) async {
        channels.remove(channel)

        if subscribedChannels.remove(channel) != nil {
            await send(PusherMessage.encode(event: "pusher:unsubscribe", data: ["channel": channel]))
        }
    }

    public func unsubscribeAll() async {
        for channel in channels {
            await unsubscribe(channel)
        }
    }

    /// The network is back (`NWPathMonitor`): reconnect now instead of waiting the backoff.
    public func networkBecameAvailable() {
        guard shouldBeConnected, !state.isConnected, state != .connecting else {
            return
        }

        reconnectTask?.cancel()
        reconnectTask = nil
        attempt = 0
        dropConnection()
        open()
    }

    // MARK: - Connection

    private func open() {
        generation += 1
        let currentGeneration = generation
        let newConnection = connector.connect(to: socketURL)

        connection = newConnection
        lastActivity = .now
        set(.connecting)

        receiveTask = Task { [weak self] in
            while !Task.isCancelled {
                do {
                    let text = try await newConnection.receive()
                    await self?.handle(text, generation: currentGeneration)
                } catch {
                    await self?.connectionLost(generation: currentGeneration)

                    return
                }
            }
        }
    }

    private func dropConnection() {
        receiveTask?.cancel()
        watchdogTask?.cancel()
        receiveTask = nil
        watchdogTask = nil
        connection?.close()
        connection = nil
        socketId = nil
        subscribedChannels = []
    }

    private func connectionLost(generation lostGeneration: Int) {
        guard lostGeneration == generation, connection != nil else {
            return
        }

        dropConnection()

        guard shouldBeConnected else {
            set(.disconnected)

            return
        }

        let delay = policy.delay(forAttempt: attempt)
        attempt += 1
        set(.waitingToReconnect(attempt: attempt))

        reconnectTask = Task { [weak self] in
            do {
                try await Task.sleep(for: delay)
            } catch {
                return
            }

            await self?.reconnectAfterDelay()
        }
    }

    private func reconnectAfterDelay() {
        reconnectTask = nil

        guard shouldBeConnected, connection == nil else {
            return
        }

        open()
    }

    // MARK: - Protocol

    private func handle(_ text: String, generation frameGeneration: Int) async {
        guard frameGeneration == generation, let message = PusherMessage(text: text) else {
            return
        }

        lastActivity = .now

        switch message.event {
        case "pusher:connection_established":
            let payload = message.payload

            guard let newSocketId = payload["socket_id"] as? String else {
                return
            }

            if let seconds = payload["activity_timeout"] as? Int, seconds > 0 {
                activityTimeout = .seconds(seconds)
            }

            socketId = newSocketId
            attempt = 0
            set(.connected(socketId: newSocketId))
            startWatchdog(generation: frameGeneration)

            for channel in channels.sorted() {
                await sendSubscribe(channel, socketId: newSocketId, generation: frameGeneration)
            }
        case "pusher:ping":
            await send(PusherMessage.encode(event: "pusher:pong", data: [:]))
        case "pusher_internal:subscription_succeeded":
            if let channel = message.channel {
                subscribedChannels.insert(channel)
            }
        case "pusher:error":
            // 4000–4099: the server will not take this connection again (wrong key, SSL only).
            if let code = message.payload["code"] as? Int, (4000..<4100).contains(code) {
                disconnect()
            }
        default:
            guard let channel = message.channel, !message.event.hasPrefix("pusher") else {
                return
            }

            let event = RealtimeEvent(channel: channel, name: message.event, data: message.data)

            for continuation in eventContinuations.values {
                continuation.yield(event)
            }
        }
    }

    private func sendSubscribe(_ channel: String, socketId: String, generation subscribeGeneration: Int) async {
        var data: [String: Any] = ["channel": channel]

        if channel.hasPrefix("private-") || channel.hasPrefix("presence-") {
            do {
                data["auth"] = try await authorizer.authorize(socketId: socketId, channel: channel)
            } catch {
                // A refused channel (module switched off, session over) is skipped.
                return
            }
        }

        guard subscribeGeneration == generation, channels.contains(channel) else {
            return
        }

        await send(PusherMessage.encode(event: "pusher:subscribe", data: data))
    }

    private func send(_ text: String) async {
        guard let connection else {
            return
        }

        do {
            try await connection.send(text)
        } catch {
            connectionLost(generation: generation)
        }
    }

    /// Pings an idle connection; no answer within `pongTimeout` means it is gone.
    private func startWatchdog(generation watchedGeneration: Int) {
        watchdogTask?.cancel()

        let timeout = activityTimeout
        let pongTimeout = pongTimeout

        watchdogTask = Task { [weak self] in
            while !Task.isCancelled {
                do {
                    try await Task.sleep(for: timeout)
                } catch {
                    return
                }

                guard let self, await self.isIdle(for: timeout) else {
                    continue
                }

                await self.send(PusherMessage.encode(event: "pusher:ping", data: [:]))

                do {
                    try await Task.sleep(for: pongTimeout)
                } catch {
                    return
                }

                if await self.isIdle(for: pongTimeout) {
                    await self.connectionLost(generation: watchedGeneration)

                    return
                }
            }
        }
    }

    private func isIdle(for duration: Duration) -> Bool {
        ContinuousClock.now - lastActivity >= duration
    }

    private func set(_ newState: RealtimeConnectionState) {
        guard newState != state else {
            return
        }

        state = newState

        for continuation in stateContinuations.values {
            continuation.yield(newState)
        }
    }

    private func removeEventContinuation(_ id: UUID) {
        eventContinuations.removeValue(forKey: id)
    }

    private func removeStateContinuation(_ id: UUID) {
        stateContinuations.removeValue(forKey: id)
    }
}
