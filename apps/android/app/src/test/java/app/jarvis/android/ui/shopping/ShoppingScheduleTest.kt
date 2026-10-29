package app.jarvis.android.ui.shopping

import app.jarvis.android.network.RecurrenceFrequency
import app.jarvis.android.network.RecurrenceInput
import app.jarvis.android.network.ShoppingListNextContent
import app.jarvis.android.network.ShoppingListScheduleInput
import app.jarvis.android.network.Weekday
import app.jarvis.android.offline.InMemoryMutationStore
import app.jarvis.android.offline.OfflineQueue
import app.jarvis.android.offline.ShoppingSync
import app.jarvis.android.offline.SyncScheduler
import app.jarvis.android.support.StubTransport
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.boolean
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class ShoppingScheduleTest {
    private val transport = StubTransport()
    private val client = transport.client()
    private val sync = ShoppingSync(
        OfflineQueue(client, InMemoryMutationStore()),
        object : SyncScheduler {
            override fun schedule() = Unit

            override fun cancel() = Unit
        },
    )

    private fun listBody(id: String, completedAt: String? = null) =
        """{"data":{"friendly_id":"$id","name":"Market","is_archived":${completedAt != null},"schedule_id":"sls_market",
        "due_at":"2026-10-09T21:00:00+00:00","is_overdue":false,"completed_at":${completedAt?.let { "\"$it\"" } ?: "null"},"items":[]}}"""

    private fun scheduleBody(openListId: String?) =
        """{"data":{"friendly_id":"sls_market","name":"Market","next_list_content":"pending","closes_automatically":false,
        "waits_for_close":true,"template_items":[],"recurrence":null,
        "open_list":${openListId?.let { """{"friendly_id":"$it","name":"Market","due_at":"2026-10-16T21:00:00+00:00","is_overdue":false}""" } ?: "null"}}}"""

    @Test
    fun `completing a list of a recurring one opens the next list it made`() = runTest {
        transport.enqueue(200, listBody("shl_this"))
        val store = ShoppingListStore("shl_this", client, sync)
        store.reload()

        transport.enqueue(200, listBody("shl_this", completedAt = "2026-10-06T18:00:00+00:00"))
        transport.enqueue(200, scheduleBody(openListId = "shl_next"))

        assertEquals("shl_next", store.complete())
        assertEquals("/api/v1/shopping/lists/shl_this/complete", transport.requests[1].url.encodedPath)
        assertEquals("/api/v1/shopping/schedules/sls_market", transport.requests[2].url.encodedPath)
        assertTrue(store.state.value.isArchived)
    }

    @Test
    fun `a recurring list that waits for the close has no next list yet`() = runTest {
        transport.enqueue(200, listBody("shl_this"))
        val store = ShoppingListStore("shl_this", client, sync)
        store.reload()

        transport.enqueue(200, listBody("shl_this", completedAt = "2026-10-06T18:00:00+00:00"))
        transport.enqueue(200, scheduleBody(openListId = null))

        assertNull(store.complete())
        assertNull(store.state.value.actionFailure)
    }

    @Test
    fun `a recurring list is sent whole with the template without blank lines`() = runTest {
        transport.enqueue(201, scheduleBody(openListId = "shl_next"))
        val input = ShoppingListScheduleInput(
            name = "Market",
            recurrence = RecurrenceInput(RecurrenceFrequency.Weekly, timeOfDay = "18:00", weekdays = listOf(Weekday.Friday)),
            nextListContent = ShoppingListNextContent.TemplateAndPending,
            closesAutomatically = true,
            waitsForClose = false,
            templateItems = ShoppingListScheduleInput.templateItems(" Milk \n\n  \nBread\n"),
        )

        client.call { createShoppingSchedule(input) }

        val sent = Json.parseToJsonElement(transport.bodies.last()!!).jsonObject
        assertEquals("POST", transport.requests.last().method)
        assertEquals("/api/v1/shopping/schedules", transport.requests.last().url.encodedPath)
        assertEquals("template_and_pending", sent["next_list_content"]!!.jsonPrimitive.content)
        assertTrue(sent["closes_automatically"]!!.jsonPrimitive.boolean)
        assertEquals(listOf("Milk", "Bread"), sent["template_items"]!!.jsonArray.map { it.jsonPrimitive.content })
        assertEquals("18:00", sent["recurrence"]!!.jsonObject["time_of_day"]!!.jsonPrimitive.content)
    }
}
