package app.jarvis.android.auth

import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.PreferenceDataStoreFactory
import androidx.datastore.preferences.core.Preferences
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancelAndJoin
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertNull
import org.junit.Rule
import org.junit.Test
import org.junit.rules.TemporaryFolder
import java.io.File

class TokenStoreTest {
    @get:Rule
    val folder = TemporaryFolder()

    /** Reversible, so the test sees that what reaches the disk is not the token. */
    private class ReversingCipher : TokenCipher {
        override fun encrypt(plainText: String) = "enc:" + plainText.reversed()

        override fun decrypt(cipherText: String) = cipherText.removePrefix("enc:").takeIf { it != cipherText }?.reversed()
    }

    private val file: File by lazy { File(folder.root, "session.preferences_pb") }

    /** Opens the file, runs [block] and closes the DataStore, as a new launch of the app would. */
    private suspend fun <T> withStore(block: suspend (DataStoreTokenStore, DataStore<Preferences>) -> T): T {
        val job = SupervisorJob()
        val dataStore = PreferenceDataStoreFactory.create(scope = CoroutineScope(Dispatchers.IO + job)) { file }

        try {
            return block(DataStoreTokenStore(dataStore, ReversingCipher()), dataStore)
        } finally {
            job.cancelAndJoin()
        }
    }

    @Test
    fun `a saved token is persisted, encrypted, for the next launch`() = runTest {
        withStore { store, dataStore ->
            assertNull(store.load())

            store.save("1|plain-sanctum-token")

            assertEquals("1|plain-sanctum-token", store.token())
            assertNotEquals("1|plain-sanctum-token", dataStore.data.first()[DataStoreTokenStore.KEY])
        }

        withStore { store, _ ->
            assertNull(store.token())
            assertEquals("1|plain-sanctum-token", store.load())
            assertEquals("1|plain-sanctum-token", store.token())
        }
    }

    @Test
    fun `a cleared token is removed from memory and from disk`() = runTest {
        withStore { store, _ ->
            store.save("1|plain-sanctum-token")
            store.clear()

            assertNull(store.token())
        }

        withStore { store, dataStore ->
            assertNull(store.load())
            assertNull(dataStore.data.first()[DataStoreTokenStore.KEY])
        }
    }

    @Test
    fun `a token that can no longer be decrypted reads as signed out`() = runTest {
        withStore { store, _ -> store.save("1|plain-sanctum-token") }

        val job = SupervisorJob()
        val dataStore = PreferenceDataStoreFactory.create(scope = CoroutineScope(Dispatchers.IO + job)) { file }
        val lostKey = object : TokenCipher {
            override fun encrypt(plainText: String) = plainText

            override fun decrypt(cipherText: String): String? = null
        }

        assertNull(DataStoreTokenStore(dataStore, lostKey).load())
        job.cancelAndJoin()
    }
}
