package app.jarvis.android.app

import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import app.jarvis.android.network.ApiClient
import app.jarvis.android.network.User
import kotlinx.coroutines.flow.first

/** The last `/me`, so the app opens on its tabs before the network answers. */
class UserCache(private val dataStore: DataStore<Preferences>) {
    suspend fun load(): User? = dataStore.data.first()[KEY]?.let {
        runCatching { ApiClient.json.decodeFromString<User>(it) }.getOrNull()
    }

    suspend fun save(user: User) {
        dataStore.edit { it[KEY] = ApiClient.json.encodeToString(User.serializer(), user) }
    }

    suspend fun clear() {
        dataStore.edit { it.remove(KEY) }
    }

    private companion object {
        val KEY = stringPreferencesKey("cached_user")
    }
}
