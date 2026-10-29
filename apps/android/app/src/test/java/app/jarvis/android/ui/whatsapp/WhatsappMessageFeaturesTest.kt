package app.jarvis.android.ui.whatsapp

import app.jarvis.android.network.ApiException
import app.jarvis.android.network.WhatsappContactCard
import app.jarvis.android.network.WhatsappGroupMember
import app.jarvis.android.network.WhatsappLocation
import app.jarvis.android.network.WhatsappMention
import app.jarvis.android.network.WhatsappPollOption
import app.jarvis.android.network.WhatsappReceipt
import app.jarvis.android.support.StubTransport
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class WhatsappMessageFeaturesTest {
    private val transport = StubTransport()

    private fun message(id: String, minute: Int, fromMe: Boolean = false, extra: String = "") =
        """{"friendly_id":"$id","from_me":$fromMe,"type":"conversation","sender":null,"body":"text $id",
            "receipt":${if (fromMe) "\"read\"" else "null"},"revoked":false,"media":null,
            "sent_at":"2026-10-05T11:${"%02d".format(minute)}:00+00:00"$extra}"""

    private fun page(vararg messages: String, hasMore: Boolean = false) =
        """{"data":[${messages.joinToString(",")}],"outgoing":[],"meta":{"has_more":$hasMore}}"""

    private val delivery = """{"data":{"account":{"friendly_id":"wacc_1","nickname":"Personal"},
        "target":{"friendly_id":"wtgt_1","name":"Ana","type":"contact","phone_number":"+5511911110000","is_synced":true},
        "message_id":null,"body":"","source":"chat","status":"pending","error_message":null,
        "scheduled_for":null,"sent_at":null,"created_at":"2026-10-05T12:00:00+00:00"}}"""

    @Test
    fun `quotes, marks, locations, contacts, polls and stickers are read, and an older answer reads as none`() = runTest {
        transport.enqueue(
            200,
            page(
                message(
                    "m3",
                    3,
                    extra = ""","quoted":{"friendly_id":"m1","sender":"Ana","from_me":false,"type":"conversation","body":"Oi"},
                    "mentions":[{"jid":"5511911110000@s.whatsapp.net","name":"Ana"}],"forwarded":true,"edited":true,
                    "can_edit":true,"can_delete_for_everyone":true,"pinned":true,"starred":true,"sticker":false,
                    "location":{"latitude":-23.55,"longitude":-46.63,"name":"Paulista"},
                    "contacts":[{"name":"Bia","numbers":["+5511922220000"]}],
                    "poll":{"name":"Lunch?","selectable_count":0,"options":[{"name":"Yes","votes":2,"mine":true},{"name":"No","votes":1,"mine":false}]}""",
                ),
                """{"friendly_id":"m2","from_me":false,"type":"stickerMessage","sticker":true,"body":null,
                    "media":{"kind":"image","mime":"image/webp","url":"https://jarvis.test/s"},"sent_at":"2026-10-05T11:02:00+00:00"}""",
                """{"friendly_id":"m1","from_me":true,"type":"conversation","body":"Oi","sent_at":"2026-10-05T11:01:00+00:00"}""",
            ),
        )
        val store = WhatsappChatStore("wtgt_1", transport.client())

        store.refresh()

        val (full, sticker, plain) = store.state.value.messages
        assertEquals("m1", full.quoted?.friendlyId)
        assertEquals("Ana", full.quoted?.sender)
        assertEquals(listOf(WhatsappMention("5511911110000@s.whatsapp.net", "Ana")), full.mentions)
        assertTrue(full.forwarded && full.edited && full.canEdit && full.canDeleteForEveryone && full.pinned && full.starred)
        assertEquals(WhatsappLocation(-23.55, -46.63, name = "Paulista"), full.location)
        assertEquals(listOf(WhatsappContactCard("Bia", listOf("+5511922220000"))), full.contacts)
        assertEquals(0, full.poll?.selectableCount)
        assertEquals(listOf(WhatsappPollOption("Yes", 2, true), WhatsappPollOption("No", 1, false)), full.poll?.options)
        assertEquals(3, full.poll?.totalVotes)
        assertTrue(sticker.sticker)
        assertEquals("image/webp", sticker.media?.mime)
        assertNull(plain.quoted)
        assertTrue(plain.mentions.isEmpty())
        assertFalse(plain.forwarded || plain.edited || plain.canEdit || plain.canDeleteForEveryone || plain.pinned || plain.starred || plain.sticker)
        assertNull(plain.location)
        assertNull(plain.contacts)
        assertNull(plain.poll)
    }

    @Test
    fun `an answer is sent with the message it quotes, and the reply ends once sent but stays when refused`() = runTest {
        transport.enqueue(200, page(message("m1", 1)))
        transport.enqueue(422, """{"message":"Too long.","errors":{"text":["Too long."]}}""")
        transport.enqueue(202, delivery)
        transport.enqueue(200, page(message("m1", 1)))
        transport.enqueue(202, delivery)
        transport.enqueue(200, page(message("m1", 1)))
        val store = WhatsappChatStore("wtgt_1", transport.client())
        store.refresh()
        store.toggleSelection("m1")

        store.startReply(store.state.value.messages.single())
        assertFalse(store.state.value.isSelecting)
        assertFalse(store.send("too long"))
        assertEquals("m1", store.state.value.replyingTo?.friendlyId)

        assertTrue(store.send(" Sim "))
        assertEquals("""{"text":"Sim","reply_to":"m1"}""", transport.bodies[2])
        assertNull(store.state.value.replyingTo)

        assertTrue(store.send("Again"))
        assertEquals("""{"text":"Again"}""", transport.bodies[4])
    }

    @Test
    fun `a file answers the quoted message, a voice note goes without the quote`() = runTest {
        transport.enqueue(200, page(message("m1", 1)))
        transport.enqueue(202, delivery)
        transport.enqueue(200, page(message("m1", 1)))
        transport.enqueue(202, delivery)
        transport.enqueue(200, page(message("m1", 1)))
        val store = WhatsappChatStore("wtgt_1", transport.client())
        store.refresh()

        store.startReply(store.state.value.messages.single())
        store.sendFile("photo.jpg", "image/jpeg", byteArrayOf(1), "")
        store.startReply(store.state.value.messages.single())
        store.sendFile("note.ogg", "audio/ogg", byteArrayOf(1), "")

        assertTrue(transport.bodies[1].orEmpty().contains("name=\"reply_to\""))
        assertFalse(transport.bodies[3].orEmpty().contains("name=\"reply_to\""))
    }

    @Test
    fun `editing, deleting for everyone and starring put the server's message in place`() = runTest {
        transport.enqueue(200, page(message("m2", 2, fromMe = true, extra = ""","can_edit":true,"can_delete_for_everyone":true"""), message("m1", 1)))
        transport.enqueue(200, """{"data":${message("m2", 2, fromMe = true, extra = ""","edited":true,"can_edit":true""").replace("text m2", "Fixed")}}""")
        transport.enqueue(200, """{"data":${message("m1", 1, extra = ""","starred":true""")}}""")
        transport.enqueue(502, """{"message":"WhatsApp did not take it."}""")
        transport.enqueue(200, """{"data":${message("m2", 2, fromMe = true).replace("\"revoked\":false", "\"revoked\":true")}}""")
        val store = WhatsappChatStore("wtgt_1", transport.client())
        store.refresh()

        store.toggleSelection("m2")
        store.edit("m2", " Fixed ")
        assertEquals("PATCH", transport.requests[1].method)
        assertEquals("https://jarvis.test/api/v1/whatsapp/conversations/wtgt_1/messages/m2", transport.requests[1].url.toString())
        assertEquals("""{"text":"Fixed"}""", transport.bodies[1])
        assertEquals("Fixed", store.state.value.messages.first().body)
        assertTrue(store.state.value.messages.first().edited)
        assertFalse(store.state.value.isSelecting)

        store.star("m1", true)
        assertEquals("https://jarvis.test/api/v1/whatsapp/conversations/wtgt_1/messages/m1/star", transport.requests[2].url.toString())
        assertEquals("""{"starred":true}""", transport.bodies[2])
        assertTrue(store.state.value.messages.last().starred)

        val refused = runCatching { store.deleteForEveryone("m2") }.exceptionOrNull()
        assertTrue(refused is ApiException.Server)
        assertFalse(store.state.value.messages.first().revoked)

        store.deleteForEveryone("m2")
        assertEquals("DELETE", transport.requests[4].method)
        assertTrue(store.state.value.messages.first().revoked)
    }

    @Test
    fun `the info of a message tells its times, only one-to-one`() = runTest {
        transport.enqueue(
            200,
            """{"data":{"friendly_id":"m2","sent_at":"2026-10-05T11:02:00+00:00","receipt":"read",
                "delivered_at":"2026-10-05T11:03:00+00:00","read_at":"2026-10-05T11:04:00+00:00","played_at":null,"per_member":true}}""",
        )
        val store = WhatsappChatStore("wtgt_1", transport.client())

        val info = store.messageInfo("m2")

        assertEquals("https://jarvis.test/api/v1/whatsapp/conversations/wtgt_1/messages/m2/info", transport.requests.single().url.toString())
        assertEquals(WhatsappReceipt.Read, info.receipt)
        assertEquals("2026-10-05T11:04:00+00:00", info.readAt)
        assertNull(info.playedAt)
        assertTrue(info.perMember)
    }

    @Test
    fun `a poll, a location, contacts and a sticker go to their own endpoints`() = runTest {
        transport.enqueue(200, page(message("m1", 1)))
        repeat(4) {
            transport.enqueue(202, delivery)
            transport.enqueue(200, page(message("m1", 1)))
        }
        val store = WhatsappChatStore("wtgt_1", transport.client())
        store.refresh()

        store.startReply(store.state.value.messages.single())
        assertTrue(store.sendPoll(" Lunch? ", listOf("Yes ", " ", "No"), multiple = true))
        assertTrue(store.sendLocation(-23.5, -46.6, " ", null))
        assertTrue(store.sendContacts(listOf("wtgt_2", "wtgt_3")))
        assertTrue(store.sendSticker("s.webp", "image/webp", byteArrayOf(1)))

        assertEquals("https://jarvis.test/api/v1/whatsapp/conversations/wtgt_1/poll", transport.requests[1].url.toString())
        assertEquals("""{"question":"Lunch?","options":["Yes","No"],"multiple":true,"reply_to":"m1"}""", transport.bodies[1])
        assertEquals("https://jarvis.test/api/v1/whatsapp/conversations/wtgt_1/location", transport.requests[3].url.toString())
        assertEquals("""{"latitude":-23.5,"longitude":-46.6}""", transport.bodies[3])
        assertEquals("https://jarvis.test/api/v1/whatsapp/conversations/wtgt_1/contacts", transport.requests[5].url.toString())
        assertEquals("""{"contacts":["wtgt_2","wtgt_3"]}""", transport.bodies[5])
        assertEquals("https://jarvis.test/api/v1/whatsapp/conversations/wtgt_1/sticker", transport.requests[7].url.toString())
        assertTrue(transport.bodies[7].orEmpty().contains("name=\"file\"; filename=\"s.webp\""))
    }

    @Test
    fun `a message further up is reached by reading older pages, and not past the limit`() = runTest {
        transport.enqueue(200, page(message("m5", 5), message("m4", 4), hasMore = true))
        transport.enqueue(200, page(message("m3", 3), message("m2", 2), hasMore = true))
        transport.enqueue(200, page(message("m1", 1), hasMore = true))
        val store = WhatsappChatStore("wtgt_1", transport.client())
        store.refresh()

        assertTrue(store.reveal("m4"))
        assertTrue(store.reveal("m2"))
        assertEquals(2, transport.requests.size)
        assertFalse(store.reveal("m0", pages = 1))
        assertEquals(3, transport.requests.size)
    }

    @Test
    fun `a message is found in the list laid out bottom up, after what is sending and the days before it`() {
        val ids = listOf("m5", "m4", "m3", "m2")

        assertEquals(1, messageListIndex(1, ids, setOf(1, 3), "m5"))
        assertEquals(2, messageListIndex(1, ids, setOf(1, 3), "m4"))
        assertEquals(4, messageListIndex(1, ids, setOf(1, 3), "m3"))
        assertNull(messageListIndex(1, ids, setOf(1, 3), "m1"))
    }

    @Test
    fun `typing @ lists the members by name or number and writes their number`() {
        val members = listOf(
            WhatsappGroupMember("5511911110000@s.whatsapp.net", name = "Ana Souza", number = "+5511911110000"),
            WhatsappGroupMember("5511922220000@s.whatsapp.net", name = "Bruno", number = "+5511922220000"),
            WhatsappGroupMember("x@lid", name = "Hidden"),
            WhatsappGroupMember("5511999990000@s.whatsapp.net", name = "Me", number = "+5511999990000", me = true),
        )

        assertNull(WhatsappMentions.query("mail@ana", 8))
        assertNull(WhatsappMentions.query("hi @ana souza", 13))
        val query = WhatsappMentions.query("hi @an", 6)
        assertEquals(MentionQuery(3, "an"), query)

        assertEquals(listOf("Ana Souza"), WhatsappMentions.matches(members, "an").map { it.name })
        assertEquals(listOf("Bruno"), WhatsappMentions.matches(members, "1192222").map { it.name })
        assertEquals(listOf("Ana Souza", "Bruno"), WhatsappMentions.matches(members, "").map { it.name })

        assertEquals("hi @5511911110000 " to 18, WhatsappMentions.insert("hi @an", 6, query!!, "5511911110000"))
        assertEquals("5511911110000", members.first().mentionDigits)
    }
}
