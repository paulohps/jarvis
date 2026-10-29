package app.jarvis.android.ui.login

import android.content.Context
import androidx.credentials.ClearCredentialStateRequest
import androidx.credentials.CredentialManager
import androidx.credentials.CustomCredential
import androidx.credentials.GetCredentialRequest
import androidx.credentials.exceptions.ClearCredentialException
import androidx.credentials.exceptions.GetCredentialCancellationException
import androidx.credentials.exceptions.GetCredentialException
import androidx.credentials.exceptions.NoCredentialException
import com.google.android.libraries.identity.googleid.GetSignInWithGoogleOption
import com.google.android.libraries.identity.googleid.GoogleIdTokenCredential
import com.google.android.libraries.identity.googleid.GoogleIdTokenParsingException

/**
 * "Continue with Google" through the Credential Manager. The ID token is
 * issued to the server's web OAuth client ([serverClientId], the server's
 * `GOOGLE_CLIENT_ID`), which `POST /auth/google` accepts as an audience.
 */
class GoogleSignInProvider(private val serverClientId: String?) {
    /** Opens the account picker over [activityContext] (it must be an Activity). */
    suspend fun idToken(activityContext: Context): GoogleSignInResult {
        if (serverClientId.isNullOrBlank()) {
            return GoogleSignInResult.NotConfigured
        }

        val request = GetCredentialRequest.Builder()
            .addCredentialOption(GetSignInWithGoogleOption.Builder(serverClientId).build())
            .build()

        return try {
            val credential = CredentialManager.create(activityContext).getCredential(activityContext, request).credential

            if (credential is CustomCredential && credential.type == GoogleIdTokenCredential.TYPE_GOOGLE_ID_TOKEN_CREDENTIAL) {
                GoogleSignInResult.IdToken(GoogleIdTokenCredential.createFrom(credential.data).idToken)
            } else {
                GoogleSignInResult.Failed
            }
        } catch (_: GetCredentialCancellationException) {
            GoogleSignInResult.Cancelled
        } catch (_: NoCredentialException) {
            GoogleSignInResult.NoAccount
        } catch (_: GoogleIdTokenParsingException) {
            GoogleSignInResult.Failed
        } catch (_: GetCredentialException) {
            GoogleSignInResult.Failed
        }
    }

    /** After the sign-out: the next "Continue with Google" shows the account picker again. */
    suspend fun forget(context: Context) {
        try {
            CredentialManager.create(context).clearCredentialState(ClearCredentialStateRequest())
        } catch (_: ClearCredentialException) {
            // Nothing kept to forget.
        }
    }
}
