import Localization
import Networking
import SwiftUI

/// Starts a chat with a number on one of the user's connected numbers, as
/// the web "new chat"; the chat opens once the server has it.
struct WhatsappNewChatForm: View {
    let onStarted: (WhatsappConversation) -> Void

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language
    @Environment(\.dismiss) private var dismiss

    @State private var accounts: [WhatsappAccount]?
    @State private var accountId: String?
    @State private var number = ""
    @State private var isSaving = false
    @State private var failure: APIError?

    var body: some View {
        NavigationStack {
            Form {
                if let accounts {
                    let open = accounts.filter(\.isOpen)

                    if open.isEmpty {
                        Text(verbatim: language.localized("Connect a number on the web to start a chat."))
                            .foregroundStyle(.secondary)
                    } else {
                        Section {
                            if open.count > 1 {
                                Picker(language.localized("From"), selection: $accountId) {
                                    ForEach(open) { account in
                                        Text(verbatim: account.nickname).tag(Optional(account.friendlyId))
                                    }
                                }
                            }

                            TextField(language.localized("Phone number with area code"), text: $number)
                                .keyboardType(.phonePad)
                                .textContentType(.telephoneNumber)
                                .accessibilityIdentifier("whatsapp.new-chat.number")
                        } footer: {
                            if let failure {
                                Text(verbatim: failure.firstError(for: "number") ?? failure.firstError(for: "account") ?? failure.userMessage(in: language))
                                    .foregroundStyle(.red)
                            }
                        }
                    }
                } else {
                    ProgressView()
                        .frame(maxWidth: .infinity)
                }
            }
            .navigationTitle(language.localized("New chat"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(language.localized("Cancel"), role: .cancel) {
                        dismiss()
                    }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button(language.localized("Start"), role: .confirm) {
                        Task { await start() }
                    }
                    .disabled(accountId == nil || number.trimmingCharacters(in: .whitespaces).isEmpty || isSaving)
                }
            }
            .task {
                accounts = (try? await model.client.send(API.whatsappAccounts()).data) ?? []
                accountId = accountId ?? accounts?.first(where: \.isOpen)?.friendlyId
            }
        }
    }

    private func start() async {
        guard let accountId else {
            return
        }

        isSaving = true
        defer { isSaving = false }

        do throws(APIError) {
            let chat = try await model.client.send(API.startWhatsappConversation(accountId: accountId, number: number)).data

            dismiss()
            onStarted(chat)
        } catch {
            failure = error
        }
    }
}

/// A reminder about the chat, or about some of its messages (all quoted in
/// the reminder's note): a title and when.
struct WhatsappChatReminderForm: View {
    let chatId: String
    let messages: [WhatsappConversationMessage]
    let onSaved: () -> Void

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language
    @Environment(\.timeZone) private var timeZone
    @Environment(\.dismiss) private var dismiss

    @State private var title = ""
    @State private var dueAt = Date.now.addingTimeInterval(3600)
    @State private var isSaving = false
    @State private var failure: APIError?

    var body: some View {
        NavigationStack {
            Form {
                if !messages.isEmpty {
                    Section(language.localized("About")) {
                        ForEach(messages, id: \.friendlyId) { message in
                            Text(verbatim: WhatsappMessageText.preview(of: message, in: language))
                                .lineLimit(3)
                                .foregroundStyle(.secondary)
                        }
                    }
                }

                Section {
                    TextField(language.localized("Title"), text: $title)
                        .accessibilityIdentifier("whatsapp.reminder.title")
                    DatePicker(language.localized("When"), selection: $dueAt)
                } footer: {
                    if let failure {
                        Text(verbatim: failure.firstError(for: "title") ?? failure.firstError(for: "due_at") ?? failure.userMessage(in: language))
                            .foregroundStyle(.red)
                    }
                }
            }
            .navigationTitle(language.localized("Reminder"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(language.localized("Cancel"), role: .cancel) {
                        dismiss()
                    }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button(language.localized("Save"), role: .confirm) {
                        Task { await save() }
                    }
                    .disabled(title.trimmingCharacters(in: .whitespaces).isEmpty || isSaving)
                }
            }
        }
    }

    private func save() async {
        isSaving = true
        defer { isSaving = false }

        do throws(APIError) {
            try await model.client.send(API.remindWhatsappConversation(
                chatId: chatId,
                title: title.trimmingCharacters(in: .whitespaces),
                dueAt: WallClock.dateTime(dueAt, in: timeZone),
                messageIds: messages.map(\.friendlyId)
            ))
            onSaved()
            dismiss()
        } catch {
            failure = error
        }
    }
}

/// What the AI's reply must say: the prompt, and whether the AI writes a
/// new message from it alone, without reading the chat.
struct WhatsappDraftPromptSheet: View {
    let hasSelection: Bool
    let onGenerate: (String, Bool) -> Void

    @Environment(\.appLanguage) private var language
    @Environment(\.dismiss) private var dismiss

    @State private var prompt = ""
    @State private var withoutContext = false
    @FocusState private var isFocused: Bool

    /// The longest prompt the server takes.
    private static let maxLength = 500

    private var trimmed: String {
        prompt.trimmingCharacters(in: .whitespacesAndNewlines)
    }

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    TextField(language.localized("What should the reply say?"), text: $prompt, axis: .vertical)
                        .lineLimit(3...8)
                        .focused($isFocused)
                        .onChange(of: prompt) { _, newValue in
                            if newValue.count > Self.maxLength {
                                prompt = String(newValue.prefix(Self.maxLength))
                            }
                        }
                        .accessibilityIdentifier("whatsapp.prompt.text")
                } footer: {
                    Text(verbatim: language.localized(withoutContext
                        ? "The AI writes a new message from your prompt alone, without reading the chat."
                        : hasSelection
                            ? "The AI answers the selected messages, following your prompt."
                            : "The AI answers the latest received messages, following your prompt."))
                }

                Section {
                    Toggle(language.localized("Only from my prompt (ignore the chat)"), isOn: $withoutContext)
                        .accessibilityIdentifier("whatsapp.prompt.without-context")
                }
            }
            .navigationTitle(language.localized("Reply following a prompt"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(language.localized("Cancel"), role: .cancel) {
                        dismiss()
                    }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button(language.localized("Generate"), role: .confirm) {
                        onGenerate(trimmed, withoutContext)
                        dismiss()
                    }
                    .disabled(trimmed.isEmpty)
                }
            }
            .onAppear { isFocused = true }
        }
        .presentationDetents([.medium, .large])
    }
}

/// A text, or a file with an optional caption, sent to the chat from its
/// number once, at a chosen time; a voice note goes without text.
struct WhatsappChatScheduleForm: View {
    let chatId: String
    let text: String
    let onSaved: () -> Void

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language
    @Environment(\.timeZone) private var timeZone
    @Environment(\.dismiss) private var dismiss

    @State private var messageText = ""
    @State private var at = Date.now.addingTimeInterval(3600)
    @State private var isSaving = false
    @State private var failure: APIError?
    @State private var attachment: WhatsappScheduleAttachment?
    @State private var recorder = WhatsappVoiceRecorder()

    private var isVoiceNote: Bool {
        attachment?.kind == .audio
    }

    private var canSave: Bool {
        guard !isSaving, !recorder.isRecording else {
            return false
        }

        if let attachment {
            return !attachment.isTooBig
        }

        return !messageText.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
    }

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    TextField(language.localized(attachment == nil ? "Message" : "Caption (optional)"), text: $messageText, axis: .vertical)
                        .lineLimit(3...10)
                        .disabled(isVoiceNote)
                        .foregroundStyle(isVoiceNote ? .secondary : .primary)
                        .accessibilityIdentifier("whatsapp.schedule.text")
                } footer: {
                    if let error = failure?.firstError(for: "text") {
                        Text(verbatim: error).foregroundStyle(.red)
                    } else if isVoiceNote {
                        Text(verbatim: language.localized("A voice note goes without text."))
                    }
                }

                WhatsappScheduleAttachmentSection(attachment: $attachment, recorder: recorder, failure: failure?.firstError(for: "file"))

                Section {
                    DatePicker(language.localized("Send at"), selection: $at, in: Date.now...)
                } footer: {
                    if let failure, failure.firstError(for: "text") == nil, failure.firstError(for: "file") == nil {
                        Text(verbatim: failure.firstError(for: "at") ?? failure.userMessage(in: language))
                            .foregroundStyle(.red)
                    }
                }
            }
            .navigationTitle(language.localized("Schedule message"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(language.localized("Cancel"), role: .cancel) {
                        dismiss()
                    }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button(language.localized("Schedule"), role: .confirm) {
                        Task { await save() }
                    }
                    .disabled(!canSave)
                }
            }
            .onAppear {
                if messageText.isEmpty {
                    messageText = text
                }
            }
            .onDisappear {
                recorder.discard()
            }
        }
    }

    private func save() async {
        isSaving = true
        defer { isSaving = false }

        let caption = isVoiceNote ? "" : messageText.trimmingCharacters(in: .whitespacesAndNewlines)
        let when = WallClock.dateTime(at, in: timeZone)

        do throws(APIError) {
            if let attachment {
                try await model.client.send(API.scheduleWhatsappConversationFile(chatId: chatId, text: caption, at: when, file: attachment.multipartFile))
            } else {
                try await model.client.send(API.scheduleWhatsappConversationText(chatId: chatId, text: caption, at: when))
            }

            recorder.stopPlaying()
            onSaved()
            dismiss()
        } catch {
            failure = error
        }
    }
}

/// The user's tags for chats: created, renamed, recolored and deleted (off
/// every chat), as in the web chat settings.
struct WhatsappTagsManager: View {
    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    @State private var tags: [WhatsappConversation.Tag]?
    @State private var palette = WhatsappPriorityColors(palette: [:], colors: [:])
    @State private var editing: WhatsappTagDraft?
    @State private var tagToDelete: WhatsappConversation.Tag?
    @State private var actionFailure: APIError?

    var body: some View {
        List {
            if let tags {
                if tags.isEmpty {
                    Text(verbatim: language.localized("No tags yet"))
                        .foregroundStyle(.secondary)
                }

                ForEach(tags) { tag in
                    Button {
                        editing = WhatsappTagDraft(tag: tag)
                    } label: {
                        Label {
                            Text(verbatim: tag.name).foregroundStyle(.primary)
                        } icon: {
                            Circle().fill(Color(hex: tag.color) ?? .secondary).frame(width: 14, height: 14)
                        }
                    }
                    .swipeActions {
                        Button(language.localized("Delete"), systemImage: "trash", role: .destructive) {
                            tagToDelete = tag
                        }
                    }
                }
            } else {
                ProgressView().frame(maxWidth: .infinity)
            }
        }
        .navigationTitle(language.localized("Tags"))
        .toolbar {
            ToolbarItem(placement: .primaryAction) {
                Button(language.localized("New tag"), systemImage: "plus") {
                    editing = WhatsappTagDraft(tag: nil)
                }
                .accessibilityIdentifier("whatsapp.tags.new")
            }
        }
        .task {
            await load()
        }
        .sheet(item: $editing) { draft in
            WhatsappTagForm(draft: draft, palette: palette) {
                Task { await load() }
            }
        }
        .confirmationDialog(
            language.localized("Delete this tag?"),
            isPresented: Binding { tagToDelete != nil } set: { if !$0 { tagToDelete = nil } },
            titleVisibility: .visible,
            presenting: tagToDelete
        ) { tag in
            Button(language.localized("Delete"), role: .destructive) {
                Task {
                    do throws(APIError) {
                        try await model.client.send(API.deleteWhatsappTag(id: tag.friendlyId))
                        model.whatsapp.chatsEdited()
                    } catch {
                        actionFailure = error
                    }

                    await load()
                }
            }
            Button(language.localized("Cancel"), role: .cancel) {}
        } message: { _ in
            Text(verbatim: language.localized("It comes off every chat."))
        }
        .actionFailureAlert($actionFailure)
    }

    private func load() async {
        tags = (try? await model.client.send(API.whatsappTags()).data) ?? tags ?? []

        if let colors = try? await model.client.send(API.whatsappPriorityColors()) {
            palette = colors
        }
    }
}

/// A tag being created (`tag` nil) or edited.
struct WhatsappTagDraft: Identifiable {
    let tag: WhatsappConversation.Tag?

    var id: String { tag?.friendlyId ?? "new" }
}

private struct WhatsappTagForm: View {
    let draft: WhatsappTagDraft
    let palette: WhatsappPriorityColors
    let onSaved: () -> Void

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language
    @Environment(\.dismiss) private var dismiss

    @State private var name = ""
    @State private var color = "blue"
    @State private var isSaving = false
    @State private var failure: APIError?

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    TextField(language.localized("Name"), text: $name)
                        .accessibilityIdentifier("whatsapp.tag.name")
                } footer: {
                    if let failure {
                        Text(verbatim: failure.firstError(for: "name") ?? failure.firstError(for: "color") ?? failure.userMessage(in: language))
                            .foregroundStyle(.red)
                    }
                }

                Section(language.localized("Color")) {
                    WhatsappColorChoice(palette: palette, selection: $color)
                }
            }
            .navigationTitle(language.localized(draft.tag == nil ? "New tag" : "Edit tag"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(language.localized("Cancel"), role: .cancel) {
                        dismiss()
                    }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button(language.localized("Save"), role: .confirm) {
                        Task { await save() }
                    }
                    .disabled(name.trimmingCharacters(in: .whitespaces).isEmpty || isSaving)
                }
            }
            .onAppear {
                name = draft.tag?.name ?? ""
                color = draft.tag?.colorName ?? color
            }
        }
        .presentationDetents([.medium])
    }

    private func save() async {
        isSaving = true
        defer { isSaving = false }

        let trimmed = name.trimmingCharacters(in: .whitespaces)

        do throws(APIError) {
            if let tag = draft.tag {
                try await model.client.send(API.updateWhatsappTag(id: tag.friendlyId, name: trimmed, color: color))
            } else {
                try await model.client.send(API.createWhatsappTag(name: trimmed, color: color))
            }

            model.whatsapp.chatsEdited()
            onSaved()
            dismiss()
        } catch {
            failure = error
        }
    }
}

/// The palette as a row of round swatches, the chosen one ringed.
struct WhatsappColorChoice: View {
    let palette: WhatsappPriorityColors
    @Binding var selection: String

    var body: some View {
        HStack(spacing: 10) {
            ForEach(palette.names, id: \.self) { name in
                Button {
                    selection = name
                } label: {
                    Circle()
                        .fill(Color(hex: palette.palette[name]) ?? .secondary)
                        .frame(width: 28, height: 28)
                        .overlay {
                            if selection == name {
                                Circle().stroke(Color.primary, lineWidth: 2).padding(-4)
                            }
                        }
                }
                .buttonStyle(.plain)
                .accessibilityLabel(Text(verbatim: name))
                .accessibilityAddTraits(selection == name ? .isSelected : [])
            }
        }
        .padding(.vertical, 6)
    }
}

/// The color of each priority, 1 (highest) to 5, out of the palette.
struct WhatsappPriorityColorsEditor: View {
    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    @State private var colors: WhatsappPriorityColors?
    @State private var actionFailure: APIError?

    var body: some View {
        Form {
            if let colors {
                ForEach(WhatsappPriorityColors.priorities, id: \.self) { priority in
                    Section(language.localized("Priority %lld", priority)) {
                        WhatsappColorChoice(palette: colors, selection: Binding {
                            colors.name(of: priority) ?? "gray"
                        } set: { name in
                            Task { await save(priority: priority, name: name) }
                        })
                    }
                }
            } else {
                ProgressView().frame(maxWidth: .infinity)
            }
        }
        .navigationTitle(language.localized("Priority colors"))
        .task {
            colors = try? await model.client.send(API.whatsappPriorityColors())
        }
        .actionFailureAlert($actionFailure)
    }

    private func save(priority: Int, name: String) async {
        guard var chosen = colors?.colors else {
            return
        }

        chosen[String(priority)] = name

        do throws(APIError) {
            colors = try await model.client.send(API.saveWhatsappPriorityColors(chosen))
            model.whatsapp.chatsEdited()
        } catch {
            actionFailure = error
        }
    }
}

/// The defaults of each number's chats: read receipts, "typing…" and the
/// pushes of new messages; a chat can still follow or override them.
struct WhatsappAccountDefaultsView: View {
    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    @State private var accounts: [WhatsappAccountChatSettings]?
    @State private var actionFailure: APIError?

    var body: some View {
        Form {
            if let accounts {
                ForEach(accounts) { account in
                    Section {
                        toggle("Send read receipts", account, \.sendsReadReceipts)
                        toggle("Show when I am typing", account, \.sendsTyping)
                        toggle("Notify new messages", account, \.notifiesMessages)
                        toggle("Mark as read here what I read on the WhatsApp app", account, \.syncsPhoneReads)
                    } header: {
                        Text(verbatim: account.nickname)
                    }
                }
            } else {
                ProgressView().frame(maxWidth: .infinity)
            }
        }
        .navigationTitle(language.localized("Defaults of the numbers"))
        .task {
            accounts = (try? await model.client.send(API.whatsappAccountChatSettings()).data) ?? []
        }
        .actionFailureAlert($actionFailure)
    }

    private func toggle(_ key: String, _ account: WhatsappAccountChatSettings, _ path: WritableKeyPath<WhatsappAccountChatSettings.Defaults, Bool>) -> some View {
        Toggle(language.localized(key), isOn: Binding {
            account.chatSettings[keyPath: path]
        } set: { value in
            var defaults = account.chatSettings
            defaults[keyPath: path] = value

            Task { await save(account, defaults) }
        })
    }

    private func save(_ account: WhatsappAccountChatSettings, _ defaults: WhatsappAccountChatSettings.Defaults) async {
        do throws(APIError) {
            let saved = try await model.client.send(API.updateWhatsappAccountChatSettings(accountId: account.friendlyId, defaults)).data

            accounts = accounts?.map { $0.id == saved.id ? saved : $0 }
        } catch {
            actionFailure = error
        }
    }
}
