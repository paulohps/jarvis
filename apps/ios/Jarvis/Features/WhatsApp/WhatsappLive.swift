import Foundation
import Networking
import Observation
import Realtime
import UIKit

/// A chat that just got a message, shown over the app for a few seconds.
struct WhatsappBanner: Identifiable, Equatable {
    let id = UUID()
    let chat: WhatsappConversation
}

/// The WhatsApp screens in realtime, from the user's private channel
/// (`RealtimeCoordinator.onUserEvent`):
///
/// - `whatsapp.chat.updated`: the chat list and the open chat reload at once
///   (their versions go up, a moment after the last of a burst), and a chat
///   the user is not reading that got a message from the contact shows a
///   banner over the app while it is in the foreground.
/// - `whatsapp.chat.presence`: who is typing, recording or online
///   (`presence`), for the chat's header and its row in the list.
///
/// The screens keep polling, slower while the connection is up, in case an
/// event is lost.
@MainActor
@Observable
final class WhatsappLive {
    /// How long a burst of updates is waited for before reloading.
    static let debounce: Duration = .milliseconds(300)

    /// How long the banner stays.
    static let bannerDuration: Duration = .seconds(4)

    /// Goes up with each batch of updates: the chat list reloads.
    private(set) var listVersion = 0

    /// Goes up with each batch of updates of a chat: the chat, when open, reloads.
    private(set) var chatVersions: [String: Int] = [:]

    private(set) var presence = WhatsappPresenceBook()
    private(set) var banner: WhatsappBanner?

    /// The chat on screen, which never gets a banner.
    @ObservationIgnored var openChatId: () -> String? = { nil }

    /// Whether the user has the WhatsApp module (the API answers 403 otherwise).
    @ObservationIgnored var isAvailable: () -> Bool = { false }

    @ObservationIgnored private let client: APIClient
    @ObservationIgnored private var detector = WhatsappIncomingDetector()
    @ObservationIgnored private var pendingChatIds: Set<String> = []
    @ObservationIgnored private var pendingReload: Task<Void, Never>?
    @ObservationIgnored private var bannerDismissal: Task<Void, Never>?

    init(client: APIClient) {
        self.client = client
    }

    func version(of chatId: String) -> Int {
        chatVersions[chatId, default: 0]
    }

    func handle(_ event: RealtimeEvent) async {
        switch event.name {
        case WhatsappChatUpdatedEvent.name:
            if let updated = try? JSONCoding.decoder.decode(WhatsappChatUpdatedEvent.self, from: event.data) {
                chatUpdated(updated.chatId)
            }
        case WhatsappChatPresenceEvent.name:
            if let changed = try? JSONCoding.decoder.decode(WhatsappChatPresenceEvent.self, from: event.data) {
                presence.receive(changed)
            }
        default:
            break
        }
    }

    /// Learns where each of the latest chats is, so only what comes from now
    /// on gets a banner: after the sign-in and back in the foreground.
    func prime() async {
        detector.reset()
        await lookForNews()
    }

    /// After the sign-out.
    func reset() {
        pendingReload?.cancel()
        bannerDismissal?.cancel()
        detector.reset()
        presence.clear()
        pendingChatIds = []
        chatVersions = [:]
        banner = nil
    }

    /// The user changed a chat, a tag or the priority colors here: the chat
    /// list reloads at once instead of waiting for its next poll.
    func chatsEdited() {
        listVersion += 1
    }

    func dismissBanner() {
        bannerDismissal?.cancel()
        banner = nil
    }

    private func chatUpdated(_ chatId: String) {
        pendingChatIds.insert(chatId)
        pendingReload?.cancel()
        pendingReload = Task { [weak self] in
            try? await Task.sleep(for: Self.debounce)

            guard !Task.isCancelled else {
                return
            }

            await self?.flush()
        }
    }

    private func flush() async {
        let chatIds = pendingChatIds
        pendingChatIds = []

        listVersion += 1

        for chatId in chatIds {
            chatVersions[chatId, default: 0] += 1
        }

        await lookForNews()
    }

    /// The latest chats: a chat whose latest message is new and from the
    /// contact gets the banner, unless it is the one on screen.
    private func lookForNews() async {
        guard isAvailable(),
              let latest = try? await client.send(API.whatsappConversations(page: 1, perPage: 20))
        else {
            return
        }

        let news = detector.newlyReceived(in: latest.data).filter { $0.friendlyId != openChatId() }

        guard let chat = news.first, UIApplication.shared.applicationState == .active else {
            return
        }

        show(WhatsappBanner(chat: chat))
    }

    private func show(_ newBanner: WhatsappBanner) {
        banner = newBanner
        bannerDismissal?.cancel()
        bannerDismissal = Task { [weak self] in
            try? await Task.sleep(for: Self.bannerDuration)

            guard !Task.isCancelled, self?.banner?.id == newBanner.id else {
                return
            }

            self?.banner = nil
        }
    }
}
