package app.jarvis.android.ui.settings

import android.content.Intent
import android.provider.Settings
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import app.jarvis.android.R
import app.jarvis.android.app.AppContainer
import app.jarvis.android.push.PushAuthorization
import app.jarvis.android.ui.components.ListRow
import app.jarvis.android.ui.components.Section

/** Whether push is on for this phone; turning it on after a refusal is in the system settings. */
@Composable
fun PushSettingsSection(container: AppContainer) {
    val context = LocalContext.current
    val authorization by container.push.authorization.collectAsStateWithLifecycle()
    val status = when (authorization) {
        PushAuthorization.Authorized -> R.string.push_on
        PushAuthorization.Denied -> R.string.push_off
        PushAuthorization.NotDetermined -> R.string.push_not_asked
    }

    Section(
        header = stringResource(R.string.push_title),
        footer = when {
            !container.push.isAvailable -> stringResource(R.string.push_not_configured)
            authorization == PushAuthorization.Denied -> stringResource(R.string.push_off_footer)
            else -> null
        },
    ) {
        ListRow(
            title = stringResource(R.string.push_title),
            showDivider = authorization == PushAuthorization.Denied,
            trailing = { Text(stringResource(status), color = MaterialTheme.colorScheme.onSurfaceVariant) },
        )

        if (authorization == PushAuthorization.Denied) {
            ListRow(
                title = stringResource(R.string.push_open_settings),
                onClick = {
                    context.startActivity(
                        Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS).putExtra(Settings.EXTRA_APP_PACKAGE, context.packageName),
                    )
                },
                titleContent = { Text(stringResource(R.string.push_open_settings), color = MaterialTheme.colorScheme.primary) },
            )
        }
    }
}
