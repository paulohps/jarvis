package app.jarvis.android.offline

import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import app.jarvis.android.network.ApiClient
import kotlinx.coroutines.flow.first
import kotlinx.serialization.builtins.ListSerializer

/** Where the queued changes are kept between launches. */
interface MutationStore {
    suspend fun load(): List<PendingMutation>

    suspend fun save(mutations: List<PendingMutation>)
}

/**
 * The queue as JSON in a Preferences DataStore, written on every change (the
 * DataStore writes the file atomically), so a killed app loses nothing it showed.
 */
class DataStoreMutationStore(private val dataStore: DataStore<Preferences>) : MutationStore {
    override suspend fun load(): List<PendingMutation> {
        val json = dataStore.data.first()[KEY] ?: return emptyList()

        return runCatching { ApiClient.json.decodeFromString(serializer, json) }.getOrDefault(emptyList())
    }

    override suspend fun save(mutations: List<PendingMutation>) {
        dataStore.edit { preferences ->
            if (mutations.isEmpty()) {
                preferences.remove(KEY)
            } else {
                preferences[KEY] = ApiClient.json.encodeToString(serializer, mutations)
            }
        }
    }

    private companion object {
        val KEY = stringPreferencesKey("shopping_mutations")
        val serializer = ListSerializer(PendingMutation.serializer())
    }
}

/** A queue store in memory, for previews and tests. */
class InMemoryMutationStore(initial: List<PendingMutation> = emptyList()) : MutationStore {
    @Volatile
    private var mutations: List<PendingMutation> = initial

    override suspend fun load(): List<PendingMutation> = mutations

    override suspend fun save(mutations: List<PendingMutation>) {
        this.mutations = mutations
    }
}
