import Foundation
import Networking
import Observation

/// An open WhatsApp chat: its messages (polled while on screen), what
/// Jarvis is still sending, older pages (until a message to go to is
/// loaded), sending a text (answering a message), a poll, a place, contact
/// cards or a sticker, editing, starring and deleting a message for
/// everyone, marking the chat read whenever a new received message shows,
/// telling "typing…" at most every few seconds, and an answer drafted by
/// the AI.
@MainActor
@Observable
final class WhatsappConversationStore {
    let chatId: String

    private(set) var timeline = WhatsappConversationTimeline()
    private(set) var hasLoaded = false
    private(set) var isLoadingOlder = false
    private(set) var isSending = false
    private(set) var loadFailure: APIError?
    /// The message of a refused send (a 422 comes in the user's language).
    var sendFailure: APIError?
    private(set) var isDrafting = false
    var draftFailure: APIError?
    var reactionFailure: APIError?
    /// Why a star, a delete or a sticker failed.
    var messageFailure: APIError?
    private(set) var isSendingContent = false

    @ObservationIgnored private let client: APIClient
    @ObservationIgnored private var readUpTo: String?
    @ObservationIgnored private var typing = WhatsappTypingThrottle()

    init(chatId: String, client: APIClient) {
        self.chatId = chatId
        self.client = client
    }

    /// The latest page again; marks the chat read when something new came in.
    func reload() async {
        do throws(APIError) {
            let page = try await client.send(API.whatsappConversationMessages(chatId: chatId))

            timeline.mergeLatest(page)
            loadFailure = nil
        } catch .unauthenticated {
            return
        } catch {
            if !hasLoaded {
                loadFailure = error
            }
        }

        hasLoaded = true
        await markReadIfNeeded()
    }

    func loadOlder() async {
        guard timeline.hasOlder, !isLoadingOlder, let oldestId = timeline.oldestId else {
            return
        }

        isLoadingOlder = true
        defer { isLoadingOlder = false }

        if let page = try? await client.send(API.whatsappConversationMessages(chatId: chatId, before: oldestId)) {
            timeline.mergeOlder(page)
        }
    }

    /// Loads older pages until the message is among the loaded ones (a
    /// quote, a search result or a starred message to go to), at most
    /// `maxPages`; true when it is.
    func reveal(_ messageId: String, maxPages: Int = 25) async -> Bool {
        var pages = 0

        while !timeline.contains(messageId), timeline.hasOlder, pages < maxPages, !Task.isCancelled {
            let before = timeline.messages.count
            await loadOlder()
            pages += 1

            if timeline.messages.count == before {
                break
            }
        }

        return timeline.contains(messageId)
    }

    /// Sends the text, quoting the message it answers (`replyTo`); true when
    /// it went to the queue (the composer clears).
    func send(_ text: String, replyTo: String? = nil) async -> Bool {
        guard !text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty, !isSending else {
            return false
        }

        isSending = true
        defer { isSending = false }

        do throws(APIError) {
            try await client.send(API.sendWhatsappConversationText(chatId: chatId, text: text, replyTo: replyTo))
            sendFailure = nil
            typing.reset()
        } catch {
            sendFailure = error

            return false
        }

        await reload()

        return true
    }

    /// An answer drafted by the AI to the picked messages, or to the latest
    /// ones the contact sent (none picked), following `instruction` when
    /// given, for the composer; nil when it failed (`draftFailure` says why:
    /// no AI connection, nothing to answer).
    func draft(answering messageIds: [String] = [], instruction: String? = nil, withoutContext: Bool = false) async -> String? {
        guard !isDrafting else {
            return nil
        }

        isDrafting = true
        defer { isDrafting = false }

        do throws(APIError) {
            let draft = try await client.send(API.draftWhatsappReply(chatId: chatId, messageIds: messageIds, instruction: instruction, withoutContext: withoutContext))
            draftFailure = nil

            return draft.text
        } catch {
            draftFailure = error

            return nil
        }
    }

    /// Reacts to the message with the emoji, or takes the number's reaction
    /// back when it is the same one: shown at once, put back as it was when
    /// WhatsApp refuses (`reactionFailure` says why).
    func react(to message: WhatsappConversationMessage, with emoji: String) async {
        let shown = timeline.messages.first { $0.friendlyId == message.friendlyId } ?? message

        timeline.replace(shown.reacting(with: shown.myReaction == emoji ? nil : emoji))

        do throws(APIError) {
            let reaction = try await client.send(API.reactToWhatsappMessage(chatId: chatId, messageId: message.friendlyId, emoji: emoji))
            timeline.replace(shown.reacting(with: reaction.emoji))
        } catch {
            timeline.replace(shown)
            reactionFailure = error
        }
    }

    /// A poll, a place, contact cards or a sticker; nil when it went to the
    /// queue (it shows once WhatsApp reports it), else why not, for the
    /// sheet that sent it to show.
    func sendContent(_ endpoint: Endpoint<DataEnvelope<WhatsappDelivery>>) async -> APIError? {
        isSendingContent = true
        defer { isSendingContent = false }

        do throws(APIError) {
            try await client.send(endpoint)
        } catch {
            return error
        }

        await reload()

        return nil
    }

    /// A new text for a message the number sent (within WhatsApp's 15
    /// minutes); the error is the caller's to show, in its sheet.
    func edit(_ message: WhatsappConversationMessage, text: String) async throws(APIError) {
        let edited = try await client.send(API.editWhatsappMessage(chatId: chatId, messageId: message.friendlyId, text: text)).data

        timeline.replace(edited)
    }

    /// Deletes for everyone a message the number sent; it stays, marked.
    func deleteForEveryone(_ message: WhatsappConversationMessage) async {
        do throws(APIError) {
            let deleted = try await client.send(API.deleteWhatsappMessageForEveryone(chatId: chatId, messageId: message.friendlyId)).data
            timeline.replace(deleted)
        } catch {
            messageFailure = error
        }
    }

    /// Stars the message, or unstars it: shown at once, put back when the
    /// server refuses.
    func toggleStar(_ message: WhatsappConversationMessage) async {
        let shown = timeline.messages.first { $0.friendlyId == message.friendlyId } ?? message

        timeline.replace(shown.starring(!shown.starred))

        do throws(APIError) {
            let starred = try await client.send(API.starWhatsappMessage(chatId: chatId, messageId: message.friendlyId, starred: !shown.starred)).data
            timeline.replace(starred)
        } catch {
            timeline.replace(shown)
            messageFailure = error
        }
    }

    /// Called on every change of the composer; the server hears it at most every few seconds.
    func userTyped() {
        guard typing.shouldSend() else {
            return
        }

        let client = client
        let chatId = chatId

        Task {
            _ = try? await client.send(API.whatsappConversationTyping(chatId: chatId))
        }
    }

    private func markReadIfNeeded() async {
        let newest = timeline.newestReceivedId ?? ""

        // Once on opening, then whenever a newer received message shows.
        guard newest != readUpTo else {
            return
        }

        if (try? await client.send(API.markWhatsappConversationRead(chatId: chatId))) != nil {
            readUpTo = newest
        }
    }
}
