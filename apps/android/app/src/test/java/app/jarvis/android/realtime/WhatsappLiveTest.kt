package app.jarvis.android.realtime

import app.jarvis.android.network.WhatsappChatAccount
import app.jarvis.android.network.WhatsappConversation
import app.jarvis.android.network.WhatsappConversationMessage
import java.time.OffsetDateTime
import org.junit.Assert.assertEquals
import org.junit.Test

class WhatsappLiveTest {
    private val at = OffsetDateTime.parse("2026-10-05T12:00:00+00:00").toInstant().toEpochMilli()

    private fun chat(id: String, messageId: String, fromMe: Boolean = false, sentAt: String = "2026-10-05T11:59:30+00:00") = WhatsappConversation(
        friendlyId = id,
        name = id,
        account = WhatsappChatAccount("wacc_1", "Personal"),
        lastMessage = WhatsappConversationMessage(friendlyId = messageId, fromMe = fromMe, type = "conversation", body = "Oi", sentAt = sentAt),
    )

    @Test
    fun `a new message the contact sent to a chat not on screen is news, the latest first`() {
        val known = mapOf("ana" to "m1", "bia" to "m2", "caio" to "m3", "duda" to "m4", "eva" to "m5")
        val chats = listOf(
            chat("ana", "m1"),
            chat("bia", "m9", sentAt = "2026-10-05T11:58:00+00:00"),
            chat("caio", "m10", fromMe = true),
            chat("duda", "m11"),
            chat("eva", "m12", sentAt = "2026-10-05T11:00:00+00:00"),
            chat("fabi", "m13", sentAt = "2026-10-05T11:59:50+00:00"),
        )

        val news = WhatsappLive.newlyReceived(known, chats, openChatId = "duda", at = at)

        // Not ana (nothing new), caio (sent by the number), duda (on screen) nor eva (an old one); fabi is new to the list.
        assertEquals(listOf("fabi", "bia"), news.map { it.friendlyId })
    }

    @Test
    fun `nothing is news on the first read`() {
        assertEquals(emptyList<WhatsappConversation>(), WhatsappLive.newlyReceived(null, listOf(chat("ana", "m1")), null, at))
    }
}
