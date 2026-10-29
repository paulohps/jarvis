package app.jarvis.android.auth

import app.jarvis.android.network.ApiClient
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.GoogleLoginRequest
import app.jarvis.android.network.LoginRequest
import app.jarvis.android.network.TokenResponse
import app.jarvis.android.network.User

/**
 * Signs the device in and out (US-1.5, US-1.3). A successful sign-in, with
 * e-mail and password or with the ID token of Google (Credential Manager),
 * saves the device's Sanctum token; the sign-out revokes it on the server and
 * always forgets it here, even without network.
 *
 * @param deviceName the name of the device as the user set it.
 */
class AuthService(
    private val client: ApiClient,
    private val deviceName: () -> String,
) {
    val isSignedIn: Boolean
        get() = client.tokenStore.token() != null

    suspend fun login(email: String, password: String): User =
        keep(client.call { login(LoginRequest(email, password, deviceName())) })

    suspend fun loginWithGoogle(idToken: String): User =
        keep(client.call { google(GoogleLoginRequest(idToken, deviceName())) })

    suspend fun logout() {
        if (isSignedIn) {
            try {
                client.call { logout() }
            } catch (_: ApiException) {
                // Forgotten here anyway; the user can still revoke it in the device list.
            }
        }

        client.tokenStore.clear()
    }

    private suspend fun keep(response: TokenResponse): User {
        client.tokenStore.save(response.token)

        return response.user
    }
}
