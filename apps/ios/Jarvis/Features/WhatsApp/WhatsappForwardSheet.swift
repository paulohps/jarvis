import Localization
import Networking
import SwiftUI

/// Sends messages on to up to five chats of the same number, picked from
/// its chats (searchable), as WhatsApp's "Forward to…". WhatsApp does not
/// show them as forwarded: Jarvis sends the text, or the file with its
/// caption, again.
struct WhatsappForwardSheet: View {
    let chatId: String
    let accountId: String
    let messages: [WhatsappConversationMessage]
    let onForwarded: () -> Void

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language
    @Environment(\.dismiss) private var dismiss

    @State private var search = ""
    @State private var chats: [WhatsappConversation] = []
    @State private var hasLoaded = false
    @State private var picked: [WhatsappConversation] = []
    @State private var isSending = false
    @State private var failure: APIError?

    var body: some View {
        NavigationStack {
            List {
                Section {
                    Text(verbatim: language.localized("Messages: %lld", messages.count))
                        .foregroundStyle(.secondary)
                } footer: {
                    Text(verbatim: language.localized("Sent again from this number. WhatsApp will not show them as forwarded."))
                }

                if !picked.isEmpty {
                    Section(language.localized("To")) {
                        ForEach(picked) { chat in
                            row(chat)
                        }
                    }
                }

                Section {
                    if !hasLoaded {
                        ProgressView()
                            .frame(maxWidth: .infinity)
                    } else if unpicked.isEmpty, picked.isEmpty {
                        Text(verbatim: language.localized("No chats found"))
                            .foregroundStyle(.secondary)
                    }

                    ForEach(unpicked) { chat in
                        row(chat)
                    }
                } header: {
                    Text(verbatim: language.localized("Up to %lld chats", WhatsappForwardLimits.chats))
                } footer: {
                    if let failure {
                        Text(verbatim: failure.firstError(for: "messages") ?? failure.firstError(for: "to") ?? failure.userMessage(in: language))
                            .foregroundStyle(.red)
                            .accessibilityIdentifier("whatsapp.forward.error")
                    }
                }
            }
            .searchable(text: $search, placement: .navigationBarDrawer(displayMode: .always), prompt: Text(verbatim: language.localized("Name or number")))
            .navigationTitle(language.localized("Forward to…"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(language.localized("Cancel"), role: .cancel) {
                        dismiss()
                    }
                }
                ToolbarItem(placement: .confirmationAction) {
                    if isSending {
                        ProgressView()
                    } else {
                        Button(language.localized("Send"), role: .confirm) {
                            Task { await forward() }
                        }
                        .disabled(picked.isEmpty)
                        .accessibilityIdentifier("whatsapp.forward.send")
                    }
                }
            }
            .task(id: search) {
                // Waits for the user to stop typing before asking the server.
                if !search.isEmpty {
                    try? await Task.sleep(for: .milliseconds(300))
                }

                guard !Task.isCancelled else {
                    return
                }

                if let page = try? await model.client.send(API.whatsappConversations(page: 1, perPage: 50, search: search, account: accountId)) {
                    chats = page.data
                }

                hasLoaded = true
            }
        }
    }

    private var unpicked: [WhatsappConversation] {
        chats.filter { chat in !picked.contains { $0.friendlyId == chat.friendlyId } }
    }

    private func row(_ chat: WhatsappConversation) -> some View {
        let isPicked = picked.contains { $0.friendlyId == chat.friendlyId }
        let isFull = picked.count >= WhatsappForwardLimits.chats

        return Button {
            if isPicked {
                picked.removeAll { $0.friendlyId == chat.friendlyId }
            } else if !isFull {
                picked.append(chat)
            }
        } label: {
            HStack(spacing: 12) {
                WhatsappChatAvatar(chat: chat, size: 36)

                Text(verbatim: chat.name)
                    .foregroundStyle(.primary)
                    .lineLimit(1)

                Spacer()

                Image(systemName: isPicked ? "checkmark.circle.fill" : "circle")
                    .font(.title3)
                    .foregroundStyle(isPicked ? WhatsappPalette.send : WhatsappPalette.meta)
            }
        }
        .disabled(!isPicked && isFull)
        .accessibilityAddTraits(isPicked ? .isSelected : [])
        .accessibilityIdentifier("whatsapp.forward.chat")
    }

    private func forward() async {
        isSending = true
        defer { isSending = false }

        do throws(APIError) {
            try await model.client.send(API.forwardWhatsappMessages(
                chatId: chatId,
                messageIds: messages.map(\.friendlyId),
                to: picked.map(\.friendlyId)
            ))
            onForwarded()
            dismiss()
        } catch {
            failure = error
        }
    }
}
