package app.jarvis.android.ui.login

import androidx.annotation.StringRes
import app.jarvis.android.R
import app.jarvis.android.auth.AuthService
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.User
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update

/** A message of the login: the server's own text (already in the app's language) or one of the app's strings. */
sealed interface LoginMessage {
    data class Server(val text: String) : LoginMessage

    data class Resource(@param:StringRes val id: Int) : LoginMessage
}

data class LoginState(
    val email: String = "",
    val password: String = "",
    val isSubmitting: Boolean = false,
    val error: LoginMessage? = null,
)

/** What came back from the Google account picker. */
sealed interface GoogleSignInResult {
    data class IdToken(val token: String) : GoogleSignInResult

    /** The user closed the picker: nothing to show. */
    data object Cancelled : GoogleSignInResult

    /** No server client id in this build (`jarvis.googleServerClientId`). */
    data object NotConfigured : GoogleSignInResult

    /** No Google account on the device. */
    data object NoAccount : GoogleSignInResult

    data object Failed : GoogleSignInResult
}

/**
 * The sign-in (US-1.5, US-1.3): e-mail and password, or Google. Access is by
 * invitation only, so the server's refusal ("invitation required", already in
 * the app's language through `Accept-Language`) is shown as it comes.
 */
class LoginController(
    private val auth: AuthService,
    private val onSignedIn: suspend (User) -> Unit,
) {
    private val mutableState = MutableStateFlow(LoginState())
    val state: StateFlow<LoginState> = mutableState.asStateFlow()

    fun onEmailChange(email: String) = mutableState.update { it.copy(email = email, error = null) }

    fun onPasswordChange(password: String) = mutableState.update { it.copy(password = password, error = null) }

    suspend fun signIn() {
        val email = state.value.email.trim()
        val password = state.value.password

        if (email.isEmpty() || password.isEmpty()) {
            mutableState.update { it.copy(error = LoginMessage.Resource(R.string.login_missing_credentials)) }

            return
        }

        submit { auth.login(email, password) }
    }

    /** [idToken] opens the account picker and gives back its result. */
    suspend fun signInWithGoogle(idToken: suspend () -> GoogleSignInResult) {
        submit {
            when (val result = idToken()) {
                is GoogleSignInResult.IdToken -> auth.loginWithGoogle(result.token)
                GoogleSignInResult.Cancelled -> null
                GoogleSignInResult.NotConfigured -> throw MessageException(R.string.login_google_not_configured)
                GoogleSignInResult.NoAccount -> throw MessageException(R.string.login_google_no_account)
                GoogleSignInResult.Failed -> throw MessageException(R.string.error_generic)
            }
        }
    }

    private suspend fun submit(operation: suspend () -> User?) {
        if (state.value.isSubmitting) {
            return
        }

        mutableState.update { it.copy(isSubmitting = true, error = null) }

        val error = try {
            operation()?.let {
                mutableState.update { state -> state.copy(password = "") }
                onSignedIn(it)
            }
            null
        } catch (exception: ApiException) {
            messageFor(exception)
        } catch (exception: MessageException) {
            LoginMessage.Resource(exception.id)
        }

        mutableState.update { it.copy(isSubmitting = false, error = error) }
    }

    private class MessageException(@param:StringRes val id: Int) : Exception()

    companion object {
        /** What to tell about a refused sign-in. */
        fun messageFor(exception: ApiException): LoginMessage = when (exception) {
            is ApiException.Transport -> LoginMessage.Resource(R.string.error_unreachable)
            is ApiException.Validation, is ApiException.TooManyRequests ->
                exception.displayMessage?.let(LoginMessage::Server) ?: LoginMessage.Resource(R.string.login_invitation_required)

            else -> LoginMessage.Resource(R.string.error_generic)
        }
    }
}
