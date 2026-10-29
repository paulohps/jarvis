package app.jarvis.android.realtime

import app.jarvis.android.support.StubTransport
import com.pusher.client.AuthorizationFailureException
import org.junit.Assert.assertEquals
import org.junit.Test

class ApiChannelAuthorizerTest {
    private val transport = StubTransport()

    @Test
    fun `a private channel is signed with the bearer token`() {
        transport.enqueue(200, """{"auth":"jarvis-local-key:signature"}""")

        val answer = ApiChannelAuthorizer(transport.client()).authorize("private-App.Models.User.1", "123.456")

        val request = transport.requests.single()
        assertEquals("https://jarvis.test/api/v1/broadcasting/auth", request.url.toString())
        assertEquals("Bearer device-token", request.header("Authorization"))
        assertEquals("""{"socket_id":"123.456","channel_name":"private-App.Models.User.1"}""", transport.bodies.single())
        assertEquals("""{"auth":"jarvis-local-key:signature","channel_data":null}""", answer)
    }

    @Test(expected = AuthorizationFailureException::class)
    fun `a refused channel fails the subscription`() {
        transport.enqueue(403, """{"message":"Forbidden."}""")

        ApiChannelAuthorizer(transport.client()).authorize("private-shopping-lists.shl_1", "123.456")
    }
}
