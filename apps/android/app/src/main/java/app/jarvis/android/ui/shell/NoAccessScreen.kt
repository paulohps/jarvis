package app.jarvis.android.ui.shell

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Lock
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import app.jarvis.android.R
import app.jarvis.android.ui.components.ContentUnavailable

/** What a module screen shows when the server answers 403 (US-11.1). "Try again" reads `/me` again. */
@Composable
fun NoAccessScreen(onRetry: () -> Unit) {
    ContentUnavailable(
        title = stringResource(R.string.no_access_title),
        icon = Icons.Filled.Lock,
        description = stringResource(R.string.no_access_description),
        actionLabel = stringResource(R.string.try_again),
        onAction = onRetry,
        modifier = Modifier.testTag("no-access"),
    )
}
