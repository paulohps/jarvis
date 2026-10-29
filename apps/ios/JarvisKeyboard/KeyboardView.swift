import Networking
import SwiftUI

/// The reply keyboard: a bar (back, title, next keyboard) over the chats,
/// the messages of a chat or the drafted answer.
struct KeyboardView: View {
    @Bindable var model: KeyboardModel

    /// The text before the cursor in the field, read when a draft is asked for.
    let typedText: () -> String?
    let insert: (_ text: String, _ replacingTyped: String?) -> Void
    let nextKeyboard: () -> Void

    var body: some View {
        VStack(spacing: 0) {
            bar
            Divider()
            content
                .frame(maxWidth: .infinity, maxHeight: .infinity)
        }
        .background(Color(uiColor: .secondarySystemBackground))
        .task { model.loadChats() }
    }

    private var bar: some View {
        HStack(spacing: 12) {
            if model.screen != .chats {
                Button { model.back() } label: {
                    Image(systemName: "chevron.left")
                }
                .accessibilityLabel(Text("Back"))
            }

            Text(title)
                .font(.headline)
                .lineLimit(1)

            Spacer()

            if model.screen == .chats {
                Button { model.loadChats() } label: {
                    Image(systemName: "arrow.clockwise")
                }
                .accessibilityLabel(Text("Refresh"))
            }

            Button(action: nextKeyboard) {
                Image(systemName: "globe")
            }
            .accessibilityLabel(Text("Next keyboard"))
        }
        .padding(.horizontal, 12)
        .frame(height: 40)
    }

    private var title: String {
        switch model.screen {
        case .chats: String(localized: "Reply with Jarvis")
        case let .messages(chat), let .draft(chat, _): chat.name
        }
    }

    @ViewBuilder
    private var content: some View {
        if let problem = model.problem {
            ProblemView(problem: problem)
        } else {
            switch model.screen {
            case .chats:
                chatList
            case let .messages(chat):
                messageList(chat)
            case let .draft(_, message):
                draftView(message)
            }
        }
    }

    private var chatList: some View {
        List(model.chats) { chat in
            Button { model.open(chat) } label: {
                HStack(alignment: .top, spacing: 8) {
                    Circle()
                        .fill(chat.awaitingReply ? Color.accentColor : .clear)
                        .frame(width: 8, height: 8)
                        .padding(.top, 6)

                    VStack(alignment: .leading, spacing: 2) {
                        HStack {
                            Text(chat.name).font(.subheadline.weight(.semibold)).lineLimit(1)
                            Spacer()
                            if let sentAt = chat.lastReceivedMessage?.sentAt {
                                Text(sentAt, style: .relative).font(.caption2).foregroundStyle(.secondary)
                            }
                        }
                        Text(chat.lastReceivedMessage.map(preview) ?? "")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                            .lineLimit(1)
                    }
                }
            }
            .buttonStyle(.plain)
        }
        .listStyle(.plain)
        .overlay {
            if model.isLoading {
                ProgressView()
            } else if model.chats.isEmpty {
                Text("No messages received yet.").font(.footnote).foregroundStyle(.secondary)
            }
        }
    }

    private func messageList(_ chat: WhatsappChat) -> some View {
        List(model.messages) { message in
            Button {
                model.draftReply(to: message, in: chat, typed: typedText())
            } label: {
                HStack {
                    if message.fromMe { Spacer(minLength: 40) }
                    Text(preview(message))
                        .font(.callout)
                        .padding(8)
                        .background(message.fromMe ? Color.accentColor.opacity(0.15) : Color(uiColor: .systemBackground), in: .rect(cornerRadius: 10))
                    if !message.fromMe { Spacer(minLength: 40) }
                }
            }
            .buttonStyle(.plain)
            .disabled(message.fromMe)
            .listRowSeparator(.hidden)
        }
        .listStyle(.plain)
        .overlay {
            if model.isLoading { ProgressView() }
        }
        .safeAreaInset(edge: .bottom) {
            Text("Tap a message to draft the answer. What you typed in the field is used as the instruction.")
                .font(.caption2)
                .foregroundStyle(.secondary)
                .padding(.horizontal, 12)
                .padding(.vertical, 4)
        }
    }

    private func draftView(_ message: WhatsappChatMessage) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            Text(preview(message))
                .font(.caption)
                .foregroundStyle(.secondary)
                .lineLimit(2)

            if let instruction = model.instruction {
                Label(instruction, systemImage: "text.cursor")
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .lineLimit(1)
            }

            ScrollView {
                if let draft = model.draft {
                    Text(draft)
                        .font(.body)
                        .frame(maxWidth: .infinity, alignment: .leading)
                } else {
                    ProgressView(String(localized: "Writing…"))
                        .frame(maxWidth: .infinity)
                }
            }
            .padding(10)
            .background(Color(uiColor: .systemBackground), in: .rect(cornerRadius: 10))

            HStack {
                Button { model.regenerate(typed: typedText()) } label: {
                    Label("Another one", systemImage: "arrow.triangle.2.circlepath")
                }
                .buttonStyle(.bordered)

                Spacer()

                Button {
                    if let draft = model.draft {
                        insert(draft, model.instruction)
                        model.loadChats()
                    }
                } label: {
                    Label("Insert", systemImage: "text.insert")
                }
                .buttonStyle(.borderedProminent)
                .disabled(model.draft == nil)
            }
        }
        .padding(12)
    }

    private func preview(_ message: WhatsappChatMessage) -> String {
        if let body = message.body, !body.isEmpty {
            return body
        }

        return switch message.type {
        case "audioMessage": String(localized: "🎤 Audio")
        case "imageMessage": String(localized: "📷 Photo")
        case "videoMessage": String(localized: "🎥 Video")
        case "stickerMessage": String(localized: "Sticker")
        default: String(localized: "📎 Attachment")
        }
    }
}

private struct ProblemView: View {
    let problem: KeyboardModel.Problem

    var body: some View {
        VStack(spacing: 8) {
            Image(systemName: "exclamationmark.bubble").font(.title2).foregroundStyle(.secondary)
            Text(message)
                .font(.footnote)
                .multilineTextAlignment(.center)
                .foregroundStyle(.secondary)
        }
        .padding(16)
    }

    private var message: String {
        switch problem {
        case .needsFullAccess:
            String(localized: "Turn on Full Access in Settings › General › Keyboard › Keyboards › Jarvis so the keyboard can reach Jarvis.")
        case .signedOut:
            String(localized: "Sign in to the Jarvis app first.")
        case let .failed(message):
            message ?? String(localized: "Something went wrong. Try again.")
        }
    }
}
