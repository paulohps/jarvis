package app.jarvis.android.ui.login

import app.jarvis.android.R
import app.jarvis.android.auth.AuthService
import app.jarvis.android.network.InMemoryTokenStore
import app.jarvis.android.network.User
import app.jarvis.android.support.StubTransport
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Test

class LoginControllerTest {
    private val transport = StubTransport()
    private val tokenStore = InMemoryTokenStore(null)
    private val signedIn = mutableListOf<User>()

    private fun controller(language: String = "en") =
        LoginController(AuthService(transport.client(tokenStore, acceptLanguage = { language })) { "Pixel 10" }) { signedIn += it }

    private val tokenIssued =
        """{"token":"7|device-token","user":{"friendly_id":"usr_1","name":"Ana","email":"ana@example.com","locale":"pt_BR","modules":[]}}"""

    @Test
    fun `an invited email signs in and keeps the device token`() = runTest {
        transport.enqueue(200, tokenIssued)
        val controller = controller()

        controller.onEmailChange("  ana@example.com ")
        controller.onPasswordChange("secret")
        controller.signIn()

        assertEquals(listOf("Ana"), signedIn.map { it.name })
        assertEquals("7|device-token", tokenStore.token())
        assertEquals("""{"email":"ana@example.com","password":"secret","device_name":"Pixel 10"}""", transport.bodies.single())
        assertNull(controller.state.value.error)
        assertEquals("", controller.state.value.password)
        assertFalse(controller.state.value.isSubmitting)
    }

    @Test
    fun `an invited google account signs in with its id token`() = runTest {
        transport.enqueue(200, tokenIssued)

        controller().signInWithGoogle { GoogleSignInResult.IdToken("google-id-token") }

        assertEquals("https://jarvis.test/api/v1/auth/google", transport.requests.single().url.toString())
        assertEquals("""{"id_token":"google-id-token","device_name":"Pixel 10"}""", transport.bodies.single())
        assertEquals("7|device-token", tokenStore.token())
    }

    @Test
    fun `an email without invitation sees the server message in the app language`() = runTest {
        transport.enqueue(
            422,
            """{"message":"O acesso é só por convite.","errors":{"id_token":["O acesso é só por convite. Peça um convite ao administrador."]}}""",
        )
        val controller = controller(language = "pt-BR")

        controller.signInWithGoogle { GoogleSignInResult.IdToken("google-id-token") }

        assertEquals("pt-BR", transport.requests.single().header("Accept-Language"))
        assertEquals(
            LoginMessage.Server("O acesso é só por convite. Peça um convite ao administrador."),
            controller.state.value.error,
        )
        assertNull(tokenStore.token())
        assertEquals(emptyList<User>(), signedIn)
    }

    @Test
    fun `a refusal without a message falls back to the invitation text`() = runTest {
        transport.enqueue(422, """{"message":"","errors":{}}""")
        val controller = controller()

        controller.signInWithGoogle { GoogleSignInResult.IdToken("google-id-token") }

        assertEquals(LoginMessage.Resource(R.string.login_invitation_required), controller.state.value.error)
    }

    @Test
    fun `empty credentials are not sent`() = runTest {
        val controller = controller()

        controller.signIn()

        assertEquals(LoginMessage.Resource(R.string.login_missing_credentials), controller.state.value.error)
        assertEquals(emptyList<Any>(), transport.requests)
    }

    @Test
    fun `closing the google picker shows nothing`() = runTest {
        val controller = controller()

        controller.signInWithGoogle { GoogleSignInResult.Cancelled }

        assertNull(controller.state.value.error)
        assertEquals(emptyList<Any>(), transport.requests)
    }

    @Test
    fun `no network says so`() = runTest {
        transport.enqueueFailure()
        val controller = controller()
        controller.onEmailChange("ana@example.com")
        controller.onPasswordChange("secret")

        controller.signIn()

        assertEquals(LoginMessage.Resource(R.string.error_unreachable), controller.state.value.error)
    }
}
