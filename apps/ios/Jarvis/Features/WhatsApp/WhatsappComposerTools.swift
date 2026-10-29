import CoreLocation
import Localization
import Networking
import PhotosUI
import SwiftUI
import UniformTypeIdentifiers

/// The message being answered, over the composer as on WhatsApp: who sent
/// it and its text; the cross drops the answer.
struct WhatsappReplyBar: View {
    let message: WhatsappConversationMessage
    let onCancel: () -> Void

    @Environment(\.appLanguage) private var language

    var body: some View {
        HStack(spacing: 8) {
            WhatsappQuotedBlock(
                quoted: .init(friendlyId: message.friendlyId, sender: message.sender, fromMe: message.fromMe, type: message.type, body: WhatsappMessageText.preview(of: message, in: language)),
                onTap: nil
            )

            Button(language.localized("Cancel reply"), systemImage: "xmark.circle.fill", action: onCancel)
                .labelStyle(.iconOnly)
                .font(.title3)
                .foregroundStyle(WhatsappPalette.meta)
                .buttonStyle(.plain)
                .accessibilityIdentifier("whatsapp.conversation.cancel-reply")
        }
        .padding(.horizontal, 8)
    }
}

/// The group members matching what was typed after `@`, over the
/// composer; a tap writes the member's number as the mention.
struct WhatsappMentionSuggestions: View {
    let members: [WhatsappGroup.Member]
    let onPick: (WhatsappGroup.Member) -> Void

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                ForEach(members) { member in
                    Button {
                        onPick(member)
                    } label: {
                        HStack(spacing: 10) {
                            Image(systemName: "person.crop.circle.fill")
                                .font(.title2)
                                .foregroundStyle(WhatsappPalette.sender(member.name))
                            VStack(alignment: .leading, spacing: 0) {
                                Text(verbatim: member.name)
                                    .font(.subheadline.weight(.medium))
                                    .foregroundStyle(.primary)
                                if let number = member.number {
                                    Text(verbatim: number)
                                        .font(.caption)
                                        .foregroundStyle(.secondary)
                                }
                            }
                            Spacer(minLength: 0)
                        }
                        .padding(.horizontal, 12)
                        .padding(.vertical, 6)
                        .contentShape(.rect)
                    }
                    .buttonStyle(.plain)
                    .accessibilityIdentifier("whatsapp.conversation.mention")
                }
            }
        }
        .frame(maxHeight: 200)
        .fixedSize(horizontal: false, vertical: true)
        .background(WhatsappPalette.incoming, in: .rect(cornerRadius: 12))
        .padding(.horizontal, 8)
    }
}

/// A poll for the chat: the question, 2 to 10 options and whether people
/// may pick more than one. Nobody votes from Jarvis: the votes come as
/// WhatsApp reports them.
struct WhatsappPollForm: View {
    let onSend: (_ question: String, _ options: [String], _ multiple: Bool) async -> APIError?

    @Environment(\.appLanguage) private var language
    @Environment(\.dismiss) private var dismiss

    @State private var question = ""
    @State private var options = ["", ""]
    @State private var multiple = false
    @State private var isSending = false
    @State private var failure: APIError?

    private var filled: [String] {
        options.map { $0.trimmingCharacters(in: .whitespacesAndNewlines) }.filter { !$0.isEmpty }
    }

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    TextField(language.localized("Question"), text: $question, axis: .vertical)
                        .lineLimit(1...4)
                        .accessibilityIdentifier("whatsapp.poll.question")
                } footer: {
                    if let error = failure?.firstError(for: "question") {
                        Text(verbatim: error).foregroundStyle(.red)
                    }
                }

                Section {
                    ForEach(options.indices, id: \.self) { index in
                        TextField(language.localized("Option %lld", index + 1), text: $options[index])
                            .accessibilityIdentifier("whatsapp.poll.option")
                    }
                    .onDelete { offsets in
                        if options.count - offsets.count >= WhatsappPollLimits.options.lowerBound {
                            options.remove(atOffsets: offsets)
                        }
                    }

                    if options.count < WhatsappPollLimits.options.upperBound {
                        Button(language.localized("Add option"), systemImage: "plus") {
                            options.append("")
                        }
                    }
                } header: {
                    language.text("Options")
                } footer: {
                    if let failure, failure.firstError(for: "question") == nil {
                        Text(verbatim: failure.firstError(for: "options") ?? failure.userMessage(in: language))
                            .foregroundStyle(.red)
                    }
                }

                Section {
                    Toggle(language.localized("Allow multiple answers"), isOn: $multiple)
                } footer: {
                    Text(verbatim: language.localized("Votes show as WhatsApp reports them; voting is done on the phone."))
                }
            }
            .navigationTitle(language.localized("Poll"))
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
                            Task { await send() }
                        }
                        .disabled(question.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty || filled.count < WhatsappPollLimits.options.lowerBound)
                        .accessibilityIdentifier("whatsapp.poll.send")
                    }
                }
            }
        }
    }

    private func send() async {
        isSending = true
        defer { isSending = false }

        failure = await onSend(question, filled, multiple)

        if failure == nil {
            dismiss()
        }
    }
}

/// Finds where the device is, once, asking for the permission the first
/// time; `isDenied` when the user said no.
@MainActor
@Observable
final class WhatsappLocationFinder {
    private(set) var isLocating = false
    private(set) var isDenied = false

    func locate() async -> CLLocationCoordinate2D? {
        isLocating = true
        isDenied = false
        defer { isLocating = false }

        // Keeps the "when in use" permission asked for while it looks.
        let session = CLServiceSession(authorization: .whenInUse)
        defer { session.invalidate() }

        do {
            for try await update in CLLocationUpdate.liveUpdates() {
                if update.authorizationDenied || update.authorizationDeniedGlobally {
                    isDenied = true

                    return nil
                }

                if let location = update.location {
                    return location.coordinate
                }
            }
        } catch {
            return nil
        }

        return nil
    }
}

/// A place for the chat: where the device is (with the permission), or
/// typed coordinates, with an optional name and address.
struct WhatsappLocationForm: View {
    let onSend: (_ latitude: Double, _ longitude: Double, _ name: String, _ address: String) async -> APIError?

    @Environment(\.appLanguage) private var language
    @Environment(\.dismiss) private var dismiss

    @State private var finder = WhatsappLocationFinder()
    @State private var latitude = ""
    @State private var longitude = ""
    @State private var name = ""
    @State private var address = ""
    @State private var isSending = false
    @State private var failure: APIError?

    /// The coordinates typed, with a comma or a point, inside their ranges.
    private var coordinates: (Double, Double)? {
        guard let lat = Self.number(latitude), let lon = Self.number(longitude), (-90...90).contains(lat), (-180...180).contains(lon) else {
            return nil
        }

        return (lat, lon)
    }

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    Button {
                        Task { await useCurrentLocation() }
                    } label: {
                        HStack {
                            Label(language.localized("Use my current location"), systemImage: "location.fill")
                            if finder.isLocating {
                                Spacer()
                                ProgressView()
                            }
                        }
                    }
                    .disabled(finder.isLocating)
                    .accessibilityIdentifier("whatsapp.location.current")
                } footer: {
                    if finder.isDenied {
                        Text(verbatim: language.localized("Jarvis may not use the location. Allow it in Settings, or type the coordinates."))
                            .foregroundStyle(.red)
                    }
                }

                Section {
                    TextField(language.localized("Latitude"), text: $latitude)
                        .keyboardType(.numbersAndPunctuation)
                        .accessibilityIdentifier("whatsapp.location.latitude")
                    TextField(language.localized("Longitude"), text: $longitude)
                        .keyboardType(.numbersAndPunctuation)
                        .accessibilityIdentifier("whatsapp.location.longitude")
                } header: {
                    language.text("Coordinates")
                } footer: {
                    if let failure {
                        Text(verbatim: failure.firstError(for: "latitude") ?? failure.firstError(for: "longitude") ?? failure.userMessage(in: language))
                            .foregroundStyle(.red)
                    }
                }

                Section {
                    TextField(language.localized("Name (optional)"), text: $name)
                    TextField(language.localized("Address (optional)"), text: $address, axis: .vertical)
                        .lineLimit(1...3)
                }
            }
            .navigationTitle(language.localized("Location"))
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
                            Task { await send() }
                        }
                        .disabled(coordinates == nil)
                        .accessibilityIdentifier("whatsapp.location.send")
                    }
                }
            }
        }
    }

    private static func number(_ text: String) -> Double? {
        Double(text.trimmingCharacters(in: .whitespaces).replacingOccurrences(of: ",", with: "."))
    }

    private func useCurrentLocation() async {
        if let coordinate = await finder.locate() {
            latitude = String(format: "%.6f", coordinate.latitude)
            longitude = String(format: "%.6f", coordinate.longitude)
        }
    }

    private func send() async {
        guard let (lat, lon) = coordinates else {
            return
        }

        isSending = true
        defer { isSending = false }

        failure = await onSend(lat, lon, name, address)

        if failure == nil {
            dismiss()
        }
    }
}

/// Picks contact chats of one number (the ones with a phone number),
/// searchable, up to `limit`: the cards to send, a new group's members or
/// the members to add to one. `onPick` returns why it failed, nil to close.
struct WhatsappContactPicker: View {
    let accountId: String
    let title: String
    let actionTitle: String
    var limit: Int?
    var excluded: Set<String> = []
    var initial: [WhatsappConversation] = []
    let onPick: ([WhatsappConversation]) async -> APIError?

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language
    @Environment(\.dismiss) private var dismiss

    @State private var search = ""
    @State private var chats: [WhatsappConversation] = []
    @State private var hasLoaded = false
    @State private var picked: [WhatsappConversation] = []
    @State private var isSaving = false
    @State private var failure: APIError?

    var body: some View {
        NavigationStack {
            List {
                if !picked.isEmpty {
                    Section(language.localized("Picked")) {
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
                        Text(verbatim: language.localized("No contacts found"))
                            .foregroundStyle(.secondary)
                    }

                    ForEach(unpicked) { chat in
                        row(chat)
                    }
                } header: {
                    if let limit {
                        Text(verbatim: language.localized("Up to %lld contacts", limit))
                    }
                } footer: {
                    if let failure {
                        Text(verbatim: failure.firstError(for: "contacts") ?? failure.firstError(for: "members") ?? failure.firstError(for: "participants") ?? failure.userMessage(in: language))
                            .foregroundStyle(.red)
                            .accessibilityIdentifier("whatsapp.contacts.error")
                    }
                }
            }
            .searchable(text: $search, placement: .navigationBarDrawer(displayMode: .always), prompt: Text(verbatim: language.localized("Name or number")))
            .navigationTitle(title)
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
                        Button(actionTitle, role: .confirm) {
                            Task { await pick() }
                        }
                        .disabled(picked.isEmpty)
                        .accessibilityIdentifier("whatsapp.contacts.done")
                    }
                }
            }
            .onAppear {
                if picked.isEmpty {
                    picked = initial
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
                    chats = page.data.filter { !$0.isGroup && $0.phoneNumber != nil && !excluded.contains($0.friendlyId) }
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
        let isFull = limit.map { picked.count >= $0 } ?? false

        return Button {
            if isPicked {
                picked.removeAll { $0.friendlyId == chat.friendlyId }
            } else if !isFull {
                picked.append(chat)
            }
        } label: {
            HStack(spacing: 12) {
                WhatsappChatAvatar(chat: chat, size: 36)

                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: chat.name)
                        .foregroundStyle(.primary)
                        .lineLimit(1)
                    if let phoneNumber = chat.phoneNumber, phoneNumber != chat.name {
                        Text(verbatim: phoneNumber)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                }

                Spacer()

                Image(systemName: isPicked ? "checkmark.circle.fill" : "circle")
                    .font(.title3)
                    .foregroundStyle(isPicked ? WhatsappPalette.send : WhatsappPalette.meta)
            }
        }
        .disabled(!isPicked && isFull)
        .accessibilityAddTraits(isPicked ? .isSelected : [])
        .accessibilityIdentifier("whatsapp.contacts.chat")
    }

    private func pick() async {
        isSaving = true
        defer { isSaving = false }

        failure = await onPick(picked)

        if failure == nil {
            dismiss()
        }
    }
}

/// An image made a sticker the server takes: a WebP up to 1 MB goes as it
/// is (it may be animated); anything else is fitted in 512 × 512 and sent
/// as a PNG, keeping its transparency.
enum WhatsappStickerFile {
    static let side: CGFloat = 512

    static func load(_ item: PhotosPickerItem) async -> MultipartForm.File? {
        guard let data = try? await item.loadTransferable(type: Data.self) else {
            return nil
        }

        if item.supportedContentTypes.contains(where: { $0.conforms(to: .webP) }), data.count <= WhatsappContentLimits.stickerBytes {
            return MultipartForm.File(name: "file", fileName: "sticker.webp", mimeType: "image/webp", data: data)
        }

        guard let image = UIImage(data: data) else {
            return nil
        }

        let scale = min(1, side / max(image.size.width, image.size.height, 1))
        let size = CGSize(width: (image.size.width * scale).rounded(), height: (image.size.height * scale).rounded())
        let format = UIGraphicsImageRendererFormat()
        format.scale = 1
        let fitted = UIGraphicsImageRenderer(size: size, format: format).image { _ in
            image.draw(in: CGRect(origin: .zero, size: size))
        }

        if let png = fitted.pngData(), png.count <= WhatsappContentLimits.stickerBytes {
            return MultipartForm.File(name: "file", fileName: "sticker.png", mimeType: "image/png", data: png)
        }

        return fitted.jpegData(compressionQuality: 0.8).map { MultipartForm.File(name: "file", fileName: "sticker.jpg", mimeType: "image/jpeg", data: $0) }
    }
}
