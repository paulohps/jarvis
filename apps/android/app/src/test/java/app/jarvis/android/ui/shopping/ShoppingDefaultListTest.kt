package app.jarvis.android.ui.shopping

import app.jarvis.android.network.ShoppingList
import app.jarvis.android.offline.InMemoryMutationStore
import app.jarvis.android.offline.OfflineQueue
import app.jarvis.android.offline.ShoppingSync
import app.jarvis.android.offline.SyncScheduler
import app.jarvis.android.support.StubTransport
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class ShoppingDefaultListTest {
    private val transport = StubTransport()
    private val client = transport.client()
    private val sync = ShoppingSync(
        OfflineQueue(client, InMemoryMutationStore()),
        object : SyncScheduler {
            override fun schedule() = Unit

            override fun cancel() = Unit
        },
    )

    private fun listBody(isDefault: Boolean) =
        """{"data":{"friendly_id":"shl_market","name":"Market","is_archived":false,"is_default":$isDefault,"items":[]}}"""

    @Test
    fun `a list without is_default reads as not the default`() {
        val list = Json.decodeFromString<ShoppingList>("""{"friendly_id":"shl_1","name":"Market"}""")

        assertFalse(list.isDefault)
    }

    @Test
    fun `the default is set with post and unset with delete`() = runTest {
        transport.enqueue(200, listBody(isDefault = false))
        val store = ShoppingListStore("shl_market", client, sync)
        store.reload()

        transport.enqueue(200, listBody(isDefault = true))
        store.setDefault(true)

        assertTrue(store.state.value.isDefault)
        assertEquals("POST", transport.requests.last().method)
        assertEquals("/api/v1/shopping/lists/shl_market/default", transport.requests.last().url.encodedPath)

        transport.enqueue(200, listBody(isDefault = false))
        store.setDefault(false)

        assertFalse(store.state.value.isDefault)
        assertEquals("DELETE", transport.requests.last().method)
    }
}
