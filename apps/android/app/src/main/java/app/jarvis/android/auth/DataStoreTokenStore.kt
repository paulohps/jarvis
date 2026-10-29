package app.jarvis.android.auth

import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import app.jarvis.android.network.TokenStore
import kotlinx.coroutines.flow.first

/**
 * The Sanctum token of this device, encrypted ([TokenCipher]) in a
 * Preferences DataStore. [load] reads it once at launch; afterwards
 * [token] answers from memory, as the client asks for it on every call.
 */
class DataStoreTokenStore(
    private val dataStore: DataStore<Preferences>,
    private val cipher: TokenCipher,
) : TokenStore {
    @Volatile
    private var cached: String? = null

    /** Reads the saved token (call before the first request). */
    suspend fun load(): String? {
        cached = dataStore.data.first()[KEY]?.let(cipher::decrypt)

        return cached
    }

    override fun token(): String? = cached

    override suspend fun save(token: String) {
        val encrypted = cipher.encrypt(token)
        dataStore.edit { it[KEY] = encrypted }
        cached = token
    }

    override suspend fun clear() {
        cached = null
        dataStore.edit { it.remove(KEY) }
    }

    internal companion object {
        val KEY = stringPreferencesKey("session_token")
    }
}
