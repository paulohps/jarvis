import Localization
import Networking
import SwiftUI

/// The user's WhatsApp (US-7.3, US-7.5): the chats of their numbers (open
/// one to read and answer it), their scheduled messages — cancel,
/// pause, resume, edit, delete —, scheduling a new one, sending one now and
/// the history of what went out. Connecting a number by QR code is web only:
/// the app links to the web page instead.
///
/// For now the tab shows only the chats (`showsOnlyChats`): the scheduled
/// messages and the history stay here, hidden, until they come back.
struct WhatsAppHomeView: View {
    private static let showsOnlyChats = true

    private enum Pane: Hashable {
        case chats
        case messages
        case history
    }

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    @State private var pane: Pane = .chats
    @State private var accounts: [WhatsappAccount] = []
    @State private var messages: [WhatsappMessage]?
    @State private var loadFailure: APIError?
    @State private var actionFailure: APIError?
    @State private var composer: WhatsappComposer?
    @State private var messageToCancel: WhatsappMessage?
    @State private var messageToDelete: WhatsappMessage?

    var body: some View {
        @Bindable var router = model.router

        NavigationStack(path: $router.whatsappPath) {
            Group {
                switch pane {
                case .chats:
                    WhatsappChatsView()
                case .messages:
                    messagesContent
                case .history:
                    WhatsappHistoryView()
                }
            }
            .navigationDestination(for: WhatsappDestination.self) { destination in
                switch destination {
                case .chat(let id, let preview):
                    WhatsappConversationView(chatId: id, preview: preview)
                case .archived:
                    WhatsappChatsView(isArchive: true)
                        .navigationTitle(language.localized("Archived"))
                }
            }
            .pinnedHeader {
                if !Self.showsOnlyChats {
                    Picker(selection: $pane) {
                        Text(verbatim: language.localized("Chats")).tag(Pane.chats)
                        Text(verbatim: language.localized("Scheduled")).tag(Pane.messages)
                        Text(verbatim: language.localized("History")).tag(Pane.history)
                    } label: {
                        EmptyView()
                    }
                    .pickerStyle(.segmented)
                    .padding(.horizontal)
                    .padding(.bottom, 8)
                }
            }
            .navigationTitle(language.localized("WhatsApp"))
            // A large title with the chats' search field above the filters
            // overlaps the field and flickers when the list reaches its top.
            .navigationBarTitleDisplayMode(.inline)
            .shellToolbar()
            .toolbar {
                if !Self.showsOnlyChats {
                    ToolbarItem(placement: .primaryAction) {
                        Menu(language.localized("New"), systemImage: "plus") {
                            Button(language.localized("Schedule message"), systemImage: "calendar.badge.clock") {
                                composer = .schedule(nil)
                            }
                            Button(language.localized("Send now"), systemImage: "paperplane") {
                                composer = .sendNow
                            }
                        }
                        .accessibilityIdentifier("whatsapp.new")
                    }
                }
            }
            .task {
                await load()
            }
            .sheet(item: $composer) { composer in
                WhatsappMessageForm(composer: composer, accounts: accounts) {
                    Task { await load() }
                }
            }
            .confirmationDialog(
                language.localized("Cancel this message?"),
                isPresented: Binding { messageToCancel != nil } set: { if !$0 { messageToCancel = nil } },
                titleVisibility: .visible,
                presenting: messageToCancel
            ) { message in
                Button(language.localized("Cancel message"), role: .destructive) {
                    Task { await run(API.cancelWhatsappMessage(id: message.friendlyId)) }
                }
                Button(language.localized("Keep"), role: .cancel) {}
            }
            .confirmationDialog(
                language.localized("Delete this message?"),
                isPresented: Binding { messageToDelete != nil } set: { if !$0 { messageToDelete = nil } },
                titleVisibility: .visible,
                presenting: messageToDelete
            ) { message in
                Button(language.localized("Delete"), role: .destructive) {
                    Task { await run(API.deleteWhatsappMessage(id: message.friendlyId)) }
                }
                Button(language.localized("Cancel"), role: .cancel) {}
            }
            .actionFailureAlert($actionFailure)
        }
    }

    // MARK: - Messages

    @ViewBuilder
    private var messagesContent: some View {
        if let messages {
            List {
                Section {
                    if messages.isEmpty {
                        ContentUnavailableView(
                            language.localized("No scheduled messages"),
                            systemImage: "calendar.badge.clock",
                            description: language.text("Schedule a message with the + button.")
                        )
                        .listRowBackground(Color.clear)
                    }

                    ForEach(messages) { message in
                        Button {
                            if message.canBeEdited {
                                composer = .schedule(message)
                            }
                        } label: {
                            WhatsappMessageRow(message: message)
                        }
                        .buttonStyle(.plain)
                        .swipeActions(edge: .trailing) {
                            Button(language.localized("Delete"), systemImage: "trash", role: .destructive) {
                                messageToDelete = message
                            }

                            if message.canBeCancelled {
                                Button(language.localized("Cancel message"), systemImage: "xmark.circle") {
                                    messageToCancel = message
                                }
                                .tint(.orange)
                            }
                        }
                        .swipeActions(edge: .leading) {
                            if message.canBePaused {
                                Button(language.localized("Pause"), systemImage: "pause.fill") {
                                    Task { await run(API.pauseWhatsappMessage(id: message.friendlyId)) }
                                }
                                .tint(.indigo)
                            } else if message.canBeResumed {
                                Button(language.localized("Resume"), systemImage: "play.fill") {
                                    Task { await run(API.resumeWhatsappMessage(id: message.friendlyId)) }
                                }
                                .tint(.green)
                            }
                        }
                        .contextMenu {
                            messageActions(for: message)
                        }
                    }
                }

                accountsSection
            }
            .refreshable {
                await load()
            }
        } else if let loadFailure {
            if case .forbidden = loadFailure {
                NoAccessView()
            } else {
                ContentUnavailableView {
                    Label(loadFailure.userMessage(in: language), systemImage: "wifi.exclamationmark")
                } actions: {
                    Button(language.localized("Try again")) {
                        Task { await load() }
                    }
                    .buttonStyle(.glass)
                }
            }
        } else {
            ProgressView(language.localized("Loading…"))
                .frame(maxWidth: .infinity, maxHeight: .infinity)
        }
    }

    @ViewBuilder
    private func messageActions(for message: WhatsappMessage) -> some View {
        if message.canBeEdited {
            Button(language.localized("Edit"), systemImage: "pencil") {
                composer = .schedule(message)
            }
        }

        if message.canBePaused {
            Button(language.localized("Pause"), systemImage: "pause") {
                Task { await run(API.pauseWhatsappMessage(id: message.friendlyId)) }
            }
        }

        if message.canBeResumed {
            Button(language.localized("Resume"), systemImage: "play") {
                Task { await run(API.resumeWhatsappMessage(id: message.friendlyId)) }
            }
        }

        if message.canBeCancelled {
            Button(language.localized("Cancel message"), systemImage: "xmark.circle") {
                messageToCancel = message
            }
        }

        Button(language.localized("Delete"), systemImage: "trash", role: .destructive) {
            messageToDelete = message
        }
    }

    /// The user's numbers, read only; connecting one is done on the web.
    private var accountsSection: some View {
        Section {
            ForEach(accounts) { account in
                LabeledContent {
                    WhatsappAccountStatus(account: account)
                } label: {
                    Text(verbatim: account.nickname)
                    if let phoneNumber = account.phoneNumber {
                        Text(verbatim: phoneNumber)
                    }
                }
            }

            Link(destination: model.configuration.apiBaseURL.appending(path: "whatsapp/accounts")) {
                Label(language.localized("Connect a number on the web"), systemImage: "safari")
            }
            .accessibilityIdentifier("whatsapp.connect-on-web")
        } header: {
            language.text("Numbers")
        } footer: {
            language.text("Connecting a number is done on the web.")
        }
    }

    private func load() async {
        do throws(APIError) {
            accounts = try await model.client.send(API.whatsappAccounts()).data
            messages = try await model.client.send(API.whatsappMessages()).data
            loadFailure = nil
        } catch .unauthenticated {
            // The shell already went back to the login.
        } catch {
            if messages == nil {
                loadFailure = error
            }
        }
    }

    private func run<Response: Decodable & Sendable>(_ endpoint: Endpoint<Response>) async {
        do throws(APIError) {
            try await model.client.send(endpoint)
        } catch {
            actionFailure = error
        }

        await load()
    }
}

/// What the compose sheet does.
enum WhatsappComposer: Identifiable, Hashable {
    /// Schedules a new message, or edits a saved one.
    case schedule(WhatsappMessage?)
    case sendNow

    var id: String {
        switch self {
        case .schedule(let message): "schedule-\(message?.friendlyId ?? "new")"
        case .sendNow: "send-now"
        }
    }

    static func == (lhs: WhatsappComposer, rhs: WhatsappComposer) -> Bool {
        lhs.id == rhs.id
    }

    func hash(into hasher: inout Hasher) {
        hasher.combine(id)
    }
}

private struct WhatsappMessageRow: View {
    let message: WhatsappMessage

    @Environment(\.appLanguage) private var language

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(alignment: .firstTextBaseline) {
                Text(verbatim: message.targets.map(\.name).joined(separator: ", "))
                    .font(.headline)
                    .lineLimit(1)

                Spacer(minLength: 8)

                WhatsappMessageStateBadge(state: message.state)
            }

            Text(verbatim: message.body)
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .lineLimit(2)

            HStack(spacing: 6) {
                if let recurrence = message.recurrence {
                    if recurrence.frequency == .once {
                        Image(systemName: "calendar")
                        Text(recurrence.nextRunAt ?? recurrence.startsAt, format: .dateTime.day().month().hour().minute())
                    } else {
                        Image(systemName: "repeat")
                        Text(verbatim: recurrence.summary(in: language))

                        if let nextRunAt = recurrence.nextRunAt, message.state == .active {
                            Text(verbatim: "·")
                            Text(verbatim: language.localized("Next %@", nextRunAt.formatted(date: .abbreviated, time: .shortened)))
                        }
                    }
                }

                Text(verbatim: "·")
                Text(verbatim: message.account.nickname)
            }
            .font(.caption)
            .foregroundStyle(.secondary)
            .lineLimit(1)
        }
        .padding(.vertical, 4)
        .contentShape(.rect)
    }
}

private struct WhatsappMessageStateBadge: View {
    let state: WhatsappMessageState

    @Environment(\.appLanguage) private var language

    var body: some View {
        let (key, color): (String, Color) = switch state {
        case .active: ("Active", .green)
        case .paused: ("Paused", .orange)
        case .cancelled: ("Cancelled", .secondary)
        case .finished: ("Finished", .blue)
        }

        Text(verbatim: language.localized(key))
            .font(.caption.weight(.semibold))
            .padding(.horizontal, 8)
            .padding(.vertical, 3)
            .foregroundStyle(color)
            .background(color.opacity(0.15), in: .capsule)
    }
}

struct WhatsappAccountStatus: View {
    let account: WhatsappAccount

    @Environment(\.appLanguage) private var language

    var body: some View {
        let (key, color): (String, Color) = switch account.status {
        case "open": ("Connected", .green)
        case "connecting": ("Connecting", .orange)
        default: ("Disconnected", .secondary)
        }

        Label(language.localized(key), systemImage: "circle.fill")
            .labelStyle(.titleAndIcon)
            .font(.subheadline)
            .foregroundStyle(color)
    }
}
