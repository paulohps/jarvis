import Localization
import Networking
import PhotosUI
import SwiftUI

/// A group's own part of the chat info, as WhatsApp's group info, asked to
/// WhatsApp when it opens: the subject and description (editable when the
/// number may edit the info), the picture, disappearing messages, the
/// permissions (admins), the members — added, made admins or not, removed
/// by an admin —, the invite link (shown, copied, reset) and leaving the
/// group.
struct WhatsappGroupInfoSections: View {
    let chat: WhatsappConversation
    let onChanged: (WhatsappConversation) -> Void
    let onLeft: () -> Void

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    @State private var group: WhatsappGroup?
    @State private var loadFailure: APIError?
    @State private var actionFailure: APIError?
    @State private var subject = ""
    @State private var groupDescription = ""
    @State private var isSaving = false
    @State private var picture: PhotosPickerItem?
    @State private var pictureNotice: String?
    @State private var inviteURL: String?
    @State private var isAddingMembers = false
    @State private var membersNotice: String?
    @State private var memberToRemove: WhatsappGroup.Member?
    @State private var isConfirmingReset = false
    @State private var isConfirmingLeave = false

    var body: some View {
        Group {
            if let group {
                infoSection(group)
                settingsSection(group)
                membersSection(group)
                inviteSection(group)
            } else if let loadFailure {
                Section {
                    Text(verbatim: loadFailure.userMessage(in: language))
                        .foregroundStyle(.secondary)
                    Button(language.localized("Try again")) {
                        Task { await load() }
                    }
                } header: {
                    language.text("Group")
                }
            } else {
                Section {
                    ProgressView()
                        .frame(maxWidth: .infinity)
                }
            }

            Section {
                Button(language.localized("Leave group"), systemImage: "rectangle.portrait.and.arrow.right", role: .destructive) {
                    isConfirmingLeave = true
                }
                .accessibilityIdentifier("whatsapp.group.leave")
            }
        }
        .task {
            await load()
        }
        .onChange(of: picture) { _, item in
            if let item {
                Task { await upload(item) }
            }
        }
        .sheet(isPresented: $isAddingMembers) {
            WhatsappContactPicker(
                accountId: chat.account.friendlyId,
                title: language.localized("Add members"),
                actionTitle: language.localized("Add"),
                limit: 50
            ) { picked in
                await changeMembers(.add, picked.compactMap(\.phoneNumber))
            }
        }
        .confirmationDialog(
            language.localized("Remove from the group?"),
            isPresented: Binding { memberToRemove != nil } set: { if !$0 { memberToRemove = nil } },
            titleVisibility: .visible,
            presenting: memberToRemove
        ) { member in
            Button(language.localized("Remove %@", member.name), role: .destructive) {
                Task { _ = await changeMembers(.remove, [member.jid]) }
            }
            Button(language.localized("Cancel"), role: .cancel) {}
        }
        .confirmationDialog(
            language.localized("Reset the invite link?"),
            isPresented: $isConfirmingReset,
            titleVisibility: .visible
        ) {
            Button(language.localized("Reset link"), role: .destructive) {
                Task { await loadInvite(revoke: true) }
            }
            Button(language.localized("Cancel"), role: .cancel) {}
        } message: {
            Text(verbatim: language.localized("The current link stops working."))
        }
        .confirmationDialog(
            language.localized("Leave this group?"),
            isPresented: $isConfirmingLeave,
            titleVisibility: .visible
        ) {
            Button(language.localized("Leave group"), role: .destructive) {
                Task { await leave() }
            }
            Button(language.localized("Cancel"), role: .cancel) {}
        } message: {
            Text(verbatim: language.localized("The number leaves it on WhatsApp; the chat stays here, archived."))
        }
        .actionFailureAlert($actionFailure)
    }

    private func infoSection(_ group: WhatsappGroup) -> some View {
        Section {
            TextField(language.localized("Subject"), text: $subject)
                .disabled(!group.canEditInfo)
                .submitLabel(.done)
                .onSubmit {
                    let trimmed = subject.trimmingCharacters(in: .whitespacesAndNewlines)

                    if !trimmed.isEmpty, trimmed != group.chat.whatsappName {
                        Task { await update(WhatsappGroupUpdate(subject: trimmed)) }
                    }
                }
                .accessibilityIdentifier("whatsapp.group.subject")

            TextField(language.localized("Description"), text: $groupDescription, axis: .vertical)
                .lineLimit(2...8)
                .disabled(!group.canEditInfo)
                .accessibilityIdentifier("whatsapp.group.description")

            if group.canEditInfo, groupDescription != (group.chat.description ?? "") {
                Button(language.localized("Save description")) {
                    Task { await update(WhatsappGroupUpdate(description: groupDescription.trimmingCharacters(in: .whitespacesAndNewlines))) }
                }
                .disabled(isSaving)
            }

            if group.canEditInfo {
                let title = language.localized("Change group picture")

                PhotosPicker(selection: $picture, matching: .images) {
                    Label(title, systemImage: "photo")
                }
                .accessibilityIdentifier("whatsapp.group.picture")
            }
        } header: {
            language.text("Group")
        } footer: {
            if let pictureNotice {
                Text(verbatim: pictureNotice)
            } else if !group.canEditInfo {
                Text(verbatim: language.localized("Only admins can edit the group info."))
            }
        }
    }

    @ViewBuilder
    private func settingsSection(_ group: WhatsappGroup) -> some View {
        Section {
            Picker(language.localized("Disappearing messages"), selection: Binding { group.chat.ephemeralSeconds } set: { seconds in
                Task { await update(WhatsappGroupUpdate(ephemeralSeconds: seconds)) }
            }) {
                ForEach(WhatsappEphemeralTimer.choices, id: \.self) { seconds in
                    Text(verbatim: WhatsappGroupText.timer(seconds, in: language)).tag(seconds)
                }
            }
            .disabled(!group.canEditInfo)
            .accessibilityIdentifier("whatsapp.group.timer")

            if group.isAdmin {
                Toggle(language.localized("Only admins send messages"), isOn: Binding { group.adminsOnly } set: { isOn in
                    Task { await update(WhatsappGroupUpdate(adminsOnly: isOn)) }
                })
                Toggle(language.localized("Only admins edit the group info"), isOn: Binding { group.infoAdminsOnly } set: { isOn in
                    Task { await update(WhatsappGroupUpdate(infoAdminsOnly: isOn)) }
                })
            }
        } header: {
            language.text("Group settings")
        }
    }

    private func membersSection(_ group: WhatsappGroup) -> some View {
        Section {
            if group.isAdmin {
                Button(language.localized("Add members"), systemImage: "person.badge.plus") {
                    isAddingMembers = true
                }
                .accessibilityIdentifier("whatsapp.group.add-members")
            }

            ForEach(group.members) { member in
                memberRow(member, isAdmin: group.isAdmin)
            }
        } header: {
            Text(verbatim: language.localized("%lld members", group.members.count))
        } footer: {
            if let membersNotice {
                Text(verbatim: membersNotice)
            }
        }
    }

    private func memberRow(_ member: WhatsappGroup.Member, isAdmin: Bool) -> some View {
        HStack(spacing: 10) {
            Image(systemName: "person.crop.circle.fill")
                .font(.title2)
                .foregroundStyle(WhatsappPalette.sender(member.name))

            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: member.me ? language.localized("You") : member.name)
                    .lineLimit(1)
                if let number = member.number, number != member.name {
                    Text(verbatim: number)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }

            Spacer()

            if member.admin {
                Text(verbatim: language.localized("Admin"))
                    .font(.caption.weight(.medium))
                    .foregroundStyle(WhatsappPalette.send)
                    .padding(.horizontal, 6)
                    .padding(.vertical, 2)
                    .background(WhatsappPalette.send.opacity(0.12), in: .capsule)
            }

            if isAdmin, !member.me {
                Menu(language.localized("Member actions"), systemImage: "ellipsis.circle") {
                    if member.admin {
                        Button(language.localized("Dismiss as admin"), systemImage: "person.badge.minus") {
                            Task { _ = await changeMembers(.demote, [member.jid]) }
                        }
                    } else {
                        Button(language.localized("Make group admin"), systemImage: "person.badge.shield.checkmark") {
                            Task { _ = await changeMembers(.promote, [member.jid]) }
                        }
                    }
                    Button(language.localized("Remove from group"), systemImage: "person.fill.xmark", role: .destructive) {
                        memberToRemove = member
                    }
                }
                .labelStyle(.iconOnly)
                .accessibilityIdentifier("whatsapp.group.member-actions")
            }
        }
        .accessibilityElement(children: .combine)
    }

    @ViewBuilder
    private func inviteSection(_ group: WhatsappGroup) -> some View {
        if group.isAdmin {
            Section {
                if let inviteURL {
                    Text(verbatim: inviteURL)
                        .font(.footnote.monospaced())
                        .textSelection(.enabled)
                    Button(language.localized("Copy link"), systemImage: "doc.on.doc") {
                        UIPasteboard.general.string = inviteURL
                    }
                    if let url = URL(string: inviteURL) {
                        ShareLink(item: url) {
                            Label(language.localized("Share link"), systemImage: "square.and.arrow.up")
                        }
                    }
                    Button(language.localized("Reset link"), systemImage: "arrow.triangle.2.circlepath", role: .destructive) {
                        isConfirmingReset = true
                    }
                } else {
                    Button(language.localized("Show invite link"), systemImage: "link") {
                        Task { await loadInvite(revoke: false) }
                    }
                    .accessibilityIdentifier("whatsapp.group.invite")
                }
            } header: {
                language.text("Invite link")
            }
        }
    }

    private func load() async {
        do throws(APIError) {
            let loaded = try await model.client.send(API.whatsappGroup(chatId: chat.friendlyId)).data
            show(loaded)
            loadFailure = nil
        } catch .unauthenticated {
            return
        } catch {
            loadFailure = error
        }
    }

    private func show(_ loaded: WhatsappGroup) {
        group = loaded
        subject = loaded.chat.whatsappName ?? loaded.chat.name
        groupDescription = loaded.chat.description ?? ""
        onChanged(loaded.chat)
    }

    private func update(_ update: WhatsappGroupUpdate) async {
        isSaving = true
        defer { isSaving = false }

        do throws(APIError) {
            show(try await model.client.send(API.updateWhatsappGroup(chatId: chat.friendlyId, update)).data)
            model.whatsapp.chatsEdited()
        } catch {
            actionFailure = error
        }
    }

    private func upload(_ item: PhotosPickerItem) async {
        defer { picture = nil }

        guard let data = try? await item.loadTransferable(type: Data.self), let image = UIImage(data: data), let jpeg = image.jpegData(compressionQuality: 0.85) else {
            return
        }

        do throws(APIError) {
            try await model.client.send(API.updateWhatsappGroupPicture(chatId: chat.friendlyId, file: .init(name: "file", fileName: "picture.jpg", mimeType: "image/jpeg", data: jpeg)))
            pictureNotice = language.localized("The new picture shows with the chat's next refresh.")
            model.whatsapp.chatsEdited()
        } catch {
            actionFailure = error
        }
    }

    /// Adds, removes, promotes or demotes; says how many WhatsApp refused.
    private func changeMembers(_ action: WhatsappGroupMemberAction, _ participants: [String]) async -> APIError? {
        guard !participants.isEmpty else {
            return nil
        }

        do throws(APIError) {
            let change = try await model.client.send(API.changeWhatsappGroupMembers(chatId: chat.friendlyId, action: action, participants: participants))

            group = group?.withMembers(change.members)

            membersNotice = change.refused > 0 ? language.localized("WhatsApp did not do it for %lld of them.", change.refused) : nil
            model.whatsapp.chatsEdited()

            return nil
        } catch {
            if action != .add {
                actionFailure = error
            }

            return error
        }
    }

    private func loadInvite(revoke: Bool) async {
        do throws(APIError) {
            inviteURL = try await model.client.send(API.whatsappGroupInvite(chatId: chat.friendlyId, revoke: revoke)).url
        } catch {
            actionFailure = error
        }
    }

    private func leave() async {
        do throws(APIError) {
            let left = try await model.client.send(API.leaveWhatsappGroup(chatId: chat.friendlyId)).data
            onChanged(left)
            model.whatsapp.chatsEdited()
            onLeft()
        } catch {
            actionFailure = error
        }
    }
}

/// The words of the group screens.
enum WhatsappGroupText {
    /// "Off", "24 hours", "7 days", "90 days".
    static func timer(_ seconds: Int, in language: AppLanguage) -> String {
        switch seconds {
        case 0: language.localized("Off")
        case 86_400: language.localized("24 hours")
        case 604_800: language.localized("7 days")
        case 7_776_000: language.localized("90 days")
        default: language.localized("%lld days", seconds / 86_400)
        }
    }
}

/// A new WhatsApp group on one of the user's numbers: its subject, an
/// optional description and its members, picked among the number's
/// contacts; the number is its admin. The chat opens once it is made.
struct WhatsappNewGroupForm: View {
    let onCreated: (WhatsappConversation) -> Void

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language
    @Environment(\.dismiss) private var dismiss

    @State private var accounts: [WhatsappAccount]?
    @State private var accountId: String?
    @State private var subject = ""
    @State private var groupDescription = ""
    @State private var members: [WhatsappConversation] = []
    @State private var isPickingMembers = false
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
                                .onChange(of: accountId) {
                                    members = []
                                }
                            }

                            TextField(language.localized("Subject"), text: $subject)
                                .accessibilityIdentifier("whatsapp.new-group.subject")
                            TextField(language.localized("Description (optional)"), text: $groupDescription, axis: .vertical)
                                .lineLimit(2...6)
                        } footer: {
                            if let error = failure?.firstError(for: "subject") {
                                Text(verbatim: error).foregroundStyle(.red)
                            }
                        }

                        Section {
                            Button(language.localized("Pick members"), systemImage: "person.badge.plus") {
                                isPickingMembers = true
                            }
                            .accessibilityIdentifier("whatsapp.new-group.members")

                            ForEach(members) { member in
                                HStack(spacing: 10) {
                                    WhatsappChatAvatar(chat: member, size: 30)
                                    Text(verbatim: member.name)
                                }
                            }
                            .onDelete { offsets in
                                members.remove(atOffsets: offsets)
                            }
                        } header: {
                            Text(verbatim: language.localized("%lld members", members.count))
                        } footer: {
                            if let failure, failure.firstError(for: "subject") == nil {
                                Text(verbatim: failure.firstError(for: "members") ?? failure.userMessage(in: language))
                                    .foregroundStyle(.red)
                            } else {
                                Text(verbatim: language.localized("WhatsApp leaves out whoever is not on it."))
                            }
                        }
                    }
                } else {
                    ProgressView()
                        .frame(maxWidth: .infinity)
                }
            }
            .navigationTitle(language.localized("New group"))
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
                        Button(language.localized("Create"), role: .confirm) {
                            Task { await create() }
                        }
                        .disabled(accountId == nil || subject.trimmingCharacters(in: .whitespaces).isEmpty || members.isEmpty)
                        .accessibilityIdentifier("whatsapp.new-group.create")
                    }
                }
            }
            .sheet(isPresented: $isPickingMembers) {
                if let accountId {
                    WhatsappContactPicker(
                        accountId: accountId,
                        title: language.localized("Pick members"),
                        actionTitle: language.localized("Done"),
                        initial: members
                    ) { picked in
                        members = picked

                        return nil
                    }
                }
            }
            .task {
                accounts = (try? await model.client.send(API.whatsappAccounts()).data) ?? []
                accountId = accountId ?? accounts?.first(where: \.isOpen)?.friendlyId
            }
        }
    }

    private func create() async {
        guard let accountId else {
            return
        }

        isSaving = true
        defer { isSaving = false }

        do throws(APIError) {
            let chat = try await model.client.send(API.createWhatsappGroup(accountId: accountId, subject: subject, memberIds: members.map(\.friendlyId), description: groupDescription)).data

            dismiss()
            onCreated(chat)
        } catch {
            failure = error
        }
    }
}
