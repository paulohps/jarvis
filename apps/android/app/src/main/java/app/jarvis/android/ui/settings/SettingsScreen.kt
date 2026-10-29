package app.jarvis.android.ui.settings

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Check
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import app.jarvis.android.BuildConfig
import app.jarvis.android.R
import app.jarvis.android.app.AppContainer
import app.jarvis.android.app.Features
import app.jarvis.android.i18n.AppLanguage
import app.jarvis.android.network.ApiException
import app.jarvis.android.navigation.AppTab
import app.jarvis.android.network.User
import app.jarvis.android.ui.admin.AdminSettingsSection
import app.jarvis.android.ui.components.GroupedList
import app.jarvis.android.ui.components.JarvisScreen
import app.jarvis.android.ui.components.ListRow
import app.jarvis.android.ui.components.Section
import kotlinx.coroutines.launch

/**
 * The account, the language (saved on the server, US-3.2), the workspaces
 * (own color, default), WhatsApp (the priority colors), the voice
 * interpreter, push, the super admin's administration and the sign-out.
 */
@Composable
fun SettingsScreen(container: AppContainer, user: User) {
    val model = container.model
    val scope = rememberCoroutineScope()
    var isConfirmingSignOut by remember { mutableStateOf(false) }
    var languageError by remember { mutableStateOf<String?>(null) }
    val genericError = stringResource(R.string.error_generic)
    val current = AppLanguage.fromApiLocale(user.locale)

    JarvisScreen(title = stringResource(R.string.tab_settings)) {
        GroupedList {
            item {
                Section(header = stringResource(R.string.settings_account)) {
                    ListRow(title = user.name, subtitle = user.email)
                }
            }

            item {
                Section(header = stringResource(R.string.settings_language), footer = languageError) {
                    AppLanguage.entries.forEachIndexed { index, language ->
                        ListRow(
                            title = language.nativeName,
                            showDivider = index < AppLanguage.entries.lastIndex,
                            onClick = {
                                if (language != current) {
                                    scope.launch {
                                        languageError = try {
                                            model.updateLanguage(language)
                                            null
                                        } catch (exception: ApiException) {
                                            exception.displayMessage ?: genericError
                                        }
                                    }
                                }
                            },
                            trailing = {
                                if (language == current) {
                                    Icon(Icons.Filled.Check, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
                                }
                            },
                        )
                    }
                }
            }

            if (user.workspaces.isNotEmpty()) {
                item {
                    WorkspacesSettingsSection(model, user)
                }
            }

            if (user.modules.any { it.slug == AppTab.WhatsApp.moduleSlug && it.isAvailableOnMobile }) {
                item {
                    WhatsappSettingsSection(model)
                }
            }

            if (Features.ASK_JARVIS) {
                item {
                    VoiceSettingsSection(container.voice, user.locale)
                }
            }

            item {
                PushSettingsSection(container)
            }

            if (user.isSuperAdmin) {
                item {
                    AdminSettingsSection(model)
                }
            }

            item {
                Section {
                    ListRow(
                        title = stringResource(R.string.settings_version),
                        trailing = { Text(BuildConfig.VERSION_NAME, color = MaterialTheme.colorScheme.onSurfaceVariant) },
                    )
                }
            }

            item {
                Section {
                    ListRow(
                        title = stringResource(R.string.settings_sign_out),
                        onClick = { isConfirmingSignOut = true },
                        modifier = Modifier.testTag("settings.sign-out"),
                        titleContent = { Text(stringResource(R.string.settings_sign_out), color = MaterialTheme.colorScheme.error) },
                    )
                }
            }
        }
    }

    if (isConfirmingSignOut) {
        AlertDialog(
            onDismissRequest = { isConfirmingSignOut = false },
            title = { Text(stringResource(R.string.settings_sign_out_confirm)) },
            confirmButton = {
                TextButton(onClick = {
                    isConfirmingSignOut = false
                    scope.launch { model.signOut() }
                }) {
                    Text(stringResource(R.string.settings_sign_out), color = MaterialTheme.colorScheme.error)
                }
            },
            dismissButton = {
                TextButton(onClick = { isConfirmingSignOut = false }) {
                    Text(stringResource(R.string.cancel))
                }
            },
            shape = MaterialTheme.shapes.large,
        )
    }
}
