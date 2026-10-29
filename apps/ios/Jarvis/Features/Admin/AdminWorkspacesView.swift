import Localization
import Networking
import SwiftUI

/// The super admin's workspaces: each with its color and how many members
/// it has; a new one, and deleting one — refused by the server while it has
/// data, with its message. A workspace opens its members.
struct AdminWorkspacesView: View {
    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    @State private var workspaces: [AdminWorkspace]?
    @State private var loadFailure: APIError?
    @State private var actionFailure: APIError?
    @State private var isCreating = false
    @State private var workspaceToDelete: AdminWorkspace?

    var body: some View {
        content
            .navigationTitle(language.localized("Workspaces"))
            .toolbar {
                ToolbarItem(placement: .primaryAction) {
                    Button(language.localized("New workspace"), systemImage: "plus") {
                        isCreating = true
                    }
                    .accessibilityIdentifier("admin.workspaces.new")
                }
            }
            .task {
                await load()
            }
            .refreshable {
                await load()
            }
            .sheet(isPresented: $isCreating) {
                AdminWorkspaceForm(workspace: nil) {
                    await load()
                }
            }
            .confirmationDialog(
                language.localized("Delete this workspace?"),
                isPresented: Binding { workspaceToDelete != nil } set: { if !$0 { workspaceToDelete = nil } },
                titleVisibility: .visible,
                presenting: workspaceToDelete
            ) { workspace in
                Button(language.localized("Delete"), role: .destructive) {
                    Task { await delete(workspace) }
                }
                Button(language.localized("Cancel"), role: .cancel) {}
            } message: { _ in
                language.text("A workspace with lists, reminders or invoices cannot be deleted.")
            }
            .actionFailureAlert($actionFailure)
    }

    @ViewBuilder
    private var content: some View {
        if let workspaces {
            if workspaces.isEmpty {
                ContentUnavailableView(language.localized("No workspaces"), systemImage: "square.stack.3d.up")
            } else {
                List(workspaces) { workspace in
                    NavigationLink {
                        AdminWorkspaceDetailView(workspaceId: workspace.id) {
                            await load()
                        }
                    } label: {
                        HStack(spacing: 10) {
                            WorkspaceDot(hex: workspace.colorHex, size: 12)

                            VStack(alignment: .leading, spacing: 2) {
                                Text(verbatim: workspace.name)
                                Text(verbatim: language.localized("%lld members", workspace.membersCount))
                                    .font(.subheadline)
                                    .foregroundStyle(.secondary)
                            }
                        }
                        .padding(.vertical, 2)
                    }
                    .swipeActions(edge: .trailing) {
                        Button(language.localized("Delete"), systemImage: "trash", role: .destructive) {
                            workspaceToDelete = workspace
                        }
                    }
                    .accessibilityIdentifier("admin.workspace.\(workspace.id)")
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
            workspaces = try await model.client.send(API.adminWorkspaces()).data
            loadFailure = nil
        } catch .unauthenticated {
            // The shell already went back to the login.
        } catch {
            if workspaces == nil {
                loadFailure = error
            }
        }
    }

    private func delete(_ workspace: AdminWorkspace) async {
        do throws(APIError) {
            try await model.client.send(API.deleteAdminWorkspace(id: workspace.id))
            workspaces?.removeAll { $0.id == workspace.id }
            await model.refreshUser()
        } catch {
            actionFailure = error
        }
    }
}

/// A workspace and its members: their modules there, their color and
/// whether it is their default (a member opens to change them, or to be
/// removed); adding an existing user; renaming, recoloring and deleting it.
struct AdminWorkspaceDetailView: View {
    let workspaceId: String
    let onChange: () async -> Void

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language
    @Environment(\.dismiss) private var dismiss

    @State private var detail: AdminWorkspaceDetail?
    @State private var catalog = AdminCatalog()
    @State private var loadFailure: APIError?
    @State private var actionFailure: APIError?
    @State private var isEditing = false
    @State private var isAddingMember = false
    @State private var isConfirmingDelete = false

    var body: some View {
        content
            .navigationTitle(detail?.name ?? language.localized("Workspace"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                if detail != nil {
                    ToolbarItem(placement: .primaryAction) {
                        Button(language.localized("Edit")) {
                            isEditing = true
                        }
                        .accessibilityIdentifier("admin.workspace.edit")
                    }
                }
            }
            .task {
                await load()
            }
            .refreshable {
                await load()
            }
            .sheet(isPresented: $isEditing) {
                if let detail {
                    AdminWorkspaceForm(workspace: detail) {
                        await changed()
                    }
                }
            }
            .sheet(isPresented: $isAddingMember) {
                if let detail {
                    AdminAddMemberForm(workspace: detail, catalog: catalog) {
                        await changed()
                    }
                }
            }
            .confirmationDialog(
                language.localized("Delete this workspace?"),
                isPresented: $isConfirmingDelete,
                titleVisibility: .visible
            ) {
                Button(language.localized("Delete"), role: .destructive) {
                    Task { await delete() }
                }
                Button(language.localized("Cancel"), role: .cancel) {}
            } message: {
                language.text("A workspace with lists, reminders or invoices cannot be deleted.")
            }
            .actionFailureAlert($actionFailure)
    }

    @ViewBuilder
    private var content: some View {
        if let detail {
            Form {
                Section {
                    HStack(spacing: 10) {
                        WorkspaceDot(hex: detail.colorHex, size: 14)
                        Text(verbatim: detail.name)
                            .font(.headline)
                    }
                }

                Section {
                    ForEach(detail.members) { member in
                        NavigationLink {
                            AdminMemberView(workspace: detail, member: member, catalog: catalog) {
                                await changed()
                            }
                        } label: {
                            AdminMemberRow(member: member, catalog: catalog)
                        }
                        .accessibilityIdentifier("admin.member.\(member.id)")
                    }

                    Button(language.localized("Add member"), systemImage: "person.badge.plus") {
                        isAddingMember = true
                    }
                    .accessibilityIdentifier("admin.workspace.add-member")
                } header: {
                    language.text("Members")
                }

                Section {
                    Button(language.localized("Delete workspace"), systemImage: "trash", role: .destructive) {
                        isConfirmingDelete = true
                    }
                    .accessibilityIdentifier("admin.workspace.delete")
                } footer: {
                    if detail.hasData {
                        language.text("This workspace has data, so it cannot be deleted.")
                    }
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

            detail = try await model.client.send(API.adminWorkspace(id: workspaceId)).data
            loadFailure = nil
        } catch .unauthenticated {
            // The shell already went back to the login.
        } catch {
            if detail == nil {
                loadFailure = error
            }
        }
    }

    /// After a change here: this screen, the list behind it and `/me` (the
    /// admin may have changed their own membership).
    private func changed() async {
        await load()
        await onChange()
        await model.refreshUser()
    }

    private func delete() async {
        do throws(APIError) {
            try await model.client.send(API.deleteAdminWorkspace(id: workspaceId))
            await onChange()
            await model.refreshUser()
            dismiss()
        } catch {
            actionFailure = error
        }
    }
}

/// A member: their name, their color there, their modules and whether it
/// is their default.
private struct AdminMemberRow: View {
    let member: AdminWorkspaceMember
    let catalog: AdminCatalog

    @Environment(\.appLanguage) private var language

    var body: some View {
        HStack(spacing: 10) {
            WorkspaceDot(hex: member.effectiveColorHex, size: 12)

            VStack(alignment: .leading, spacing: 2) {
                HStack(spacing: 6) {
                    Text(verbatim: member.user.name)
                        .strikethrough(member.user.isDeactivated)

                    if member.isDefault {
                        Image(systemName: "star.fill")
                            .font(.caption)
                            .foregroundStyle(.yellow)
                            .accessibilityLabel(Text(verbatim: language.localized("Default")))
                    }
                }

                Text(verbatim: modulesText)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                    .lineLimit(2)
            }
        }
        .padding(.vertical, 2)
    }

    private var modulesText: String {
        if member.user.isSuperAdmin {
            return language.localized("Super admin: every module")
        }

        guard !member.modules.isEmpty else {
            return language.localized("No modules")
        }

        return member.modules.map(catalog.moduleName).formatted(.list(type: .and).locale(language.locale))
    }
}

/// A new workspace (name and color) or an edit of one.
struct AdminWorkspaceForm: View {
    let workspace: AdminWorkspaceDetail?
    let onSave: () async -> Void

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language
    @Environment(\.dismiss) private var dismiss

    @State private var name = ""
    @State private var color: String?
    @State private var colors: [WorkspaceColorOption] = []
    @State private var isSaving = false
    @State private var failure: APIError?

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    TextField(language.localized("Name"), text: $name)
                        .accessibilityIdentifier("admin.workspace.name")
                }

                Section {
                    if colors.isEmpty {
                        ProgressView()
                            .frame(maxWidth: .infinity)
                    } else {
                        WorkspaceColorPalette(colors: colors, selection: $color)
                    }
                } header: {
                    language.text("Color")
                }

                FormFailureSection(failure: failure)
            }
            .navigationTitle(language.localized(workspace == nil ? "New workspace" : "Edit workspace"))
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
                    .disabled(trimmedName.isEmpty || color == nil || isSaving)
                }
            }
            .task {
                await fill()
            }
        }
    }

    private var trimmedName: String {
        name.trimmingCharacters(in: .whitespacesAndNewlines)
    }

    private func fill() async {
        if let workspace, name.isEmpty {
            name = workspace.name
            color = workspace.color
        }

        do throws(APIError) {
            colors = try await model.client.send(API.workspaceColors()).data

            if color == nil {
                color = colors.first?.name
            }
        } catch {
            failure = error
        }
    }

    private func save() async {
        guard let color else {
            return
        }

        isSaving = true
        defer { isSaving = false }

        let input = AdminWorkspaceInput(name: trimmedName, color: color)

        do throws(APIError) {
            if let workspace {
                try await model.client.send(API.updateAdminWorkspace(id: workspace.id, input))
            } else {
                try await model.client.send(API.createAdminWorkspace(input))
            }

            await onSave()
            dismiss()
        } catch {
            failure = error
        }
    }
}
