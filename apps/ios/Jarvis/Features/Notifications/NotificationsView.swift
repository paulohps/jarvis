import Localization
import Networking
import Realtime
import SwiftUI

/// The in-app notifications (US-5.1): newest first, a new one shows up while
/// the screen is open (Reverb), one or all can be marked read, and tapping one
/// opens its link in the app.
struct NotificationsView: View {
    @Environment(AppModel.self) private var model
    @Environment(\.appLanguage) private var language

    private var store: NotificationsStore {
        model.notifications
    }

    var body: some View {
        NavigationStack {
            content
                .navigationTitle(language.localized("Notifications"))
                .toolbar {
                    ShellCloseButton()

                    if store.unreadCount > 0 {
                        ToolbarItem(placement: .primaryAction) {
                            Button(language.localized("Mark all as read"), systemImage: "checkmark.circle") {
                                Task { await store.markAllRead() }
                            }
                            .accessibilityIdentifier("notifications.read-all")
                        }
                    }
                }
                .pinnedHeader {
                    if case .waitingToReconnect = model.realtime.connectionState {
                        Label(language.localized("Live updates paused. Reconnecting…"), systemImage: "antenna.radiowaves.left.and.right.slash")
                            .font(.footnote)
                            .padding(.horizontal, 14)
                            .padding(.vertical, 8)
                            .glassEffect()
                            .padding(.bottom, 4)
                    }
                }
                .refreshable {
                    await store.reload()
                }
                .task {
                    if !store.hasLoaded {
                        await store.reload()
                    }
                }
        }
    }

    @ViewBuilder
    private var content: some View {
        if !store.hasLoaded {
            ProgressView(language.localized("Loading…"))
                .frame(maxWidth: .infinity, maxHeight: .infinity)
        } else if store.items.isEmpty {
            ContentUnavailableView {
                Label(language.localized("No notifications yet"), systemImage: "bell")
            } description: {
                language.text("New notifications appear here as they arrive.")
            } actions: {
                if store.failure != nil {
                    Button(language.localized("Try again")) {
                        Task { await store.reload() }
                    }
                    .buttonStyle(.glass)
                }
            }
        } else {
            List {
                ForEach(store.items) { item in
                    Button {
                        open(item)
                    } label: {
                        NotificationRow(item: item)
                    }
                    .buttonStyle(.plain)
                    .swipeActions(edge: .leading) {
                        if !item.isRead {
                            Button(language.localized("Mark as read"), systemImage: "envelope.open") {
                                Task { await store.markRead(item) }
                            }
                            .tint(.accentColor)
                        }
                    }
                }

                if store.hasMorePages {
                    Button(language.localized("Load more")) {
                        Task { await store.loadMore() }
                    }
                    .frame(maxWidth: .infinity)
                    .disabled(store.isLoading)
                }
            }
            .listStyle(.plain)
        }
    }

    private func open(_ item: NotificationItem) {
        Task { await store.markRead(item) }

        if let link = item.url ?? item.actions.first?.url {
            model.open(deepLink: link)
        }
    }
}

struct NotificationRow: View {
    let item: NotificationItem

    var body: some View {
        HStack(alignment: .top, spacing: 12) {
            Circle()
                .fill(item.isRead ? Color.clear : Color.accentColor)
                .frame(width: 9, height: 9)
                .padding(.top, 6)
                .accessibilityHidden(true)

            VStack(alignment: .leading, spacing: 4) {
                Text(verbatim: item.title)
                    .font(.body.weight(item.isRead ? .regular : .semibold))

                if let body = item.plainBody {
                    Text(verbatim: body)
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                        .lineLimit(3)
                }

                Text(item.createdAt, format: .relative(presentation: .named))
                    .font(.caption)
                    .foregroundStyle(.tertiary)
            }
        }
        .padding(.vertical, 4)
        .contentShape(.rect)
    }
}
