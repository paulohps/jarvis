package app.jarvis.android.notifications

import app.jarvis.android.network.NotificationCreatedEvent
import app.jarvis.android.support.StubTransport
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class NotificationsStoreTest {
    private val transport = StubTransport()

    private fun page(unread: Int, vararg ids: String, currentPage: Int = 1, lastPage: Int = 1) =
        """{"data":[${ids.joinToString(",") { """{"id":"$it","title":"Title $it","body":"<p>Line<br>two &amp; more</p>","url":"https://jarvis.test/reminders/remo_1","actions":[],"is_read":false,"created_at":"2026-10-04T12:00:00+00:00"}""" }}],"meta":{"current_page":$currentPage,"last_page":$lastPage,"unread_count":$unread}}"""

    @Test
    fun `the first page fills the list and the badge`() = runTest {
        transport.enqueue(200, page(unread = 2, "n1", "n2", lastPage = 2))
        val store = NotificationsStore(transport.client(), backgroundScope)

        store.reload()

        assertEquals(listOf("n1", "n2"), store.state.value.items.map { it.id })
        assertEquals(2, store.state.value.unreadCount)
        assertTrue(store.state.value.hasMorePages)
        assertEquals("Line\ntwo & more", store.state.value.items.first().plainBody)
    }

    @Test
    fun `a new notification in real time updates the badge and the list`() = runTest {
        transport.enqueue(200, page(unread = 1, "n1"))
        transport.enqueue(200, page(unread = 2, "n2", "n1"))
        val store = NotificationsStore(transport.client(), backgroundScope)
        store.reload()

        store.didReceive(NotificationCreatedEvent(id = "n2", title = "Title n2", unreadCount = 2))

        assertEquals(2, store.state.value.unreadCount)
        assertEquals(listOf("n2", "n1"), store.state.value.items.map { it.id })
    }

    @Test
    fun `marking one read takes the server count`() = runTest {
        transport.enqueue(200, page(unread = 3, "n1", "n2"))
        transport.enqueue(200, """{"marked":1,"unread_count":2}""")
        val store = NotificationsStore(transport.client(), backgroundScope)
        store.reload()

        store.markRead("n1")

        assertEquals("https://jarvis.test/api/v1/notifications/n1/read", transport.requests.last().url.toString())
        assertTrue(store.state.value.items.first { it.id == "n1" }.isRead)
        assertEquals(2, store.state.value.unreadCount)
    }

    @Test
    fun `marking all read clears the badge`() = runTest {
        transport.enqueue(200, page(unread = 2, "n1", "n2"))
        transport.enqueue(200, """{"marked":2,"unread_count":0}""")
        val store = NotificationsStore(transport.client(), backgroundScope)
        store.reload()

        store.markAllRead()

        assertEquals(0, store.state.value.unreadCount)
        assertTrue(store.state.value.items.all { it.isRead })
    }

    @Test
    fun `the next page is appended without duplicates`() = runTest {
        transport.enqueue(200, page(unread = 3, "n1", "n2", lastPage = 2))
        transport.enqueue(200, page(unread = 3, "n2", "n3", currentPage = 2, lastPage = 2))
        val store = NotificationsStore(transport.client(), backgroundScope)
        store.reload()

        store.loadMore()

        assertEquals(listOf("n1", "n2", "n3"), store.state.value.items.map { it.id })
        assertEquals(false, store.state.value.hasMorePages)
        assertEquals("page=2", transport.requests.last().url.query?.split("&")?.first())
    }

    @Test
    fun `the sign-out clears the list and the badge`() = runTest {
        transport.enqueue(200, page(unread = 1, "n1"))
        val store = NotificationsStore(transport.client(), backgroundScope)
        store.reload()

        store.onSessionEnded()

        assertEquals(NotificationsState(), store.state.value)
    }
}
