import Localization
import Networking
import SwiftUI

/// The invitations, for the super admin: who, to which workspace, their
/// state; a new one, and sending again or revoking an open one.
struct AdminInvitationsView: View {
    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    @State private var invitations: [AdminInvitation]?
    @State private var catalog = AdminCatalog()
    @State private var loadFailure: APIError?
    @State private var actionFailure: APIError?
    @State private var isCreating = false
    @State private var invitationToRevoke: AdminInvitation?
    @State private var resentEmail: String?

    var body: some View {
        content
            .navigationTitle(language.localized("Invitations"))
            .toolbar {
                ToolbarItem(placement: .primaryAction) {
                    Button(language.localized("New invitation"), systemImage: "plus") {
                        isCreating = true
                    }
                    .accessibilityIdentifier("admin.invitations.new")
                }
            }
            .task {
                await load()
            }
            .refreshable {
                await load()
            }
            .sheet(isPresented: $isCreating) {
                AdminInvitationForm(catalog: catalog) {
                    await load()
                }
            }
            .confirmationDialog(
                language.localized("Revoke this invitation?"),
                isPresented: Binding { invitationToRevoke != nil } set: { if !$0 { invitationToRevoke = nil } },
                titleVisibility: .visible,
                presenting: invitationToRevoke
            ) { invitation in
                Button(language.localized("Revoke"), role: .destructive) {
                    Task { await revoke(invitation) }
                }
                Button(language.localized("Cancel"), role: .cancel) {}
            } message: { invitation in
                Text(verbatim: language.localized("The link sent to %@ stops working.", invitation.email))
            }
            .alert(
                language.localized("Invitation sent again"),
                isPresented: Binding { resentEmail != nil } set: { if !$0 { resentEmail = nil } },
                presenting: resentEmail
            ) { _ in
                Button(language.localized("OK"), role: .cancel) {}
            } message: { email in
                Text(verbatim: email)
            }
            .actionFailureAlert($actionFailure)
    }

    @ViewBuilder
    private var content: some View {
        if let invitations {
            if invitations.isEmpty {
                ContentUnavailableView(language.localized("No invitations"), systemImage: "envelope")
            } else {
                List(invitations) { invitation in
                    AdminInvitationRow(invitation: invitation, catalog: catalog)
                        .swipeActions(edge: .trailing) {
                            if invitation.isPending {
                                Button(language.localized("Revoke"), systemImage: "xmark.circle", role: .destructive) {
                                    invitationToRevoke = invitation
                                }

                                Button(language.localized("Resend"), systemImage: "arrow.clockwise") {
                                    Task { await resend(invitation) }
                                }
                                .tint(.blue)
                            }
                        }
                        .accessibilityIdentifier("admin.invitation.\(invitation.id)")
                }
            }
        } else if let loadFailure {
            AdminLoadFailureView(failure: loadFailure) {
                await load()
            }
        } else {
            ProgressView(language.localized("Loading…"))
                .frame(maxWidth: .infinity, maxHeight: .infinity)
        }
    }

    private func load() async {
        do throws(APIError) {
            if catalog.modules.isEmpty {
                catalog = try await AdminCatalog.load(with: model.client)
            }

            invitations = try await model.client.send(API.adminInvitations()).data
            loadFailure = nil
        } catch .unauthenticated {
            // The shell already went back to the login.
        } catch {
            if invitations == nil {
                loadFailure = error
            }
        }
    }

    private func resend(_ invitation: AdminInvitation) async {
        do throws(APIError) {
            replace(try await model.client.send(API.resendAdminInvitation(id: invitation.id)).data)
            resentEmail = invitation.email
        } catch {
            actionFailure = error
        }
    }

    private func revoke(_ invitation: AdminInvitation) async {
        do throws(APIError) {
            replace(try await model.client.send(API.revokeAdminInvitation(id: invitation.id)).data)
        } catch {
            actionFailure = error
        }
    }

    private func replace(_ saved: AdminInvitation) {
        if let index = invitations?.firstIndex(where: { $0.id == saved.id }) {
            invitations?[index] = saved
        }
    }
}

/// An invitation: the email, its state, the workspace, the modules and
/// until when it can be accepted.
private struct AdminInvitationRow: View {
    let invitation: AdminInvitation
    let catalog: AdminCatalog

    @Environment(\.appLanguage) private var language

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            HStack {
                Text(verbatim: invitation.email)
                    .lineLimit(1)

                Spacer(minLength: 8)

                Text(verbatim: statusText)
                    .font(.caption.weight(.semibold))
                    .foregroundStyle(statusColor)
            }

            if let workspace = invitation.workspace {
                WorkspaceBadge(workspace: workspace)
            }

            if !invitation.modules.isEmpty {
                Text(verbatim: invitation.modules.map(catalog.moduleName).formatted(.list(type: .and).locale(language.locale)))
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .lineLimit(2)
            }

            if invitation.isPending, let expiresAt = invitation.expiresAt {
                Text(verbatim: language.localized("Expires %@", expiresAt.formatted(date: .abbreviated, time: .shortened)))
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
        }
        .padding(.vertical, 2)
    }

    private var statusText: String {
        switch invitation.knownStatus {
        case .pending: language.localized("Pending")
        case .accepted: language.localized("Accepted")
        case .expired: language.localized("Expired")
        case .revoked: language.localized("Revoked")
        case nil: invitation.status
        }
    }

    private var statusColor: Color {
        switch invitation.knownStatus {
        case .pending: .orange
        case .accepted: .green
        case .expired, .revoked, nil: .secondary
        }
    }
}

/// A new invitation: the email, the workspace the person joins, their
/// modules there and the language of the email.
struct AdminInvitationForm: View {
    let catalog: AdminCatalog
    let onSave: () async -> Void

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language
    @Environment(\.dismiss) private var dismiss

    @State private var email = ""
    @State private var workspaces: [AdminWorkspace]?
    @State private var workspaceId: String?
    @State private var modules: Set<String> = []
    @State private var locale = AppLanguage.brazilianPortuguese
    @State private var isSaving = false
    @State private var failure: APIError?

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    TextField(language.localized("Email"), text: $email)
                        .keyboardType(.emailAddress)
                        .textContentType(.emailAddress)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                        .accessibilityIdentifier("admin.invitation.email")

                    Picker(language.localized("Language of the email"), selection: $locale) {
                        ForEach(AppLanguage.allCases) { option in
                            Text(verbatim: option.nativeName).tag(option)
                        }
                    }
                    .accessibilityIdentifier("admin.invitation.locale")
                }

                Section {
                    if let workspaces {
                        Picker(selection: $workspaceId) {
                            ForEach(workspaces) { workspace in
                                Label {
                                    Text(verbatim: workspace.name)
                                } icon: {
                                    WorkspaceDot(hex: workspace.colorHex, size: 12)
                                }
                                .tag(String?.some(workspace.id))
                            }
                        } label: {
                            language.text("Workspace")
                        }
                        .pickerStyle(.inline)
                        .labelsHidden()
                        .accessibilityIdentifier("admin.invitation.workspace")
                    } else {
                        ProgressView()
                            .frame(maxWidth: .infinity)
                    }
                } header: {
                    language.text("Workspace")
                }

                ModuleTogglesSection(modules: catalog.activeModules, selection: $modules)

                FormFailureSection(failure: failure)
            }
            .navigationTitle(language.localized("New invitation"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(language.localized("Cancel"), role: .cancel) {
                        dismiss()
                    }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button(language.localized("Send"), role: .confirm) {
                        Task { await save() }
                    }
                    .disabled(trimmedEmail.isEmpty || workspaceId == nil || isSaving)
                    .accessibilityIdentifier("admin.invitation.send")
                }
            }
            .task {
                await loadWorkspaces()
            }
        }
    }

    private var trimmedEmail: String {
        email.trimmingCharacters(in: .whitespacesAndNewlines)
    }

    private func loadWorkspaces() async {
        do throws(APIError) {
            let loaded = try await model.client.send(API.adminWorkspaces()).data
            workspaces = loaded

            if workspaceId == nil {
                let preferred = model.user?.workspaces.first(where: \.isDefault)?.id
                workspaceId = loaded.first { $0.id == preferred }?.id ?? loaded.first?.id
            }
        } catch {
            workspaces = []
            failure = error
        }
    }

    private func save() async {
        guard let workspaceId else {
            return
        }

        isSaving = true
        defer { isSaving = false }

        let input = AdminInvitationInput(email: trimmedEmail, workspace: workspaceId, modules: modules.sorted(), locale: locale.rawValue)

        do throws(APIError) {
            try await model.client.send(API.createAdminInvitation(input))
            await onSave()
            dismiss()
        } catch {
            failure = error
        }
    }
}
