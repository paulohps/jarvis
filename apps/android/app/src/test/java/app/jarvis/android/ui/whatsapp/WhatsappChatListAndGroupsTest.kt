package app.jarvis.android.ui.whatsapp

import app.jarvis.android.network.ApiClient
import app.jarvis.android.network.WhatsappConversation
import app.jarvis.android.network.WhatsappGroup
import app.jarvis.android.support.StubTransport
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class WhatsappChatListAndGroupsTest {
    private val transport = StubTransport()

    private fun chat(id: String, extra: String = "", isGroup: Boolean = false, unread: Int = 0) =
        """{"friendly_id":"$id","name":"Chat $id","is_group":$isGroup,"phone_number":${if (isGroup) "null" else "\"+5511911110000\""},
            "account":{"friendly_id":"wacc_1","nickname":"Personal"},"unread_count":$unread$extra}"""

    private fun page(vararg chats: String) = """{"data":[${chats.joinToString(",")}],"meta":{"current_page":1,"last_page":1}}"""

    private fun member(jid: String, admin: Boolean = false, me: Boolean = false) =
        """{"jid":"$jid","lid":null,"name":"M $jid","number":"+$jid","admin":$admin,"me":$me}"""

    private fun group(extra: String = "", members: String = member("551100") + "," + member("551199", admin = true, me = true)) =
        """{"data":{"chat":${chat("wtgt_g", isGroup = true, extra = extra)},"admins_only":false,"info_admins_only":true,"is_admin":true,"members":[$members]}}"""

    @Test
    fun `a chat's new fields are read, and an older answer reads as not archived, muted or blocked`() = runTest {
        transport.enqueue(
            200,
            page(
                chat("c1", ""","marked_unread":true,"archived":true,"muted_until":"2026-10-08T20:00:00+00:00","blocked":true,"ephemeral_seconds":86400,"description":"Team""""),
                chat("c2", unread = 3),
            ),
        )
        val store = WhatsappConversationsStore(transport.client())

        store.refresh()

        val (marked, plain) = store.state.value.chats
        assertTrue(marked.markedUnread && marked.archived && marked.blocked && marked.isMuted && marked.looksUnread)
        assertEquals(86400, marked.ephemeralSeconds)
        assertEquals("Team", marked.description)
        assertFalse(plain.markedUnread || plain.archived || plain.blocked || plain.isMuted)
        assertTrue(plain.looksUnread)
        assertNull(plain.description)
    }

    @Test
    fun `the archive lists only the archived chats, and archiving moves a row out of the list shown`() = runTest {
        transport.enqueue(200, page(chat("c1"), chat("c2")))
        transport.enqueue(200, """{"data":${chat("c1", ""","archived":true""")}}""")
        transport.enqueue(200, page(chat("c1", ""","archived":true""")))
        transport.enqueue(200, """{"data":${chat("c1", ""","muted_until":"2026-10-08T20:00:00+00:00","archived":true""")}}""")
        val store = WhatsappConversationsStore(transport.client())
        store.refresh()

        store.apply("c1", WhatsappChatChange.Archived(true))
        assertEquals("""{"archived":true}""", transport.bodies[1])
        assertEquals(listOf("c2"), store.state.value.chats.map { it.friendlyId })

        store.showArchived(true)
        assertEquals("https://jarvis.test/api/v1/whatsapp/conversations?page=1&per_page=30&archived=1", transport.requests[2].url.toString())
        assertEquals(listOf("c1"), store.state.value.chats.map { it.friendlyId })

        store.apply("c1", WhatsappChatChange.Muted("8h"))
        assertEquals("""{"muted":"8h"}""", transport.bodies[3])
        assertTrue(store.state.value.chats.single().isMuted)
    }

    @Test
    fun `marking unread marks by hand, and marking read reads the unread messages and takes the mark off`() = runTest {
        transport.enqueue(200, page(chat("c1"), chat("c2", unread = 2), chat("c3", ""","marked_unread":true""")))
        transport.enqueue(200, """{"data":${chat("c1", ""","marked_unread":true""")}}""")
        transport.enqueue(204)
        transport.enqueue(200, """{"data":${chat("c3")}}""")
        val store = WhatsappConversationsStore(transport.client())
        store.refresh()
        val (plain, unread, marked) = store.state.value.chats

        store.toggleUnread(plain)
        store.toggleUnread(unread)
        store.toggleUnread(marked)

        assertEquals("""{"marked_unread":true}""", transport.bodies[1])
        assertEquals("https://jarvis.test/api/v1/whatsapp/conversations/c2/read", transport.requests[2].url.toString())
        assertEquals("""{"marked_unread":false}""", transport.bodies[3])
        assertEquals(listOf(true, false, false), store.state.value.chats.map { it.looksUnread })
    }

    @Test
    fun `the chat changes send only their field, null to unmute`() {
        assertEquals("""{"muted":null}""", WhatsappChatChange.Muted(null).body().toString())
        assertEquals("""{"blocked":true}""", WhatsappChatChange.Blocked(true).body().toString())
        assertEquals("""{"marked_unread":false}""", WhatsappChatChange.MarkedUnread(false).body().toString())
        assertEquals("""{"description":null}""", WhatsappGroupChange.Description("  ").body().toString())
        assertEquals("""{"ephemeral_seconds":604800}""", WhatsappGroupChange.Ephemeral(604_800).body().toString())
        assertEquals("""{"subject":"Team"}""", WhatsappGroupChange.Subject(" Team ").body().toString())
    }

    @Test
    fun `a new group is created with its members, without an empty description, and the list is read again`() = runTest {
        transport.enqueue(201, """{"data":${chat("wtgt_g", isGroup = true)}}""")
        transport.enqueue(200, page(chat("wtgt_g", isGroup = true)))
        val store = WhatsappConversationsStore(transport.client())

        val created = store.createGroup("wacc_1", " Team ", listOf("c1", "c2"), " ")

        assertEquals("https://jarvis.test/api/v1/whatsapp/groups", transport.requests[0].url.toString())
        assertEquals("""{"account":"wacc_1","subject":"Team","members":["c1","c2"]}""", transport.bodies[0])
        assertTrue(created.isGroup)
        assertEquals(listOf("wtgt_g"), store.state.value.chats.map { it.friendlyId })
    }

    @Test
    fun `a group is read with its members, changed, and its members managed`() = runTest {
        transport.enqueue(200, group())
        transport.enqueue(200, group(extra = ""","ephemeral_seconds":86400"""))
        transport.enqueue(200, """{"results":{"551177@s.whatsapp.net":true,"551188@s.whatsapp.net":false},"members":[${member("551100")},${member("551177")}]}""")
        transport.enqueue(200, """{"url":"https://chat.whatsapp.com/abc"}""")
        transport.enqueue(200, """{"url":"https://chat.whatsapp.com/new"}""")
        transport.enqueue(200, """{"data":${chat("wtgt_g", isGroup = true, extra = ""","archived":true""")}}""")
        val store = WhatsappChatInfoStore("wtgt_g", transport.client())

        store.loadGroup()
        val group = store.state.value.group!!
        assertTrue(group.isAdmin && group.infoAdminsOnly && group.canEditInfo)
        assertEquals(2, group.members.size)
        assertTrue(group.members.last().me)

        store.updateGroup(WhatsappGroupChange.Ephemeral(86_400))
        assertEquals("PATCH", transport.requests[1].method)
        assertEquals(86_400, store.state.value.group?.chat?.ephemeralSeconds)

        assertEquals(1, store.members(WhatsappChatInfoStore.ADD, listOf("+551177", "+551188")))
        assertEquals("""{"action":"add","participants":["+551177","+551188"]}""", transport.bodies[2])
        assertEquals(listOf("551100", "551177"), store.state.value.group?.members?.map { it.jid })

        assertEquals("https://chat.whatsapp.com/abc", store.invite())
        assertEquals("""{"revoke":false}""", transport.bodies[3])
        store.invite(revoke = true)
        assertEquals("https://chat.whatsapp.com/new", store.state.value.inviteUrl)

        assertTrue(store.leave().archived)
        assertEquals("https://jarvis.test/api/v1/whatsapp/conversations/wtgt_g/group/leave", transport.requests[5].url.toString())
    }

    @Test
    fun `a group the number cannot reach keeps the failure, and a member who is not an admin edits only an open group`() = runTest {
        transport.enqueue(502, """{"message":"WhatsApp could not be reached."}""")
        val store = WhatsappChatInfoStore("wtgt_g", transport.client())

        store.loadGroup()

        assertNull(store.state.value.group)
        assertEquals("WhatsApp could not be reached.", store.state.value.groupFailure?.message)
        val locked = WhatsappGroup(chat = ApiClient.json.decodeFromString<WhatsappConversation>(chat("g", isGroup = true)), infoAdminsOnly = true)
        assertFalse(locked.canEditInfo)
        assertTrue(locked.copy(infoAdminsOnly = false).canEditInfo)
    }

    @Test
    fun `the search, the starred messages and the gallery are read from the chat, a short search not at all`() = runTest {
        val message = """{"friendly_id":"m1","from_me":false,"type":"conversation","body":"pizza tonight","sent_at":"2026-10-05T11:00:00+00:00"}"""
        transport.enqueue(200, """{"data":[$message]}""")
        transport.enqueue(200, """{"data":[$message]}""")
        transport.enqueue(200, """{"data":[$message],"meta":{"has_more":true}}""")
        val store = WhatsappChatInfoStore("wtgt_1", transport.client())

        assertTrue(store.search(" p ").isEmpty())
        assertEquals(listOf("m1"), store.search(" pizza tonight ").map { it.friendlyId })
        assertEquals(listOf("m1"), store.starred().map { it.friendlyId })
        val gallery = store.gallery(WhatsappChatInfoStore.GalleryKind.Documents, before = "m9")

        assertEquals("https://jarvis.test/api/v1/whatsapp/conversations/wtgt_1/search?q=pizza%20tonight", transport.requests[0].url.toString())
        assertEquals("https://jarvis.test/api/v1/whatsapp/conversations/wtgt_1/starred", transport.requests[1].url.toString())
        assertEquals("https://jarvis.test/api/v1/whatsapp/conversations/wtgt_1/gallery?kind=documents&before=m9", transport.requests[2].url.toString())
        assertTrue(gallery.meta.hasMore)
    }
}
