import Localization
import Networking
import SwiftUI

/// The chats of the user's numbers, as on the web WhatsApp screen: the
/// pinned first, then the latest message first, with the unread count, the
/// priority and the tags, and "typing…" in place of the last message while
/// the contact types. Sorted by the latest message or by priority, filtered
/// by a tag; a swipe pins, marks read or unread, archives or mutes, a long
/// press does the same and sets the priority. Archived chats are left out,
/// behind an "Archived" row that opens them (`isArchive`). The menu starts
/// a chat with a number or a new group and opens the tags, the priority
/// colors and the numbers' defaults. Reloads as soon as a chat changes (`WhatsappLive`),
/// and polls while on screen in case an event is lost: every POLL_SECONDS,
/// LIVE_POLL_SECONDS while the realtime connection is up. A tap opens the
/// conversation.
struct WhatsappChatsView: View {
    private static let pollSeconds: Double = 10
    private static let livePollSeconds: Double = 30

    /// What restarts the loading: a new search, sort or tag, or a chat that changed.
    private struct LoadKey: Equatable {
        let search: String
        let sort: WhatsappChatSort
        let tag: String?
        let version: Int
    }

    /// The archived chats only.
    var isArchive = false

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    @State private var chats: [WhatsappConversation]?
    @State private var page = 1
    @State private var hasMorePages = false
    @State private var isLoadingMore = false
    @State private var search = ""
    @State private var loadFailure: APIError?
    @State private var sort: WhatsappChatSort = .recent
    @State private var tagId: String?
    @State private var tags: [WhatsappConversation.Tag] = []
    @State private var colors: WhatsappPriorityColors?
    @State private var isStartingChat = false
    @State private var isCreatingGroup = false
    @State private var chatToMute: WhatsappConversation?
    @State private var settingsSheet: WhatsappChatSettingsSheet?
    @State private var actionFailure: APIError?

    var body: some View {
        content
            .searchable(text: $search, prompt: language.localized("Search chats"))
            .pinnedHeader {
                if !tags.isEmpty, !isArchive {
                    tagFilter
                }
            }
            .toolbar {
                if !isArchive {
                    ToolbarItem(placement: .primaryAction) {
                        menu
                    }
                }
            }
            .sheet(isPresented: $isStartingChat) {
                WhatsappNewChatForm { chat in
                    model.whatsapp.chatsEdited()
                    model.router.whatsappPath.append(.chat(id: chat.friendlyId, preview: chat))
                }
            }
            .sheet(isPresented: $isCreatingGroup) {
                WhatsappNewGroupForm { chat in
                    model.whatsapp.chatsEdited()
                    model.router.whatsappPath.append(.chat(id: chat.friendlyId, preview: chat))
                }
            }
            .confirmationDialog(
                language.localized("Mute notifications"),
                isPresented: Binding { chatToMute != nil } set: { if !$0 { chatToMute = nil } },
                titleVisibility: .visible,
                presenting: chatToMute
            ) { chat in
                ForEach(WhatsappMute.allCases, id: \.self) { mute in
                    Button(WhatsappMuteText.duration(mute, in: language)) {
                        var update = WhatsappConversationUpdate()
                        update.muted = .set(mute)

                        Task { await save(chat, update) }
                    }
                }
                Button(language.localized("Cancel"), role: .cancel) {}
            }
            .sheet(item: $settingsSheet) { sheet in
                NavigationStack {
                    Group {
                        switch sheet {
                        case .tags: WhatsappTagsManager()
                        case .accountDefaults: WhatsappAccountDefaultsView()
                        }
                    }
                    .toolbar {
                        ToolbarItem(placement: .confirmationAction) {
                            Button(language.localized("Done"), role: .confirm) {
                                settingsSheet = nil
                            }
                        }
                    }
                }
            }
            .actionFailureAlert($actionFailure)
            .task(id: model.whatsapp.listVersion) {
                await loadOptions()
            }
            .task(id: LoadKey(search: search, sort: sort, tag: tagId, version: model.whatsapp.listVersion)) {
                // Typing a search waits a moment before asking the server.
                if !search.isEmpty {
                    try? await Task.sleep(for: .milliseconds(350))
                }

                while !Task.isCancelled {
                    await reload()
                    try? await Task.sleep(for: .seconds(model.realtime.connectionState.isConnected ? Self.livePollSeconds : Self.pollSeconds))
                }
            }
    }

    @ViewBuilder
    private var content: some View {
        if let chats {
            List {
                if !isArchive, search.isEmpty {
                    NavigationLink(value: WhatsappDestination.archived) {
                        Label(language.localized("Archived"), systemImage: "archivebox")
                            .foregroundStyle(.secondary)
                    }
                    .accessibilityIdentifier("whatsapp.archived")
                }

                if chats.isEmpty {
                    ContentUnavailableView(
                        isArchive ? language.localized("No archived chats") : search.isEmpty ? language.localized("No chats yet") : language.localized("No chats found"),
                        systemImage: isArchive ? "archivebox" : "bubble.left.and.bubble.right",
                        description: search.isEmpty && !isArchive ? language.text("The chats of your numbers show here once they get a message.") : nil
                    )
                    .listRowBackground(Color.clear)
                }

                ForEach(chats) { chat in
                    NavigationLink(value: WhatsappDestination.chat(id: chat.friendlyId, preview: chat)) {
                        WhatsappChatRow(chat: chat)
                    }
                    .accessibilityIdentifier("whatsapp.chat.\(chat.friendlyId)")
                    .swipeActions(edge: .leading) {
                        readButton(chat)
                            .tint(.blue)
                        pinButton(chat)
                            .tint(.indigo)
                    }
                    .swipeActions(edge: .trailing) {
                        archiveButton(chat)
                            .tint(.gray)
                        if chat.isMuted() {
                            Button(language.localized("Unmute"), systemImage: "bell") {
                                var update = WhatsappConversationUpdate()
                                update.muted = .set(nil)

                                Task { await save(chat, update) }
                            }
                            .tint(.orange)
                        } else {
                            Button(language.localized("Mute"), systemImage: "bell.slash") {
                                chatToMute = chat
                            }
                            .tint(.orange)
                        }
                    }
                    .contextMenu {
                        pinButton(chat)
                        readButton(chat)

                        Menu(language.localized(chat.isMuted() ? "Unmute" : "Mute"), systemImage: chat.isMuted() ? "bell" : "bell.slash") {
                            WhatsappMuteChoices(chat: chat) { mute in
                                var update = WhatsappConversationUpdate()
                                update.muted = .set(mute)

                                Task { await save(chat, update) }
                            }
                        }

                        archiveButton(chat)

                        WhatsappPriorityMenu(priority: chat.priority, colors: colors) { priority in
                            var update = WhatsappConversationUpdate()
                            update.priority = .set(priority)

                            Task { await save(chat, update) }
                        }
                    }
                    .onAppear {
                        if chat.id == chats.last?.id {
                            Task { await loadMore() }
                        }
                    }
                }

                if isLoadingMore {
                    ProgressView()
                        .frame(maxWidth: .infinity)
                        .listRowBackground(Color.clear)
                }
            }
            .listStyle(.plain)
            .refreshable {
                await reload()
            }
        } else if let loadFailure {
            if case .forbidden = loadFailure {
                NoAccessView()
            } else {
                ContentUnavailableView {
                    Label(loadFailure.userMessage(in: language), systemImage: "wifi.exclamationmark")
                } actions: {
                    Button(language.localized("Try again")) {
                        Task { await reload() }
                    }
                    .buttonStyle(.glass)
                }
            }
        } else {
            ProgressView(language.localized("Loading…"))
                .frame(maxWidth: .infinity, maxHeight: .infinity)
        }
    }

    /// The tags as chips over the list: all the chats, or those with one tag.
    private var tagFilter: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                WhatsappFilterChip(title: language.localized("All"), color: nil, isOn: tagId == nil) {
                    tagId = nil
                }

                ForEach(tags) { tag in
                    WhatsappFilterChip(title: tag.name, color: Color(hex: tag.color), isOn: tagId == tag.friendlyId) {
                        tagId = tagId == tag.friendlyId ? nil : tag.friendlyId
                    }
                    .accessibilityIdentifier("whatsapp.tag-filter.\(tag.friendlyId)")
                }
            }
            .padding(.horizontal)
            .padding(.bottom, 8)
        }
    }

    private func pinButton(_ chat: WhatsappConversation) -> some View {
        Button(language.localized(chat.pinned ? "Unpin" : "Pin"), systemImage: chat.pinned ? "pin.slash" : "pin") {
            var update = WhatsappConversationUpdate()
            update.pinned = .set(!chat.pinned)

            Task { await save(chat, update) }
        }
    }

    /// Marks an unread chat read (as opening it would), or a read one unread.
    private func readButton(_ chat: WhatsappConversation) -> some View {
        Button(language.localized(chat.looksUnread ? "Mark as read" : "Mark as unread"), systemImage: chat.looksUnread ? "envelope.open" : "envelope.badge") {
            Task {
                if chat.looksUnread {
                    await markRead(chat)
                } else {
                    var update = WhatsappConversationUpdate()
                    update.markedUnread = .set(true)

                    await save(chat, update)
                }
            }
        }
    }

    private func archiveButton(_ chat: WhatsappConversation) -> some View {
        Button(language.localized(chat.archived ? "Unarchive" : "Archive"), systemImage: chat.archived ? "tray.and.arrow.up" : "archivebox") {
            var update = WhatsappConversationUpdate()
            update.archived = .set(!chat.archived)

            Task { await save(chat, update) }
        }
    }

    private var menu: some View {
        Menu(language.localized("More"), systemImage: "ellipsis") {
            Button(language.localized("New chat"), systemImage: "square.and.pencil") {
                isStartingChat = true
            }
            .accessibilityIdentifier("whatsapp.new-chat")

            Button(language.localized("New group"), systemImage: "person.3") {
                isCreatingGroup = true
            }
            .accessibilityIdentifier("whatsapp.new-group")

            Picker(selection: $sort) {
                Label(language.localized("Latest first"), systemImage: "clock").tag(WhatsappChatSort.recent)
                Label(language.localized("Priority first"), systemImage: "flag").tag(WhatsappChatSort.priority)
            } label: {
                Label(language.localized("Sort"), systemImage: "arrow.up.arrow.down")
            }
            .pickerStyle(.menu)
            .accessibilityIdentifier("whatsapp.sort")

            Section {
                Button(language.localized("Tags"), systemImage: "tag") {
                    settingsSheet = .tags
                }
                Button(language.localized("Defaults of the numbers"), systemImage: "gearshape") {
                    settingsSheet = .accountDefaults
                }
            }
        }
        .accessibilityIdentifier("whatsapp.chats.menu")
    }

    private func loadOptions() async {
        if let loaded = try? await model.client.send(API.whatsappTags()).data {
            tags = loaded

            if let tagId, !loaded.contains(where: { $0.friendlyId == tagId }) {
                self.tagId = nil
            }
        }

        colors = (try? await model.client.send(API.whatsappPriorityColors())) ?? colors
    }

    private func save(_ chat: WhatsappConversation, _ update: WhatsappConversationUpdate) async {
        do throws(APIError) {
            try await model.client.send(API.updateWhatsappConversation(chatId: chat.friendlyId, update))
        } catch {
            actionFailure = error
        }

        model.whatsapp.chatsEdited()
    }

    private func markRead(_ chat: WhatsappConversation) async {
        do throws(APIError) {
            try await model.client.send(API.markWhatsappConversationRead(chatId: chat.friendlyId))
        } catch {
            actionFailure = error
        }

        model.whatsapp.chatsEdited()
    }

    /// The first page again, keeping the pages already scrolled through
    /// out of it (they come back as the list scrolls).
    private func reload() async {
        do throws(APIError) {
            let first = try await model.client.send(API.whatsappConversations(page: 1, search: search, sort: sort, tag: isArchive ? nil : tagId, archived: isArchive))

            chats = first.data
            page = 1
            hasMorePages = first.meta.hasMorePages
            loadFailure = nil
        } catch .unauthenticated {
            // The shell already went back to the login.
        } catch {
            if chats == nil {
                loadFailure = error
            }
        }
    }

    private func loadMore() async {
        guard hasMorePages, !isLoadingMore else {
            return
        }

        isLoadingMore = true
        defer { isLoadingMore = false }

        if let next = try? await model.client.send(API.whatsappConversations(page: page + 1, search: search, sort: sort, tag: isArchive ? nil : tagId, archived: isArchive)) {
            let known = Set((chats ?? []).map(\.id))

            chats = (chats ?? []) + next.data.filter { !known.contains($0.id) }
            page = next.meta.currentPage
            hasMorePages = next.meta.hasMorePages
        }
    }
}

/// The settings opened from the chats' menu.
enum WhatsappChatSettingsSheet: String, Identifiable {
    case tags
    case accountDefaults

    var id: String { rawValue }
}

private struct WhatsappFilterChip: View {
    let title: String
    let color: Color?
    let isOn: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 5) {
                if let color {
                    Circle().fill(color).frame(width: 8, height: 8)
                }
                Text(verbatim: title)
                    .font(.subheadline.weight(.medium))
                    .lineLimit(1)
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 6)
            .foregroundStyle(isOn ? WhatsappPalette.send : Color.primary)
            .background(isOn ? WhatsappPalette.send.opacity(0.15) : Color.secondary.opacity(0.12), in: .capsule)
        }
        .buttonStyle(.plain)
        .accessibilityAddTraits(isOn ? .isSelected : [])
    }
}

/// The priorities, none or 1 (highest) to 5 with the user's colors, the
/// chat's one checked.
struct WhatsappPriorityMenu: View {
    let priority: Int?
    let colors: WhatsappPriorityColors?
    let onPick: (Int?) -> Void

    @Environment(\.appLanguage) private var language

    var body: some View {
        Menu(language.localized("Priority"), systemImage: "flag") {
            Picker(selection: Binding { priority } set: { onPick($0) }) {
                Text(verbatim: language.localized("None")).tag(Int?.none)
                ForEach(WhatsappPriorityColors.priorities, id: \.self) { value in
                    WhatsappPriorityLabel(priority: value, colors: colors).tag(Optional(value))
                }
            } label: {
                EmptyView()
            }
            .pickerStyle(.inline)
        }
    }
}

private struct WhatsappChatRow: View {
    let chat: WhatsappConversation

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    var body: some View {
        HStack(spacing: 12) {
            WhatsappChatAvatar(chat: chat)

            VStack(alignment: .leading, spacing: 3) {
                HStack(alignment: .firstTextBaseline) {
                    Text(verbatim: chat.name)
                        .font(.headline)
                        .lineLimit(1)

                    Spacer(minLength: 8)

                    if let lastMessageAt = chat.lastMessageAt {
                        Text(verbatim: WhatsappDates.listTime(lastMessageAt, in: language))
                            .font(.caption)
                            .foregroundStyle(chat.looksUnread ? Color.green : Color.secondary)
                    }
                }

                HStack(spacing: 6) {
                    previewText

                    Spacer(minLength: 4)

                    ForEach(chat.tags.prefix(3)) { tag in
                        Circle()
                            .fill(Color(hex: tag.color) ?? .secondary)
                            .frame(width: 8, height: 8)
                            .accessibilityLabel(Text(verbatim: tag.name))
                    }

                    if chat.isMuted() {
                        Image(systemName: "bell.slash.fill")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                            .accessibilityLabel(language.text("Muted"))
                    }

                    if chat.pinned {
                        Image(systemName: "pin.fill")
                            .font(.caption)
                            .foregroundStyle(.tint)
                            .accessibilityLabel(language.text("Pinned"))
                    }

                    if chat.unreadCount == 0, chat.markedUnread {
                        Circle()
                            .fill(Color.green)
                            .frame(width: 12, height: 12)
                            .accessibilityLabel(language.text("Marked unread"))
                    }

                    if chat.unreadCount > 0 {
                        Text(verbatim: "\(chat.unreadCount)")
                            .font(.caption.weight(.semibold))
                            .foregroundStyle(.white)
                            .padding(.horizontal, 7)
                            .padding(.vertical, 2)
                            .background(Color.green, in: .capsule)
                            .accessibilityLabel(Text(verbatim: language.localized("%lld unread", chat.unreadCount)))
                    }
                }
            }
        }
        .padding(.vertical, 2)
    }

    /// The last message, or "typing…" in green while the contact types.
    @ViewBuilder
    private var previewText: some View {
        if model.whatsapp.presence.hasNews(for: chat.friendlyId) {
            TimelineView(.periodic(from: .now, by: 1)) { context in
                if let status = model.whatsapp.presence.status(of: chat.friendlyId, at: context.date), status.isActivity {
                    Text(verbatim: WhatsappPresenceText.text(for: status, in: language))
                        .font(.subheadline)
                        .foregroundStyle(WhatsappPalette.send)
                        .lineLimit(1)
                } else {
                    plainPreview
                }
            }
        } else {
            plainPreview
        }
    }

    private var plainPreview: some View {
        Text(verbatim: preview)
            .font(.subheadline)
            .foregroundStyle(.secondary)
            .lineLimit(1)
    }

    private var preview: String {
        guard let message = chat.lastMessage else {
            return ""
        }

        let text = WhatsappMessageText.preview(of: message, in: language)

        if message.fromMe {
            return language.localized("You: %@", text)
        }

        if chat.isGroup, let sender = message.sender {
            return "\(sender): \(text)"
        }

        return text
    }
}

/// The WhatsApp picture of a chat, or the first letter of its name; the
/// priority, when set, sits on its corner in the user's color for it.
struct WhatsappChatAvatar: View {
    let chat: WhatsappConversation
    var size: CGFloat = 48

    var body: some View {
        ZStack(alignment: .bottomTrailing) {
            AsyncImage(url: chat.pictureUrl) { phase in
                if case .success(let image) = phase {
                    image.resizable().scaledToFill()
                } else {
                    ZStack {
                        Color.secondary.opacity(0.2)
                        if chat.isGroup {
                            Image(systemName: "person.3.fill")
                                .font(.system(size: size * 0.35))
                                .foregroundStyle(.secondary)
                        } else {
                            Text(verbatim: chat.initial)
                                .font(.system(size: size * 0.42, weight: .semibold))
                                .foregroundStyle(.secondary)
                        }
                    }
                }
            }
            .frame(width: size, height: size)
            .clipShape(.circle)

            if let priority = chat.priority {
                Text(verbatim: "\(priority)")
                    .font(.system(size: size * 0.24, weight: .bold))
                    .foregroundStyle(.white)
                    .frame(width: size * 0.38, height: size * 0.38)
                    .background(Color(hex: chat.priorityColor) ?? .orange, in: .circle)
                    .overlay(Circle().stroke(Color(.systemBackground), lineWidth: 2))
                    .offset(x: 3, y: 3)
            }
        }
        .accessibilityHidden(true)
    }
}

/// How a message reads in one line: its text, or what kind of message it is.
enum WhatsappMessageText {
    static func preview(of message: WhatsappConversationMessage, in language: AppLanguage) -> String {
        if let body = message.body, !body.isEmpty {
            return body
        }

        if message.isSticker {
            return language.localized("Sticker")
        }

        if let poll = message.poll {
            return language.localized("Poll: %@", poll.name)
        }

        if let location = message.location {
            return location.name.flatMap { $0.isEmpty ? nil : $0 } ?? language.localized(location.isLive ? "Live location" : "Location")
        }

        if let contacts = message.contacts, !contacts.isEmpty {
            return contacts.count == 1 ? (contacts[0].name ?? language.localized("Contact")) : language.localized("%lld contacts", contacts.count)
        }

        return switch message.media?.kind {
        case .image: language.localized("Photo")
        case .video: language.localized("Video")
        case .audio: language.localized("Audio")
        case .document: message.media?.name ?? language.localized("Document")
        case nil: language.localized("Message")
        }
    }

    /// A quoted message: its text, else what kind of message it is by its WhatsApp type.
    static func preview(of quoted: WhatsappConversationMessage.Quoted, in language: AppLanguage) -> String {
        if let body = quoted.body, !body.isEmpty {
            return body
        }

        return switch quoted.type {
        case "imageMessage": language.localized("Photo")
        case "videoMessage", "ptvMessage": language.localized("Video")
        case "audioMessage": language.localized("Audio")
        case "documentMessage", "documentWithCaptionMessage": language.localized("Document")
        case "stickerMessage": language.localized("Sticker")
        case "locationMessage": language.localized("Location")
        case "liveLocationMessage": language.localized("Live location")
        case "contactMessage", "contactsArrayMessage": language.localized("Contact")
        case let type? where type.hasPrefix("pollCreation"): language.localized("Poll")
        default: language.localized("Message")
        }
    }
}

enum WhatsappDates {
    /// The time for today, "Yesterday", otherwise the date.
    static func listTime(_ date: Date, in language: AppLanguage, calendar: Calendar = .current) -> String {
        if calendar.isDateInToday(date) {
            return date.formatted(date: .omitted, time: .shortened)
        }

        if calendar.isDateInYesterday(date) {
            return language.localized("Yesterday")
        }

        return date.formatted(date: .numeric, time: .omitted)
    }
}

extension Color {
    /// A `#rrggbb` color of the API; nil for nil or anything else.
    init?(hex: String?) {
        guard let hex, let color = HexColor(hex) else {
            return nil
        }

        self.init(red: color.red, green: color.green, blue: color.blue)
    }
}
