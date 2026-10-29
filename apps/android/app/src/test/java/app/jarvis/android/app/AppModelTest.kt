package app.jarvis.android.app

import androidx.datastore.preferences.core.PreferenceDataStoreFactory
import app.jarvis.android.auth.AuthService
import app.jarvis.android.i18n.LanguagePreference
import app.jarvis.android.navigation.AppTab
import app.jarvis.android.navigation.Destination
import app.jarvis.android.network.InMemoryTokenStore
import app.jarvis.android.support.FakeAppLocales
import app.jarvis.android.support.StubTransport
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancelAndJoin
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeout
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Rule
import org.junit.Test
import org.junit.rules.TemporaryFolder
import java.io.File

class AppModelTest {
    @get:Rule
    val folder = TemporaryFolder()

    private val transport = StubTransport()
    private val locales = FakeAppLocales(system = listOf("en-US"))

    private fun me(locale: String = "pt_BR", modules: List<String> = emptyList()) =
        """{"data":{"friendly_id":"usr_1","name":"Ana","email":"ana@example.com","locale":"$locale","timezone":"UTC","modules":[${
            modules.joinToString(",") { """{"slug":"$it","name":"$it","is_available_on_mobile":true}""" }
        }]}}"""

    private fun withModel(token: String?, block: suspend (AppModel) -> Unit) = runTest {
        val job = SupervisorJob()
        val dataStore = PreferenceDataStoreFactory.create(scope = CoroutineScope(Dispatchers.IO + job)) { File(folder.root, "cache.preferences_pb") }
        val client = transport.client(InMemoryTokenStore(token))
        val model = AppModel(
            scope = CoroutineScope(UnconfinedTestDispatcher(testScheduler) + SupervisorJob()),
            client = client,
            auth = AuthService(client) { "Pixel" },
            languagePreference = LanguagePreference(locales),
            userCache = UserCache(dataStore),
        )

        try {
            block(model)
        } finally {
            job.cancelAndJoin()
        }
    }

    @Test
    fun `without a token the app opens at the login`() = withModel(token = null) { model ->
        model.start()

        assertEquals(SessionState.SignedOut(), model.state.value)
        assertEquals(emptyList<Any>(), transport.requests)
    }

    @Test
    fun `a saved token opens the session and the language follows me`() = withModel(token = "device-token") { model ->
        transport.enqueue(200, me(locale = "pt_BR"))

        model.start()

        assertEquals("Ana", model.user?.name)
        assertEquals(listOf("pt-BR"), locales.app)
    }

    @Test
    fun `a 401 of any call takes the app back to the login`() = withModel(token = "device-token") { model ->
        transport.enqueue(200, me())
        model.start()

        transport.enqueue(401, """{"message":"Unauthenticated."}""")
        model.refreshUser()

        // The 401 is announced from OkHttp's thread; the shell follows it right after.
        val state = withContext(Dispatchers.Default) {
            withTimeout(5_000) { model.state.first { it is SessionState.SignedOut } }
        }

        assertEquals(SessionState.SignedOut(SignedOutNotice.SessionExpired), state)
        assertNull(model.client.tokenStore.token())
        assertEquals(emptyList<String>(), locales.app)
    }

    @Test
    fun `the sign-out forgets the token even without network`() = withModel(token = "device-token") { model ->
        transport.enqueue(200, me())
        model.start()

        transport.enqueueFailure()
        model.signOut()

        assertEquals(SessionState.SignedOut(), model.state.value)
        assertNull(model.client.tokenStore.token())
    }

    @Test
    fun `a module switched off disappears on the refresh`() = withModel(token = "device-token") { model ->
        transport.enqueue(200, me(modules = listOf("shopping", "reminders", "invoices")))
        model.start()

        assertEquals(listOf(AppTab.Shopping, AppTab.Reminders, AppTab.Notifications, AppTab.Settings), model.tabs.value)
        assertEquals(AppTab.Shopping, model.router.state.value.selectedTab)

        transport.enqueue(200, me(modules = listOf("reminders")))
        model.didBecomeActive()

        assertEquals(listOf(AppTab.Reminders, AppTab.Notifications, AppTab.Settings), model.tabs.value)
        assertEquals(AppTab.Reminders, model.router.state.value.selectedTab)
    }

    @Test
    fun `a push tapped before the session is ready opens its screen once signed in`() = withModel(token = "device-token") { model ->
        model.open("jarvis://reminders/remo_1")

        transport.enqueue(200, me(modules = listOf("shopping", "reminders")))
        model.start()

        assertEquals(AppTab.Reminders, model.router.state.value.selectedTab)
        assertEquals(listOf(Destination.Reminder("remo_1")), model.router.state.value.stack(AppTab.Reminders))
    }

    @Test
    fun `a notification link of the server opens the right tab`() = withModel(token = "device-token") { model ->
        transport.enqueue(200, me(modules = listOf("shopping", "reminders")))
        model.start()

        model.open("https://jarvis.test/shopping/lists/shl_9")

        assertEquals(AppTab.Shopping, model.router.state.value.selectedTab)
        assertEquals(listOf(Destination.ShoppingList("shl_9")), model.router.state.value.stack(AppTab.Shopping))
    }
}
