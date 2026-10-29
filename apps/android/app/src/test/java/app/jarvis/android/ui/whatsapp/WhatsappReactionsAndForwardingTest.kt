package app.jarvis.android.ui.whatsapp

import app.jarvis.android.network.ApiException
import app.jarvis.android.network.WhatsappReaction
import app.jarvis.android.support.StubTransport
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class WhatsappReactionsAndForwardingTest {
    private val transport = StubTransport()

    private fun message(id: String, minute: Int, reactions: String = "[]", extra: String = """"body":"text $id","revoked":false,"media":null""") =
        """{"friendly_id":"$id","from_me":false,"type":"conversation","sender":null,$extra,"receipt":null,
            "sent_at":"2026-10-05T11:${"%02d".format(minute)}:00+00:00","reactions":$reactions}"""

    private fun page(vararg messages: String) = """{"data":[${messages.joinToString(",")}],"outgoing":[],"meta":{"has_more":false}}"""

    @Test
    fun `the reactions of a message are read, and an older answer without them reads as none`() = runTest {
        transport.enqueue(200, page(message("m2", 2, """[{"emoji":"❤️","count":2,"mine":true},{"emoji":"👍","count":1,"mine":false}]"""), """{"friendly_id":"m1","from_me":true,"type":"conversation","body":"Oi","sent_at":"2026-10-05T11:01:00+00:00"}"""))
        val store = WhatsappChatStore("wtgt_1", transport.client())

        store.refresh()

        val (reacted, plain) = store.state.value.messages
        assertEquals(listOf(WhatsappReaction("❤️", 2, true), WhatsappReaction("👍", 1, false)), reacted.reactions)
        assertEquals("❤️", reacted.myReaction)
        assertTrue(plain.reactions.isEmpty())
    }

    @Test
    fun `the user's reaction replaces their previous one, and the same emoji again takes it back`() {
        val given = listOf(WhatsappReaction("❤️", 2, true), WhatsappReaction("👍", 1, false))

        assertEquals(listOf(WhatsappReaction("👍", 2, true), WhatsappReaction("❤️", 1, false)), WhatsappChatStore.withMyReaction(given, "👍"))
        assertEquals(listOf(WhatsappReaction("❤️", 1, false), WhatsappReaction("👍", 1, false)), WhatsappChatStore.withMyReaction(given, "❤️"))
        assertEquals(listOf(WhatsappReaction("❤️", 1, false), WhatsappReaction("👍", 1, false)), WhatsappChatStore.withMyReaction(given, ""))
        assertEquals(listOf(WhatsappReaction("😂", 1, true)), WhatsappChatStore.withMyReaction(emptyList(), "😂"))
    }

    @Test
    fun `a reaction shows at once, is sent with its emoji, and goes back when whatsapp refuses it`() = runTest {
        transport.enqueue(200, page(message("m1", 1)))
        transport.enqueue(200, """{"emoji":"🙏"}""")
        transport.enqueue(200, page(message("m1", 1, """[{"emoji":"🙏","count":1,"mine":true}]""")))
        transport.enqueue(502, """{"message":"WhatsApp refused it."}""")
        val store = WhatsappChatStore("wtgt_1", transport.client())
        store.refresh()

        store.react("m1", "🙏")

        assertEquals("https://jarvis.test/api/v1/whatsapp/conversations/wtgt_1/messages/m1/reaction", transport.requests[1].url.toString())
        assertEquals("""{"emoji":"🙏"}""", transport.bodies[1])
        assertEquals("🙏", store.state.value.messages.single().myReaction)

        val refused = runCatching { store.react("m1", "👍") }.exceptionOrNull()

        assertTrue(refused is ApiException)
        assertEquals(listOf(WhatsappReaction("🙏", 1, true)), store.state.value.messages.single().reactions)
    }

    @Test
    fun `the picked messages are forwarded oldest first, only when all of them can be sent again`() = runTest {
        transport.enqueue(
            200,
            page(
                message("m3", 3, extra = """"body":"Apagada","revoked":true,"media":null"""),
                message("m2", 2),
                message("m1", 1, extra = """"body":null,"revoked":false,"media":{"kind":"image","mime":"image/png"}"""),
            ),
        )
        transport.enqueue(202, """{"deliveries":4}""")
        transport.enqueue(422, """{"message":"Pick chats of this number.","errors":{"to":["Pick chats of this number."]}}""")
        val store = WhatsappChatStore("wtgt_1", transport.client())
        store.refresh()

        store.toggleSelection("m3")
        assertFalse(store.state.value.canForward)
        store.toggleSelection("m3")

        store.toggleSelection("m2")
        store.toggleSelection("m1")
        assertTrue(store.state.value.canForward)

        assertEquals(4, store.forward(listOf("wtgt_2", "wtgt_3")))
        assertEquals("https://jarvis.test/api/v1/whatsapp/conversations/wtgt_1/forward", transport.requests[1].url.toString())
        assertEquals("""{"messages":["m1","m2"],"to":["wtgt_2","wtgt_3"]}""", transport.bodies[1])
        assertFalse(store.state.value.isSelecting)

        store.toggleSelection("m2")
        val refused = runCatching { store.forward(listOf("wtgt_9")) }.exceptionOrNull() as? ApiException

        assertEquals("Pick chats of this number.", refused?.firstError("to"))
        assertTrue(store.state.value.isSelecting)
    }
}
