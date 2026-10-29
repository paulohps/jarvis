package app.jarvis.android.network

import app.jarvis.android.support.StubTransport
import kotlinx.coroutines.async
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.toList
import kotlinx.coroutines.launch
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test

class ApiClientTest {
    private val transport = StubTransport()

    @Test
    fun `a 401 clears the token and emits SessionExpired`() = runTest {
        transport.enqueue(401, """{"message":"Unauthenticated."}""")
        val client = transport.client(InMemoryTokenStore("device-token"))
        val event = async(UnconfinedTestDispatcher(testScheduler)) { client.sessionEvents.first() }

        try {
            client.call { me() }
            fail("A 401 must throw")
        } catch (exception: ApiException) {
            assertTrue(exception is ApiException.Unauthenticated)
        }

        assertNull(client.tokenStore.token())
        assertEquals(SessionEvent.SessionExpired, event.await())
    }

    @Test
    fun `a 401 of the sign-in is not a lost session`() = runTest {
        transport.enqueue(401, """{"message":"Unauthenticated."}""")
        val client = transport.client(InMemoryTokenStore("old-token"))
        val events = mutableListOf<SessionEvent>()
        backgroundScope.launch(UnconfinedTestDispatcher(testScheduler)) { client.sessionEvents.toList(events) }

        runCatching { client.call { login(LoginRequest("ana@example.com", "secret", "Pixel")) } }

        assertEquals(emptyList<SessionEvent>(), events)
        assertEquals("old-token", client.tokenStore.token())
        assertNull(transport.requests.single().header("Authorization"))
        assertNull(transport.requests.single().header("X-Jarvis-No-Auth"))
    }

    @Test
    fun `every call sends the bearer token, json and the app language`() = runTest {
        transport.enqueue(
            200,
            """{"data":{"friendly_id":"usr_1","name":"Ana","email":"ana@example.com","locale":"pt_BR","timezone":"America/Sao_Paulo","is_super_admin":false,"modules":[{"slug":"shopping","name":"Shopping","is_available_on_mobile":true}],"broadcast_channel":"private-App.Models.User.1","unknown":1}}""",
        )

        val user = transport.client(acceptLanguage = { "pt-BR" }).call { me() }.data

        val request = transport.requests.single()
        assertEquals("https://jarvis.test/api/v1/me", request.url.toString())
        assertEquals("Bearer device-token", request.header("Authorization"))
        assertEquals("application/json", request.header("Accept"))
        assertEquals("pt-BR", request.header("Accept-Language"))
        assertEquals("pt_BR", user.locale)
        assertEquals(listOf("shopping"), user.modules.map { it.slug })
        assertEquals("private-App.Models.User.1", user.broadcastChannel)
    }

    @Test
    fun `a 403 emits AccessDenied and keeps the session`() = runTest {
        transport.enqueue(403, """{"message":"This module is not enabled for you."}""")
        val client = transport.client()
        val event = async(UnconfinedTestDispatcher(testScheduler)) { client.sessionEvents.first() }

        try {
            client.call { notifications() }
            fail("A 403 must throw")
        } catch (exception: ApiException.Forbidden) {
            assertEquals("This module is not enabled for you.", exception.displayMessage)
        }

        assertEquals(SessionEvent.AccessDenied, event.await())
        assertEquals("device-token", client.tokenStore.token())
    }

    @Test
    fun `a 422 gives the first validation message, as the server translated it`() = runTest {
        transport.enqueue(422, """{"message":"Convite necessário.","errors":{"id_token":["É preciso um convite para entrar."]}}""")

        try {
            transport.client(InMemoryTokenStore(null)).call { google(GoogleLoginRequest("id-token", "Pixel")) }
            fail("A 422 must throw")
        } catch (exception: ApiException.Validation) {
            assertEquals("É preciso um convite para entrar.", exception.displayMessage)
            assertEquals("É preciso um convite para entrar.", exception.firstError("id_token"))
        }

        assertEquals("https://jarvis.test/api/v1/auth/google", transport.requests.single().url.toString())
        assertEquals("""{"id_token":"id-token","device_name":"Pixel"}""", transport.bodies.single())
    }

    @Test
    fun `no answer is a transport failure`() = runTest {
        transport.enqueueFailure()

        try {
            transport.client().call { me() }
            fail("No answer must throw")
        } catch (exception: ApiException) {
            assertTrue(exception is ApiException.Transport)
        }
    }

    @Test
    fun `a device registration sends a null push token on purpose`() = runTest {
        transport.enqueue(201, """{"data":{"friendly_id":"dev_1","name":"Pixel","platform":"android","has_push_token":false,"is_current":true}}""")

        transport.client().call { registerDevice(DeviceRegistration(name = "Pixel", pushToken = null)) }

        assertEquals("""{"platform":"android","name":"Pixel","push_token":null}""", transport.bodies.single())
    }

    @Test
    fun `a logout answered 204 reads as no body`() = runTest {
        transport.enqueue(204)

        transport.client().call { logout() }

        assertEquals("POST", transport.requests.single().method)
    }
}
