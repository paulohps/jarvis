import Localization
import Networking
import SwiftUI

/// Schedules a WhatsApp message, edits a saved one (US-7.3) or sends one now
/// (US-7.5): one of the user's connected numbers, the contacts and groups of
/// that number and/or typed numbers, the text and, unless it is sent now,
/// the schedule. The server checks everything; a refusal (a time in the
/// past, a number not connected) comes translated and is shown in the form.
struct WhatsappMessageForm: View {
    let composer: WhatsappComposer
    let accounts: [WhatsappAccount]
    let onSave: () -> Void

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language
    @Environment(\.timeZone) private var timeZone
    @Environment(\.openURL) private var openURL
    @Environment(\.dismiss) private var dismiss

    @State private var accountId: String?
    @State private var targets: [WhatsappTarget] = []
    @State private var numbers = ""
    @State private var messageBody = ""
    @State private var schedule: ScheduleDraft?
    @State private var isSaving = false
    @State private var failure: APIError?
    @State private var queuedDeliveries: Int?

    private var editedMessage: WhatsappMessage? {
        if case .schedule(let message) = composer {
            return message
        }

        return nil
    }

    private var isSendingNow: Bool {
        composer == .sendNow
    }

    private var openAccounts: [WhatsappAccount] {
        accounts.filter(\.isOpen)
    }

    var body: some View {
        NavigationStack {
            Form {
                if openAccounts.isEmpty && editedMessage == nil {
                    noConnectedNumber
                } else {
                    fields
                }
            }
            .navigationTitle(language.localized(title))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(language.localized("Cancel"), role: .cancel) {
                        dismiss()
                    }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button(language.localized(isSendingNow ? "Send" : "Save"), role: .confirm) {
                        Task { await save() }
                    }
                    .disabled(!canSave || isSaving)
                }
            }
            .onAppear(perform: fill)
            .alert(
                language.localized("Sending"),
                isPresented: Binding { queuedDeliveries != nil } set: { if !$0 { queuedDeliveries = nil } }
            ) {
                Button(language.localized("OK")) {
                    dismiss()
                }
            } message: {
                Text(verbatim: language.localized("The message is going out to %lld recipients.", queuedDeliveries ?? 0))
            }
        }
    }

    private var title: String {
        switch composer {
        case .schedule(nil): "Schedule message"
        case .schedule: "Edit message"
        case .sendNow: "Send now"
        }
    }

    private var noConnectedNumber: some View {
        Section {
            ContentUnavailableView(
                language.localized("No connected number"),
                systemImage: "phone.badge.waveform",
                description: language.text("Connecting a number is done on the web.")
            )

            Button(language.localized("Connect a number on the web"), systemImage: "safari") {
                openURL(model.configuration.apiBaseURL.appending(path: "whatsapp/accounts"))
            }
        }
    }

    @ViewBuilder
    private var fields: some View {
        Section {
            Picker(language.localized("From"), selection: $accountId) {
                ForEach(selectableAccounts) { account in
                    Text(verbatim: account.nickname).tag(Optional(account.friendlyId))
                }
            }
            .onChange(of: accountId) { oldValue, _ in
                // The contacts and groups belong to the number.
                if oldValue != nil {
                    targets = []
                }
            }
        }

        Section {
            ForEach(targets) { target in
                Label {
                    Text(verbatim: target.name)
                } icon: {
                    Image(systemName: target.type == .group ? "person.3" : "person")
                }
            }
            .onDelete { offsets in
                targets.remove(atOffsets: offsets)
            }

            if let accountId {
                NavigationLink {
                    WhatsappTargetPicker(accountId: accountId, selection: $targets)
                } label: {
                    Label(language.localized("Choose contacts and groups"), systemImage: "person.crop.circle.badge.plus")
                }
            }

            TextField(language.localized("Phone numbers"), text: $numbers, prompt: Text(verbatim: "+5511999990000"), axis: .vertical)
                .keyboardType(.phonePad)
                .lineLimit(1...4)
        } header: {
            language.text("Recipients")
        } footer: {
            language.text("Numbers in international format, separated by commas.")
        }

        Section {
            TextField(language.localized("Message"), text: $messageBody, axis: .vertical)
                .lineLimit(4...12)
        } header: {
            language.text("Message")
        } footer: {
            Text(verbatim: language.localized("Hi {% recipient.name %}! puts each recipient's name in the text."))
        }

        if !isSendingNow, let schedule {
            ScheduleEditor(
                draft: Binding { schedule } set: { self.schedule = $0 },
                kinds: ScheduleDraft.Kind.allCases.filter { $0 != .none }
            )
        }

        if let failure {
            Section {
                Label(failure.userMessage(in: language), systemImage: "exclamationmark.triangle.fill")
                    .foregroundStyle(.red)
                    .accessibilityIdentifier("whatsapp.error")
            }
        }
    }

    /// The connected numbers, and the one of an edited message even when it is not connected now.
    private var selectableAccounts: [WhatsappAccount] {
        guard let editedMessage, !openAccounts.contains(where: { $0.friendlyId == editedMessage.account.friendlyId }) else {
            return openAccounts
        }

        return openAccounts + accounts.filter { $0.friendlyId == editedMessage.account.friendlyId }
    }

    private var typedNumbers: [String] {
        numbers
            .split(whereSeparator: { $0 == "," || $0 == ";" || $0.isNewline })
            .map { $0.trimmingCharacters(in: .whitespaces) }
            .filter { !$0.isEmpty }
    }

    private var canSave: Bool {
        accountId != nil
            && !messageBody.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
            && (!targets.isEmpty || !typedNumbers.isEmpty)
    }

    private func fill() {
        guard schedule == nil else {
            return
        }

        if let editedMessage {
            accountId = editedMessage.account.friendlyId
            targets = editedMessage.targets
            messageBody = editedMessage.body
            schedule = ScheduleDraft(recurrence: editedMessage.recurrence, timeZone: timeZone)
        } else {
            accountId = openAccounts.first?.friendlyId
            schedule = ScheduleDraft(kind: .once, timeZone: timeZone)
        }
    }

    private func save() async {
        guard let accountId, let schedule else {
            return
        }

        isSaving = true
        defer { isSaving = false }

        let input = WhatsappMessageInput(
            account: accountId,
            targets: targets.map(\.friendlyId),
            numbers: typedNumbers,
            body: messageBody.trimmingCharacters(in: .whitespacesAndNewlines),
            recurrence: schedule.recurrenceInput(in: timeZone)
        )

        do throws(APIError) {
            switch composer {
            case .sendNow:
                let deliveries = try await model.client.send(API.sendWhatsappNow(input)).data
                onSave()
                queuedDeliveries = deliveries.count

                return
            case .schedule(let message?):
                try await model.client.send(API.updateWhatsappMessage(id: message.friendlyId, input))
            case .schedule(nil):
                try await model.client.send(API.scheduleWhatsappMessage(input))
            }

            onSave()
            dismiss()
        } catch {
            failure = error
        }
    }
}

/// The contacts and groups of a number, searched by name or number, to
/// pick the recipients from.
struct WhatsappTargetPicker: View {
    let accountId: String
    @Binding var selection: [WhatsappTarget]

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    @State private var search = ""
    @State private var targets: [WhatsappTarget] = []
    @State private var page = 1
    @State private var hasMorePages = false
    @State private var failure: APIError?

    var body: some View {
        List {
            ForEach(targets) { target in
                let isSelected = selection.contains { $0.friendlyId == target.friendlyId }

                Button {
                    if isSelected {
                        selection.removeAll { $0.friendlyId == target.friendlyId }
                    } else {
                        selection.append(target)
                    }
                } label: {
                    HStack {
                        Label {
                            VStack(alignment: .leading) {
                                Text(verbatim: target.name)
                                if let phoneNumber = target.phoneNumber, phoneNumber != target.name {
                                    Text(verbatim: phoneNumber)
                                        .font(.caption)
                                        .foregroundStyle(.secondary)
                                }
                            }
                        } icon: {
                            Image(systemName: target.type == .group ? "person.3" : "person")
                        }

                        Spacer()

                        if isSelected {
                            Image(systemName: "checkmark")
                                .foregroundStyle(.tint)
                        }
                    }
                    .contentShape(.rect)
                }
                .buttonStyle(.plain)
                .accessibilityAddTraits(isSelected ? .isSelected : [])
            }

            if hasMorePages {
                ProgressView()
                    .frame(maxWidth: .infinity)
                    .task {
                        await load(page: page + 1)
                    }
            }

            if let failure {
                Text(verbatim: failure.userMessage(in: language))
                    .foregroundStyle(.red)
            } else if targets.isEmpty, !hasMorePages {
                ContentUnavailableView.search(text: search)
            }
        }
        .searchable(text: $search, prompt: Text(verbatim: language.localized("Name or number")))
        .navigationTitle(language.localized("Recipients"))
        .navigationBarTitleDisplayMode(.inline)
        .task(id: search) {
            // A short pause while typing, then the first page of the search.
            try? await Task.sleep(for: .milliseconds(300))

            guard !Task.isCancelled else {
                return
            }

            await load(page: 1)
        }
    }

    private func load(page: Int) async {
        do throws(APIError) {
            let result = try await model.client.send(API.whatsappTargets(accountId: accountId, search: search, page: page))

            targets = page == 1 ? result.data : targets + result.data.filter { new in !targets.contains { $0.id == new.id } }
            self.page = result.meta.currentPage
            hasMorePages = result.meta.hasMorePages
            failure = nil
        } catch {
            failure = error
            hasMorePages = false
        }
    }
}
