import Localization
import Networking
import SwiftUI

/// The user's workspaces (from `/me`), each with the color they see it in
/// and the default one marked; a workspace opens its preferences.
struct WorkspacesSettingsSection: View {
    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    var body: some View {
        if let workspaces = model.user?.workspaces, !workspaces.isEmpty {
            Section {
                ForEach(workspaces) { workspace in
                    NavigationLink {
                        WorkspacePreferencesView(workspaceId: workspace.id)
                    } label: {
                        HStack(spacing: 10) {
                            WorkspaceDot(hex: workspace.colorHex, size: 12)
                            Text(verbatim: workspace.name)

                            Spacer()

                            if workspace.isDefault {
                                language.text("Default")
                                    .font(.subheadline)
                                    .foregroundStyle(.secondary)
                            }
                        }
                    }
                    .accessibilityIdentifier("settings.workspace.\(workspace.id)")
                }
            } header: {
                language.text("Workspaces")
            } footer: {
                language.text("You see the lists and reminders of all your workspaces together. New ones go to your default workspace.")
            }
        }
    }
}

/// One of the user's workspaces: the color they see it in — their own, or
/// the workspace's — and making it their default. Saved at once; `/me` is
/// read again so every screen follows.
struct WorkspacePreferencesView: View {
    let workspaceId: String

    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    @State private var colors: [WorkspaceColorOption] = []
    @State private var colorsFailure: APIError?
    @State private var isSaving = false
    @State private var failure: APIError?

    var body: some View {
        Form {
            if let workspace {
                Section {
                    LabeledContent(language.localized("Name"), value: workspace.name)

                    if !workspace.modules.isEmpty {
                        LabeledContent(language.localized("Modules"), value: moduleNames(of: workspace))
                    }
                }

                Section {
                    if colors.isEmpty, colorsFailure == nil {
                        ProgressView()
                            .frame(maxWidth: .infinity)
                    } else if let colorsFailure, colors.isEmpty {
                        Label(colorsFailure.userMessage(in: language), systemImage: "wifi.exclamationmark")
                            .foregroundStyle(.secondary)
                    } else {
                        WorkspaceColorPalette(
                            colors: colors,
                            selection: colorBinding(of: workspace),
                            allowsWorkspaceColor: true,
                            workspaceColorHex: colors.first { $0.name == workspace.workspaceColor }?.hex
                        )
                        .disabled(isSaving)
                    }
                } header: {
                    language.text("Your color")
                } footer: {
                    language.text("Only you see this color.")
                }

                Section {
                    if workspace.isDefault {
                        Label(language.localized("Your default workspace"), systemImage: "star.fill")
                            .foregroundStyle(.secondary)
                    } else {
                        Button(language.localized("Make default"), systemImage: "star") {
                            Task { await save(WorkspacePreferenceInput(makeDefault: true)) }
                        }
                        .disabled(isSaving)
                        .accessibilityIdentifier("settings.workspace.make-default")
                    }
                } footer: {
                    language.text("New lists and reminders go to the default workspace unless you pick another.")
                }

                if let failure {
                    Section {
                        Label(failure.userMessage(in: language), systemImage: "exclamationmark.triangle.fill")
                            .foregroundStyle(.red)
                    }
                }
            } else {
                ContentUnavailableView(language.localized("Workspace not found"), systemImage: "questionmark.circle")
            }
        }
        .navigationTitle(workspace?.name ?? language.localized("Workspace"))
        .navigationBarTitleDisplayMode(.inline)
        .task {
            await loadColors()
        }
    }

    /// Read from `/me`, so it follows what was saved.
    private var workspace: UserWorkspace? {
        model.user?.workspaces.first { $0.id == workspaceId }
    }

    /// The modules of the workspace by the names of `/me`.
    private func moduleNames(of workspace: UserWorkspace) -> String {
        let names = Dictionary(model.user?.modules.map { ($0.slug, $0.name) } ?? [], uniquingKeysWith: { first, _ in first })

        return workspace.modules.map { names[$0] ?? $0 }.formatted(.list(type: .and).locale(language.locale))
    }

    /// The user's own color (nil: the workspace's); picking one saves it.
    private func colorBinding(of workspace: UserWorkspace) -> Binding<String?> {
        Binding {
            workspace.ownColor
        } set: { newColor in
            guard newColor != workspace.ownColor else {
                return
            }

            Task { await save(WorkspacePreferenceInput(color: newColor.map { .own($0) } ?? .workspaceColor)) }
        }
    }

    private func loadColors() async {
        do throws(APIError) {
            colors = try await model.client.send(API.workspaceColors()).data
            colorsFailure = nil
        } catch {
            colorsFailure = error
        }
    }

    private func save(_ input: WorkspacePreferenceInput) async {
        isSaving = true
        failure = nil
        defer { isSaving = false }

        do throws(APIError) {
            try await model.updateWorkspacePreference(id: workspaceId, input)
        } catch {
            failure = error
        }
    }
}
