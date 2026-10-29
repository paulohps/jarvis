package app.jarvis.android.offline

import app.jarvis.android.support.StubTransport
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class ShoppingSyncTest {
    private class RecordingScheduler : SyncScheduler {
        var scheduled = 0
        var cancelled = 0

        override fun schedule() {
            scheduled++
        }

        override fun cancel() {
            cancelled++
        }
    }

    private val transport = StubTransport()
    private val scheduler = RecordingScheduler()
    private val sync = ShoppingSync(OfflineQueue(transport.client(), InMemoryMutationStore()), scheduler)

    private fun itemBody(id: String, checked: Boolean) =
        """{"data":{"friendly_id":"$id","name":"x","position":1,"is_checked":$checked}}"""

    @Test
    fun `a mark made offline is handed to WorkManager and sent when the network is back`() = runTest {
        transport.enqueueFailure()

        sync.record(ShoppingItemChange.Toggle(checked = true), itemId = "shi_milk", listId = "shl_market")

        assertEquals(1, scheduler.scheduled)
        assertEquals(mapOf("shl_market" to 1), sync.pendingByList.value)

        // The worker runs once the phone is online.
        transport.enqueue(200, itemBody("shi_milk", checked = true))
        val isStillPending = sync.flush()

        assertFalse(isStillPending)
        assertEquals(emptyMap<String, Int>(), sync.pendingByList.value)
        assertEquals(1, sync.revision.value)
    }

    @Test
    fun `a mark sent at once needs no background work`() = runTest {
        transport.enqueue(200, itemBody("shi_milk", checked = true))

        val outcomes = sync.record(ShoppingItemChange.Toggle(checked = true), itemId = "shi_milk", listId = "shl_market")

        assertEquals(1, outcomes.size)
        assertEquals(0, scheduler.scheduled)
        assertEquals(0, sync.revision.value)
    }

    @Test
    fun `a refusal is shown and the sign-out forgets the queue`() = runTest {
        transport.enqueue(422, """{"message":"The list is archived.","errors":{"list":["The list is archived."]}}""")
        sync.record(ShoppingItemChange.Toggle(checked = true), itemId = "shi_milk", listId = "shl_archived")

        assertEquals("The list is archived.", sync.rejection.value?.displayMessage)

        transport.enqueueFailure()
        sync.record(ShoppingItemChange.Remove, itemId = "shi_rice", listId = "shl_market")
        sync.onSessionEnded()

        assertEquals(1, scheduler.cancelled)
        assertTrue(sync.pendingByList.value.isEmpty())
        assertTrue(sync.pending("shl_market").isEmpty())
    }
}
