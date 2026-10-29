package app.jarvis.android.ui.whatsapp

import app.jarvis.android.network.ApiException
import app.jarvis.android.support.StubTransport
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class WhatsappChatToolsTest {
    private val transport = StubTransport()

    private fun chat(extra: String = "") =
        """{"friendly_id":"wtgt_1","name":"Ana","nickname":null,"whatsapp_name":"Ana","is_group":false,"phone_number":"5511911110000",
            "picture_url":null,"account":{"friendly_id":"wacc_1","nickname":"Personal"},"unread_count":0,"pinned":false,"priority":2,
            "priority_color":"#ff9500","tags":[],"last_message":null,"last_message_at":null,
            "settings":{"sends_read_receipts":null,"sends_typing":true,"notifies_messages":null,
            "effective":{"sends_read_receipts":false,"sends_typing":true,"notifies_messages":true}}$extra}"""

    private fun message(id: String, minute: Int) =
        """{"friendly_id":"$id","from_me":false,"type":"conversation","sender":null,"body":"text $id","receipt":null,"revoked":false,
            "media":null,"sent_at":"2026-10-05T11:${"%02d".format(minute)}:00+00:00"}"""

    @Test
    fun `a change sends only its own fields, a null following the number or dropping the value`() {
        assertEquals("""{"priority":null}""", WhatsappChatChange.Priority(null).body().toString())
        assertEquals("""{"nickname":null}""", WhatsappChatChange.Nickname("  ").body().toString())
        assertEquals("""{"tags":["wtag_1","wtag_2"]}""", WhatsappChatChange.Tags(listOf("wtag_1", "wtag_2")).body().toString())
        assertEquals(
            """{"sends_read_receipts":true,"sends_typing":null,"notifies_messages":false}""",
            WhatsappChatChange.Settings(sendsReadReceipts = true, sendsTyping = null, notifiesMessages = false).body().toString(),
        )
    }

    @Test
    fun `the chat is read with its details and a change answers it as the server keeps it`() = runTest {
        transport.enqueue(200, """{"data":${chat()},"scheduled":[{"friendly_id":"wmsg_1","body":"Oi","next_run_at":"2026-10-06T12:00:00+00:00"}],
            "reminders":[{"friendly_id":"rem_1","title":"Ligar","due_at":"2026-10-06T09:00:00+00:00"}]}""")
        transport.enqueue(200, """{"data":${chat().replace("\"pinned\":false", "\"pinned\":true")}}""")
        val store = WhatsappChatStore("wtgt_1", transport.client())

        val loaded = store.loadDetail()
        val pinned = store.apply(WhatsappChatChange.Pinned(true))

        assertEquals(true, loaded?.settings?.sendsTyping)
        assertEquals("Oi", store.state.value.detail?.scheduled?.single()?.body)
        assertEquals("Ligar", store.state.value.detail?.reminders?.single()?.title)
        assertTrue(pinned.pinned)
        assertTrue(store.state.value.detail!!.data.pinned)
        assertEquals("PATCH", transport.requests[1].method)
        assertEquals("""{"pinned":true}""", transport.bodies[1])
    }

    @Test
    fun `the draft answers the picked messages oldest first and ends the picking`() = runTest {
        transport.enqueue(200, """{"data":[${message("m3", 3)},${message("m2", 2)},${message("m1", 1)}],"outgoing":[],"meta":{"has_more":false}}""")
        transport.enqueue(200, """{"text":"Claro, amanhã!"}""")
        transport.enqueue(200, """{"text":"Oi"}""")
        transport.enqueue(200, """{"text":"Oi de novo"}""")
        transport.enqueue(200, """{"text":"Feliz aniversário!"}""")
        val store = WhatsappChatStore("wtgt_1", transport.client())
        store.refresh()

        store.toggleSelection("m3")
        store.toggleSelection("m1")
        assertTrue(store.state.value.isSelecting)

        assertEquals("Claro, amanhã!", store.draft())
        assertFalse(store.state.value.isSelecting)
        assertEquals("""{"messages":["m1","m3"]}""", transport.bodies[1])

        // Nothing picked: the latest received messages, with a prompt when given.
        store.draft(" diz que sim ")
        assertEquals("""{"messages":[],"instruction":"diz que sim"}""", transport.bodies[2])

        store.draft("  ")
        assertEquals("""{"messages":[]}""", transport.bodies[3])

        // Only from the prompt: no messages, even picked ones.
        store.toggleSelection("m2")
        assertEquals("Feliz aniversário!", store.draft(" deseja feliz aniversário ", withoutContext = true))
        assertEquals("""{"messages":[],"instruction":"deseja feliz aniversário","without_context":true}""", transport.bodies[4])
        assertFalse(store.state.value.isSelecting)
    }

    @Test
    fun `a scheduled file goes as multipart with its caption and time, and the details show it`() = runTest {
        transport.enqueue(201, """{"data":{"friendly_id":"wmsg_1","body":"","next_run_at":"2026-10-06T12:00:00+00:00","media":{"mime":"audio/mp4","name":"voice-note.m4a","size":3}}}""")
        transport.enqueue(200, """{"data":${chat()},"scheduled":[{"friendly_id":"wmsg_1","body":"","next_run_at":"2026-10-06T12:00:00+00:00","media":{"mime":"audio/mp4","name":"voice-note.m4a","size":3}}],"reminders":[]}""")
        val store = WhatsappChatStore("wtgt_1", transport.client())

        store.scheduleFile("voice-note.m4a", VOICE_NOTE_MIME, byteArrayOf(1, 2, 3), "", "2026-10-06T09:00")

        val body = transport.bodies[0].orEmpty()
        assertEquals("https://jarvis.test/api/v1/whatsapp/conversations/wtgt_1/schedule", transport.requests[0].url.toString())
        assertTrue(transport.requests[0].body?.contentType().toString().startsWith("multipart/form-data"))
        assertTrue(body.contains("name=\"file\"; filename=\"voice-note.m4a\""))
        assertTrue(body.contains("Content-Type: audio/mp4"))
        assertTrue(body.contains("name=\"at\""))
        assertTrue(body.contains("2026-10-06T09:00"))

        val scheduled = store.state.value.detail?.scheduled?.single()
        assertTrue(scheduled?.media?.isAudio == true)
        assertEquals("voice-note.m4a", scheduled?.media?.name)
    }

    @Test
    fun `a reminder quotes every picked message, and a refusal comes back`() = runTest {
        transport.enqueue(200, """{"data":[${message("m2", 2)},${message("m1", 1)}],"outgoing":[],"meta":{"has_more":false}}""")
        transport.enqueue(201, """{"data":{"friendly_id":"rem_1","title":"Ligar","due_at":"2026-10-06T09:00:00+00:00"}}""")
        transport.enqueue(200, """{"data":${chat()},"scheduled":[],"reminders":[]}""")
        transport.enqueue(422, """{"message":"The at must be a date after now.","errors":{"at":["The at must be a date after now."]}}""")
        val store = WhatsappChatStore("wtgt_1", transport.client())
        store.refresh()
        store.toggleSelection("m2")
        store.toggleSelection("m1")

        store.remind(" Ligar ", "2026-10-06T09:00")

        assertEquals("""{"title":"Ligar","due_at":"2026-10-06T09:00","messages":["m1","m2"]}""", transport.bodies[1])
        assertFalse(store.state.value.isSelecting)

        val refused = runCatching { store.schedule("Oi", "2020-01-01T09:00") }.exceptionOrNull()
        assertTrue(refused is ApiException.Validation)
        assertEquals("https://jarvis.test/api/v1/whatsapp/conversations/wtgt_1/schedule", transport.requests[3].url.toString())
    }

    @Test
    fun `the tags are saved by color name, the priority colors by priority, the number defaults whole`() = runTest {
        transport.enqueue(201, """{"data":{"friendly_id":"wtag_1","name":"Clientes","color":"#ff9500","color_name":"orange"}}""")
        transport.enqueue(200, """{"data":[{"friendly_id":"wtag_1","name":"Clientes","color":"#ff9500","color_name":"orange"}]}""")
        transport.enqueue(200, """{"palette":{"red":"#ff3b30","blue":"#007aff"},"colors":{"1":"blue","2":"red"}}""")
        val store = WhatsappChatToolsStore(transport.client())

        store.saveTag(null, " Clientes ", "orange")
        store.savePriorityColors(mapOf(1 to "blue", 2 to "red"))

        assertEquals("""{"name":"Clientes","color":"orange"}""", transport.bodies[0])
        assertEquals("orange", store.state.value.tags.single().colorName)
        assertEquals("""{"colors":{"1":"blue","2":"red"}}""", transport.bodies[2])
        assertEquals("#007aff", store.state.value.priorityColors.hex(1))
    }
}
