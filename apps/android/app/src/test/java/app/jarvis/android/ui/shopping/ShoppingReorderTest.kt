package app.jarvis.android.ui.shopping

import app.jarvis.android.network.ShoppingListItem
import app.jarvis.android.network.reordered
import app.jarvis.android.network.sortedForDisplay
import app.jarvis.android.offline.InMemoryMutationStore
import app.jarvis.android.offline.OfflineQueue
import app.jarvis.android.offline.ShoppingSync
import app.jarvis.android.offline.SyncScheduler
import app.jarvis.android.support.StubTransport
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Test

class ShoppingReorderTest {
    private val transport = StubTransport()
    private val client = transport.client()
    private val sync = ShoppingSync(
        OfflineQueue(client, InMemoryMutationStore()),
        object : SyncScheduler {
            override fun schedule() = Unit

            override fun cancel() = Unit
        },
    )

    private val items = listOf(
        ShoppingListItem(friendlyId = "shi_milk", name = "Milk", position = 0),
        ShoppingListItem(friendlyId = "shi_bread", name = "Bread", position = 1),
        ShoppingListItem(friendlyId = "shi_eggs", name = "Eggs", position = 2),
        ShoppingListItem(friendlyId = "shi_rice", name = "Rice", position = 3),
    )

    private fun itemJson(id: String, name: String, position: Int) =
        """{"friendly_id":"$id","name":"$name","position":$position,"is_checked":false}"""

    private fun listBody(vararg items: String) =
        """{"data":{"friendly_id":"shl_market","name":"Market","is_archived":false,"items":[${items.joinToString(",")}]}}"""

    @Test
    fun `the given items take the first positions and the others keep their order after them`() {
        val reordered = items.reordered(listOf("shi_rice", "shi_gone", "shi_milk"))

        assertEquals(listOf("Rice", "Milk", "Bread", "Eggs"), reordered.sortedForDisplay().map { it.name })
        assertEquals(listOf(0, 1, 2, 3), reordered.map { it.position })
    }

    @Test
    fun `a drag sends the new order and shows the list the server saved`() = runTest {
        transport.enqueue(200, listBody(itemJson("shi_milk", "Milk", 0), itemJson("shi_bread", "Bread", 1)))
        val store = ShoppingListStore("shl_market", client, sync)
        store.reload()

        transport.enqueue(200, listBody(itemJson("shi_bread", "Bread", 0), itemJson("shi_milk", "Milk", 1)))
        store.reorderUnchecked(listOf("shi_bread", "shi_milk"))

        assertEquals(listOf("Bread", "Milk"), store.state.value.uncheckedItems.map { it.name })
        val request = transport.requests.last()
        assertEquals("POST", request.method)
        assertEquals("/api/v1/shopping/lists/shl_market/reorder", request.url.encodedPath)
        val body = Json.parseToJsonElement(checkNotNull(transport.bodies.last())).jsonObject
        assertEquals(listOf("shi_bread", "shi_milk"), body.getValue("items").jsonArray.map { it.jsonPrimitive.content })
    }
}
