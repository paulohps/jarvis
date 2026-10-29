package app.jarvis.android.offline

import app.jarvis.android.network.ApiClient
import app.jarvis.android.network.ShoppingItemInput
import app.jarvis.android.network.ShoppingItemToggle
import app.jarvis.android.network.ShoppingListItem
import kotlinx.serialization.KSerializer
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.descriptors.PrimitiveKind
import kotlinx.serialization.descriptors.PrimitiveSerialDescriptor
import kotlinx.serialization.encoding.Decoder
import kotlinx.serialization.encoding.Encoder
import java.time.Instant
import java.util.UUID

/**
 * A change to a shopping item made on this phone and not yet confirmed by the
 * server (US-8.2). Only changes that can be sent twice with the same result
 * are queued: the toggle carries the state to end in, the edit the whole item
 * and a removal of a removed item answers 404. Adding an item is not queued,
 * as sending it again would add it twice.
 */
@Serializable
sealed interface ShoppingItemChange {
    @Serializable
    @SerialName("toggle")
    data class Toggle(val checked: Boolean) : ShoppingItemChange

    @Serializable
    @SerialName("update")
    data class Update(val name: String, val quantityNote: String?) : ShoppingItemChange

    @Serializable
    @SerialName("remove")
    data object Remove : ShoppingItemChange
}

@Serializable
data class PendingMutation(
    val id: String = UUID.randomUUID().toString(),
    val listId: String,
    val itemId: String,
    val change: ShoppingItemChange,
    /**
     * The moment of the tap, sent as `client_updated_at`: the server keeps the
     * last write (US-8.2), however late this one arrives.
     */
    @Serializable(with = InstantSerializer::class)
    val clientUpdatedAt: Instant,
) {
    /**
     * Applies the change on the server.
     *
     * @return the item as saved; `null` after a removal.
     */
    suspend fun send(client: ApiClient): ShoppingListItem? = when (change) {
        is ShoppingItemChange.Toggle -> client.call {
            toggleShoppingItem(itemId, ShoppingItemToggle(change.checked, clientUpdatedAt.toString()))
        }.data

        is ShoppingItemChange.Update -> client.call {
            updateShoppingItem(itemId, ShoppingItemInput(change.name, change.quantityNote))
        }.data

        ShoppingItemChange.Remove -> {
            client.call { deleteShoppingItem(itemId) }
            null
        }
    }
}

/**
 * The items as the user left them: the server's state with the changes still
 * waiting in the queue on top, in their order.
 */
fun List<ShoppingListItem>.applying(mutations: List<PendingMutation>): List<ShoppingListItem> {
    val items = toMutableList()

    for (mutation in mutations) {
        val index = items.indexOfFirst { it.friendlyId == mutation.itemId }

        if (index < 0) {
            continue
        }

        val item = items[index]

        when (val change = mutation.change) {
            is ShoppingItemChange.Toggle -> items[index] = item.copy(
                isChecked = change.checked,
                checkedAt = when {
                    item.isChecked == change.checked -> item.checkedAt
                    change.checked -> mutation.clientUpdatedAt.toString()
                    else -> null
                },
                clientUpdatedAt = mutation.clientUpdatedAt.toString(),
            )

            is ShoppingItemChange.Update -> items[index] = item.copy(name = change.name, quantityNote = change.quantityNote)
            ShoppingItemChange.Remove -> items.removeAt(index)
        }
    }

    return items
}

/** An [Instant] as ISO-8601 in UTC (`2026-10-06T12:00:00.250Z`), to the fraction of a second. */
object InstantSerializer : KSerializer<Instant> {
    override val descriptor = PrimitiveSerialDescriptor("app.jarvis.android.Instant", PrimitiveKind.STRING)

    override fun serialize(encoder: Encoder, value: Instant) = encoder.encodeString(value.toString())

    override fun deserialize(decoder: Decoder): Instant = Instant.parse(decoder.decodeString())
}
