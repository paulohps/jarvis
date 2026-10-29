import Foundation
import Networking
import Observation
import UserNotifications

/// The in-app notifications of the user (US-5.1): the pages of
/// `GET /notifications`, the unread badge, and the new ones from Reverb.
@MainActor
@Observable
final class NotificationsStore {
    private(set) var items: [NotificationItem] = []
    private(set) var unreadCount = 0 {
        didSet {
            // The app icon shows the same count as the tab.
            if unreadCount != oldValue {
                UNUserNotificationCenter.current().setBadgeCount(unreadCount)
            }
        }
    }
    private(set) var hasMorePages = false
    private(set) var isLoading = false
    private(set) var hasLoaded = false
    private(set) var failure: APIError?

    @ObservationIgnored private let client: APIClient
    @ObservationIgnored private var currentPage = 1

    init(client: APIClient) {
        self.client = client
    }

    /// The first page again (pull to refresh, a new notification, back to the foreground).
    func reload() async {
        do {
            let page = try await client.send(API.notifications(page: 1))

            items = page.data
            currentPage = 1
            hasMorePages = page.hasMorePages
            unreadCount = page.meta.unreadCount
            failure = nil
        } catch {
            failure = error
        }

        hasLoaded = true
    }

    func loadMore() async {
        guard hasMorePages, !isLoading else {
            return
        }

        isLoading = true
        defer { isLoading = false }

        do {
            let page = try await client.send(API.notifications(page: currentPage + 1))
            let knownIds = Set(items.map(\.id))

            items += page.data.filter { !knownIds.contains($0.id) }
            currentPage = page.meta.currentPage
            hasMorePages = page.hasMorePages
            unreadCount = page.meta.unreadCount
        } catch {
            failure = error
        }
    }

    func markRead(_ item: NotificationItem) async {
        guard let index = items.firstIndex(where: { $0.id == item.id }), !items[index].isRead else {
            return
        }

        items[index].isRead = true
        unreadCount = max(unreadCount - 1, 0)

        if let marked = try? await client.send(API.markNotificationRead(id: item.id)) {
            unreadCount = marked.unreadCount
        }
    }

    func markAllRead() async {
        for index in items.indices {
            items[index].isRead = true
        }

        unreadCount = 0

        if let marked = try? await client.send(API.markAllNotificationsRead()) {
            unreadCount = marked.unreadCount
        }
    }

    /// A `notification.created` from the user's channel: the badge right away, then the list.
    func didReceive(_ event: NotificationCreatedEvent) async {
        unreadCount = event.unreadCount
        await reload()
    }

    func clear() {
        items = []
        unreadCount = 0
        currentPage = 1
        hasMorePages = false
        hasLoaded = false
        failure = nil
    }
}
