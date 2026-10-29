import Localization
import Networking
import SwiftUI

/// A chat's own setting that may follow its number's default (nil), as the
/// web settings' "follow the number / on / off".
enum WhatsappFollowSetting: Hashable, CaseIterable {
    case follow
    case on
    case off

    init(_ value: Bool?) {
        self = switch value {
        case nil: .follow
        case true?: .on
        case false?: .off
        }
    }

    var value: Bool? {
        switch self {
        case .follow: nil
        case .on: true
        case .off: false
        }
    }
}

/// The chat's info and tools, as WhatsApp's contact info and the web's chat
/// menu: searching it, its starred messages and its media, links and docs
/// (a pick goes to the message, `onJump`), its nickname, priority and tags,
/// muting and archiving it, a contact's number and blocking it, a group's
/// info, settings and members (`WhatsappGroupInfoSections`), what reaches
/// WhatsApp from it and whether it is pushed, its scheduled messages
/// (cancel) and open reminders, and syncing it again. Each change is saved
/// at once.
struct WhatsappChatInfoView: View {
    let chatId: String
    var onJump: (String) -> Void = { _ in }
    let onChanged: (WhatsappConversation) -> Void

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language
    @Environment(\.dismiss) private var dismiss

    @State private var details: WhatsappChatDetails?
    @State private var tags: [WhatsappConversation.Tag] = []
    @State private var colors: WhatsappPriorityColors?
    @State private var nickname = ""
    @State private var loadFailure: APIError?
    @State private var actionFailure: APIError?
    @State private var syncedMessage: String?
    @State private var isSyncing = false
    @State private var scheduledToCancel: WhatsappChatDetails.Scheduled?
    @State private var isConfirmingBlock = false

    var body: some View {
        NavigationStack {
            Group {
                if let details {
                    form(details)
                } else if let loadFailure {
                    ContentUnavailableView {
                        Label(loadFailure.userMessage(in: language), systemImage: "wifi.exclamationmark")
                    } actions: {
                        Button(language.localized("Try again")) {
                            Task { await load() }
                        }
                        .buttonStyle(.glass)
                    }
                } else {
                    ProgressView(language.localized("Loading…"))
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                }
            }
            .navigationTitle(language.localized("Chat info"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button(language.localized("Done"), role: .confirm) {
                        dismiss()
                    }
                }
            }
            .task {
                await load()
            }
            .confirmationDialog(
                language.localized("Cancel this message?"),
                isPresented: Binding { scheduledToCancel != nil } set: { if !$0 { scheduledToCancel = nil } },
                titleVisibility: .visible,
                presenting: scheduledToCancel
            ) { message in
                Button(language.localized("Cancel message"), role: .destructive) {
                    Task {
                        do throws(APIError) {
                            try await model.client.send(API.cancelWhatsappMessage(id: message.friendlyId))
                        } catch {
                            actionFailure = error
                        }

                        await load()
                    }
                }
                Button(language.localized("Keep"), role: .cancel) {}
            }
            .alert(
                language.localized("Sync"),
                isPresented: Binding { syncedMessage != nil } set: { if !$0 { syncedMessage = nil } }
            ) {
                Button(language.localized("OK")) {}
            } message: {
                Text(verbatim: syncedMessage ?? "")
            }
            .confirmationDialog(
                language.localized(details?.data.blocked == true ? "Unblock this contact?" : "Block this contact?"),
                isPresented: $isConfirmingBlock,
                titleVisibility: .visible
            ) {
                let blocked = details?.data.blocked == true

                Button(language.localized(blocked ? "Unblock" : "Block"), role: blocked ? nil : .destructive) {
                    var update = WhatsappConversationUpdate()
                    update.blocked = .set(!blocked)

                    Task { await save(update) }
                }
                Button(language.localized("Cancel"), role: .cancel) {}
            } message: {
                if details?.data.blocked != true {
                    Text(verbatim: language.localized("Blocked contacts cannot call or send messages to this number."))
                }
            }
            .actionFailureAlert($actionFailure)
        }
    }

    /// Searching the chat, its starred messages and its media, links and
    /// docs; a pick closes the info and goes to the message.
    private var browseSection: some View {
        Section {
            NavigationLink {
                WhatsappChatBrowser(chatId: chatId, tab: .search, onPick: jump)
            } label: {
                Label(language.localized("Search in chat"), systemImage: "magnifyingglass")
            }
            .accessibilityIdentifier("whatsapp.info.search")
            NavigationLink {
                WhatsappChatBrowser(chatId: chatId, tab: .starred, onPick: jump)
            } label: {
                Label(language.localized("Starred messages"), systemImage: "star")
            }
            .accessibilityIdentifier("whatsapp.info.starred")
            NavigationLink {
                WhatsappChatBrowser(chatId: chatId, tab: .media, onPick: jump)
            } label: {
                Label(language.localized("Media, links and docs"), systemImage: "photo.on.rectangle")
            }
            .accessibilityIdentifier("whatsapp.info.media")
        }
    }

    private func jump(to messageId: String) {
        dismiss()
        onJump(messageId)
    }

    /// Muting (8 hours, a week, always) and archiving.
    private func muteAndArchive(_ chat: WhatsappConversation) -> some View {
        Group {
            Menu {
                WhatsappMuteChoices(chat: chat) { mute in
                    var update = WhatsappConversationUpdate()
                    update.muted = .set(mute)

                    Task { await save(update) }
                }
            } label: {
                LabeledContent {
                    Text(verbatim: WhatsappMuteText.state(of: chat, in: language))
                } label: {
                    Label(language.localized("Mute notifications"), systemImage: chat.isMuted() ? "bell.slash" : "bell")
                        .foregroundStyle(.primary)
                }
            }
            .accessibilityIdentifier("whatsapp.info.mute")

            Toggle(isOn: Binding { chat.archived } set: { archived in
                var update = WhatsappConversationUpdate()
                update.archived = .set(archived)

                Task { await save(update) }
            }) {
                Label(language.localized("Archived"), systemImage: "archivebox")
            }
            .accessibilityIdentifier("whatsapp.info.archived")
        }
    }

    /// A contact's number (copied on a tap) and blocking it.
    @ViewBuilder
    private func contactSection(_ chat: WhatsappConversation) -> some View {
        Section {
            if let phoneNumber = chat.phoneNumber {
                Button {
                    UIPasteboard.general.string = phoneNumber
                } label: {
                    LabeledContent(language.localized("Number"), value: phoneNumber)
                        .foregroundStyle(.primary)
                }
                .accessibilityHint(language.text("Copies the number"))
            }

            Button(language.localized(chat.blocked ? "Unblock contact" : "Block contact"), systemImage: chat.blocked ? "hand.raised.slash" : "hand.raised", role: chat.blocked ? nil : .destructive) {
                isConfirmingBlock = true
            }
            .accessibilityIdentifier("whatsapp.info.block")
        } footer: {
            if chat.blocked {
                Text(verbatim: language.localized("This contact is blocked."))
            }
        }
    }

    private func form(_ details: WhatsappChatDetails) -> some View {
        let chat = details.data

        return Form {
            Section {
                VStack(spacing: 8) {
                    WhatsappChatAvatar(chat: chat, size: 88)
                    Text(verbatim: chat.name)
                        .font(.title2.weight(.semibold))
                    if let subtitle = subtitle(chat) {
                        Text(verbatim: subtitle)
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                    }
                }
                .frame(maxWidth: .infinity)
                .listRowBackground(Color.clear)
            }

            browseSection

            if chat.isGroup {
                WhatsappGroupInfoSections(chat: chat) { changed in
                    if let current = self.details, changed != current.data {
                        self.details = WhatsappChatDetails(data: changed, scheduled: current.scheduled, reminders: current.reminders)
                        onChanged(changed)
                    }
                } onLeft: {
                    dismiss()
                }
            } else {
                contactSection(chat)
            }

            Section {
                muteAndArchive(chat)
            }

            Section {
                TextField(language.localized("Nickname"), text: $nickname, prompt: Text(verbatim: chat.whatsappName ?? chat.name))
                    .submitLabel(.done)
                    .onSubmit {
                        let trimmed = nickname.trimmingCharacters(in: .whitespaces)
                        var update = WhatsappConversationUpdate()
                        update.nickname = .set(trimmed.isEmpty ? nil : trimmed)

                        Task { await save(update) }
                    }
                    .accessibilityIdentifier("whatsapp.info.nickname")

                Toggle(language.localized("Pinned"), isOn: Binding { chat.pinned } set: { pinned in
                    var update = WhatsappConversationUpdate()
                    update.pinned = .set(pinned)

                    Task { await save(update) }
                })

                Picker(language.localized("Priority"), selection: Binding { chat.priority } set: { priority in
                    var update = WhatsappConversationUpdate()
                    update.priority = .set(priority)

                    Task { await save(update) }
                }) {
                    Text(verbatim: language.localized("None")).tag(Int?.none)
                    ForEach(WhatsappPriorityColors.priorities, id: \.self) { priority in
                        WhatsappPriorityLabel(priority: priority, colors: colors).tag(Optional(priority))
                    }
                }
            } footer: {
                Text(verbatim: language.localized("The nickname shows in place of the WhatsApp name, only for you."))
            }

            Section {
                ForEach(tags) { tag in
                    let isOn = chat.tags.contains { $0.id == tag.id }

                    Button {
                        var ids = chat.tags.map(\.friendlyId)
                        if isOn {
                            ids.removeAll { $0 == tag.friendlyId }
                        } else {
                            ids.append(tag.friendlyId)
                        }

                        var update = WhatsappConversationUpdate()
                        update.tags = .set(ids)

                        Task { await save(update) }
                    } label: {
                        HStack {
                            Circle().fill(Color(hex: tag.color) ?? .secondary).frame(width: 12, height: 12)
                            Text(verbatim: tag.name).foregroundStyle(.primary)
                            Spacer()
                            if isOn {
                                Image(systemName: "checkmark").foregroundStyle(.tint)
                            }
                        }
                    }
                }

                NavigationLink(language.localized("Manage tags")) {
                    WhatsappTagsManager()
                        .onDisappear { Task { await load() } }
                }
            } header: {
                language.text("Tags")
            }

            Section {
                followPicker("Read receipts", chat.settings?.sendsReadReceipts, effective: chat.settings?.effective.sendsReadReceipts) { update, value in
                    update.sendsReadReceipts = .set(value)
                }
                followPicker("Show typing", chat.settings?.sendsTyping, effective: chat.settings?.effective.sendsTyping) { update, value in
                    update.sendsTyping = .set(value)
                }
                followPicker("Notifications", chat.settings?.notifiesMessages, effective: chat.settings?.effective.notifiesMessages) { update, value in
                    update.notifiesMessages = .set(value)
                }

                NavigationLink(language.localized("Defaults of the numbers")) {
                    WhatsappAccountDefaultsView()
                        .onDisappear { Task { await load() } }
                }
            } header: {
                language.text("Settings")
            } footer: {
                Text(verbatim: language.localized("“Follow the number” uses the defaults of %@.", chat.account.nickname))
            }

            Section {
                if details.scheduled.isEmpty {
                    Text(verbatim: language.localized("No scheduled messages"))
                        .foregroundStyle(.secondary)
                }

                ForEach(details.scheduled) { message in
                    VStack(alignment: .leading, spacing: 3) {
                        if let media = message.media {
                            Label {
                                Text(verbatim: media.isAudio ? language.localized("Voice note") : (media.name ?? language.localized("File")))
                                    .lineLimit(1)
                            } icon: {
                                Image(systemName: media.isAudio ? "mic" : media.isImage ? "photo" : media.isVideo ? "video" : "doc")
                            }
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                        }
                        if !message.body.isEmpty {
                            Text(verbatim: message.body).lineLimit(2)
                        }
                        if let nextRunAt = message.nextRunAt {
                            Text(nextRunAt, format: .dateTime.day().month().hour().minute())
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                    }
                    .swipeActions {
                        Button(language.localized("Cancel message"), systemImage: "xmark.circle", role: .destructive) {
                            scheduledToCancel = message
                        }
                    }
                    .contextMenu {
                        Button(language.localized("Cancel message"), systemImage: "xmark.circle", role: .destructive) {
                            scheduledToCancel = message
                        }
                    }
                }
            } header: {
                language.text("Scheduled")
            }

            Section {
                if details.reminders.isEmpty {
                    Text(verbatim: language.localized("No open reminders"))
                        .foregroundStyle(.secondary)
                }

                ForEach(details.reminders) { reminder in
                    VStack(alignment: .leading, spacing: 3) {
                        Text(verbatim: reminder.title).lineLimit(2)
                        if let dueAt = reminder.dueAt {
                            Text(dueAt, format: .dateTime.day().month().hour().minute())
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                    }
                }
            } header: {
                language.text("Reminders")
            }

            Section {
                Button {
                    Task { await sync() }
                } label: {
                    HStack {
                        Label(language.localized("Sync messages"), systemImage: "arrow.triangle.2.circlepath")
                        if isSyncing {
                            Spacer()
                            ProgressView()
                        }
                    }
                }
                .disabled(isSyncing)
            }
        }
    }

    private func followPicker(_ key: String, _ value: Bool?, effective: Bool?, apply: @escaping (inout WhatsappConversationUpdate, Bool?) -> Void) -> some View {
        Picker(selection: Binding { WhatsappFollowSetting(value) } set: { setting in
            var update = WhatsappConversationUpdate()
            apply(&update, setting.value)

            Task { await save(update) }
        }) {
            Text(verbatim: followLabel(effective)).tag(WhatsappFollowSetting.follow)
            Text(verbatim: language.localized("On")).tag(WhatsappFollowSetting.on)
            Text(verbatim: language.localized("Off")).tag(WhatsappFollowSetting.off)
        } label: {
            Text(verbatim: language.localized(key))
        }
    }

    private func followLabel(_ effective: Bool?) -> String {
        guard let effective else {
            return language.localized("Follow the number")
        }

        return language.localized("Follow the number (%@)", language.localized(effective ? "On" : "Off"))
    }

    private func subtitle(_ chat: WhatsappConversation) -> String? {
        var parts = [chat.account.nickname]

        if chat.isGroup {
            parts.insert(language.localized("Group"), at: 0)
        } else if let phoneNumber = chat.phoneNumber {
            parts.insert(phoneNumber, at: 0)
        }

        if let whatsappName = chat.whatsappName, whatsappName != chat.name {
            parts.insert(whatsappName, at: 0)
        }

        return parts.joined(separator: " · ")
    }

    private func load() async {
        do throws(APIError) {
            let loaded = try await model.client.send(API.whatsappConversation(chatId: chatId))

            if details?.data.nickname != loaded.data.nickname || details == nil {
                nickname = loaded.data.nickname ?? ""
            }

            details = loaded
            loadFailure = nil
            onChanged(loaded.data)
        } catch .unauthenticated {
            return
        } catch {
            if details == nil {
                loadFailure = error
            }
        }

        tags = (try? await model.client.send(API.whatsappTags()).data) ?? tags
        colors = (try? await model.client.send(API.whatsappPriorityColors())) ?? colors
    }

    private func save(_ update: WhatsappConversationUpdate) async {
        do throws(APIError) {
            let chat = try await model.client.send(API.updateWhatsappConversation(chatId: chatId, update)).data

            if let details {
                self.details = WhatsappChatDetails(data: chat, scheduled: details.scheduled, reminders: details.reminders)
            }

            onChanged(chat)
            model.whatsapp.chatsEdited()
        } catch {
            actionFailure = error
        }
    }

    private func sync() async {
        isSyncing = true
        defer { isSyncing = false }

        do throws(APIError) {
            let synced = try await model.client.send(API.syncWhatsappConversation(chatId: chatId))

            syncedMessage = language.localized("%lld new messages.", synced.added)
            model.whatsapp.chatsEdited()
        } catch {
            actionFailure = error
        }
    }
}

/// "Priority 2" with its color.
struct WhatsappPriorityLabel: View {
    let priority: Int
    let colors: WhatsappPriorityColors?

    @Environment(\.appLanguage) private var language

    var body: some View {
        Label {
            Text(verbatim: language.localized("Priority %lld", priority))
        } icon: {
            Image(systemName: "circle.fill")
                .foregroundStyle(Color(hex: colors?.hex(of: priority)) ?? .orange)
        }
    }
}

/// Muting a chat for 8 hours, a week or always, or unmuting it.
struct WhatsappMuteChoices: View {
    let chat: WhatsappConversation
    let onPick: (WhatsappMute?) -> Void

    @Environment(\.appLanguage) private var language

    var body: some View {
        if chat.isMuted() {
            Button(language.localized("Unmute"), systemImage: "bell") {
                onPick(nil)
            }
        } else {
            ForEach(WhatsappMute.allCases, id: \.self) { mute in
                Button(WhatsappMuteText.duration(mute, in: language)) {
                    onPick(mute)
                }
            }
        }
    }
}

enum WhatsappMuteText {
    static func duration(_ mute: WhatsappMute, in language: AppLanguage) -> String {
        switch mute {
        case .eightHours: language.localized("8 hours")
        case .oneWeek: language.localized("1 week")
        case .always: language.localized("Always")
        }
    }

    /// "Off", "Always", or until when.
    static func state(of chat: WhatsappConversation, in language: AppLanguage) -> String {
        guard chat.isMuted(), let until = chat.mutedUntil else {
            return language.localized("Off")
        }

        if until.timeIntervalSinceNow > 365 * 86_400 {
            return language.localized("Always")
        }

        return language.localized("Until %@", until.formatted(date: .abbreviated, time: .shortened))
    }
}
