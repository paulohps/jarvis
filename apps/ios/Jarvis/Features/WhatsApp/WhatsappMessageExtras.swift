import Localization
import MapKit
import Networking
import SwiftUI

/// The message a bubble answers, as WhatsApp quotes it over the text: a
/// bar in the sender's color, who sent it ("You" for the number) and its
/// text or kind; a tap goes to it when Jarvis keeps it.
struct WhatsappQuotedBlock: View {
    let quoted: WhatsappConversationMessage.Quoted
    let onTap: (() -> Void)?

    @Environment(\.appLanguage) private var language

    var body: some View {
        if let onTap {
            Button(action: onTap) {
                content
            }
            .buttonStyle(.plain)
            .accessibilityIdentifier("whatsapp.conversation.quoted")
        } else {
            content
        }
    }

    private var content: some View {
        HStack(spacing: 0) {
            Rectangle()
                .fill(color)
                .frame(width: 4)

            VStack(alignment: .leading, spacing: 1) {
                Text(verbatim: author)
                    .font(.footnote.weight(.semibold))
                    .foregroundStyle(color)
                    .lineLimit(1)

                Text(verbatim: WhatsappMessageText.preview(of: quoted, in: language))
                    .font(.footnote)
                    .foregroundStyle(.secondary)
                    .lineLimit(3)
                    .multilineTextAlignment(.leading)
            }
            .padding(.horizontal, 8)
            .padding(.vertical, 5)

            Spacer(minLength: 0)
        }
        .background(Color.primary.opacity(0.06))
        .clipShape(.rect(cornerRadius: 7))
        .contentShape(.rect)
        .accessibilityElement(children: .combine)
    }

    private var author: String {
        quoted.fromMe ? language.localized("You") : (quoted.sender ?? language.localized("Contact"))
    }

    private var color: Color {
        quoted.fromMe ? WhatsappPalette.send : WhatsappPalette.sender(quoted.sender ?? "")
    }
}

/// WhatsApp's "Forwarded" over a message that came from another chat.
struct WhatsappForwardedLabel: View {
    @Environment(\.appLanguage) private var language

    var body: some View {
        Label(language.localized("Forwarded"), systemImage: "arrowshape.turn.up.right")
            .font(.caption.italic())
            .foregroundStyle(WhatsappPalette.meta)
            .padding(.horizontal, 4)
    }
}

/// A place sent to the chat: a small map with its pin, its name and
/// address; a tap opens it in Apple Maps. A live one says so (Jarvis only
/// has where it started).
struct WhatsappLocationCard: View {
    let location: WhatsappConversationMessage.Location

    @Environment(\.appLanguage) private var language
    @Environment(\.openURL) private var openURL

    var body: some View {
        Button {
            if let url = mapsURL {
                openURL(url)
            }
        } label: {
            VStack(alignment: .leading, spacing: 4) {
                Map(initialPosition: .region(MKCoordinateRegion(center: coordinate, latitudinalMeters: 800, longitudinalMeters: 800)), interactionModes: []) {
                    Marker(location.name ?? "", coordinate: coordinate)
                }
                .allowsHitTesting(false)
                .frame(width: 230, height: 120)
                .clipShape(.rect(cornerRadius: 7))

                if location.isLive {
                    Label(language.localized("Live location"), systemImage: "dot.radiowaves.left.and.right")
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(WhatsappPalette.send)
                        .padding(.horizontal, 4)
                }

                if let name = location.name, !name.isEmpty {
                    Text(verbatim: name)
                        .font(.subheadline.weight(.semibold))
                        .foregroundStyle(.primary)
                        .padding(.horizontal, 4)
                }

                if let address = location.address, !address.isEmpty {
                    Text(verbatim: address)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .lineLimit(2)
                        .padding(.horizontal, 4)
                }
            }
            .frame(width: 230, alignment: .leading)
        }
        .buttonStyle(.plain)
        .accessibilityLabel(Text(verbatim: language.localized("Location") + (location.name.map { ", \($0)" } ?? "")))
        .accessibilityHint(language.text("Opens in Maps"))
        .accessibilityIdentifier("whatsapp.conversation.location")
    }

    private var coordinate: CLLocationCoordinate2D {
        CLLocationCoordinate2D(latitude: location.latitude, longitude: location.longitude)
    }

    /// Apple Maps at the place, with its name as the pin's.
    private var mapsURL: URL? {
        var components = URLComponents(string: "https://maps.apple.com/")
        components?.queryItems = [
            URLQueryItem(name: "ll", value: "\(location.latitude),\(location.longitude)"),
            URLQueryItem(name: "q", value: location.name ?? language.localized("Location")),
        ]

        return components?.url
    }
}

/// Contact cards sent to the chat: each name and number, and a button that
/// opens (or starts) the chat with it on the same number.
struct WhatsappContactCards: View {
    let contacts: [WhatsappConversationMessage.ContactCard]
    let onMessage: ((String) -> Void)?

    @Environment(\.appLanguage) private var language

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            ForEach(Array(contacts.enumerated()), id: \.offset) { _, contact in
                VStack(alignment: .leading, spacing: 6) {
                    HStack(spacing: 10) {
                        Image(systemName: "person.crop.circle.fill")
                            .font(.system(size: 34))
                            .foregroundStyle(WhatsappPalette.meta)

                        VStack(alignment: .leading, spacing: 1) {
                            Text(verbatim: contact.name ?? contact.numbers.first ?? language.localized("Contact"))
                                .font(.subheadline.weight(.semibold))
                                .lineLimit(1)
                            ForEach(contact.numbers, id: \.self) { number in
                                Text(verbatim: number)
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                        }
                    }

                    if let number = contact.numbers.first, let onMessage {
                        Divider()
                        Button(language.localized("Message")) {
                            onMessage(number)
                        }
                        .font(.subheadline.weight(.medium))
                        .foregroundStyle(WhatsappPalette.send)
                        .frame(maxWidth: .infinity)
                        .accessibilityIdentifier("whatsapp.conversation.contact-message")
                    }
                }
                .padding(.horizontal, 4)
                .frame(minWidth: 200, alignment: .leading)
            }
        }
    }
}

/// A poll with its votes as WhatsApp reported them: each option with a bar
/// of its share and how many voted it, the number's own ticked. Read only:
/// WhatsApp gives Jarvis no way to vote.
struct WhatsappPollView: View {
    let poll: WhatsappConversationMessage.Poll

    @Environment(\.appLanguage) private var language

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(verbatim: poll.name)
                .font(.subheadline.weight(.semibold))

            Label(language.localized(poll.allowsMultipleAnswers ? "Select one or more" : "Select one"), systemImage: poll.allowsMultipleAnswers ? "checklist" : "checkmark.circle")
                .font(.caption)
                .foregroundStyle(WhatsappPalette.meta)

            ForEach(Array(poll.options.enumerated()), id: \.offset) { _, option in
                VStack(alignment: .leading, spacing: 3) {
                    HStack(spacing: 6) {
                        Image(systemName: option.mine ? "checkmark.circle.fill" : "circle")
                            .foregroundStyle(option.mine ? WhatsappPalette.send : WhatsappPalette.meta)
                        Text(verbatim: option.name)
                            .font(.subheadline)
                        Spacer(minLength: 8)
                        Text(verbatim: "\(option.votes)")
                            .font(.caption.monospacedDigit())
                            .foregroundStyle(WhatsappPalette.meta)
                    }

                    GeometryReader { proxy in
                        Capsule()
                            .fill(WhatsappPalette.meta.opacity(0.25))
                            .overlay(alignment: .leading) {
                                Capsule()
                                    .fill(WhatsappPalette.send)
                                    .frame(width: proxy.size.width * share(of: option))
                            }
                    }
                    .frame(height: 5)
                }
                .accessibilityElement(children: .ignore)
                .accessibilityLabel(Text(verbatim: language.localized("%@: %lld votes", option.name, option.votes)))
                .accessibilityAddTraits(option.mine ? .isSelected : [])
            }

            Label(language.localized("Vote on the phone"), systemImage: "iphone")
                .font(.caption)
                .foregroundStyle(WhatsappPalette.meta)
        }
        .padding(.horizontal, 4)
        .frame(minWidth: 220, alignment: .leading)
        .accessibilityIdentifier("whatsapp.conversation.poll")
    }

    private func share(of option: WhatsappConversationMessage.Poll.Option) -> CGFloat {
        let total = poll.totalVotes

        return total > 0 ? CGFloat(option.votes) / CGFloat(total) : 0
    }
}

/// A sticker as WhatsApp shows it: the image alone, no bubble.
struct WhatsappStickerView: View {
    static let size: CGFloat = 150

    let media: WhatsappConversationMessage.Media
    let onFailed: () -> Void

    @Environment(\.appLanguage) private var language

    var body: some View {
        AsyncImage(url: media.url) { phase in
            switch phase {
            case .success(let image):
                image.resizable().scaledToFit()
            case .failure:
                Image(systemName: "seal")
                    .font(.system(size: 40))
                    .foregroundStyle(WhatsappPalette.meta)
                    .onAppear(perform: onFailed)
            default:
                ProgressView()
            }
        }
        .frame(width: Self.size, height: Self.size)
        .accessibilityLabel(language.text("Sticker"))
    }
}

/// How far a message the number sent got, and when: delivered, read and
/// played in a one-to-one chat; in a group WhatsApp does not tell Jarvis.
struct WhatsappMessageInfoSheet: View {
    let chatId: String
    let message: WhatsappConversationMessage

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language
    @Environment(\.dismiss) private var dismiss

    @State private var info: WhatsappMessageInfo?
    @State private var failure: APIError?

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    Text(verbatim: WhatsappMessageText.preview(of: message, in: language))
                        .lineLimit(4)
                        .foregroundStyle(.secondary)
                }

                if let info {
                    Section {
                        row("Sent", systemImage: "checkmark", date: info.sentAt)

                        if !info.perMember {
                            row("Delivered", systemImage: "checkmark.circle", date: info.deliveredAt)
                            row("Read", systemImage: "eye", date: info.readAt)

                            if message.media?.kind == .audio || info.playedAt != nil {
                                row("Played", systemImage: "play.circle", date: info.playedAt)
                            }
                        }
                    } footer: {
                        if info.perMember {
                            Text(verbatim: language.localized("In a group WhatsApp does not tell Jarvis who received or read each message."))
                        }
                    }
                } else if let failure {
                    Text(verbatim: failure.userMessage(in: language))
                        .foregroundStyle(.red)
                } else {
                    ProgressView()
                        .frame(maxWidth: .infinity)
                }
            }
            .navigationTitle(language.localized("Message info"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button(language.localized("Done"), role: .confirm) {
                        dismiss()
                    }
                }
            }
            .task {
                do throws(APIError) {
                    info = try await model.client.send(API.whatsappMessageInfo(chatId: chatId, messageId: message.friendlyId)).data
                } catch {
                    failure = error
                }
            }
        }
        .presentationDetents([.medium, .large])
    }

    private func row(_ key: String, systemImage: String, date: Date?) -> some View {
        LabeledContent {
            if let date {
                Text(date, format: .dateTime.day().month().hour().minute())
            } else {
                Text(verbatim: "—")
            }
        } label: {
            Label(language.localized(key), systemImage: systemImage)
        }
    }
}

/// A new text for a message the number sent, within WhatsApp's 15 minutes.
struct WhatsappEditMessageSheet: View {
    let message: WhatsappConversationMessage
    let onSave: (String) async throws(APIError) -> Void

    @Environment(\.appLanguage) private var language
    @Environment(\.dismiss) private var dismiss

    @State private var text = ""
    @State private var isSaving = false
    @State private var failure: APIError?
    @FocusState private var isFocused: Bool

    private var trimmed: String {
        text.trimmingCharacters(in: .whitespacesAndNewlines)
    }

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    TextField(language.localized("Message"), text: $text, axis: .vertical)
                        .lineLimit(2...10)
                        .focused($isFocused)
                        .accessibilityIdentifier("whatsapp.edit.text")
                } footer: {
                    if let failure {
                        Text(verbatim: failure.firstError(for: "text") ?? failure.userMessage(in: language))
                            .foregroundStyle(.red)
                    } else {
                        Text(verbatim: language.localized("WhatsApp lets you edit a message for 15 minutes after sending it."))
                    }
                }
            }
            .navigationTitle(language.localized("Edit message"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(language.localized("Cancel"), role: .cancel) {
                        dismiss()
                    }
                }
                ToolbarItem(placement: .confirmationAction) {
                    if isSaving {
                        ProgressView()
                    } else {
                        Button(language.localized("Save"), role: .confirm) {
                            Task { await save() }
                        }
                        .disabled(trimmed.isEmpty || trimmed == message.body)
                        .accessibilityIdentifier("whatsapp.edit.save")
                    }
                }
            }
            .onAppear {
                text = message.body ?? ""
                isFocused = true
            }
        }
        .presentationDetents([.medium, .large])
    }

    private func save() async {
        isSaving = true
        defer { isSaving = false }

        do throws(APIError) {
            try await onSave(trimmed)
            dismiss()
        } catch {
            failure = error
        }
    }
}
