package app.jarvis.android.ui.whatsapp

import app.jarvis.android.support.StubTransport
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class WhatsappConversationsStoreTest {
    private val transport = StubTransport()

    private fun chat(id: String, name: String = id) =
        """{"friendly_id":"$id","name":"$name","is_group":false,"phone_number":null,"picture_url":null,
            "account":{"friendly_id":"wacc_1","nickname":"Personal"},"unread_count":2,"pinned":true,"priority":1,
            "priority_color":"#ff3b30","tags":[{"friendly_id":"wtag_1","name":"Clientes","color":"#ff9500"}],
            "last_message":null,"last_message_at":"2026-10-05T11:00:00+00:00"}"""

    private fun page(chats: List<String>, current: Int, last: Int) =
        """{"data":[${chats.joinToString(",")}],"meta":{"current_page":$current,"last_page":$last}}"""

    @Test
    fun `the chats page on, a refresh keeps the further pages and a search starts over`() = runTest {
        val first = (1..30).map { chat("c$it") }
        transport.enqueue(200, page(first, 1, 2))
        transport.enqueue(200, page(listOf(chat("c31")), 2, 2))
        transport.enqueue(200, page(listOf(chat("c0")) + first.dropLast(1), 1, 2))
        transport.enqueue(200, page(listOf(chat("c7", "Ana")), 1, 1))
        val store = WhatsappConversationsStore(transport.client())

        store.refresh()
        assertTrue(store.state.value.hasMore)
        store.loadMore()
        store.refresh()

        val chats = store.state.value.chats
        assertEquals("c0", chats.first().friendlyId)
        assertEquals("c31", chats.last().friendlyId)
        assertEquals(31, chats.size)
        assertEquals("Clientes", chats.first().tags.single().name)

        store.search("  ana ")
        assertEquals(listOf("Ana"), store.state.value.chats.map { it.name })
        assertFalse(store.state.value.hasMore)
        assertEquals("https://jarvis.test/api/v1/whatsapp/conversations?search=ana&page=1&per_page=30", transport.requests.last().url.toString())
    }

    @Test
    fun `the priority sort and a tag start the list over and go to the server`() = runTest {
        transport.enqueue(200, page(listOf(chat("c1")), 1, 1))
        transport.enqueue(200, page(listOf(chat("c2")), 1, 1))
        val store = WhatsappConversationsStore(transport.client())

        store.sortByPriority(true)
        store.filterByTag("wtag_1")

        assertTrue(store.state.value.isSortedByPriority)
        assertEquals("https://jarvis.test/api/v1/whatsapp/conversations?sort=priority&page=1&per_page=30", transport.requests[0].url.toString())
        assertEquals("https://jarvis.test/api/v1/whatsapp/conversations?tag=wtag_1&sort=priority&page=1&per_page=30", transport.requests[1].url.toString())
        assertEquals(listOf("c2"), store.state.value.chats.map { it.friendlyId })
    }
}
