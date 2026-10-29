package app.jarvis.android.ui

import android.Manifest
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.togetherWith
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberCoroutineScope
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import app.jarvis.android.app.AppContainer
import app.jarvis.android.app.SessionState
import app.jarvis.android.ui.login.LoginScreen
import app.jarvis.android.ui.shell.MainShell
import app.jarvis.android.ui.voice.VoiceSheet
import kotlinx.coroutines.launch

/** The root: the login while signed out (any 401 comes back here), the tabs while signed in. */
@Composable
fun JarvisApp(container: AppContainer) {
    val state by container.model.state.collectAsStateWithLifecycle()

    // Only the way out of the splash fades; the sign-in and sign-out swap at once.
    AnimatedContent(
        state,
        transitionSpec = { fadeIn() togetherWith fadeOut() },
        contentKey = { it is SessionState.Launching },
        label = "session",
    ) { session ->
        SessionContent(container, session)
    }

    // Over any screen: an App Action may come before the session is ready.
    VoiceSheet(container.voice)
}

@Composable
private fun SessionContent(container: AppContainer, state: SessionState) {
    when (val session = state) {
        SessionState.Launching -> SplashScreen()

        is SessionState.SignedOut -> LoginScreen(container, session.notice)
        is SessionState.SignedIn -> {
            val notifications by container.notifications.state.collectAsStateWithLifecycle()

            PushPermissionPrompt(container)
            MainShell(container, session.user, unreadNotifications = notifications.unreadCount)
        }
    }
}

/** Asks `POST_NOTIFICATIONS` once, after the sign-in (US-5.2). */
@Composable
private fun PushPermissionPrompt(container: AppContainer) {
    val shouldAsk by container.push.shouldAskPermission.collectAsStateWithLifecycle()
    val scope = rememberCoroutineScope()
    val launcher = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        scope.launch { container.push.didAnswerPermission(granted) }
    }

    LaunchedEffect(shouldAsk) {
        if (shouldAsk) {
            container.push.didShowPermissionPrompt()
            launcher.launch(Manifest.permission.POST_NOTIFICATIONS)
        }
    }
}
