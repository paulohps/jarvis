package app.jarvis.android.ui.settings

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material.icons.filled.SearchOff
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.outlined.StarOutline
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import app.jarvis.android.R
import app.jarvis.android.app.AppModel
import app.jarvis.android.navigation.AppTab
import app.jarvis.android.navigation.Destination
import app.jarvis.android.network.ApiException
import app.jarvis.android.network.User
import app.jarvis.android.network.UserModule
import app.jarvis.android.network.UserWorkspace
import app.jarvis.android.network.WorkspaceColorOption
import app.jarvis.android.network.WorkspacePreferenceInput
import app.jarvis.android.ui.components.ContentUnavailable
import app.jarvis.android.ui.components.FormError
import app.jarvis.android.ui.components.GroupedList
import app.jarvis.android.ui.components.JarvisScreen
import app.jarvis.android.ui.components.ListRow
import app.jarvis.android.ui.components.Section
import app.jarvis.android.ui.components.WorkspaceColorPalette
import app.jarvis.android.ui.components.WorkspaceDot
import app.jarvis.android.ui.components.observedUser
import app.jarvis.android.ui.components.userMessage
import kotlinx.coroutines.launch

/**
 * The user's workspaces (from `/me`), each with the color they see it in and
 * the default one marked; a workspace opens its preferences. Hidden without
 * workspaces (a server older than them).
 */
@Composable
fun WorkspacesSettingsSection(model: AppModel, user: User) {
    val workspaces = user.workspaces

    if (workspaces.isEmpty()) {
        return
    }

    Section(header = stringResource(R.string.workspaces), footer = stringResource(R.string.workspaces_settings_footer)) {
        workspaces.forEachIndexed { index, workspace ->
            ListRow(
                title = workspace.name,
                showDivider = index < workspaces.lastIndex,
                onClick = { model.router.push(AppTab.Settings, Destination.WorkspacePreferences(workspace.id)) },
                modifier = Modifier.testTag("settings.workspace.${workspace.id}"),
                leading = { WorkspaceDot(workspace.colorHex, size = 14.dp) },
                trailing = {
                    if (workspace.isDefault) {
                        Text(stringResource(R.string.workspace_default), color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }

                    Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
                },
            )
        }
    }
}

/**
 * One of the user's workspaces: the color they see it in — their own, or the
 * workspace's — and making it their default. Saved at once; `/me` is read
 * again so every screen follows.
 */
@Composable
fun WorkspacePreferencesScreen(model: AppModel, workspaceId: String, onBack: () -> Unit) {
    val user = model.observedUser()
    // Read from `/me`, so it follows what was saved.
    val workspace = user?.workspaces?.firstOrNull { it.id == workspaceId }
    var colors by remember { mutableStateOf<List<WorkspaceColorOption>?>(null) }
    var colorsFailure by remember { mutableStateOf<ApiException?>(null) }
    var isSaving by remember { mutableStateOf(false) }
    var failure by remember { mutableStateOf<ApiException?>(null) }
    val scope = rememberCoroutineScope()

    LaunchedEffect(Unit) {
        try {
            colors = model.client.call { workspaceColors() }.data
        } catch (exception: ApiException) {
            colorsFailure = exception
        }
    }

    fun save(input: WorkspacePreferenceInput) {
        isSaving = true
        failure = null
        scope.launch {
            try {
                model.updateWorkspacePreference(workspaceId, input)
            } catch (exception: ApiException) {
                failure = exception
            } finally {
                isSaving = false
            }
        }
    }

    JarvisScreen(title = workspace?.name ?: stringResource(R.string.workspace), onBack = onBack) {
        if (workspace == null) {
            ContentUnavailable(title = stringResource(R.string.workspace_not_found), icon = Icons.Filled.SearchOff)

            return@JarvisScreen
        }

        GroupedList {
            item {
                Section {
                    ListRow(
                        title = stringResource(R.string.name),
                        showDivider = workspace.modules.isNotEmpty(),
                        trailing = { Text(workspace.name, color = MaterialTheme.colorScheme.onSurfaceVariant) },
                    )

                    if (workspace.modules.isNotEmpty()) {
                        ListRow(title = stringResource(R.string.workspace_modules), subtitle = moduleNames(user.modules, workspace))
                    }
                }
            }

            item {
                Section(header = stringResource(R.string.workspace_your_color), footer = stringResource(R.string.workspace_your_color_footer)) {
                    val loaded = colors
                    val loadFailure = colorsFailure

                    when {
                        loaded != null -> WorkspaceColorPalette(
                            colors = loaded,
                            selection = workspace.ownColor,
                            onSelect = { color ->
                                if (color != workspace.ownColor) {
                                    save(
                                        WorkspacePreferenceInput(
                                            color = color?.let { WorkspacePreferenceInput.ColorChoice.Own(it) } ?: WorkspacePreferenceInput.ColorChoice.WorkspaceColor,
                                        ),
                                    )
                                }
                            },
                            allowsWorkspaceColor = true,
                            workspaceColorHex = loaded.firstOrNull { it.name == workspace.workspaceColor }?.hex,
                            isEnabled = !isSaving,
                        )

                        loadFailure != null -> ListRow(title = loadFailure.userMessage())
                        else -> Box(Modifier.fillMaxWidth().padding(16.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
                    }
                }
            }

            item {
                Section(footer = stringResource(R.string.workspace_default_footer)) {
                    if (workspace.isDefault) {
                        ListRow(
                            title = stringResource(R.string.workspace_your_default),
                            leading = { Icon(Icons.Filled.Star, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant) },
                        )
                    } else {
                        ListRow(
                            title = stringResource(R.string.workspace_make_default),
                            onClick = if (isSaving) null else ({ save(WorkspacePreferenceInput(makeDefault = true)) }),
                            modifier = Modifier.testTag("settings.workspace.make-default"),
                            leading = { Icon(Icons.Outlined.StarOutline, contentDescription = null, tint = MaterialTheme.colorScheme.primary) },
                            titleContent = { Text(stringResource(R.string.workspace_make_default), color = MaterialTheme.colorScheme.primary) },
                        )
                    }
                }
            }

            failure?.let { error ->
                item { FormError(error.userMessage()) }
            }
        }
    }
}

/** The modules of the workspace by the names of `/me`, joined. */
private fun moduleNames(modules: List<UserModule>, workspace: UserWorkspace): String {
    val names = modules.associate { it.slug to it.name }

    return workspace.modules.joinToString(", ") { names[it] ?: it }
}
