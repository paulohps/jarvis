import Foundation
import Networking
import Observation
import Realtime

/// The Reverb connection of the signed-in user (US-5.1): their private
/// channel feeds the notification list and badge while the app is open, and
/// the connection comes back by itself after the network was lost.
@MainActor
@Observable
final class RealtimeCoordinator {
    private(set) var connectionState: RealtimeConnectionState = .disconnected

    @ObservationIgnored private let pusher: PusherClient
    @ObservationIgnored private let networkMonitor = NetworkMonitor()
    @ObservationIgnored private var listeners: [Task<Void, Never>] = []
    @ObservationIgnored private var userChannel: String?

    /// The screens listening to other channels (a shopping list, US-8.2), by channel.
    @ObservationIgnored private var channelListeners: [String: [UUID: AsyncStream<RealtimeEvent>.Continuation]] = [:]

    /// The subscribes and unsubscribes of those channels, one after the other,
    /// so leaving and opening a list again cannot end unsubscribed.
    @ObservationIgnored private var channelOperations: Task<Void, Never>?

    /// Called with each `notification.created` of the user's channel.
    @ObservationIgnored var onNotificationCreated: ((NotificationCreatedEvent) async -> Void)?

    /// Called with every event of the user's channel (the WhatsApp screens' `whatsapp.chat.*`).
    @ObservationIgnored var onUserEvent: ((RealtimeEvent) async -> Void)?

    init(configuration: AppConfiguration, client: APIClient) {
        pusher = PusherClient(
            serverURL: configuration.reverbURL,
            appKey: configuration.reverbAppKey,
            authorizer: APIChannelAuthorizer(client: client)
        )

        let pusher = pusher
        networkMonitor.start {
            Task { await pusher.networkBecameAvailable() }
        }
    }

    /// After the sign-in, and back in the foreground.
    func start(channel: String?) async {
        if listeners.isEmpty {
            listen()
        }

        if let channel, channel != userChannel {
            if let userChannel {
                await pusher.unsubscribe(userChannel)
            }

            userChannel = channel
            await pusher.subscribe(channel)
        }

        await pusher.connect()
    }

    /// In the background (iOS would drop the socket anyway).
    func pause() async {
        await pusher.disconnect()
    }

    /// After the sign-out: no channel of the previous user is kept.
    func stop() async {
        await pusher.unsubscribeAll()
        await pusher.disconnect()
        userChannel = nil

        for continuation in channelListeners.values.flatMap(\.values) {
            continuation.finish()
        }
        channelListeners = [:]
    }

    /// The events of a private channel while the stream is consumed, e.g. a
    /// list's `private-shopping-lists.{id}`. The channel is left when its last
    /// listener goes away.
    func events(on channel: String) -> AsyncStream<RealtimeEvent> {
        let (stream, continuation) = AsyncStream.makeStream(of: RealtimeEvent.self, bufferingPolicy: .bufferingNewest(20))
        let id = UUID()
        let isFirstListener = channelListeners[channel, default: [:]].isEmpty

        channelListeners[channel, default: [:]][id] = continuation
        continuation.onTermination = { [weak self] _ in
            Task { @MainActor in self?.removeListener(id, of: channel) }
        }

        if isFirstListener {
            enqueueChannelOperation { pusher in await pusher.subscribe(channel) }
        }

        return stream
    }

    private func removeListener(_ id: UUID, of channel: String) {
        guard channelListeners[channel]?.removeValue(forKey: id) != nil, channelListeners[channel]?.isEmpty == true else {
            return
        }

        channelListeners[channel] = nil

        if channel != userChannel {
            enqueueChannelOperation { pusher in await pusher.unsubscribe(channel) }
        }
    }

    private func enqueueChannelOperation(_ operation: @escaping @Sendable (PusherClient) async -> Void) {
        let previous = channelOperations
        let pusher = pusher

        channelOperations = Task {
            await previous?.value
            await operation(pusher)
        }
    }

    private func listen() {
        let pusher = pusher

        listeners.append(Task { [weak self] in
            for await event in await pusher.events() {
                await self?.handle(event)
            }
        })

        listeners.append(Task { [weak self] in
            for await state in await pusher.states() {
                self?.connectionState = state
            }
        })
    }

    private func handle(_ event: RealtimeEvent) async {
        if let listeners = channelListeners[event.channel] {
            for continuation in listeners.values {
                continuation.yield(event)
            }
        }

        if event.channel == userChannel {
            await onUserEvent?(event)
        }

        guard event.channel == userChannel, event.name == NotificationCreatedEvent.name,
              let created = try? JSONCoding.decoder.decode(NotificationCreatedEvent.self, from: event.data)
        else {
            return
        }

        await onNotificationCreated?(created)
    }
}
