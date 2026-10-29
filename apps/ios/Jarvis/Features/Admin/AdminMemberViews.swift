import Localization
import Networking
import SwiftUI

/// A member of a workspace: their modules there, their own color for it
/// (or the workspace's) and making it their default; removing them. The
/// server checks it; a refusal shows under the form.
struct AdminMemberView: View {
    let workspace: AdminWorkspaceDetail
    let member: AdminWorkspaceMember
    let catalog: AdminCatalog
    let onChange: () async -> Void

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language
    @Environment(\.dismiss) private var dismiss

    @State private var modules: Set<String>
    @State private var color: String?
    @State private var makesDefault = false
    @State private var isSaving = false
    @State private var failure: APIError?
    @State private var isConfirmingRemove = false

    init(workspace: AdminWorkspaceDetail, member: AdminWorkspaceMember, catalog: AdminCatalog, onChange: @escaping () async -> Void) {
        self.workspace = workspace
        self.member = member
        self.catalog = catalog
        self.onChange = onChange
        _modules = State(initialValue: Set(member.modules))
        _color = State(initialValue: member.color)
    }

    var body: some View {
        Form {
            Section {
                LabeledContent(language.localized("Name"), value: member.user.name)
                LabeledContent(language.localized("Email"), value: member.user.email)

                if member.user.isDeactivated {
                    Label(language.localized("Deactivated"), systemImage: "person.slash")
                        .foregroundStyle(.secondary)
                }
            }

            if member.user.isSuperAdmin {
                Section {
                    language.text("A super admin has every module in the workspaces they are in.")
                        .foregroundStyle(.secondary)
                }
            } else {
                ModuleTogglesSection(modules: catalog.activeModules, selection: $modules)
            }

            Section {
                WorkspaceColorPalette(
                    colors: catalog.colors,
                    selection: $color,
                    allowsWorkspaceColor: true,
                    workspaceColorHex: workspace.colorHex
                )
            } header: {
                language.text("Their color")
            } footer: {
                language.text("Only this member sees this color.")
            }

            Section {
                if member.isDefault {
                    Label(language.localized("Their default workspace"), systemImage: "star.fill")
                        .foregroundStyle(.secondary)
                } else {
                    Toggle(language.localized("Make it their default workspace"), isOn: $makesDefault)
                        .accessibilityIdentifier("admin.member.default")
                }
            }

            FormFailureSection(failure: failure)

            Section {
                Button(language.localized("Remove from the workspace"), systemImage: "person.badge.minus", role: .destructive) {
                    isConfirmingRemove = true
                }
                .accessibilityIdentifier("admin.member.remove")
            }
        }
        .navigationTitle(member.user.name)
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .confirmationAction) {
                Button(language.localized("Save"), role: .confirm) {
                    Task { await save() }
                }
                .disabled(isSaving)
                .accessibilityIdentifier("admin.member.save")
            }
        }
        .confirmationDialog(
            language.localized("Remove %@ from %@?", member.user.name, workspace.name),
            isPresented: $isConfirmingRemove,
            titleVisibility: .visible
        ) {
            Button(language.localized("Remove"), role: .destructive) {
                Task { await remove() }
            }
            Button(language.localized("Cancel"), role: .cancel) {}
        } message: {
            language.text("They stop seeing the lists and reminders of this workspace.")
        }
    }

    private func save() async {
        isSaving = true
        defer { isSaving = false }

        let input = AdminMemberInput(modules: modules.sorted(), color: color, isDefault: makesDefault)

        do throws(APIError) {
            try await model.client.send(API.updateAdminWorkspaceMember(workspaceId: workspace.id, userId: member.id, input))
            await onChange()
            dismiss()
        } catch {
            failure = error
        }
    }

    private func remove() async {
        do throws(APIError) {
            try await model.client.send(API.removeAdminWorkspaceMember(workspaceId: workspace.id, userId: member.id))
            await onChange()
            dismiss()
        } catch {
            failure = error
        }
    }
}

/// Adds an existing user to a workspace, with their modules there and,
/// optionally, a color of their own for it.
struct AdminAddMemberForm: View {
    let workspace: AdminWorkspaceDetail
    let catalog: AdminCatalog
    let onSave: () async -> Void

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language
    @Environment(\.dismiss) private var dismiss

    @State private var users: [AdminUser]?
    @State private var userId: String?
    @State private var modules: Set<String> = []
    @State private var color: String?
    @State private var isSaving = false
    @State private var failure: APIError?

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    if let candidates {
                        if candidates.isEmpty {
                            language.text("Every user is already a member.")
                                .foregroundStyle(.secondary)
                        } else {
                            Picker(language.localized("User"), selection: $userId) {
                                language.text("Choose…").tag(String?.none)
                                ForEach(candidates) { user in
                                    Text(verbatim: "\(user.name) · \(user.email)").tag(String?.some(user.id))
                                }
                            }
                            .accessibilityIdentifier("admin.add-member.user")
                        }
                    } else {
                        ProgressView()
                            .frame(maxWidth: .infinity)
                    }
                }

                if !selectedIsSuperAdmin {
                    ModuleTogglesSection(modules: catalog.activeModules, selection: $modules)
                }

                Section {
                    WorkspaceColorPalette(
                        colors: catalog.colors,
                        selection: $color,
                        allowsWorkspaceColor: true,
                        workspaceColorHex: workspace.colorHex
                    )
                } header: {
                    language.text("Their color")
                }

                FormFailureSection(failure: failure)
            }
            .navigationTitle(language.localized("Add member"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(language.localized("Cancel"), role: .cancel) {
                        dismiss()
                    }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button(language.localized("Add"), role: .confirm) {
                        Task { await save() }
                    }
                    .disabled(userId == nil || isSaving)
                }
            }
            .task {
                await loadUsers()
            }
        }
    }

    /// The active users who are not members yet.
    private var candidates: [AdminUser]? {
        let memberIds = Set(workspace.members.map(\.id))

        return users?.filter { !memberIds.contains($0.id) && !$0.isDeactivated }
    }

    private var selectedIsSuperAdmin: Bool {
        users?.first { $0.id == userId }?.isSuperAdmin ?? false
    }

    private func loadUsers() async {
        do throws(APIError) {
            users = try await model.client.send(API.adminUsers()).data
        } catch {
            users = []
            failure = error
        }
    }

    private func save() async {
        guard let userId else {
            return
        }

        isSaving = true
        defer { isSaving = false }

        let input = AdminMemberInput(user: userId, modules: modules.sorted(), color: color)

        do throws(APIError) {
            try await model.client.send(API.addAdminWorkspaceMember(workspaceId: workspace.id, input))
            await onSave()
            dismiss()
        } catch {
            failure = error
        }
    }
}
