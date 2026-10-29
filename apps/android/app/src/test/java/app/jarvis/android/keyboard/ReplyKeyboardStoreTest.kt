package app.jarvis.android.keyboard

import app.jarvis.android.network.InMemoryTokenStore
import app.jarvis.android.support.StubTransport
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeout
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class ReplyKeyboardStoreTest {
    private val transport = StubTransport()

    private val chats = """{"data":[{"friendly_id":"wtgt_1","name":"Ana","phone_number":"+5511911110000",
        "account":{"friendly_id":"wacc_1","nickname":"Personal"},
        "last_received_message":{"friendly_id":"wchm_2","from_me":false,"type":"conversation","body":"Vamos jantar sexta?","sent_at":"2026-10-05T11:00:00+00:00"},
        "awaiting_reply":true}],"meta":{"current_page":1,"last_page":1}}"""

    private val messages = """{"data":[
        {"friendly_id":"wchm_2","from_me":false,"type":"conversation","body":"Vamos jantar sexta?","sent_at":"2026-10-05T11:00:00+00:00"},
        {"friendly_id":"wchm_1","from_me":true,"type":"conversation","body":"blz","sent_at":"2026-10-05T10:00:00+00:00"}]}"""

    private suspend fun ReplyKeyboardStore.awaitIdle(): ReplyKeyboardState = withContext(Dispatchers.Default) {
        withTimeout(5_000) { state.first { !it.isLoading } }
    }

    @Test
    fun `a draft follows the chat to the picked message with the typed instruction`() = runTest {
        transport.enqueue(200, chats)
        transport.enqueue(200, messages)
        transport.enqueue(200, """{"text":"bora! sexta fechou"}""")
        val store = ReplyKeyboardStore(transport.client(), backgroundScope)

        store.loadChats()
        val chat = store.awaitIdle().chats.single()
        store.open(chat)
        val received = store.awaitIdle().messages.first { !it.fromMe }
        store.draftReply(chat, received, typed = "  aceita \n")
        val state = store.awaitIdle()

        assertEquals("bora! sexta fechou", state.draft)
        assertEquals("aceita", state.instruction)
        assertEquals(ReplyKeyboardScreen.Draft(chat, received), state.screen)
        assertEquals("https://jarvis.test/api/v1/whatsapp/chat-messages/wchm_2/reply", transport.requests.last().url.toString())
        assertEquals("""{"instruction":"aceita"}""", transport.bodies.last())
    }

    @Test
    fun `signed out of the app, the keyboard asks to sign in without calling the server`() = runTest {
        val store = ReplyKeyboardStore(transport.client(tokenStore = InMemoryTokenStore(null)), backgroundScope)

        store.loadChats()

        assertEquals(ReplyKeyboardProblem.SignedOut, store.awaitIdle().problem)
        assertTrue(transport.requests.isEmpty())
    }
}
