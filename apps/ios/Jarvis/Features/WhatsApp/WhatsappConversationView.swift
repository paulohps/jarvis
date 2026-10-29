import Localization
import Networking
import PhotosUI
import SwiftUI
import UIKit

/// An open WhatsApp chat, looking like WhatsApp on the phone: full screen
/// (no tab bar), the contact's picture and name in the bar, the messages by
/// day on the chat wallpaper (mine on the right with their ticks, a group's
/// with who sent them), their photos, audios, videos and documents, older
/// ones on demand, what Jarvis is still sending, and a composer that sends
/// from the chat's number. Reloads as soon as the chat changes
/// (`WhatsappLive`) and polls while on screen and in the foreground, in case
/// an event is lost: every POLL_SECONDS, LIVE_POLL_SECONDS while the realtime
/// connection is up. The header says when the contact types, records an
/// audio or is online.
///
/// A message shows what it answers (a tap goes to it, loading older pages
/// as needed), "Forwarded", "Edited", its star and pin, a place, contact
/// cards (with a button to message them), a poll with its votes (read
/// only: voting is done on the phone) or a sticker without a bubble.
///
/// The chat's tools, as on the web: the bar's menu (and a tap on the header)
/// opens the chat's info (search, starred, media, nickname, priority, tags,
/// mute, archive, block, a group's info and members, settings, scheduled
/// messages, reminders, sync), searches it, pins it, sets its priority, a
/// reminder or a scheduled message; a long press on a message reacts to
/// it (the quick emojis, or any), answers it (a bar over the composer),
/// edits it, stars it, shows its info, forwards it, copies it, drafts an
/// answer to it with the AI, sets a reminder about it, deletes it for
/// everyone or starts picking messages to draft an answer to; the
/// composer's + drafts an answer to the latest received messages, schedules
/// the text, or sends a poll, a place, contact cards or a sticker. In a
/// group, typing `@` suggests the members to mention. Every AI answer is
/// either generated straight away or following a prompt the user writes.
///
/// Opened from the list it has the chat already (`preview`); opened from a
/// push it only has the id and finds the chat among the latest ones.
struct WhatsappConversationView: View {
    private static let pollSeconds: Double = 4
    private static let livePollSeconds: Double = 15

    /// What restarts the polling: the app coming back, or the chat changing.
    private struct PollKey: Equatable {
        let phase: ScenePhase
        let version: Int
    }

    let chatId: String
    let preview: WhatsappConversation?

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language
    @Environment(\.scenePhase) private var scenePhase

    @State private var chat: WhatsappConversation?
    @State private var store: WhatsappConversationStore?
    @State private var text = ""
    @State private var selection: Set<String>?
    @State private var sheet: WhatsappConversationSheet?
    @State private var colors: WhatsappPriorityColors?
    @State private var actionFailure: APIError?
    /// The messages an answer is being prompted for (none: the latest received).
    @State private var promptingFor: [String]?
    /// The message the composer answers (quoted).
    @State private var replyingTo: WhatsappConversationMessage?
    @State private var messageToDelete: WhatsappConversationMessage?
    /// The message to scroll to (a quote, a search result), then marked a moment.
    @State private var jumpTo: String?
    @State private var highlighted: String?
    @State private var isJumpMissed = false
    @State private var isPickingSticker = false
    @State private var stickerItem: PhotosPickerItem?
    /// A group's members, for the mentions; loaded at the first `@`.
    @State private var groupMembers: [WhatsappGroup.Member]?
    @FocusState private var isComposerFocused: Bool

    var body: some View {
        Group {
            if let store {
                conversation(store)
            } else {
                ProgressView(language.localized("Loading…"))
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
        .background(WhatsappPalette.wallpaper.ignoresSafeArea())
        .navigationTitle(Text(verbatim: chat?.name ?? ""))
        .navigationBarTitleDisplayMode(.inline)
        .toolbar(.hidden, for: .tabBar)
        .toolbarBackground(WhatsappPalette.bar, for: .navigationBar)
        .toolbarBackground(.visible, for: .navigationBar)
        .toolbar {
            ToolbarItem(placement: .principal) {
                EmptyView()
            }
            ToolbarItem(placement: .topBarLeading) {
                Button {
                    sheet = .info
                } label: {
                    header
                }
                .buttonStyle(.plain)
            }
            .sharedBackgroundVisibility(.hidden)
            ToolbarItem(placement: .topBarTrailing) {
                if selection != nil {
                    Button(language.localized("Cancel")) {
                        selection = nil
                    }
                } else {
                    menu
                }
            }
        }
        .sheet(item: $sheet) { sheet in
            switch sheet {
            case .info:
                WhatsappChatInfoView(chatId: chatId, onJump: { jumpTo = $0 }) { changed in
                    chat = changed
                }
            case .reminder(let messages):
                WhatsappChatReminderForm(chatId: chatId, messages: messages) {}
            case .schedule(let text):
                WhatsappChatScheduleForm(chatId: chatId, text: text) {
                    if self.text == text {
                        self.text = ""
                    }
                }
            case .react(let message):
                WhatsappEmojiPickerSheet(current: message.myReaction) { emoji in
                    Task { await store?.react(to: message, with: emoji) }
                }
            case .forward(let messages, let accountId):
                WhatsappForwardSheet(chatId: chatId, accountId: accountId, messages: messages) {
                    selection = nil
                }
            case .edit(let message):
                WhatsappEditMessageSheet(message: message) { (text: String) async throws(APIError) in
                    try await store?.edit(message, text: text)
                }
            case .messageInfo(let message):
                WhatsappMessageInfoSheet(chatId: chatId, message: message)
            case .browse(let tab):
                NavigationStack {
                    WhatsappChatBrowser(chatId: chatId, tab: tab) { messageId in
                        self.sheet = nil
                        jumpTo = messageId
                    }
                    .toolbar {
                        ToolbarItem(placement: .confirmationAction) {
                            Button(language.localized("Done"), role: .confirm) {
                                self.sheet = nil
                            }
                        }
                    }
                }
            case .poll:
                WhatsappPollForm { question, options, multiple in
                    await sendContent(API.sendWhatsappPoll(chatId: chatId, question: question, options: options, multiple: multiple, replyTo: replyingTo?.friendlyId))
                }
            case .location:
                WhatsappLocationForm { latitude, longitude, name, address in
                    await sendContent(API.sendWhatsappLocation(chatId: chatId, latitude: latitude, longitude: longitude, name: name, address: address, replyTo: replyingTo?.friendlyId))
                }
            case .contacts(let accountId):
                WhatsappContactPicker(
                    accountId: accountId,
                    title: language.localized("Send contacts"),
                    actionTitle: language.localized("Send"),
                    limit: WhatsappContentLimits.contacts
                ) { picked in
                    await sendContent(API.sendWhatsappContacts(chatId: chatId, contactIds: picked.map(\.friendlyId)))
                }
            }
        }
        .photosPicker(isPresented: $isPickingSticker, selection: $stickerItem, matching: .images)
        .onChange(of: stickerItem) { _, item in
            if let item {
                Task { await sendSticker(item) }
            }
        }
        .confirmationDialog(
            language.localized("Delete this message for everyone?"),
            isPresented: Binding { messageToDelete != nil } set: { if !$0 { messageToDelete = nil } },
            titleVisibility: .visible,
            presenting: messageToDelete
        ) { message in
            Button(language.localized("Delete for everyone"), role: .destructive) {
                Task { await store?.deleteForEveryone(message) }
            }
            Button(language.localized("Cancel"), role: .cancel) {}
        } message: { _ in
            Text(verbatim: language.localized("It is deleted on WhatsApp for everyone in the chat; Jarvis keeps it, marked as deleted."))
        }
        .alert(language.localized("Message not found"), isPresented: $isJumpMissed) {
            Button(language.localized("OK")) {}
        } message: {
            Text(verbatim: language.localized("It is too far back in the chat to show here."))
        }
        .actionFailureAlert($actionFailure)
        .actionFailureAlert(Binding { store?.draftFailure } set: { store?.draftFailure = $0 })
        .actionFailureAlert(Binding { store?.reactionFailure } set: { store?.reactionFailure = $0 })
        .actionFailureAlert(Binding { store?.messageFailure } set: { store?.messageFailure = $0 })
        .sheet(isPresented: Binding { promptingFor != nil } set: { if !$0 { promptingFor = nil } }) {
            let messageIds = promptingFor ?? []

            WhatsappDraftPromptSheet(hasSelection: !messageIds.isEmpty) { prompt, withoutContext in
                Task { await draft(messageIds, instruction: prompt, withoutContext: withoutContext) }
            }
        }
        .onAppear {
            model.openWhatsappChatId = chatId
        }
        .onDisappear {
            if model.openWhatsappChatId == chatId {
                model.openWhatsappChatId = nil
            }
        }
        .task {
            chat = chat ?? preview

            if chat == nil {
                await findChat()
            }

            colors = try? await model.client.send(API.whatsappPriorityColors())
        }
        .task(id: PollKey(phase: scenePhase, version: model.whatsapp.version(of: chatId))) {
            if store == nil {
                store = WhatsappConversationStore(chatId: chatId, client: model.client)
            }

            // Only while the chat is on screen: reading it marks it read.
            while scenePhase == .active, !Task.isCancelled {
                await store?.reload()
                try? await Task.sleep(for: .seconds(model.realtime.connectionState.isConnected ? Self.livePollSeconds : Self.pollSeconds))
            }
        }
    }

    /// The picture, the name and, under it, the group or the number.
    private var header: some View {
        HStack(spacing: 10) {
            if let chat {
                WhatsappChatAvatar(chat: chat, size: 36)
            } else {
                Circle()
                    .fill(Color.secondary.opacity(0.2))
                    .frame(width: 36, height: 36)
            }

            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: chat?.name ?? "")
                    .font(.headline)
                    .lineLimit(1)

                TimelineView(.periodic(from: .now, by: 1)) { context in
                    if let status = model.whatsapp.presence.status(of: chatId, at: context.date) {
                        Text(verbatim: WhatsappPresenceText.text(for: status, in: language))
                            .font(.caption)
                            .foregroundStyle(status.isActivity ? WhatsappPalette.send : Color.secondary)
                            .lineLimit(1)
                            .accessibilityIdentifier("whatsapp.conversation.presence")
                    } else if let subtitle {
                        Text(verbatim: subtitle)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                            .lineLimit(1)
                    }
                }
            }
        }
        .accessibilityElement(children: .combine)
        .accessibilityIdentifier("whatsapp.conversation.header")
    }

    private var subtitle: String? {
        guard let chat else {
            return nil
        }

        var parts = [chat.account.nickname]

        if chat.isGroup {
            parts.insert(language.localized("Group"), at: 0)
        } else if let phoneNumber = chat.phoneNumber, phoneNumber != chat.name {
            parts.insert(phoneNumber, at: 0)
        }

        return parts.joined(separator: " · ")
    }

    /// The chat's tools: its info, searching it, pin, priority, picking
    /// messages for the AI, the AI's answer to the latest message, a
    /// reminder, a scheduled message and syncing it.
    private var menu: some View {
        Menu(language.localized("More"), systemImage: "ellipsis") {
            Button(language.localized("Chat info"), systemImage: "info.circle") {
                sheet = .info
            }
            Button(language.localized("Search in chat"), systemImage: "magnifyingglass") {
                sheet = .browse(.search)
            }
            .accessibilityIdentifier("whatsapp.conversation.search")

            if let chat {
                Button(language.localized(chat.pinned ? "Unpin" : "Pin"), systemImage: chat.pinned ? "pin.slash" : "pin") {
                    var update = WhatsappConversationUpdate()
                    update.pinned = .set(!chat.pinned)

                    Task { await save(update) }
                }

                WhatsappPriorityMenu(priority: chat.priority, colors: colors) { priority in
                    var update = WhatsappConversationUpdate()
                    update.priority = .set(priority)

                    Task { await save(update) }
                }
            }

            Section {
                draftChoices([])
                Button(language.localized("Select messages"), systemImage: "checkmark.circle") {
                    selection = []
                }
            }

            Section {
                Button(language.localized("Remind me"), systemImage: "bell") {
                    sheet = .reminder([])
                }
                Button(language.localized("Schedule message"), systemImage: "calendar.badge.clock") {
                    sheet = .schedule(text)
                }
                Button(language.localized("Sync messages"), systemImage: "arrow.triangle.2.circlepath") {
                    Task { await sync() }
                }
            }
        }
        .accessibilityIdentifier("whatsapp.conversation.menu")
    }

    private func save(_ update: WhatsappConversationUpdate) async {
        do throws(APIError) {
            chat = try await model.client.send(API.updateWhatsappConversation(chatId: chatId, update)).data
            model.whatsapp.chatsEdited()
        } catch {
            actionFailure = error
        }
    }

    private func sync() async {
        do throws(APIError) {
            try await model.client.send(API.syncWhatsappConversation(chatId: chatId))
            await store?.reload()
        } catch {
            actionFailure = error
        }
    }

    /// The two ways of drafting with the AI: straight away, or following a
    /// prompt the user writes first.
    @ViewBuilder
    private func draftChoices(_ messageIds: [String]) -> some View {
        Button(language.localized("Generate reply"), systemImage: "sparkles") {
            Task { await draft(messageIds) }
        }
        .accessibilityIdentifier("whatsapp.conversation.generate")
        Button(language.localized("Generate with prompt…"), systemImage: "text.bubble") {
            promptingFor = messageIds
        }
        .accessibilityIdentifier("whatsapp.conversation.generate-with-prompt")
    }

    /// The AI's answer to the messages (the latest received when none),
    /// following the prompt when there is one, goes to the composer in place
    /// of what was typed.
    private func draft(_ messageIds: [String], instruction: String? = nil, withoutContext: Bool = false) async {
        guard let store, let drafted = await store.draft(answering: messageIds, instruction: instruction, withoutContext: withoutContext) else {
            return
        }

        selection = nil
        text = drafted
        isComposerFocused = true
    }

    /// A poll, a place or contact cards from a sheet (which shows the
    /// failure); the answer bar goes once it is sent.
    private func sendContent(_ endpoint: Endpoint<DataEnvelope<WhatsappDelivery>>) async -> APIError? {
        guard let store else {
            return nil
        }

        let failure = await store.sendContent(endpoint)

        if failure == nil {
            replyingTo = nil
        }

        return failure
    }

    private func sendSticker(_ item: PhotosPickerItem) async {
        defer { stickerItem = nil }

        guard let store else {
            return
        }

        guard let file = await WhatsappStickerFile.load(item) else {
            store.messageFailure = .validation(message: language.localized("This image cannot be sent as a sticker."), errors: [:])

            return
        }

        if let failure = await sendContent(API.sendWhatsappSticker(chatId: chatId, file: file, replyTo: replyingTo?.friendlyId)) {
            store.messageFailure = failure
        }
    }

    /// The chat with a number of a contact card, on the same number of
    /// the user's, opened (started when there is none yet).
    private func openChat(with number: String) async {
        guard let accountId = chat?.account.friendlyId else {
            return
        }

        do throws(APIError) {
            let started = try await model.client.send(API.startWhatsappConversation(accountId: accountId, number: number)).data

            model.whatsapp.chatsEdited()
            model.router.whatsappPath.append(.chat(id: started.friendlyId, preview: started))
        } catch {
            actionFailure = error
        }
    }

    /// A group's members for the mentions, once.
    private func loadGroupMembers() async {
        guard groupMembers == nil, chat?.isGroup == true else {
            return
        }

        groupMembers = (try? await model.client.send(API.whatsappGroup(chatId: chatId)).data.members) ?? []
    }

    /// Loads older pages until the message shows, scrolls to it and marks it
    /// a moment; says so when it is too far back.
    private func scroll(to target: String, store: WhatsappConversationStore, proxy: ScrollViewProxy) async {
        let isFound = await store.reveal(target)
        jumpTo = nil

        guard isFound else {
            isJumpMissed = true

            return
        }

        // Lets an older page lay out before scrolling to one of its messages.
        try? await Task.sleep(for: .milliseconds(150))
        withAnimation {
            proxy.scrollTo(target, anchor: .center)
        }
        highlighted = target

        try? await Task.sleep(for: .seconds(2))

        if highlighted == target {
            withAnimation {
                highlighted = nil
            }
        }
    }

    /// A chat opened from a push: it just got a message, so it is among the
    /// latest ones.
    private func findChat() async {
        let latest = try? await model.client.send(API.whatsappConversations(page: 1, perPage: 50))

        chat = latest?.data.first { $0.friendlyId == chatId }
    }

    @ViewBuilder
    private func conversation(_ store: WhatsappConversationStore) -> some View {
        if !store.hasLoaded {
            ProgressView(language.localized("Loading…"))
                .frame(maxWidth: .infinity, maxHeight: .infinity)
        } else if let failure = store.loadFailure, store.timeline.messages.isEmpty {
            ContentUnavailableView {
                Label(failure.userMessage(in: language), systemImage: "wifi.exclamationmark")
            } actions: {
                Button(language.localized("Try again")) {
                    Task { await store.reload() }
                }
                .buttonStyle(.glass)
            }
        } else {
            ScrollViewReader { proxy in
                messages(store, proxy: proxy)
                    .safeAreaInset(edge: .bottom, spacing: 0) {
                        if let selection {
                            selectionBar(store, selection)
                        } else {
                            composer(store)
                        }
                    }
            }
        }
    }

    private func messages(_ store: WhatsappConversationStore, proxy: ScrollViewProxy) -> some View {
        let isGroup = chat?.isGroup ?? store.timeline.messages.contains { $0.sender != nil }

        return ScrollView {
            LazyVStack(spacing: 0) {
                if store.timeline.hasOlder {
                    Button {
                        Task { await store.loadOlder() }
                    } label: {
                        if store.isLoadingOlder {
                            ProgressView()
                        } else {
                            Text(verbatim: language.localized("Load older messages"))
                                .font(.footnote.weight(.medium))
                        }
                    }
                    .buttonStyle(.plain)
                    .padding(.horizontal, 12)
                    .padding(.vertical, 6)
                    .background(WhatsappPalette.incoming, in: .capsule)
                    .padding(.vertical, 8)
                    .accessibilityIdentifier("whatsapp.conversation.older")
                }

                if store.timeline.messages.isEmpty, store.timeline.outgoing.isEmpty {
                    ContentUnavailableView(
                        language.localized("No messages yet"),
                        systemImage: "bubble.left",
                        description: language.text("Say something below.")
                    )
                    .padding(.top, 40)
                }

                ForEach(Array(store.timeline.messages.enumerated()), id: \.element.id) { index, message in
                    let previous = index > 0 ? store.timeline.messages[index - 1] : nil
                    let startsDay = previous.map { !Calendar.current.isDate($0.sentAt, inSameDayAs: message.sentAt) } ?? true
                    let startsRun = startsDay || previous?.fromMe != message.fromMe || previous?.sender != message.sender

                    if startsDay {
                        WhatsappDaySeparator(date: message.sentAt)
                    }

                    selectable(message) {
                        WhatsappMessageBubble(
                            message: message,
                            showsSender: isGroup && !message.fromMe && startsRun,
                            hasTail: startsRun,
                            onMediaFailed: { Task { await store.reload() } },
                            onReactionTapped: { reaction in Task { await store.react(to: message, with: reaction.emoji) } },
                            onQuoteTapped: { messageId in jumpTo = messageId },
                            onContactMessage: { number in Task { await openChat(with: number) } }
                        )
                    }
                    .padding(.vertical, highlighted == message.id ? 2 : 0)
                    .background(highlighted == message.id ? WhatsappPalette.send.opacity(0.22) : Color.clear, in: .rect(cornerRadius: 8))
                    .padding(.top, startsRun && !startsDay ? 8 : 2)
                    .id(message.id)
                }

                ForEach(Array(store.timeline.outgoing.enumerated()), id: \.offset) { index, delivery in
                    WhatsappOutgoingBubble(delivery: delivery, hasTail: index == 0 && store.timeline.messages.last?.fromMe != true)
                        .padding(.top, 2)
                }
            }
            .padding(.horizontal, 10)
            .padding(.vertical, 8)
        }
        .defaultScrollAnchor(.bottom)
        .scrollDismissesKeyboard(.interactively)
        .onChange(of: jumpTo) { _, target in
            if let target {
                Task { await scroll(to: target, store: store, proxy: proxy) }
            }
        }
    }

    /// A message with its long-press actions or, while picking messages, a
    /// tap that picks it and a check on its left.
    @ViewBuilder
    private func selectable(_ message: WhatsappConversationMessage, @ViewBuilder bubble: () -> some View) -> some View {
        if let selection {
            let isPicked = selection.contains(message.friendlyId)

            HStack(spacing: 8) {
                Image(systemName: isPicked ? "checkmark.circle.fill" : "circle")
                    .font(.title3)
                    .foregroundStyle(isPicked ? WhatsappPalette.send : WhatsappPalette.meta)

                bubble()
                    .allowsHitTesting(false)
            }
            .contentShape(.rect)
            .background(isPicked ? WhatsappPalette.send.opacity(0.12) : Color.clear)
            .onTapGesture {
                if isPicked {
                    self.selection?.remove(message.friendlyId)
                } else {
                    self.selection?.insert(message.friendlyId)
                }
            }
            .accessibilityAddTraits(isPicked ? .isSelected : [])
        } else {
            bubble()
                .contextMenu {
                    if !message.revoked {
                        reactionChoices(message)

                        Button(language.localized("Reply"), systemImage: "arrowshape.turn.up.left") {
                            replyingTo = message
                            isComposerFocused = true
                        }
                        .accessibilityIdentifier("whatsapp.conversation.reply")
                    }

                    if let body = message.body, !body.isEmpty {
                        Button(language.localized("Copy"), systemImage: "doc.on.doc") {
                            UIPasteboard.general.string = body
                        }
                    }

                    if message.canEdit, !message.revoked {
                        Button(language.localized("Edit"), systemImage: "pencil") {
                            sheet = .edit(message)
                        }
                        .accessibilityIdentifier("whatsapp.conversation.edit")
                    }

                    if !message.revoked {
                        Button(language.localized(message.starred ? "Unstar" : "Star"), systemImage: message.starred ? "star.slash" : "star") {
                            Task { await store?.toggleStar(message) }
                        }
                        .accessibilityIdentifier("whatsapp.conversation.star")
                    }

                    if message.fromMe {
                        Button(language.localized("Message info"), systemImage: "info.circle") {
                            sheet = .messageInfo(message)
                        }
                        .accessibilityIdentifier("whatsapp.conversation.message-info")
                    }

                    Menu(language.localized("Draft a reply with AI"), systemImage: "sparkles") {
                        draftChoices([message.friendlyId])
                    }
                    if message.isForwardable, let accountId = chat?.account.friendlyId {
                        Button(language.localized("Forward"), systemImage: "arrowshape.turn.up.right") {
                            sheet = .forward([message], accountId: accountId)
                        }
                        .accessibilityIdentifier("whatsapp.conversation.forward")
                    }
                    Button(language.localized("Remind me"), systemImage: "bell") {
                        sheet = .reminder([message])
                    }
                    Button(language.localized("Select"), systemImage: "checkmark.circle") {
                        selection = [message.friendlyId]
                    }

                    if message.canDeleteForEveryone, !message.revoked {
                        Button(language.localized("Delete for everyone"), systemImage: "trash", role: .destructive) {
                            messageToDelete = message
                        }
                        .accessibilityIdentifier("whatsapp.conversation.delete")
                    }
                }
        }
    }

    /// WhatsApp's row of quick reactions on a long press (the number's own
    /// marked), every other emoji, and taking the reaction back.
    @ViewBuilder
    private func reactionChoices(_ message: WhatsappConversationMessage) -> some View {
        Picker(language.localized("React"), selection: Binding<String?> { message.myReaction } set: { emoji in
            if let emoji {
                Task { await store?.react(to: message, with: emoji) }
            }
        }) {
            ForEach(WhatsappEmojis.quick, id: \.self) { emoji in
                Text(verbatim: emoji)
                    .tag(Optional(emoji))
            }
        }
        .pickerStyle(.palette)
        .accessibilityIdentifier("whatsapp.conversation.react")

        Button(language.localized("More emojis"), systemImage: "face.smiling") {
            sheet = .react(message)
        }

        if let mine = message.myReaction {
            Button(language.localized("Remove reaction"), systemImage: "xmark.circle") {
                Task { await store?.react(to: message, with: mine) }
            }
        }

        Divider()
    }

    /// While picking messages: how many, forwarding them, the AI's answer to
    /// them and a reminder quoting them all.
    private func selectionBar(_ store: WhatsappConversationStore, _ selection: Set<String>) -> some View {
        let picked = store.timeline.messages.filter { selection.contains($0.friendlyId) }

        return HStack(spacing: 12) {
            Text(verbatim: language.localized("%lld selected", picked.count))
                .font(.subheadline.weight(.medium))

            Spacer()

            Button(language.localized("Forward"), systemImage: "arrowshape.turn.up.right") {
                if let accountId = chat?.account.friendlyId {
                    sheet = .forward(picked.filter(\.isForwardable), accountId: accountId)
                }
            }
            .labelStyle(.iconOnly)
            .disabled(!canForward(picked))
            .accessibilityIdentifier("whatsapp.conversation.forward-selected")

            Button(language.localized("Remind me"), systemImage: "bell") {
                sheet = .reminder(picked)
                self.selection = nil
            }
            .labelStyle(.iconOnly)
            .disabled(picked.isEmpty)

            Menu {
                draftChoices(picked.map(\.friendlyId))
            } label: {
                if store.isDrafting {
                    ProgressView()
                } else {
                    Label(language.localized("Draft a reply with AI"), systemImage: "sparkles")
                }
            }
            .buttonStyle(.glassProminent)
            .tint(WhatsappPalette.send)
            .disabled(picked.isEmpty || store.isDrafting)
            .accessibilityIdentifier("whatsapp.conversation.draft-selected")
        }
        .padding(.horizontal, 12)
        .padding(.vertical, 10)
        .background(WhatsappPalette.bar.ignoresSafeArea(edges: .bottom))
    }

    /// Something to forward, no more than one forward takes, and the chat's
    /// number known (only its chats can receive them). Deleted messages and
    /// ones without a text or a file are left out.
    private func canForward(_ picked: [WhatsappConversationMessage]) -> Bool {
        let forwardable = picked.filter(\.isForwardable).count

        return chat != nil && forwardable > 0 && forwardable <= WhatsappForwardLimits.messages
    }

    /// The members matching the `@` being typed in a group, if any.
    private var mentionSuggestions: [WhatsappGroup.Member] {
        guard let groupMembers, let query = WhatsappMentions.query(in: text) else {
            return []
        }

        return Array(WhatsappMentions.suggestions(for: query, in: groupMembers).prefix(6))
    }

    /// WhatsApp's composer: the message it answers over it, the members to
    /// mention while typing `@` in a group, a + with the AI's answer,
    /// scheduling, a poll, a place, contacts and a sticker, the message in a
    /// rounded field, and a round send button beside it.
    private func composer(_ store: WhatsappConversationStore) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            let suggestions = mentionSuggestions

            if !suggestions.isEmpty {
                WhatsappMentionSuggestions(members: suggestions) { member in
                    text = WhatsappMentions.inserting(member, into: text)
                }
            }

            if let replyingTo {
                WhatsappReplyBar(message: replyingTo) {
                    self.replyingTo = nil
                }
            }

            if let failure = store.sendFailure {
                Text(verbatim: failure.firstError(for: "text") ?? failure.userMessage(in: language))
                    .font(.caption)
                    .foregroundStyle(.red)
                    .padding(.horizontal, 8)
                    .accessibilityIdentifier("whatsapp.conversation.send-error")
            }

            HStack(alignment: .bottom, spacing: 6) {
                Menu {
                    draftChoices([])
                    Button(language.localized("Schedule message"), systemImage: "calendar.badge.clock") {
                        sheet = .schedule(text)
                    }

                    Section {
                        Button(language.localized("Poll"), systemImage: "chart.bar.xaxis") {
                            sheet = .poll
                        }
                        .accessibilityIdentifier("whatsapp.conversation.poll-new")
                        Button(language.localized("Location"), systemImage: "mappin.and.ellipse") {
                            sheet = .location
                        }
                        .accessibilityIdentifier("whatsapp.conversation.location-new")
                        if let accountId = chat?.account.friendlyId {
                            Button(language.localized("Contact"), systemImage: "person.crop.circle") {
                                sheet = .contacts(accountId: accountId)
                            }
                            .accessibilityIdentifier("whatsapp.conversation.contact-new")
                        }
                        Button(language.localized("Sticker"), systemImage: "face.smiling.inverse") {
                            isPickingSticker = true
                        }
                        .accessibilityIdentifier("whatsapp.conversation.sticker-new")
                    }
                } label: {
                    Group {
                        if store.isDrafting || store.isSendingContent {
                            ProgressView()
                        } else {
                            Image(systemName: "plus")
                                .font(.system(size: 20, weight: .medium))
                                .foregroundStyle(WhatsappPalette.meta)
                        }
                    }
                    .frame(width: 36, height: 44)
                }
                .disabled(store.isDrafting || store.isSendingContent)
                .accessibilityLabel(language.text("More"))
                .accessibilityIdentifier("whatsapp.conversation.plus")

                TextField(language.localized("Message"), text: $text, axis: .vertical)
                    .lineLimit(1...6)
                    .focused($isComposerFocused)
                    .padding(.horizontal, 16)
                    .padding(.vertical, 10)
                    .background(WhatsappPalette.incoming, in: .rect(cornerRadius: 22))
                    .onChange(of: text) { _, newValue in
                        if !newValue.isEmpty {
                            store.userTyped()
                        }

                        if groupMembers == nil, chat?.isGroup == true, WhatsappMentions.query(in: newValue) != nil {
                            Task { await loadGroupMembers() }
                        }
                    }
                    .accessibilityIdentifier("whatsapp.conversation.text")

                Button {
                    let sending = text
                    let answering = replyingTo?.friendlyId

                    Task {
                        if await store.send(sending, replyTo: answering) {
                            text = ""

                            if replyingTo?.friendlyId == answering {
                                replyingTo = nil
                            }
                        }
                    }
                } label: {
                    Group {
                        if store.isSending {
                            ProgressView()
                                .tint(.white)
                        } else {
                            Image(systemName: "paperplane.fill")
                                .font(.system(size: 18, weight: .semibold))
                                .foregroundStyle(.white)
                        }
                    }
                    .frame(width: 44, height: 44)
                    .background(WhatsappPalette.send, in: .circle)
                }
                .buttonStyle(.plain)
                .disabled(text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty || store.isSending)
                .opacity(text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty ? 0.5 : 1)
                .accessibilityLabel(language.text("Send"))
                .accessibilityIdentifier("whatsapp.conversation.send")
            }
        }
        .padding(.horizontal, 8)
        .padding(.top, 6)
        .padding(.bottom, 8)
        .background(WhatsappPalette.bar.ignoresSafeArea(edges: .bottom))
    }
}

/// What the conversation opens over itself.
enum WhatsappConversationSheet: Identifiable {
    case info
    /// A reminder about the chat (no messages), or some of its messages.
    case reminder([WhatsappConversationMessage])
    /// Schedules the text (what the composer had).
    case schedule(String)
    /// Every emoji, to react to the message with.
    case react(WhatsappConversationMessage)
    /// Sends the messages on to other chats of the number.
    case forward([WhatsappConversationMessage], accountId: String)
    /// A new text for a message the number sent.
    case edit(WhatsappConversationMessage)
    /// How far a message the number sent got.
    case messageInfo(WhatsappConversationMessage)
    /// Searching the chat, its starred messages or its gallery.
    case browse(WhatsappChatBrowserTab)
    case poll
    case location
    /// Contact cards of the number's contacts.
    case contacts(accountId: String)

    var id: String {
        switch self {
        case .info: "info"
        case .reminder(let messages): "reminder-\(messages.map(\.friendlyId).joined(separator: ","))"
        case .schedule: "schedule"
        case .react(let message): "react-\(message.friendlyId)"
        case .forward(let messages, _): "forward-\(messages.map(\.friendlyId).joined(separator: ","))"
        case .edit(let message): "edit-\(message.friendlyId)"
        case .messageInfo(let message): "message-info-\(message.friendlyId)"
        case .browse(let tab): "browse-\(tab.rawValue)"
        case .poll: "poll"
        case .location: "location"
        case .contacts: "contacts"
        }
    }
}

/// WhatsApp's colors on the phone, light and dark: the chat wallpaper, the
/// bubbles (the contact's and the number's own), the bars and the send
/// button.
enum WhatsappPalette {
    static let wallpaper = dynamic(light: 0xEFEAE2, dark: 0x0B141A)
    static let incoming = dynamic(light: 0xFFFFFF, dark: 0x202C33)
    static let outgoing = dynamic(light: 0xD9FDD3, dark: 0x005C4B)
    static let bar = dynamic(light: 0xF6F5F3, dark: 0x1F2C34)
    static let meta = dynamic(light: 0x667781, dark: 0x8696A0)
    static let send = Color(rgb: 0x00A884)
    static let read = Color(rgb: 0x53BDEB)

    /// The colors WhatsApp gives the members of a group, by name.
    private static let senders: [Color] = [0xE542A3, 0x1F7AEC, 0xD3963F, 0x35CD96, 0x6BCBEF, 0xA62C71, 0xFA6533, 0x7F66FF].map(Color.init(rgb:))

    static func sender(_ name: String) -> Color {
        let sum = name.unicodeScalars.reduce(0) { ($0 &* 31 &+ Int($1.value)) & 0x7FFF_FFFF }

        return senders[sum % senders.count]
    }

    private static func dynamic(light: UInt32, dark: UInt32) -> Color {
        Color(UIColor { traits in
            UIColor(rgb: traits.userInterfaceStyle == .dark ? dark : light)
        })
    }
}

private extension UIColor {
    convenience init(rgb: UInt32) {
        self.init(
            red: CGFloat((rgb >> 16) & 0xFF) / 255,
            green: CGFloat((rgb >> 8) & 0xFF) / 255,
            blue: CGFloat(rgb & 0xFF) / 255,
            alpha: 1
        )
    }
}

private extension Color {
    init(rgb: UInt32) {
        self.init(uiColor: UIColor(rgb: rgb))
    }
}

/// The bubble shape: rounded, with the corner at the side of who sent it
/// sharp on the first message of a run, as WhatsApp's tail.
private struct WhatsappBubbleShape: Shape {
    let fromMe: Bool
    let hasTail: Bool

    func path(in rect: CGRect) -> Path {
        let radius: CGFloat = 10
        let tail: CGFloat = hasTail ? 2 : radius

        return UnevenRoundedRectangle(
            topLeadingRadius: fromMe ? radius : tail,
            bottomLeadingRadius: radius,
            bottomTrailingRadius: radius,
            topTrailingRadius: fromMe ? tail : radius
        )
        .path(in: rect)
    }
}

private struct WhatsappDaySeparator: View {
    let date: Date

    @Environment(\.appLanguage) private var language

    var body: some View {
        Text(verbatim: label)
            .font(.caption.weight(.medium))
            .foregroundStyle(WhatsappPalette.meta)
            .padding(.horizontal, 10)
            .padding(.vertical, 5)
            .background(WhatsappPalette.incoming, in: .rect(cornerRadius: 8))
            .shadow(color: .black.opacity(0.06), radius: 0.5, y: 0.5)
            .padding(.vertical, 10)
    }

    private var label: String {
        if Calendar.current.isDateInToday(date) {
            return language.localized("Today")
        }

        if Calendar.current.isDateInYesterday(date) {
            return language.localized("Yesterday")
        }

        return date.formatted(date: .complete, time: .omitted)
    }
}

/// The time and, for the number's own, the ticks, at the bottom right of a
/// bubble, after "Edited", the pin and the star when they apply.
private struct WhatsappBubbleMeta: View {
    let sentAt: Date
    let receipt: WhatsappConversationMessage.Receipt?
    let isPending: Bool
    /// Over a photo without a caption: white on a dark capsule, as WhatsApp.
    var isOverMedia = false
    var isEdited = false
    var isPinned = false
    var isStarred = false

    @Environment(\.appLanguage) private var language

    /// The room the text leaves for it at its end, in figure spaces.
    static func room(fromMe: Bool, isEdited: Bool, isPinned: Bool, isStarred: Bool) -> String {
        let count = (fromMe ? 11 : 8) + (isEdited ? 8 : 0) + (isPinned ? 3 : 0) + (isStarred ? 3 : 0)

        return String(repeating: "\u{2007}", count: count)
    }

    var body: some View {
        HStack(spacing: 3) {
            if isEdited {
                Text(verbatim: language.localized("Edited"))
                    .italic()
            }

            if isPinned {
                Image(systemName: "pin.fill")
                    .accessibilityLabel(language.text("Pinned"))
            }

            if isStarred {
                Image(systemName: "star.fill")
                    .accessibilityLabel(language.text("Starred"))
            }

            Text(sentAt, format: .dateTime.hour().minute())

            if isPending {
                Image(systemName: "clock")
            } else if let receipt {
                WhatsappReceiptTicks(receipt: receipt)
            }
        }
        .font(.system(size: 11))
        .foregroundStyle(isOverMedia ? AnyShapeStyle(.white) : AnyShapeStyle(WhatsappPalette.meta))
        .padding(.horizontal, isOverMedia ? 6 : 0)
        .padding(.vertical, isOverMedia ? 2 : 0)
        .background {
            if isOverMedia {
                Capsule().fill(.black.opacity(0.35))
            }
        }
    }
}

/// One message: a bubble on the right for the number's own, on the left for
/// the contact's (with the sender in a group), its media, its text, and the
/// time and ticks inside it at the bottom right.
private struct WhatsappMessageBubble: View {
    let message: WhatsappConversationMessage
    let showsSender: Bool
    let hasTail: Bool
    let onMediaFailed: () -> Void
    let onReactionTapped: (WhatsappConversationMessage.Reaction) -> Void
    /// Goes to the quoted message (by its friendly id).
    let onQuoteTapped: (String) -> Void
    /// Opens the chat with a contact card's number.
    let onContactMessage: (String) -> Void

    @Environment(\.appLanguage) private var language

    var body: some View {
        HStack(spacing: 0) {
            if message.fromMe {
                Spacer(minLength: 56)
            }

            // The reactions hang over the bubble's bottom edge, as on WhatsApp.
            VStack(alignment: message.fromMe ? .trailing : .leading, spacing: -4) {
                if message.isSticker, let media = message.media, !message.revoked {
                    sticker(media)
                } else {
                    bubble
                }

                if !message.reactions.isEmpty {
                    WhatsappReactionChips(reactions: message.reactions, onTap: onReactionTapped)
                        .padding(.horizontal, 8)
                }
            }

            if !message.fromMe {
                Spacer(minLength: 56)
            }
        }
    }

    private var meta: WhatsappBubbleMeta {
        WhatsappBubbleMeta(
            sentAt: message.sentAt,
            receipt: message.fromMe ? message.receipt : nil,
            isPending: false,
            isEdited: message.edited,
            isPinned: message.pinned,
            isStarred: message.starred
        )
    }

    /// A place, contact cards or a poll, in place of a text.
    private var hasCard: Bool {
        message.location != nil || message.contacts?.isEmpty == false || message.poll != nil
    }

    @ViewBuilder
    private var senderName: some View {
        if showsSender, let sender = message.sender {
            Text(verbatim: sender)
                .font(.footnote.weight(.semibold))
                .foregroundStyle(WhatsappPalette.sender(sender))
                .lineLimit(1)
                .padding(.horizontal, 4)
        }
    }

    @ViewBuilder
    private var quote: some View {
        if let quoted = message.quoted {
            WhatsappQuotedBlock(quoted: quoted, onTap: quoted.friendlyId.map { id in { onQuoteTapped(id) } })
                .frame(minWidth: 160)
        }
    }

    /// A sticker on the wallpaper, with what it answers over it and its
    /// time under it.
    private func sticker(_ media: WhatsappConversationMessage.Media) -> some View {
        VStack(alignment: message.fromMe ? .trailing : .leading, spacing: 3) {
            senderName
            quote
                .padding(5)
                .background(message.fromMe ? WhatsappPalette.outgoing : WhatsappPalette.incoming, in: .rect(cornerRadius: 10))
            WhatsappStickerView(media: media, onFailed: onMediaFailed)
            WhatsappBubbleMeta(
                sentAt: message.sentAt,
                receipt: message.fromMe ? message.receipt : nil,
                isPending: false,
                isOverMedia: true,
                isPinned: message.pinned,
                isStarred: message.starred
            )
        }
        .accessibilityElement(children: .combine)
    }

    private var bubble: some View {
        VStack(alignment: .leading, spacing: 3) {
            senderName

            if message.forwarded {
                WhatsappForwardedLabel()
            }

            quote

            if let media = message.media {
                WhatsappMediaView(media: media, messageId: message.friendlyId, onFailed: onMediaFailed)
                    .clipShape(.rect(cornerRadius: 7))
                    .overlay(alignment: .bottomTrailing) {
                        if hasMetaOverMedia {
                            WhatsappBubbleMeta(sentAt: message.sentAt, receipt: message.fromMe ? message.receipt : nil, isPending: false, isOverMedia: true, isEdited: message.edited, isPinned: message.pinned, isStarred: message.starred)
                                .padding(6)
                        }
                    }
            }

            if let location = message.location {
                WhatsappLocationCard(location: location)
            }

            if let contacts = message.contacts, !contacts.isEmpty {
                WhatsappContactCards(contacts: contacts, onMessage: onContactMessage)
            }

            if let poll = message.poll {
                WhatsappPollView(poll: poll)
            }

            if message.revoked {
                Label(language.localized("Deleted on WhatsApp"), systemImage: "nosign")
                    .font(.caption.italic())
                    .foregroundStyle(WhatsappPalette.meta)
                    .padding(.horizontal, 4)
            }

            textAndMeta
        }
        .padding(message.media == nil && message.location == nil ? EdgeInsets(top: 6, leading: 5, bottom: 6, trailing: 5) : EdgeInsets(top: 3, leading: 3, bottom: hasMetaOverMedia ? 3 : 5, trailing: 3))
        .background(message.fromMe ? WhatsappPalette.outgoing : WhatsappPalette.incoming, in: WhatsappBubbleShape(fromMe: message.fromMe, hasTail: hasTail))
        .shadow(color: .black.opacity(0.08), radius: 0.5, y: 1)
        .accessibilityElement(children: .combine)
    }

    /// A photo or video without a caption carries the time over itself.
    private var hasMetaOverMedia: Bool {
        guard let kind = message.media?.kind, kind == .image || kind == .video, !message.revoked else {
            return false
        }

        return message.body?.isEmpty ?? true
    }

    /// The text with room left at its end for the time, which sits over the
    /// bottom right corner, as WhatsApp lays it out.
    @ViewBuilder
    private var textAndMeta: some View {
        let text = message.body.flatMap { $0.isEmpty ? nil : $0 }
        let meta = self.meta
        let room = WhatsappBubbleMeta.room(fromMe: message.fromMe, isEdited: message.edited, isPinned: message.pinned, isStarred: message.starred)

        if let text {
            ZStack(alignment: .bottomTrailing) {
                (Text(verbatim: text) + Text(verbatim: room).font(.system(size: 11)))
                    .font(.body)
                    .foregroundStyle(.primary)
                    .fixedSize(horizontal: false, vertical: true)

                meta
                    .offset(y: 3)
            }
            .padding(.horizontal, 4)
        } else if message.media == nil, !hasCard {
            ZStack(alignment: .bottomTrailing) {
                (Text(verbatim: WhatsappMessageText.preview(of: message, in: language)).italic() + Text(verbatim: room).font(.system(size: 11)))
                    .font(.body)
                    .foregroundStyle(.secondary)

                meta
                    .offset(y: 3)
            }
            .padding(.horizontal, 4)
        } else if !hasMetaOverMedia {
            HStack {
                Spacer(minLength: 0)
                meta
            }
            .padding(.horizontal, 4)
        }
    }
}

/// WhatsApp's ticks: a clock while waiting, one tick sent, two delivered,
/// two blue read or played, a warning when it failed.
struct WhatsappReceiptTicks: View {
    let receipt: WhatsappConversationMessage.Receipt

    @Environment(\.appLanguage) private var language

    var body: some View {
        Group {
            switch receipt {
            case .failed:
                Image(systemName: "exclamationmark.circle")
                    .foregroundStyle(.red)
            case .pending:
                Image(systemName: "clock")
            case .sent:
                Image(systemName: "checkmark")
            case .delivered:
                doubleTick
            case .read, .played:
                doubleTick
                    .foregroundStyle(WhatsappPalette.read)
            }
        }
        .font(.system(size: 11, weight: .semibold))
        .accessibilityLabel(language.text(label))
    }

    private var doubleTick: some View {
        HStack(spacing: -7) {
            Image(systemName: "checkmark")
            Image(systemName: "checkmark")
        }
    }

    private var label: String {
        switch receipt {
        case .failed: "Not sent"
        case .pending: "Sending"
        case .sent: "Sent"
        case .delivered: "Delivered"
        case .read, .played: "Read"
        }
    }
}

/// A text sent from Jarvis that WhatsApp did not report yet, or that failed.
private struct WhatsappOutgoingBubble: View {
    let delivery: WhatsappDelivery
    let hasTail: Bool

    @Environment(\.appLanguage) private var language

    var body: some View {
        HStack(spacing: 0) {
            Spacer(minLength: 56)

            VStack(alignment: .trailing, spacing: 3) {
                ZStack(alignment: .bottomTrailing) {
                    (Text(verbatim: delivery.body) + Text(verbatim: String(repeating: "\u{2007}", count: 9)).font(.system(size: 11)))
                        .font(.body)
                        .fixedSize(horizontal: false, vertical: true)

                    if delivery.status != .failed {
                        WhatsappBubbleMeta(sentAt: delivery.createdAt ?? .now, receipt: nil, isPending: true)
                            .offset(y: 3)
                    }
                }

                if delivery.status == .failed {
                    Label(delivery.errorMessage ?? language.localized("Not sent"), systemImage: "exclamationmark.circle")
                        .font(.caption)
                        .foregroundStyle(.red)
                }
            }
            .padding(.horizontal, 9)
            .padding(.vertical, 6)
            .background(WhatsappPalette.outgoing, in: WhatsappBubbleShape(fromMe: true, hasTail: hasTail))
            .shadow(color: .black.opacity(0.08), radius: 0.5, y: 1)
            .opacity(delivery.status == .failed ? 1 : 0.85)
            .accessibilityElement(children: .combine)
        }
    }
}
