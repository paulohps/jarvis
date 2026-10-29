package app.jarvis.android.ui.whatsapp

import app.jarvis.android.network.ApiException
import app.jarvis.android.network.WhatsappReceipt
import app.jarvis.android.support.StubTransport
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import java.time.LocalDate

class WhatsappChatStoreTest {
    private val transport = StubTransport()

    private fun message(id: String, minute: Int, fromMe: Boolean = false, extra: String = "") =
        """{"friendly_id":"$id","from_me":$fromMe,"type":"conversation","sender":null,"body":"text $id",
            "receipt":${if (fromMe) "\"delivered\"" else "null"},"revoked":false,"media":null,
            "sent_at":"2026-10-05T11:${"%02d".format(minute)}:00+00:00"$extra}"""

    private fun page(vararg messages: String, hasMore: Boolean = false, outgoing: String = "[]") =
        """{"data":[${messages.joinToString(",")}],"outgoing":$outgoing,"meta":{"has_more":$hasMore}}"""

    @Test
    fun `the messages page backwards and a refresh keeps the older ones under the first page`() = runTest {
        transport.enqueue(200, page(message("m4", 4, fromMe = true), message("m3", 3), hasMore = true))
        transport.enqueue(200, page(message("m2", 2), message("m1", 1)))
        transport.enqueue(200, page(message("m5", 5), message("m4", 4, fromMe = true, extra = ""","x":1"""), hasMore = true))
        val store = WhatsappChatStore("wtgt_1", transport.client())

        store.refresh()
        store.loadOlder()
        store.refresh()
        val state = store.state.value

        assertEquals(listOf("m5", "m4", "m3", "m2", "m1"), state.messages.map { it.friendlyId })
        assertFalse(state.hasMore)
        assertEquals(WhatsappReceipt.Delivered, state.messages[1].receipt)
        assertEquals("https://jarvis.test/api/v1/whatsapp/conversations/wtgt_1/messages?before=m3", transport.requests[1].url.toString())
    }

    @Test
    fun `the chat is marked read once per newest message`() = runTest {
        transport.enqueue(200, page(message("m1", 1)))
        transport.enqueue(204)
        transport.enqueue(200, page(message("m1", 1)))
        transport.enqueue(200, page(message("m2", 2), message("m1", 1)))
        transport.enqueue(204)
        val store = WhatsappChatStore("wtgt_1", transport.client())

        store.refresh()
        store.markRead()
        store.refresh()
        store.markRead()
        store.refresh()
        store.markRead()

        val reads = transport.requests.filter { it.url.encodedPath.endsWith("/read") }
        assertEquals(2, reads.size)
        assertEquals("POST", reads.first().method)
    }

    @Test
    fun `typing is told at most once every four seconds`() = runTest {
        var clock = 0L
        transport.enqueue(200, """{"shown":true}""")
        transport.enqueue(200, """{"shown":false}""")
        val store = WhatsappChatStore("wtgt_1", transport.client(), now = { clock })

        assertTrue(store.typing())
        clock = 3_999
        assertFalse(store.typing())
        clock = 4_000
        assertTrue(store.typing())

        assertEquals(2, transport.requests.size)
        assertEquals("https://jarvis.test/api/v1/whatsapp/conversations/wtgt_1/typing", transport.requests.last().url.toString())
    }

    @Test
    fun `a text is sent trimmed, shown as outgoing, and a refused one keeps the server message`() = runTest {
        val delivery = """{"account":{"friendly_id":"wacc_1","nickname":"Personal"},
            "target":{"friendly_id":"wtgt_1","name":"Ana","type":"contact","phone_number":"+5511911110000","is_synced":true},
            "message_id":null,"body":"Tudo certo!","source":"chat","status":"pending","error_message":null,
            "scheduled_for":null,"sent_at":null,"created_at":"2026-10-05T12:00:00+00:00"}"""
        transport.enqueue(202, """{"data":$delivery}""")
        transport.enqueue(200, page(message("m1", 1), outgoing = "[$delivery]"))
        transport.enqueue(422, """{"message":"Too long.","errors":{"text":["The text is too long."]}}""")
        val store = WhatsappChatStore("wtgt_1", transport.client())

        assertFalse(store.send("   "))
        assertTrue(store.send(" Tudo certo! "))
        assertEquals("""{"text":"Tudo certo!"}""", transport.bodies.first())
        assertEquals("Tudo certo!", store.state.value.outgoing.single().body)

        assertFalse(store.send("x"))
        assertEquals("The text is too long.", store.state.value.sendFailure?.displayMessage)

        store.clearSendFailure()
        assertNull(store.state.value.sendFailure)
    }

    @Test
    fun `a file goes as multipart with its caption`() = runTest {
        transport.enqueue(422, """{"message":"Too big.","errors":{"file":["Too big."]}}""")
        val store = WhatsappChatStore("wtgt_1", transport.client())

        assertFalse(store.sendFile("photo.jpg", "image/jpeg", byteArrayOf(1, 2, 3), " look "))

        val body = transport.bodies.single().orEmpty()
        assertTrue(transport.requests.single().body?.contentType().toString().startsWith("multipart/form-data"))
        assertTrue(body.contains("name=\"file\"; filename=\"photo.jpg\""))
        assertTrue(body.contains("look"))
        assertTrue(store.state.value.sendFailure is ApiException.Validation)
    }

    @Test
    fun `media and group senders decode`() = runTest {
        transport.enqueue(
            200,
            page(
                """{"friendly_id":"a1","from_me":false,"type":"audioMessage","sender":"Carla","body":null,"receipt":null,"revoked":true,
                "media":{"kind":"audio","mime":"audio/ogg","name":null,"size":1000,"seconds":7,"waveform":[10,90],"thumbnail":null,
                "url":"https://jarvis.test/api/v1/whatsapp/media/a1?signature=x"},"sent_at":"2026-10-05T11:00:00+00:00"}""",
            ),
        )
        val store = WhatsappChatStore("wtgt_1", transport.client())

        store.refresh()
        val audio = store.state.value.messages.single()

        assertEquals("Carla", audio.sender)
        assertTrue(audio.revoked)
        assertEquals(7, audio.media?.seconds)
        assertEquals(listOf(10, 90), audio.media?.waveform)
    }

    @Test
    fun `a day starts above each message whose day differs from the older one`() {
        val days = listOf(LocalDate.of(2026, 10, 6), LocalDate.of(2026, 10, 6), LocalDate.of(2026, 10, 5), null)

        assertEquals(setOf(1, 2, 3), dayStarts(days))
        assertEquals(LocalDate.of(2026, 10, 5), chatDay("2026-10-06T01:00:00+00:00", "America/Sao_Paulo"))
    }
}
