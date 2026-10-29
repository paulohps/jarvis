package app.jarvis.android.offline

import androidx.datastore.preferences.core.PreferenceDataStoreFactory
import app.jarvis.android.network.ShoppingListItem
import app.jarvis.android.network.sortedForDisplay
import app.jarvis.android.support.StubTransport
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.async
import kotlinx.coroutines.awaitAll
import kotlinx.coroutines.cancelAndJoin
import kotlinx.coroutines.coroutineScope
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.boolean
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.rules.TemporaryFolder
import java.io.File
import java.time.Duration
import java.time.Instant

class OfflineQueueTest {
    @get:Rule
    val folder = TemporaryFolder()

    private val file: File by lazy { File(folder.root, "offline.preferences_pb") }

    /** A clock the test moves by hand. */
    private class TestClock(var now: Instant) {
        fun advance(seconds: Double) {
            now = now.plus(Duration.ofMillis((seconds * 1000).toLong()))
        }
    }

    /**
     * Opens the queue file, runs [block] and closes the DataStore, as one launch
     * of the app; a second call is the app launched again.
     */
    private suspend fun <T> withQueue(transport: StubTransport, clock: TestClock, block: suspend (OfflineQueue) -> T): T {
        val job = SupervisorJob()
        val dataStore = PreferenceDataStoreFactory.create(scope = CoroutineScope(Dispatchers.IO + job)) { file }

        try {
            return block(OfflineQueue(transport.client(), DataStoreMutationStore(dataStore)) { clock.now })
        } finally {
            job.cancelAndJoin()
        }
    }

    private fun itemBody(id: String, checked: Boolean) =
        """{"data":{"friendly_id":"$id","name":"x","position":1,"is_checked":$checked}}"""

    private fun StubTransport.jsonBodies() = bodies.filterNotNull().map { body -> Json.parseToJsonElement(body).jsonObject }

    @Test
    fun `the mutations persist between restarts with their order and client_updated_at`() = runTest {
        val clock = TestClock(Instant.parse("2026-10-06T12:00:00.250Z"))

        val made = withQueue(StubTransport(), clock) { queue ->
            queue.enqueue(ShoppingItemChange.Toggle(checked = true), itemId = "shi_milk", listId = "shl_market")
            clock.advance(2.5)
            queue.enqueue(ShoppingItemChange.Update("Bread", "2 loaves"), itemId = "shi_bread", listId = "shl_market")
            clock.advance(1.0)
            queue.enqueue(ShoppingItemChange.Remove, itemId = "shi_rice", listId = "shl_other")
            queue.pending()
        }

        // A new queue on the same file, as after the app was killed and launched again.
        withQueue(StubTransport(), TestClock(Instant.parse("2030-01-01T00:00:00Z"))) { relaunched ->
            val kept = relaunched.pending()

            assertEquals(made, kept)
            assertEquals(listOf("shi_milk", "shi_bread", "shi_rice"), kept.map { it.itemId })
            assertEquals(
                listOf("2026-10-06T12:00:00.250Z", "2026-10-06T12:00:02.750Z", "2026-10-06T12:00:03.750Z").map(Instant::parse),
                kept.map { it.clientUpdatedAt },
            )
            assertEquals(2, relaunched.pending("shl_market").size)
        }
    }

    @Test
    fun `the mutations are sent in order with their original client_updated_at`() = runTest {
        val clock = TestClock(Instant.parse("2026-10-06T12:00:00Z"))

        withQueue(StubTransport(), clock) { offline ->
            offline.enqueue(ShoppingItemChange.Toggle(checked = true), itemId = "shi_milk", listId = "shl_market")
            clock.advance(60.0)
            offline.enqueue(ShoppingItemChange.Toggle(checked = true), itemId = "shi_bread", listId = "shl_market")
            clock.advance(60.0)
            offline.enqueue(ShoppingItemChange.Toggle(checked = false), itemId = "shi_milk", listId = "shl_market")
        }

        // Sent later, after a relaunch: the moments are those of the taps, not of the sending.
        val transport = StubTransport().apply {
            enqueue(200, itemBody("shi_milk", checked = true))
            enqueue(200, itemBody("shi_bread", checked = true))
            enqueue(200, itemBody("shi_milk", checked = false))
        }

        withQueue(transport, TestClock(Instant.parse("2030-01-01T00:00:00Z"))) { queue ->
            val outcomes = queue.flush()

            assertEquals(
                listOf(
                    "/api/v1/shopping/items/shi_milk/toggle",
                    "/api/v1/shopping/items/shi_bread/toggle",
                    "/api/v1/shopping/items/shi_milk/toggle",
                ),
                transport.requests.map { it.url.encodedPath },
            )
            assertEquals(listOf("POST", "POST", "POST"), transport.requests.map { it.method })
            val bodies = transport.jsonBodies()
            assertEquals(
                listOf("2026-10-06T12:00:00Z", "2026-10-06T12:01:00Z", "2026-10-06T12:02:00Z"),
                bodies.map { it.getValue("client_updated_at").jsonPrimitive.content },
            )
            assertEquals(listOf(true, true, false), bodies.map { it.getValue("checked").jsonPrimitive.boolean })
            assertEquals(3, outcomes.size)
            assertTrue(outcomes.all { it is MutationOutcome.Applied })
            assertTrue(queue.pending().isEmpty())
        }

        // Nothing is left on the disk either.
        withQueue(StubTransport(), clock) { assertTrue(it.pending().isEmpty()) }
    }

    @Test
    fun `without network the mutations stay and are sent when it comes back`() = runTest {
        val transport = StubTransport().apply { enqueueFailure() }

        withQueue(transport, TestClock(Instant.parse("2026-10-06T12:00:00Z"))) { queue ->
            queue.enqueue(ShoppingItemChange.Toggle(checked = true), itemId = "shi_milk", listId = "shl_market")
            queue.enqueue(ShoppingItemChange.Toggle(checked = true), itemId = "shi_bread", listId = "shl_market")

            val offlineOutcomes = queue.flush()

            // The first failure stops the sending: the order is kept for the next try.
            assertTrue(offlineOutcomes.isEmpty())
            assertEquals(1, transport.requests.size)
            assertEquals(listOf("shi_milk", "shi_bread"), queue.pending().map { it.itemId })

            transport.enqueue(200, itemBody("shi_milk", checked = true))
            transport.enqueue(200, itemBody("shi_bread", checked = true))
            val onlineOutcomes = queue.flush()

            assertEquals(2, onlineOutcomes.size)
            assertEquals(
                listOf(
                    "/api/v1/shopping/items/shi_milk/toggle",
                    "/api/v1/shopping/items/shi_milk/toggle",
                    "/api/v1/shopping/items/shi_bread/toggle",
                ),
                transport.requests.map { it.url.encodedPath },
            )
            assertTrue(queue.pending().isEmpty())
        }
    }

    @Test
    fun `resent mutations do not duplicate items`() = runTest {
        // The answer to the toggle is lost: it is sent again, with the state to end in.
        val transport = StubTransport().apply {
            enqueueFailure()
            enqueue(200, itemBody("shi_milk", checked = true))
            enqueue(404, """{"message":"Not found."}""")
        }

        withQueue(transport, TestClock(Instant.parse("2026-10-06T12:00:00Z"))) { queue ->
            queue.enqueue(ShoppingItemChange.Toggle(checked = true), itemId = "shi_milk", listId = "shl_market")
            queue.enqueue(ShoppingItemChange.Remove, itemId = "shi_rice", listId = "shl_market")

            queue.flush()
            val outcomes = queue.flush()

            // No call ever adds an item; a removal that finds the item gone is done.
            assertFalse(transport.requests.any { it.url.encodedPath.endsWith("/items") })
            assertEquals(listOf("POST", "POST", "DELETE"), transport.requests.map { it.method })
            val bodies = transport.jsonBodies().take(2)
            assertEquals(listOf(true, true), bodies.map { it.getValue("checked").jsonPrimitive.boolean })
            assertEquals(bodies[0]["client_updated_at"], bodies[1]["client_updated_at"])
            assertEquals(listOf(true, true), outcomes.map { it is MutationOutcome.Applied })
            assertTrue(queue.pending().isEmpty())
        }
    }

    @Test
    fun `a concurrent flush does not send the same mutation twice`() = runTest {
        val transport = StubTransport().apply { enqueue(200, itemBody("shi_milk", checked = true)) }

        withQueue(transport, TestClock(Instant.parse("2026-10-06T12:00:00Z"))) { queue ->
            queue.enqueue(ShoppingItemChange.Toggle(checked = true), itemId = "shi_milk", listId = "shl_market")

            val outcomes = coroutineScope { listOf(async { queue.flush() }, async { queue.flush() }).awaitAll() }

            assertEquals(1, transport.requests.size)
            assertEquals(1, outcomes.sumOf { it.size })
        }
    }

    @Test
    fun `a refused mutation is dropped and the next ones go on`() = runTest {
        val transport = StubTransport().apply {
            enqueue(422, """{"message":"The list is archived.","errors":{"list":["The list is archived."]}}""")
            enqueue(200, itemBody("shi_bread", checked = true))
        }

        withQueue(transport, TestClock(Instant.parse("2026-10-06T12:00:00Z"))) { queue ->
            queue.enqueue(ShoppingItemChange.Toggle(checked = true), itemId = "shi_milk", listId = "shl_archived")
            queue.enqueue(ShoppingItemChange.Toggle(checked = true), itemId = "shi_bread", listId = "shl_market")

            val outcomes = queue.flush()

            assertEquals(listOf(false, true), outcomes.map { it is MutationOutcome.Applied })
            assertEquals("The list is archived.", (outcomes.first() as MutationOutcome.Rejected).error.displayMessage)
            assertTrue(queue.pending().isEmpty())
        }
    }

    @Test
    fun `a server error keeps the mutation for the next try`() = runTest {
        val transport = StubTransport().apply { enqueue(503, """{"message":"Down for maintenance."}""") }

        withQueue(transport, TestClock(Instant.parse("2026-10-06T12:00:00Z"))) { queue ->
            queue.enqueue(ShoppingItemChange.Toggle(checked = true), itemId = "shi_milk", listId = "shl_market")

            assertTrue(queue.flush().isEmpty())
            assertEquals(listOf("shi_milk"), queue.pending().map { it.itemId })
        }
    }

    @Test
    fun `the pending mutations are shown over the server state`() {
        val tappedAt = Instant.parse("2026-10-06T12:00:00Z")
        val fromServer = listOf(
            ShoppingListItem(friendlyId = "shi_milk", name = "Milk", position = 1),
            ShoppingListItem(friendlyId = "shi_bread", name = "Bread", position = 2),
            ShoppingListItem(friendlyId = "shi_rice", name = "Rice", position = 3),
        )
        val pending = listOf(
            PendingMutation(listId = "shl_market", itemId = "shi_milk", change = ShoppingItemChange.Toggle(true), clientUpdatedAt = tappedAt),
            PendingMutation(listId = "shl_market", itemId = "shi_bread", change = ShoppingItemChange.Update("Bread", "2"), clientUpdatedAt = tappedAt),
            PendingMutation(listId = "shl_market", itemId = "shi_rice", change = ShoppingItemChange.Remove, clientUpdatedAt = tappedAt),
        )

        val shown = fromServer.applying(pending).sortedForDisplay()

        assertEquals(listOf("Bread", "Milk"), shown.map { it.name })
        assertEquals("2", shown.first().quantityNote)
        assertTrue(shown.last().isChecked)
        assertEquals(tappedAt.toString(), shown.last().checkedAt)
    }

    @Test
    fun `clear forgets the queue of the previous user`() = runTest {
        val clock = TestClock(Instant.parse("2026-10-06T12:00:00Z"))

        withQueue(StubTransport(), clock) { queue ->
            queue.enqueue(ShoppingItemChange.Toggle(checked = true), itemId = "shi_milk", listId = "shl_market")
            queue.clear()
        }

        withQueue(StubTransport(), clock) { assertTrue(it.pending().isEmpty()) }
    }
}
