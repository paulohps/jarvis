import Auth
import Foundation
import Networking
import Observation

/// What the reply keyboard shows and does: the chats where a contact wrote
/// (latest received first), the messages of the picked chat and the answer
/// the server drafts for the picked message. The AI is called only when a
/// message is picked or "Another one" is tapped; nothing is sent from here.
///
/// The keyboard reads the app's Sanctum token from the Keychain (the
/// extension shares the app's access group) and needs Full Access to reach
/// the server.
@MainActor
@Observable
final class KeyboardModel {
    enum Screen: Equatable {
        case chats
        case messages(WhatsappChat)
        case draft(WhatsappChat, WhatsappChatMessage)
    }

    enum Problem: Equatable {
        case needsFullAccess
        case signedOut
        case failed(String?)
    }

    private(set) var screen: Screen = .chats
    private(set) var chats: [WhatsappChat] = []
    private(set) var messages: [WhatsappChatMessage] = []
    private(set) var draft: String?
    private(set) var isLoading = false
    private(set) var problem: Problem?

    /// What was typed in the field when the draft was asked for: it is the
    /// instruction, and the draft replaces it when inserted.
    private(set) var instruction: String?

    @ObservationIgnored private let client: APIClient
    @ObservationIgnored private let hasFullAccess: () -> Bool
    @ObservationIgnored private var task: Task<Void, Never>?

    init(client: APIClient, hasFullAccess: @escaping () -> Bool) {
        self.client = client
        self.hasFullAccess = hasFullAccess
    }

    /// The client of the server set in the extension's Info.plist, with the app's token.
    static func makeClient() -> APIClient {
        let base = (Bundle.main.object(forInfoDictionaryKey: "JarvisAPIBaseURL") as? String).flatMap(URL.init(string:))

        return APIClient(
            baseURL: base ?? URL(string: "http://localhost")!,
            tokenStore: KeychainTokenStore(),
            acceptLanguage: { Locale.preferredLanguages.first }
        )
    }

    func loadChats() {
        screen = .chats
        draft = nil

        run { [client] in
            let page = try await client.send(API.whatsappChats())
            self.chats = page.data
        }
    }

    func open(_ chat: WhatsappChat) {
        screen = .messages(chat)
        messages = []

        run { [client] in
            let envelope = try await client.send(API.whatsappChatMessages(chatId: chat.friendlyId))
            self.messages = envelope.data
        }
    }

    /// Drafts the answer to the message, following what was typed in the field.
    func draftReply(to message: WhatsappChatMessage, in chat: WhatsappChat, typed: String?) {
        let typed = typed?.trimmingCharacters(in: .whitespacesAndNewlines)
        instruction = typed?.isEmpty == false ? typed : nil
        screen = .draft(chat, message)
        draft = nil

        run { [client, instruction] in
            let reply = try await client.send(API.draftWhatsappReply(messageId: message.friendlyId, instruction: instruction))
            self.draft = reply.text
        }
    }

    func regenerate(typed: String?) {
        guard case let .draft(chat, message) = screen else {
            return
        }

        draftReply(to: message, in: chat, typed: typed ?? instruction)
    }

    func back() {
        switch screen {
        case .chats:
            break
        case .messages:
            loadChats()
        case let .draft(chat, _):
            open(chat)
        }
    }

    private func run(_ work: @escaping @MainActor () async throws -> Void) {
        task?.cancel()
        problem = nil

        guard hasFullAccess() else {
            problem = .needsFullAccess
            return
        }

        guard client.tokenStore.token() != nil else {
            problem = .signedOut
            return
        }

        isLoading = true

        task = Task {
            defer { self.isLoading = false }

            do {
                try await work()
            } catch is CancellationError {
                return
            } catch let error as APIError {
                self.problem = error == .unauthenticated ? .signedOut : .failed(error.displayMessage)
            } catch {
                self.problem = .failed(nil)
            }
        }
    }
}
