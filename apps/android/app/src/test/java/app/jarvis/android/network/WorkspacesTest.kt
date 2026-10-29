package app.jarvis.android.network

import app.jarvis.android.support.StubTransport
import app.jarvis.android.ui.reminders.keptAssignee
import app.jarvis.android.ui.reminders.reminderAssigneeOptions
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.boolean
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class WorkspacesTest {
    private val transport = StubTransport()
    private val client = transport.client()

    private fun lastBody(): JsonObject = Json.parseToJsonElement(transport.bodies.last()!!).jsonObject

    private val home = """{"id":"wsp_home","name":"Home","color":"blue","color_hex":"#007aff"}"""

    private fun me(workspaces: String) =
        """{"data":{"friendly_id":"usr_1","name":"Ana","email":"ana@example.com","locale":"en","timezone":"UTC","is_super_admin":true,
        "modules":[{"slug":"shopping","name":"Shopping","is_available_on_mobile":true}],"workspaces":$workspaces}}"""

    private fun user(vararg workspaces: UserWorkspace) = User("usr_1", "Ana", "ana@example.com", workspaces = workspaces.toList())

    @Test
    fun `me lists the workspaces with their colors, default and modules`() = runTest {
        transport.enqueue(
            200,
            me(
                """[{"id":"wsp_home","name":"Home","color":"purple","color_hex":"#af52de","workspace_color":"blue","own_color":"purple","is_default":true,"modules":["shopping","reminders"]},
                {"id":"wsp_office","name":"Office","color":"green","color_hex":"#34c759"}]""",
            ),
        )

        val user = client.call { me() }.data

        val home = user.workspaces.first()
        assertEquals("purple", home.color)
        assertEquals("blue", home.workspaceColor)
        assertEquals("purple", home.ownColor)
        assertTrue(home.isDefault)
        assertEquals(listOf("shopping", "reminders"), home.modules)

        // Fields absent read as the workspace's own color, not default, without modules.
        val office = user.workspaces.last()
        assertEquals("green", office.workspaceColor)
        assertNull(office.ownColor)
        assertFalse(office.isDefault)
        assertEquals(emptyList<String>(), office.modules)
    }

    @Test
    fun `me from a server older than the workspaces has none`() = runTest {
        transport.enqueue(200, """{"data":{"friendly_id":"usr_1","name":"Ana","email":"ana@example.com","modules":[]}}""")

        assertEquals(emptyList<UserWorkspace>(), client.call { me() }.data.workspaces)
    }

    @Test
    fun `the workspace of a shared record is read leniently`() {
        val list = ApiClient.json.decodeFromString<ShoppingList>("""{"friendly_id":"shl_1","name":"Market","workspace":$home}""")
        assertEquals(WorkspaceSummary("wsp_home", "Home", "blue", "#007aff"), list.workspace)

        // Absent, null or malformed: no workspace, and the record still reads.
        assertNull(ApiClient.json.decodeFromString<ShoppingList>("""{"friendly_id":"shl_1","name":"Market"}""").workspace)
        assertNull(ApiClient.json.decodeFromString<ShoppingList>("""{"friendly_id":"shl_1","name":"Market","workspace":null}""").workspace)
        assertNull(ApiClient.json.decodeFromString<ShoppingList>("""{"friendly_id":"shl_1","name":"Market","workspace":{"id":"wsp_1"}}""").workspace)
        assertNull(ApiClient.json.decodeFromString<ShoppingList>("""{"friendly_id":"shl_1","name":"Market","workspace":[]}""").workspace)

        val schedule = ApiClient.json.decodeFromString<ShoppingListSchedule>("""{"friendly_id":"shs_1","name":"Market","workspace":$home}""")
        assertEquals("wsp_home", schedule.workspace?.id)

        val reminder = ApiClient.json.decodeFromString<Reminder>("""{"friendly_id":"rem_1","title":"Dentist","workspace":$home}""")
        assertEquals("#007aff", reminder.workspace?.colorHex)
    }

    @Test
    fun `an agenda entry of a personal source has no workspace`() {
        val page = ApiClient.json.decodeFromString<CalendarPage>(
            """{"data":[
            {"key":"r1","source":"reminders","title":"Dentist","starts_at":"2026-10-06T12:00:00+00:00","workspace":$home},
            {"key":"w1","source":"whatsapp","title":"Hi","starts_at":"2026-10-06T13:00:00+00:00","workspace":null}
            ],"meta":{"assignees_by_workspace":{"wsp_home":[{"friendly_id":"usr_1","name":"Ana"}]}}}""",
        )

        assertEquals("wsp_home", page.data.first().workspace?.id)
        assertNull(page.data.last().workspace)
        assertEquals(listOf("usr_1"), page.meta.assigneesByWorkspace["wsp_home"]?.map { it.friendlyId })
    }

    @Test
    fun `assignees by workspace come as an empty array from PHP, or not at all`() {
        val empty = ApiClient.json.decodeFromString<ReminderOccurrencePage>("""{"data":[],"meta":{"assignees_by_workspace":[]}}""")
        assertEquals(emptyMap<String, List<UserSummary>>(), empty.meta.assigneesByWorkspace)

        val absent = ApiClient.json.decodeFromString<ReminderAgendaPage>("""{"data":[],"meta":{}}""")
        assertEquals(emptyMap<String, List<UserSummary>>(), absent.meta.assigneesByWorkspace)

        val broken = ApiClient.json.decodeFromString<ReminderPage>(
            """{"data":{"friendly_id":"rem_1","title":"Dentist"},"meta":{"assignees_by_workspace":{"wsp_home":"nobody"}}}""",
        )
        assertEquals(emptyMap<String, List<UserSummary>>(), broken.meta.assigneesByWorkspace)

        val filled = ApiClient.json.decodeFromString<ReminderPage>(
            """{"data":{"friendly_id":"rem_1","title":"Dentist"},"meta":{"assignees_by_workspace":{"wsp_home":[{"friendly_id":"usr_1","name":"Ana"}],"wsp_office":[]}}}""",
        )
        assertEquals(setOf("wsp_home", "wsp_office"), filled.meta.assigneesByWorkspace.keys)
    }

    @Test
    fun `the workspace of a new record is sent only when picked`() = runTest {
        transport.enqueue(201, """{"data":{"friendly_id":"shl_1","name":"Market"}}""")
        client.call { createShoppingList(ShoppingListInput("Market")) }
        assertFalse(lastBody().containsKey("workspace"))

        transport.enqueue(200, """{"data":{"friendly_id":"shl_1","name":"Market"}}""")
        client.call { renameShoppingList("shl_1", ShoppingListInput("Market", workspace = "wsp_office")) }
        assertEquals("wsp_office", lastBody()["workspace"]!!.jsonPrimitive.content)

        transport.enqueue(201, """{"data":{"friendly_id":"rem_1","title":"Dentist"}}""")
        client.call { createReminder(ReminderInput("Dentist", null, null, null, null)) }
        assertFalse(lastBody().containsKey("workspace"))
        // The other fields still go as null: an edit replaces the reminder.
        assertEquals(JsonNull, lastBody()["assigned_to"])

        transport.enqueue(201, """{"data":{"friendly_id":"rem_1","title":"Dentist"}}""")
        client.call { createReminder(ReminderInput("Dentist", null, null, null, null, workspace = "wsp_home")) }
        assertEquals("wsp_home", lastBody()["workspace"]!!.jsonPrimitive.content)
    }

    @Test
    fun `a workspace preference sends only what changes`() = runTest {
        val saved = """{"data":{"id":"wsp_home","name":"Home","color":"blue","color_hex":"#007aff"}}"""

        transport.enqueue(200, saved)
        client.call { updateWorkspacePreference("wsp_home", WorkspacePreferenceInput(color = WorkspacePreferenceInput.ColorChoice.Own("purple")).body) }
        assertEquals("PATCH", transport.requests.last().method)
        assertEquals("/api/v1/me/workspaces/wsp_home", transport.requests.last().url.encodedPath)
        assertEquals("purple", lastBody()["color"]!!.jsonPrimitive.content)
        assertFalse(lastBody().containsKey("is_default"))

        transport.enqueue(200, saved)
        client.call { updateWorkspacePreference("wsp_home", WorkspacePreferenceInput(color = WorkspacePreferenceInput.ColorChoice.WorkspaceColor).body) }
        assertEquals(JsonNull, lastBody()["color"])

        transport.enqueue(200, saved)
        client.call { updateWorkspacePreference("wsp_home", WorkspacePreferenceInput(makeDefault = true).body) }
        assertFalse(lastBody().containsKey("color"))
        assertTrue(lastBody()["is_default"]!!.jsonPrimitive.boolean)
    }

    @Test
    fun `a member is saved with the color always and the default only when made`() = runTest {
        val member = """{"data":{"user":{"id":"usr_2","name":"Bia","email":"bia@example.com"},"color":null,"effective_color":"blue","effective_color_hex":"#007aff","is_default":false,"modules":["shopping"]}}"""

        transport.enqueue(201, member)
        val added = client.call { addAdminWorkspaceMember("wsp_home", AdminMemberInput(user = "usr_2", modules = listOf("shopping"), color = null)) }.data
        assertEquals("/api/v1/admin/workspaces/wsp_home/members", transport.requests.last().url.encodedPath)
        assertEquals("usr_2", lastBody()["user"]!!.jsonPrimitive.content)
        assertEquals(JsonNull, lastBody()["color"])
        assertFalse(lastBody().containsKey("is_default"))
        assertEquals("usr_2", added.id)
        assertEquals(listOf("shopping"), added.modules)

        transport.enqueue(200, member)
        client.call { updateAdminWorkspaceMember("wsp_home", "usr_2", AdminMemberInput(modules = emptyList(), color = "red", isDefault = true)) }
        assertEquals("/api/v1/admin/workspaces/wsp_home/members/usr_2", transport.requests.last().url.encodedPath)
        assertFalse(lastBody().containsKey("user"))
        assertEquals(0, lastBody()["modules"]!!.jsonArray.size)
        assertTrue(lastBody()["is_default"]!!.jsonPrimitive.boolean)
    }

    @Test
    fun `the admin lists read with their defaults`() = runTest {
        transport.enqueue(
            200,
            """{"data":{"id":"wsp_home","name":"Home","color":"blue","color_hex":"#007aff",
            "members":[{"user":{"id":"usr_1","name":"Ana","email":"ana@example.com","is_super_admin":true},"effective_color":"blue","effective_color_hex":"#007aff","is_default":true}]}}""",
        )
        val detail = client.call { adminWorkspace("wsp_home") }.data
        assertEquals(1, detail.membersCount)
        assertFalse(detail.hasData)
        assertTrue(detail.members.single().user.isSuperAdmin)

        transport.enqueue(
            200,
            """{"data":[{"id":"usr_2","name":"Bia","email":"bia@example.com","is_deactivated":true,"last_seen_at":null,"workspaces":[$home]}]}""",
        )
        val user = client.call { adminUsers() }.data.single()
        assertTrue(user.isDeactivated)
        assertEquals(listOf("Home"), user.workspaces.map { it.name })

        transport.enqueue(
            200,
            """{"data":[
            {"id":"inv_1","email":"c@example.com","status":"pending","workspace":$home,"modules":["shopping"],"locale":"pt_BR","expires_at":"2026-10-14T12:00:00+00:00","created_at":null},
            {"id":"inv_2","email":"d@example.com","status":"bounced","workspace":null}
            ]}""",
        )
        val invitations = client.call { adminInvitations() }.data
        assertTrue(invitations.first().isPending)
        assertEquals(InvitationStatus.Pending, invitations.first().knownStatus)
        // A status this app does not know is kept raw and offers no action.
        assertNull(invitations.last().knownStatus)
        assertFalse(invitations.last().isPending)
        assertNull(invitations.last().workspace)
    }

    @Test
    fun `a deleted workspace with data is refused with the servers message`() = runTest {
        transport.enqueue(422, """{"message":"The workspace has data.","errors":{"workspace":["This workspace has lists, reminders or invoices."]}}""")

        val failure = runCatching { client.call { deleteAdminWorkspace("wsp_home") } }.exceptionOrNull()

        assertTrue(failure is ApiException.Validation)
        assertEquals("This workspace has lists, reminders or invoices.", (failure as ApiException).displayMessage)
    }

    @Test
    fun `workspaces show and are asked for only with two or more with the module`() {
        val home = UserWorkspace("wsp_home", "Home", "blue", "#007aff", modules = listOf("shopping", "reminders"))
        val office = UserWorkspace("wsp_office", "Office", "green", "#34c759", isDefault = true, modules = listOf("reminders"))

        val both = user(home, office)
        assertFalse(both.showsWorkspaces(ModuleSlug.SHOPPING))
        assertEquals(emptyList<UserWorkspace>(), both.workspacePickerOptions(ModuleSlug.SHOPPING))
        assertTrue(both.showsWorkspaces(ModuleSlug.REMINDERS))
        assertEquals(listOf("wsp_home", "wsp_office"), both.workspacePickerOptions(ModuleSlug.REMINDERS).map { it.id })

        // The default one when it has the module, otherwise the first that does.
        assertEquals("wsp_office", both.defaultWorkspace(ModuleSlug.REMINDERS)?.id)
        assertEquals("wsp_home", both.defaultWorkspace(ModuleSlug.SHOPPING)?.id)
        assertNull(user().defaultWorkspace(ModuleSlug.SHOPPING))

        val entry = { source: String, workspace: WorkspaceSummary? -> CalendarEntry("k", source, "t", startsAt = "2026-10-06T12:00:00+00:00", workspace = workspace) }
        assertTrue(both.showsWorkspace(entry("events", home.summary)))
        assertFalse(both.showsWorkspace(entry("shopping", home.summary)))
        assertFalse(both.showsWorkspace(entry("reminders", null)))
        assertFalse(both.showsWorkspace(entry("google", home.summary)))
    }

    @Test
    fun `the responsible follows the picked workspace`() {
        val ana = UserSummary("usr_1", "Ana")
        val bia = UserSummary("usr_2", "Bia")
        val caio = UserSummary("usr_3", "Caio")
        val byWorkspace = mapOf("wsp_home" to listOf(ana, bia), "wsp_office" to listOf(ana))
        val reminder = Reminder("rem_1", "Dentist", assignedTo = caio, workspace = WorkspaceSummary("wsp_home", "Home", "blue", "#007aff"))

        assertEquals(listOf(ana, bia), reminderAssigneeOptions(null, listOf(caio), byWorkspace, "wsp_home"))
        // Without them by workspace (an older server), the default workspace's.
        assertEquals(listOf(caio), reminderAssigneeOptions(null, listOf(caio), emptyMap(), "wsp_home"))
        // The one responsible now stays a choice while the reminder stays where it is, not once it moves.
        assertEquals(listOf(ana, bia, caio), reminderAssigneeOptions(reminder, emptyList(), byWorkspace, "wsp_home"))
        assertEquals(listOf(ana), reminderAssigneeOptions(reminder, emptyList(), byWorkspace, "wsp_office"))

        assertEquals("usr_1", keptAssignee("usr_1", listOf(ana), "usr_1"))
        assertEquals("usr_1", keptAssignee("usr_2", listOf(ana), "usr_1"))
        assertNull(keptAssignee("usr_2", listOf(caio), "usr_1"))
        assertNull(keptAssignee(null, listOf(ana), "usr_1"))
    }
}
