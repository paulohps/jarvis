package app.jarvis.android.push

import app.jarvis.android.network.InMemoryTokenStore
import app.jarvis.android.support.StubTransport
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Test

class PushRegistrationTest {
    private val transport = StubTransport()
    private var system = PushAuthorization.NotDetermined
    private val device = """{"data":{"friendly_id":"dev_1","name":"Pixel","platform":"android","has_push_token":true,"is_current":true}}"""

    private fun registration(token: String? = "device-token") =
        PushRegistration(transport.client(InMemoryTokenStore(token)), { "Pixel 10" }) { system }

    @Test
    fun `an allowed permission sends the fcm token to devices once`() = runTest {
        transport.enqueue(201, device)
        val registration = registration()

        registration.didReceive("fcm-1")
        registration.didAnswerPermission(granted = true)
        registration.didReceive("fcm-1")

        assertEquals("https://jarvis.test/api/v1/devices", transport.requests.single().url.toString())
        assertEquals("Bearer device-token", transport.requests.single().header("Authorization"))
        assertEquals("""{"platform":"android","name":"Pixel 10","push_token":"fcm-1"}""", transport.bodies.single())
    }

    @Test
    fun `a rotated token is sent again`() = runTest {
        transport.enqueue(201, device)
        transport.enqueue(200, device)
        system = PushAuthorization.Authorized
        val registration = registration()

        registration.refreshAuthorization()
        registration.didReceive("fcm-1")
        registration.didReceive("fcm-2")

        assertEquals(listOf("fcm-1", "fcm-2").map { """{"platform":"android","name":"Pixel 10","push_token":"$it"}""" }, transport.bodies)
    }

    @Test
    fun `a refused permission registers the device without a push token`() = runTest {
        transport.enqueue(201, device)
        val registration = registration()

        registration.didReceive("fcm-1")
        registration.didAnswerPermission(granted = false)

        assertEquals("""{"platform":"android","name":"Pixel 10","push_token":null}""", transport.bodies.single())
    }

    @Test
    fun `nothing is sent before the permission was answered or without a session`() = runTest {
        registration().didReceive("fcm-1")
        system = PushAuthorization.Authorized
        registration(token = null).apply {
            refreshAuthorization()
            didReceive("fcm-1")
        }

        assertEquals(emptyList<Any>(), transport.requests)
    }

    @Test
    fun `a failed registration is tried again on the next foreground`() = runTest {
        transport.enqueueFailure()
        transport.enqueue(201, device)
        system = PushAuthorization.Authorized
        val registration = registration()
        registration.didReceive("fcm-1")

        registration.refreshAuthorization()
        registration.refreshAuthorization()

        assertEquals(2, transport.requests.size)
    }

    @Test
    fun `after the sign-out the next session registers again`() = runTest {
        transport.enqueue(201, device)
        transport.enqueue(201, device)
        system = PushAuthorization.Authorized
        val registration = registration()
        registration.didReceive("fcm-1")
        registration.refreshAuthorization()

        registration.reset()
        registration.refreshAuthorization()

        assertEquals(2, transport.requests.size)
    }
}
