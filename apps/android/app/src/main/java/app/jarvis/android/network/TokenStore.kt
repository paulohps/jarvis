package app.jarvis.android.network

/**
 * Where the Sanctum token of this device lives (encrypted in DataStore in the
 * app, see `auth.DataStoreTokenStore`). The client reads it on every call, so
 * [token] answers from memory; it is cleared when the server answers 401.
 */
interface TokenStore {
    fun token(): String?

    suspend fun save(token: String)

    suspend fun clear()
}

/** A token store in memory, for previews and tests. */
class InMemoryTokenStore(initialToken: String? = null) : TokenStore {
    @Volatile
    private var stored: String? = initialToken

    override fun token(): String? = stored

    override suspend fun save(token: String) {
        stored = token
    }

    override suspend fun clear() {
        stored = null
    }
}
