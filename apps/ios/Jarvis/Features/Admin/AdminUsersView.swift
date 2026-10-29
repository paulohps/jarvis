import Localization
import Networking
import SwiftUI

/// The users, for the super admin: each with the workspaces they are in;
/// deactivating one (no more access) or reactivating them.
struct AdminUsersView: View {
    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    @State private var users: [AdminUser]?
    @State private var loadFailure: APIError?
    @State private var actionFailure: APIError?
    @State private var userToDeactivate: AdminUser?

    var body: some View {
        content
            .navigationTitle(language.localized("Users"))
            .task {
                await load()
            }
            .refreshable {
                await load()
            }
            .confirmationDialog(
                language.localized("Deactivate %@?", userToDeactivate?.name ?? ""),
                isPresented: Binding { userToDeactivate != nil } set: { if !$0 { userToDeactivate = nil } },
                titleVisibility: .visible,
                presenting: userToDeactivate
            ) { user in
                Button(language.localized("Deactivate"), role: .destructive) {
                    Task { await setActive(user, false) }
                }
                Button(language.localized("Cancel"), role: .cancel) {}
            } message: { _ in
                language.text("They are signed out and cannot sign in until reactivated.")
            }
            .actionFailureAlert($actionFailure)
    }

    @ViewBuilder
    private var content: some View {
        if let users {
            if users.isEmpty {
                ContentUnavailableView(language.localized("No users"), systemImage: "person.2")
            } else {
                List(users) { user in
                    AdminUserRow(user: user)
                        .swipeActions(edge: .trailing) {
                            if user.id != model.user?.friendlyId {
                                if user.isDeactivated {
                                    Button(language.localized("Reactivate"), systemImage: "person.badge.plus") {
                                        Task { await setActive(user, true) }
                                    }
                                    .tint(.green)
                                } else {
                                    Button(language.localized("Deactivate"), systemImage: "person.slash", role: .destructive) {
                                        userToDeactivate = user
                                    }
                                }
                            }
                        }
                        .accessibilityIdentifier("admin.user.\(user.id)")
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
            users = try await model.client.send(API.adminUsers()).data
            loadFailure = nil
        } catch .unauthenticated {
            // The shell already went back to the login.
        } catch {
            if users == nil {
                loadFailure = error
            }
        }
    }

    private func setActive(_ user: AdminUser, _ isActive: Bool) async {
        do throws(APIError) {
            let saved = try await model.client.send(API.setAdminUserActive(id: user.id, isActive: isActive)).data

            if let index = users?.firstIndex(where: { $0.id == user.id }) {
                users?[index] = saved
            }
        } catch {
            actionFailure = error
        }
    }
}

/// A user: name, email, their workspaces and whether they are a super
/// admin or deactivated.
private struct AdminUserRow: View {
    let user: AdminUser

    @Environment(\.appLanguage) private var language

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            HStack(spacing: 6) {
                Text(verbatim: user.name)
                    .strikethrough(user.isDeactivated)

                if user.isSuperAdmin {
                    Text(verbatim: language.localized("Super admin"))
                        .font(.caption2.weight(.semibold))
                        .padding(.horizontal, 6)
                        .padding(.vertical, 2)
                        .background(.tint.opacity(0.15), in: .capsule)
                }

                if user.isDeactivated {
                    Text(verbatim: language.localized("Deactivated"))
                        .font(.caption2.weight(.semibold))
                        .foregroundStyle(.red)
                }
            }

            Text(verbatim: user.email)
                .font(.subheadline)
                .foregroundStyle(.secondary)

            if user.workspaces.isEmpty {
                language.text("No workspace")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            } else {
                ScrollView(.horizontal) {
                    HStack(spacing: 10) {
                        ForEach(user.workspaces) { workspace in
                            WorkspaceBadge(workspace: workspace)
                        }
                    }
                }
                .scrollIndicators(.hidden)
            }
        }
        .padding(.vertical, 2)
    }
}
