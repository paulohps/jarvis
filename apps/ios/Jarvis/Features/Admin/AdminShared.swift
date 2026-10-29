import Localization
import Networking
import SwiftUI

/// The super admin's entries in Settings: the workspaces (and their
/// members), the users and the invitations. Only shown to the super admin;
/// the server refuses anyone else (403).
struct AdminSettingsSection: View {
    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    var body: some View {
        if model.user?.isSuperAdmin == true {
            Section {
                NavigationLink {
                    AdminWorkspacesView()
                } label: {
                    Label(language.localized("Workspaces"), systemImage: "square.stack.3d.up")
                }
                .accessibilityIdentifier("settings.admin.workspaces")

                NavigationLink {
                    AdminUsersView()
                } label: {
                    Label(language.localized("Users"), systemImage: "person.2")
                }
                .accessibilityIdentifier("settings.admin.users")

                NavigationLink {
                    AdminInvitationsView()
                } label: {
                    Label(language.localized("Invitations"), systemImage: "envelope")
                }
                .accessibilityIdentifier("settings.admin.invitations")
            } header: {
                language.text("Administration")
            }
        }
    }
}

/// What the admin forms pick from: the modules and the workspace colors.
struct AdminCatalog: Sendable {
    var modules: [AdminModule] = []
    var colors: [WorkspaceColorOption] = []

    /// The modules that can be given: the active ones.
    var activeModules: [AdminModule] {
        modules.filter(\.isActive)
    }

    static func load(with client: APIClient) async throws(APIError) -> AdminCatalog {
        async let modules = client.send(API.adminModules())
        async let colors = client.send(API.workspaceColors())

        do {
            return try await AdminCatalog(modules: modules.data, colors: colors.data)
        } catch let error as APIError {
            throw error
        } catch {
            throw .transport(description: error.localizedDescription)
        }
    }

    func moduleName(_ slug: String) -> String {
        modules.first { $0.slug == slug }?.name ?? slug
    }
}

/// A screen that could not load: "No access" on a 403, otherwise the
/// message and a retry.
struct AdminLoadFailureView: View {
    let failure: APIError
    let retry: () async -> Void

    @Environment(\.appLanguage) private var language

    var body: some View {
        if case .forbidden = failure {
            NoAccessView()
        } else {
            ContentUnavailableView {
                Label(failure.userMessage(in: language), systemImage: "wifi.exclamationmark")
            } actions: {
                Button(language.localized("Try again")) {
                    Task { await retry() }
                }
                .buttonStyle(.glass)
            }
        }
    }
}

/// A toggle per module, for a membership or an invitation.
struct ModuleTogglesSection: View {
    let modules: [AdminModule]
    @Binding var selection: Set<String>

    @Environment(\.appLanguage) private var language

    var body: some View {
        Section {
            if modules.isEmpty {
                ProgressView()
                    .frame(maxWidth: .infinity)
            }

            ForEach(modules) { module in
                Toggle(isOn: Binding {
                    selection.contains(module.slug)
                } set: { isOn in
                    if isOn {
                        selection.insert(module.slug)
                    } else {
                        selection.remove(module.slug)
                    }
                }) {
                    Text(verbatim: module.name)
                }
                .accessibilityIdentifier("admin.module.\(module.slug)")
            }
        } header: {
            language.text("Modules")
        }
    }
}

/// A refusal of the server under a form, in the user's language.
struct FormFailureSection: View {
    let failure: APIError?

    @Environment(\.appLanguage) private var language

    var body: some View {
        if let failure {
            Section {
                Label(failure.userMessage(in: language), systemImage: "exclamationmark.triangle.fill")
                    .foregroundStyle(.red)
                    .accessibilityIdentifier("admin.form.error")
            }
        }
    }
}
