package app.jarvis.android.ui.login

import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.systemBarsPadding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ErrorOutline
import androidx.compose.material.icons.outlined.Info
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.focus.FocusDirection
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import app.jarvis.android.R
import app.jarvis.android.app.AppContainer
import app.jarvis.android.app.SignedOutNotice
import kotlinx.coroutines.launch

/**
 * The sign-in (US-1.5, US-1.3): e-mail and password, or "Continue with
 * Google", in Material 3. A refused invitation shows the server's
 * message, in the user's language.
 */
@Composable
fun LoginScreen(container: AppContainer, notice: SignedOutNotice?) {
    val controller = remember(container) { LoginController(container.auth, container.model::didSignIn) }
    val google = remember(container) { GoogleSignInProvider(container.configuration.googleServerClientId) }
    val state by controller.state.collectAsStateWithLifecycle()
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val focusManager = LocalFocusManager.current

    val signIn: () -> Unit = {
        focusManager.clearFocus()
        scope.launch { controller.signIn() }
    }
    val signInWithGoogle: () -> Unit = {
        focusManager.clearFocus()
        scope.launch { controller.signInWithGoogle { google.idToken(context) } }
    }

    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(MaterialTheme.colorScheme.surface)
            .systemBarsPadding()
            .imePadding(),
        contentAlignment = Alignment.Center,
    ) {
        Column(
            modifier = Modifier
                .widthIn(max = 480.dp)
                .fillMaxWidth()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 24.dp, vertical = 48.dp),
            verticalArrangement = Arrangement.spacedBy(24.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Header()

            val message = state.error?.let { it to true } ?: notice?.let { LoginMessage.Resource(R.string.session_expired) to false }

            if (message != null) {
                MessageBanner(message.first, isError = message.second)
            }

            Credentials(
                email = state.email,
                password = state.password,
                onEmailChange = controller::onEmailChange,
                onPasswordChange = controller::onPasswordChange,
                onNext = { focusManager.moveFocus(FocusDirection.Down) },
                onGo = signIn,
            )

            Button(
                onClick = signIn,
                enabled = !state.isSubmitting,
                modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp).testTag("login.submit"),
            ) {
                if (state.isSubmitting) {
                    CircularProgressIndicator(Modifier.size(22.dp), color = MaterialTheme.colorScheme.onPrimary, strokeWidth = 2.dp)
                } else {
                    Text(stringResource(R.string.login_sign_in))
                }
            }

            OrDivider()

            OutlinedButton(
                onClick = signInWithGoogle,
                enabled = !state.isSubmitting,
                modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp).testTag("login.google"),
            ) {
                Text("G", fontWeight = FontWeight.Bold, color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.titleLarge)
                Spacer(Modifier.size(10.dp))
                Text(stringResource(R.string.login_continue_with_google), color = MaterialTheme.colorScheme.onSurface)
            }
        }
    }
}

@Composable
private fun Header() {
    Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Image(
            painterResource(R.drawable.jarvis_logo),
            contentDescription = null,
            modifier = Modifier.size(84.dp).clip(MaterialTheme.shapes.extraLarge),
        )

        Text(stringResource(R.string.app_name), style = MaterialTheme.typography.headlineLarge)

        Text(
            stringResource(R.string.login_tagline),
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            textAlign = TextAlign.Center,
        )
    }
}

@Composable
private fun MessageBanner(message: LoginMessage, isError: Boolean) {
    val text = when (message) {
        is LoginMessage.Server -> message.text
        is LoginMessage.Resource -> stringResource(message.id)
    }

    Row(
        modifier = Modifier
            .fillMaxWidth()
            .background(
                if (isError) MaterialTheme.colorScheme.errorContainer else MaterialTheme.colorScheme.surfaceContainerHigh,
                MaterialTheme.shapes.medium,
            )
            .padding(14.dp)
            .testTag("login.error"),
        horizontalArrangement = Arrangement.spacedBy(10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(
            if (isError) Icons.Filled.ErrorOutline else Icons.Outlined.Info,
            contentDescription = null,
            tint = if (isError) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Text(
            text,
            style = MaterialTheme.typography.bodyMedium,
            color = if (isError) MaterialTheme.colorScheme.onErrorContainer else MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

@Composable
private fun Credentials(
    email: String,
    password: String,
    onEmailChange: (String) -> Unit,
    onPasswordChange: (String) -> Unit,
    onNext: () -> Unit,
    onGo: () -> Unit,
) {
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        OutlinedTextField(
            value = email,
            onValueChange = onEmailChange,
            label = { Text(stringResource(R.string.login_email)) },
            singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email, imeAction = ImeAction.Next, autoCorrectEnabled = false),
            keyboardActions = KeyboardActions(onNext = { onNext() }),
            modifier = Modifier.fillMaxWidth().testTag("login.email"),
        )

        OutlinedTextField(
            value = password,
            onValueChange = onPasswordChange,
            label = { Text(stringResource(R.string.login_password)) },
            singleLine = true,
            visualTransformation = PasswordVisualTransformation(),
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, imeAction = ImeAction.Go),
            keyboardActions = KeyboardActions(onGo = { onGo() }),
            modifier = Modifier.fillMaxWidth().testTag("login.password"),
        )
    }
}

@Composable
private fun OrDivider() {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        HorizontalDivider(Modifier.weight(1f).height(1.dp), color = MaterialTheme.colorScheme.outlineVariant)
        Text(stringResource(R.string.login_or), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        HorizontalDivider(Modifier.weight(1f).height(1.dp), color = MaterialTheme.colorScheme.outlineVariant)
    }
}
